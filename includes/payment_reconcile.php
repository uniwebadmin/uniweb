<?php
declare(strict_types=1);

/**
 * Payment reconciliation — one path for webhook, poll, checkout return, manual backfill.
 *
 * Manual admin actions use the SAME status transitions and ledger path as auto paths;
 * only reconcile_source differs (manual | reconcile). Never ad-hoc UPDATE status + INSERT ledger.
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

/** Resolve admin input — numeric id, TXN ref, or payment order id / order_ref. */
function resolveManualReconcileTransactionRef(string $ref): ?array
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    $db = getDB();
    if (preg_match('/^\d+$/', $ref)) {
        $num = (int)$ref;
        $st = $db->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
        $st->execute([$num]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
        if (function_exists('requireFinancialTables')) {
            requireFinancialTables();
            $ost = $db->prepare('SELECT pot.transaction_id FROM payment_orders po LEFT JOIN payment_order_transactions pot ON pot.payment_order_id=po.id WHERE po.id=? LIMIT 1');
            $ost->execute([$num]);
            $txnId = (int)$ost->fetchColumn();
            if ($txnId > 0) {
                $st = $db->prepare('SELECT * FROM transactions WHERE id=? LIMIT 1');
                $st->execute([$txnId]);
                $row = $st->fetch();
                if ($row) {
                    return $row;
                }
            }
        }
    }
    if (preg_match('/^TXN/i', $ref)) {
        $st = $db->prepare('SELECT * FROM transactions WHERE txn_id=? LIMIT 1');
        $st->execute([strtoupper($ref)]);
        return $st->fetch() ?: null;
    }
    if (function_exists('requireFinancialTables')) {
        requireFinancialTables();
        $ost = $db->prepare(
            'SELECT t.* FROM payment_orders po
             JOIN payment_order_transactions pot ON pot.payment_order_id=po.id
             JOIN transactions t ON t.id=pot.transaction_id
             WHERE po.order_ref=? OR po.provider_order_id=? LIMIT 1'
        );
        $ost->execute([$ref, $ref]);
        $row = $ost->fetch();
        if ($row) {
            return $row;
        }
    }
    return null;
}

function partnerGatewayConfigured(string $provider): bool
{
    $provider = strtolower(trim($provider));
    return function_exists('isGatewayConfigured') && isGatewayConfigured($provider);
}

/**
 * Poll partner API for bound order status — returns applyPartnerPaymentReconcile payload or null.
 *
 * @return array<string,mixed>|null
 */
