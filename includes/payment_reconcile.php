<?php
declare(strict_types=1);

/**
 * Payment reconciliation — one path for webhook, poll, checkout return, manual backfill.
 *
 * Allowed txn status transitions (terminal states sticky):
 *   pending|processing|initiated → success|failed|expired
 *   success → success (idempotent duplicate)
 *   failed → failed (idempotent)
 *   success → failed  BLOCKED (partner disagree → Error Log only)
 *
 * Idempotency keys:
 *   Pay capture: gateway_events (provider, event_id) + payment_order paid status + ledger business_reference
 *   Pay failure: payment_attempts provider_payment_id + order status
 *   Refund: applyPartnerRefundWebhookEvent (provider_refund_id)
 */

/** @return list<string> */
function paymentReconcileSources(): array
{
    return ['webhook', 'poll', 'checkout', 'manual', 'reconcile'];
}

function ensurePaymentReconcileColumns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $db = getDB();
        $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS last_reconcile_source VARCHAR(16) DEFAULT NULL AFTER failure_reason");
        $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS last_reconcile_at TIMESTAMP NULL DEFAULT NULL AFTER last_reconcile_source");
        $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS partner_event_ref VARCHAR(128) DEFAULT NULL AFTER last_reconcile_at");
    } catch (Throwable $e) {
        /* column may exist or MariaDB version lacks IF NOT EXISTS on ADD */
    }
}

function paymentAllowedStatusTransition(string $from, string $to): bool
{
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if ($from === $to) {
        return true;
    }
    if (in_array($from, ['success', 'paid', 'captured', 'refunded'], true)) {
        return false;
    }
    if (in_array($from, ['failed', 'expired', 'cancelled', 'canceled'], true)) {
        return $to === 'failed' || $to === 'expired';
    }
    return in_array($to, ['pending', 'processing', 'initiated', 'success', 'failed', 'expired'], true);
}

function normalizePaymentReconcileSource(?string $source): string
{
    $source = strtolower(trim((string)$source));
    return in_array($source, paymentReconcileSources(), true) ? $source : 'webhook';
}

/** Persist reconcile metadata on a transaction row (best-effort). */
function persistPaymentReconcileMeta(int $transactionId, string $source, ?string $partnerRef = null): void
{
    if ($transactionId <= 0) {
        return;
    }
    ensurePaymentReconcileColumns();
    $source = normalizePaymentReconcileSource($source);
    $partnerRef = $partnerRef !== null ? mb_substr(trim($partnerRef), 0, 128) : null;
    if ($partnerRef === '') {
        $partnerRef = null;
    }
    try {
        getDB()->prepare(
            'UPDATE transactions SET last_reconcile_source=?, last_reconcile_at=NOW(), partner_event_ref=COALESCE(?, partner_event_ref) WHERE id=?'
        )->execute([$source, $partnerRef, $transactionId]);
    } catch (Throwable $e) {
        /* non-fatal */
    }
}

/**
 * Flag partner vs UniWeb mismatch — no silent reverse.
 */
function logPaymentPartnerStatusMismatch(int $transactionId, string $txnRef, string $provider, string $uniwebStatus, string $partnerStatus, string $source): void
{
    if (!function_exists('logPlatformError')) {
        return;
    }
    logPlatformError('warning', 'Partner status disagrees with UniWeb — manual review required.', [
        'transaction_id' => $transactionId,
        'txn_ref' => $txnRef,
        'provider' => $provider,
        'uniweb_status' => $uniwebStatus,
        'partner_status' => $partnerStatus,
        'reconcile_source' => $source,
    ]);
}

/**
 * Canonical apply — verified capture or failure after upstream signature verify.
 *
 * @param array<string,mixed> $payload captureVerifiedPaymentOrder shape OR recordPaymentOrderFailure shape
 * @return array<string,mixed>
 */
function applyPartnerPaymentReconcile(array $payload): array
{
    if (!function_exists('captureVerifiedPaymentOrder') && is_file(__DIR__ . '/financial_integrity.php')) {
        require_once __DIR__ . '/financial_integrity.php';
    }
    if (!function_exists('recordPaymentOrderFailure') && is_file(__DIR__ . '/financial_integrity.php')) {
        require_once __DIR__ . '/financial_integrity.php';
    }

    $source = normalizePaymentReconcileSource((string)($payload['reconcile_source'] ?? 'webhook'));
    $payload['reconcile_source'] = $source;
    $provider = strtolower(trim((string)($payload['provider'] ?? '')));
    $providerOrderId = trim((string)($payload['provider_order_id'] ?? ''));
    $terminal = strtolower(trim((string)($payload['terminal'] ?? '')));
    $isFailure = $terminal === 'failed'
        || !empty($payload['failed'])
        || (empty($payload['captured']) && isset($payload['error_code']));

    // Pre-check: paid order + partner failure → flag mismatch, never reverse silently.
    if ($isFailure && $provider !== '' && $providerOrderId !== '' && function_exists('requireFinancialTables')) {
        try {
            requireFinancialTables();
            $orderSt = getDB()->prepare(
                'SELECT o.status, pot.transaction_id, t.status AS txn_status, t.txn_id
                 FROM payment_orders o
                 LEFT JOIN payment_order_transactions pot ON pot.payment_order_id = o.id
                 LEFT JOIN transactions t ON t.id = pot.transaction_id
                 WHERE o.provider=? AND o.provider_order_id=? LIMIT 1'
            );
            $orderSt->execute([$provider, $providerOrderId]);
            $row = $orderSt->fetch();
            if ($row && ($row['status'] ?? '') === 'paid') {
                $txnId = (int)($row['transaction_id'] ?? 0);
                if ($txnId > 0) {
                    logPaymentPartnerStatusMismatch(
                        $txnId,
                        (string)($row['txn_id'] ?? ''),
                        $provider,
                        (string)($row['txn_status'] ?? 'success'),
                        'failed',
                        $source
                    );
                }
                return [
                    'ok' => true,
                    'ignored' => true,
                    'reason' => 'already_paid',
                    'transaction_id' => $txnId,
                    'duplicate' => true,
                ];
            }
        } catch (Throwable $e) {
            /* proceed to canonical handler */
        }
    }

    if ($isFailure) {
        $payload['terminal'] = 'failed';
        $result = recordPaymentOrderFailure($payload);
        if (!empty($result['transaction_id'])) {
            persistPaymentReconcileMeta(
                (int)$result['transaction_id'],
                $source,
                (string)($payload['provider_payment_id'] ?? $payload['reference'] ?? '')
            );
        }
        return $result;
    }

    $result = captureVerifiedPaymentOrder($payload);
    if (!empty($result['transaction_id'])) {
        persistPaymentReconcileMeta(
            (int)$result['transaction_id'],
            $source,
            (string)($payload['provider_payment_id'] ?? $payload['reference'] ?? '')
        );
    }
    return $result;
}

/** Human label for txn detail / admin. */
function paymentReconcileSourceLabel(?string $source): string
{
    return match (normalizePaymentReconcileSource($source)) {
        'webhook' => 'Partner webhook',
        'poll' => 'Status poll / partner fetch',
        'checkout' => 'Checkout return verify',
        'manual' => 'Manual admin action',
        'reconcile' => 'Ledger reconcile backfill',
        default => 'Unknown',
    };
}