function partnerPollPayloadForPaymentOrder(array $order, ?string $hintPaymentId = null): ?array
{
    if (!function_exists('fetchRazorpayPayment') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }
    $provider = strtolower(trim((string)($order['provider'] ?? '')));
    $providerOrderId = trim((string)($order['provider_order_id'] ?? ''));
    if ($provider === '' || $providerOrderId === '') {
        return null;
    }
    if (!partnerGatewayConfigured($provider)) {
        return null;
    }

    $paymentId = trim((string)($hintPaymentId ?? ''));
    if ($paymentId === '') {
        try {
            $st = getDB()->prepare(
                'SELECT provider_payment_id FROM payment_attempts WHERE payment_order_id=? ORDER BY id DESC LIMIT 1'
            );
            $st->execute([(int)$order['id']]);
            $paymentId = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            /* ok */
        }
    }

    if ($provider === 'razorpay') {
        if ($paymentId === '') {
            return null;
        }
        $providerPayment = fetchRazorpayPayment($paymentId);
        if (!$providerPayment) {
            return null;
        }
        $status = strtolower((string)($providerPayment['status'] ?? ''));
        if ($status === 'captured') {
            return [
                'provider' => 'razorpay',
                'provider_order_id' => (string)($providerPayment['order_id'] ?? $providerOrderId),
                'provider_payment_id' => $paymentId,
                'amount' => ((float)($providerPayment['amount'] ?? 0)) / 100,
                'currency' => (string)($providerPayment['currency'] ?? 'INR'),
                'captured' => true,
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => $paymentId,
            ];
        }
        if (in_array($status, ['failed', 'refunded'], true)) {
            $err = is_array($providerPayment['error'] ?? null) ? $providerPayment['error'] : [];
            return [
                'provider' => 'razorpay',
                'provider_order_id' => (string)($providerPayment['order_id'] ?? $providerOrderId),
                'provider_payment_id' => $paymentId,
                'error_code' => (string)($err['code'] ?? $status),
                'error_description' => (string)($err['description'] ?? $status),
                'amount' => isset($providerPayment['amount']) ? ((float)$providerPayment['amount'] / 100) : null,
                'currency' => (string)($providerPayment['currency'] ?? 'INR'),
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => $paymentId,
                'terminal' => 'failed',
            ];
        }
        return null;
    }

    if ($provider === 'cashfree') {
        $providerOrder = fetchCashfreeOrder($providerOrderId);
        if (!$providerOrder) {
            return null;
        }
        $orderStatus = strtoupper((string)($providerOrder['order_status'] ?? ''));
        if ($orderStatus === 'PAID') {
            $payments = fetchCashfreeOrderPayments($providerOrderId);
            $captured = null;
            foreach ($payments as $candidate) {
                if (strtoupper((string)($candidate['payment_status'] ?? '')) === 'SUCCESS') {
                    $captured = $candidate;
                    break;
                }
            }
            if (!$captured) {
                return null;
            }
            $verifiedPaymentId = (string)($captured['cf_payment_id'] ?? '');
            if ($verifiedPaymentId === '') {
                return null;
            }
            return [
                'provider' => 'cashfree',
                'provider_order_id' => $providerOrderId,
                'provider_payment_id' => $verifiedPaymentId,
                'amount' => (float)($captured['payment_amount'] ?? 0),
                'currency' => (string)($captured['payment_currency'] ?? $providerOrder['order_currency'] ?? 'INR'),
                'captured' => true,
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => $verifiedPaymentId,
            ];
        }
        if (in_array($orderStatus, ['FAILED', 'CANCELLED', 'EXPIRED'], true)) {
            return [
                'provider' => 'cashfree',
                'provider_order_id' => $providerOrderId,
                'provider_payment_id' => $paymentId !== '' ? $paymentId : ('fail:' . $providerOrderId),
                'error_code' => $orderStatus,
                'error_description' => 'Partner order ' . $orderStatus,
                'amount' => (float)($providerOrder['order_amount'] ?? 0) ?: null,
                'currency' => (string)($providerOrder['order_currency'] ?? 'INR'),
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => $providerOrderId,
                'terminal' => 'failed',
            ];
        }
        return null;
    }

    if ($provider === 'payu' && $paymentId !== '') {
        return [
            'provider' => 'payu',
            'provider_order_id' => $providerOrderId,
            'provider_payment_id' => $paymentId,
            'amount' => (float)($order['expected_amount'] ?? 0),
            'currency' => (string)($order['currency'] ?? 'INR'),
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $paymentId,
        ];
    }

    return null;
}

/**
 * Admin: backfill ledger for an already-success txn — finalizeSuccessfulPaymentTransaction only.
 *
 * @return array<string,mixed>
 */
function manualBackfillTransactionLedger(int $transactionId, int $adminId = 0): array
{
    if ($transactionId <= 0) {
        return ['ok' => false, 'error' => 'Invalid transaction id.'];
    }
    if (!function_exists('finalizeSuccessfulPaymentTransaction') && is_file(__DIR__ . '/financial_integrity.php')) {
        require_once __DIR__ . '/financial_integrity.php';
    }
    if (!function_exists('getTransactionLedgerStatus')) {
        return ['ok' => false, 'error' => 'Ledger helpers unavailable.'];
    }

    $st = getDB()->prepare('SELECT id, txn_id, merchant_id, status, payment_method FROM transactions WHERE id=? LIMIT 1');
    $st->execute([$transactionId]);
    $txn = $st->fetch();
    if (!$txn) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }
    if (strtolower((string)$txn['status']) !== 'success') {
        return ['ok' => false, 'error' => 'Ledger backfill requires Success status first — use Reconcile to fetch partner status.'];
    }

    $ledgerBefore = getTransactionLedgerStatus($transactionId, (string)$txn['txn_id'], (int)$txn['merchant_id'], 'success');
    if ($ledgerBefore === 'posted') {
        persistPaymentReconcileMeta($transactionId, 'manual', (string)($txn['txn_id'] ?? ''));
        return [
            'ok' => true,
            'duplicate' => true,
            'message' => 'Ledger already posted.',
            'transaction_id' => $transactionId,
            'reconcile_source' => 'manual',
            'ledger_status' => 'posted',
        ];
    }

    $result = finalizeSuccessfulPaymentTransaction($transactionId, [
        'provider' => (string)($txn['payment_method'] ?? 'sandbox'),
        'skip_notify' => true,
        'skip_audit' => true,
    ]);
    $ledgerStatus = (string)($result['ledger_status'] ?? 'pending');
    if (!empty($result['ok']) && ($ledgerStatus === 'posted' || !empty($result['ledger_posted']))) {
        persistPaymentReconcileMeta($transactionId, 'manual', (string)($txn['txn_id'] ?? ''));
        if ($adminId > 0 && function_exists('logStaffActivity')) {
            logStaffActivity('manual_ledger_backfill', (string)$txn['txn_id'], (int)$txn['merchant_id'], 'transaction', (string)$txn['txn_id']);
        }
        return [
            'ok' => true,
            'transaction_id' => $transactionId,
            'reconcile_source' => 'manual',
            'ledger_status' => 'posted',
            'message' => 'Ledger backfill posted.',
        ];
    }

    return [
        'ok' => false,
        'error' => (string)($result['error'] ?? 'Ledger backfill did not post — check Error Log.'),
        'transaction_id' => $transactionId,
        'ledger_status' => $ledgerStatus,
    ];
}

/**
 * Admin manual reconcile — poll partner when keys exist, else honest failure.
 * Ends in applyPartnerPaymentReconcile → captureVerifiedPaymentOrder / finalizeSuccessfulPaymentTransaction.
 *
 * @return array<string,mixed>
 */
function manualReconcileTransaction(string $ref, int $adminId = 0): array
{
    $txn = resolveManualReconcileTransactionRef($ref);
    if (!$txn) {
        return ['ok' => false, 'error' => 'Transaction not found — use TXN id, numeric id, or payment order ref.'];
    }

    $transactionId = (int)$txn['id'];
    $status = strtolower((string)$txn['status']);

    if ($status === 'success') {
        if (!function_exists('getTransactionLedgerStatus') && is_file(__DIR__ . '/financial_integrity.php')) {
            require_once __DIR__ . '/financial_integrity.php';
        }
        if (function_exists('getTransactionLedgerStatus')) {
            $ledger = getTransactionLedgerStatus($transactionId, (string)$txn['txn_id'], (int)$txn['merchant_id'], 'success');
            if ($ledger === 'pending') {
                return manualBackfillTransactionLedger($transactionId, $adminId);
            }
        }
        persistPaymentReconcileMeta($transactionId, 'manual', (string)($txn['utr'] ?? ''));
        return [
            'ok' => true,
            'duplicate' => true,
            'message' => 'Already success — no change needed.',
            'transaction_id' => $transactionId,
            'reconcile_source' => 'manual',
        ];
    }

    if (!in_array($status, ['pending', 'processing', 'initiated', 'failed'], true)) {
        return ['ok' => false, 'error' => 'Transaction status cannot be reconciled from admin: ' . $status];
    }

    if (!function_exists('requireFinancialTables')) {
        require_once __DIR__ . '/financial_integrity.php';
    }
    requireFinancialTables();

    $orderSt = getDB()->prepare(
        'SELECT po.* FROM payment_orders po
         JOIN payment_order_transactions pot ON pot.payment_order_id=po.id
         WHERE pot.transaction_id=? ORDER BY po.id DESC LIMIT 1'
    );
    $orderSt->execute([$transactionId]);
    $order = $orderSt->fetch();

    if (!$order) {
        return ['ok' => false, 'error' => 'No bound payment order — cannot poll partner. Wait for webhook or checkout verify.'];
    }

    $provider = strtolower(trim((string)$order['provider']));
    if (!partnerGatewayConfigured($provider)) {
        return ['ok' => false, 'error' => ucfirst($provider) . ' keys not configured — paste keys in Partner Registry before Reconcile.'];
    }

    $payload = partnerPollPayloadForPaymentOrder($order, trim((string)($txn['utr'] ?? '')));
    if ($payload === null) {
        return ['ok' => false, 'error' => 'Partner still pending or fetch failed — no fake success applied.'];
    }

    $payload['reconcile_source'] = 'manual';
    $result = applyPartnerPaymentReconcile($payload);
    $result['reconcile_source'] = 'manual';

    if ($adminId > 0 && function_exists('logStaffActivity') && !empty($result['ok'])) {
        logStaffActivity('manual_reconcile', (string)$txn['txn_id'], (int)$txn['merchant_id'], 'transaction', (string)$txn['txn_id']);
    }

    return $result;
}

/**
 * Safe bulk manual reconcile — each txn isolated; max 10 per request.
 *
 * @return array{ok:bool,processed:int,succeeded:int,failed:int,results:list<array<string,mixed>>}
 */
function manualReconcileTransactionsBatch(array $refs, int $adminId = 0, int $limit = 10): array
{
    $limit = max(1, min(10, $limit));
    $refs = array_slice(array_values(array_filter(array_map('trim', $refs))), 0, $limit);
    $results = [];
    $succeeded = 0;
    $failed = 0;
    foreach ($refs as $ref) {
        if ($ref === '') {
            continue;
        }
        $one = manualReconcileTransaction($ref, $adminId);
        $results[] = ['ref' => $ref, 'result' => $one];
        if (!empty($one['ok'])) {
            $succeeded++;
        } else {
            $failed++;
        }
    }
    return [
        'ok' => $failed === 0,
        'processed' => count($results),
        'succeeded' => $succeeded,
        'failed' => $failed,
        'results' => $results,
    ];
}
