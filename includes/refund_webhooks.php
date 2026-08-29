<?php
declare(strict_types=1);

/**
 * Central partner refund webhook apply — signature verified upstream; idempotent by event + provider refund id.
 */

/** Allowed local refund.status transitions (terminal states are sticky). */
function refundAllowedStatusTransition(string $from, string $to): bool
{
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if ($from === $to) {
        return true;
    }
    if ($from === 'completed' || $from === 'failed') {
        return false;
    }
    if ($from === 'pending') {
        return in_array($to, ['pending', 'completed', 'failed'], true);
    }
    return false;
}

/** Human label for Admin / merchant UI — honest, no fake "Refunded". */
function refundDisplayStatus(array $refund): string
{
    $status = strtolower((string)($refund['status'] ?? 'pending'));
    $providerStatus = strtolower((string)($refund['provider_status'] ?? ''));
    if ($status === 'completed') {
        return 'processed';
    }
    if ($status === 'failed') {
        return 'failed';
    }
    if ($providerStatus !== '' && in_array($providerStatus, ['processing', 'pending', 'accepted', 'queued'], true)) {
        return 'processing';
    }
    if (!empty($refund['provider_refund_id'])) {
        return 'processing';
    }
    return 'requested';
}

/**
 * Apply a verified partner refund webhook (or poll) event idempotently.
 *
 * @param array{provider_refund_id?:string,refund_id?:string,event_type?:string,terminal?:string,failure_reason?:string} $context
 * @return array{ok:bool,duplicate?:bool,status?:string,error?:string}
 */
function applyPartnerRefundWebhookEvent(string $provider, array $context): array
{
    $provider = strtolower(trim($provider));
    $providerRefundId = trim((string)($context['provider_refund_id'] ?? ''));
    $localRefundId = trim((string)($context['refund_id'] ?? ''));
    $eventType = strtolower(trim((string)($context['event_type'] ?? '')));
    $terminalHint = strtolower(trim((string)($context['terminal'] ?? '')));
    $failureReason = mb_substr(trim((string)($context['failure_reason'] ?? '')), 0, 500);

    if ($providerRefundId === '' && $localRefundId === '') {
        return ['ok' => false, 'error' => 'Missing provider or local refund reference.'];
    }

    if (!function_exists('completeProviderRefund') && is_file(__DIR__ . '/refunds.php')) {
        require_once __DIR__ . '/refunds.php';
    }
    if (!function_exists('fetchRazorpayRefund') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }

    $db = getDB();
    $refund = false;
    if ($providerRefundId !== '') {
        $st = $db->prepare(
            "SELECT r.*, t.utr AS payment_id, t.txn_id
             FROM refunds r
             JOIN transactions t ON t.id = r.transaction_id
             WHERE r.provider = ? AND (r.provider_refund_id = ? OR r.refund_id = ?)
             LIMIT 1"
        );
        $st->execute([$provider, $providerRefundId, $providerRefundId]);
        $refund = $st->fetch();
    }
    if (!$refund && $localRefundId !== '') {
        $st = $db->prepare(
            "SELECT r.*, t.utr AS payment_id, t.txn_id
             FROM refunds r
             JOIN transactions t ON t.id = r.transaction_id
             WHERE r.refund_id = ?
             LIMIT 1"
        );
        $st->execute([$localRefundId]);
        $refund = $st->fetch();
    }
    if (!$refund) {
        return ['ok' => false, 'error' => 'Refund row not linked to this partner reference.'];
    }

    if (($refund['status'] ?? '') === 'completed') {
        return ['ok' => true, 'duplicate' => true, 'status' => 'completed'];
    }
    if (($refund['status'] ?? '') === 'failed' && $terminalHint !== 'failed') {
        return ['ok' => true, 'duplicate' => true, 'status' => 'failed'];
    }

    $verified = verifyPartnerRefundStatus($provider, $refund, $providerRefundId ?: (string)($refund['provider_refund_id'] ?? ''));
    if ($verified === null) {
        return ['ok' => false, 'error' => 'Partner refund server verification failed.'];
    }

    $providerStatus = strtolower((string)($verified['status'] ?? $terminalHint ?: 'pending'));
    $isProcessed = in_array($providerStatus, ['processed', 'success', 'successful', 'completed'], true)
        || str_contains($eventType, 'processed')
        || str_contains($eventType, 'success')
        || $terminalHint === 'processed';
    $isFailed = in_array($providerStatus, ['failed', 'cancelled', 'canceled', 'rejected'], true)
        || str_contains($eventType, 'failed')
        || $terminalHint === 'failed';

    if ($isProcessed) {
        if (!refundAllowedStatusTransition((string)$refund['status'], 'completed')) {
            return ['ok' => true, 'duplicate' => true, 'status' => (string)$refund['status']];
        }
        $refId = $providerRefundId !== '' ? $providerRefundId : (string)($verified['id'] ?? $refund['provider_refund_id'] ?? '');
        $result = completeProviderRefund((string)$refund['refund_id'], $refId);
        return ['ok' => true, 'status' => 'completed', 'duplicate' => !empty($result['duplicate'])];
    }

    if ($isFailed) {
        if (!refundAllowedStatusTransition((string)$refund['status'], 'failed')) {
            return ['ok' => true, 'duplicate' => true, 'status' => (string)$refund['status']];
        }
        $reason = $failureReason !== ''
            ? $failureReason
            : mb_substr((string)($verified['failure_reason'] ?? $verified['error_description'] ?? 'Partner marked the refund failed.'), 0, 500);
        $result = markProviderRefundFailed((int)$refund['id'], $reason);
        return ['ok' => true, 'status' => 'failed', 'duplicate' => !empty($result['duplicate'])];
    }

    if (refundAllowedStatusTransition((string)$refund['status'], 'pending')) {
        $db->prepare(
            "UPDATE refunds SET provider_status=?, provider_reference=COALESCE(provider_reference, ?) WHERE id=? AND status='pending'"
        )->execute([
            $providerStatus ?: 'pending',
            $providerRefundId ?: (string)($refund['provider_refund_id'] ?? ''),
            (int)$refund['id'],
        ]);
    }
    return ['ok' => true, 'status' => $providerStatus ?: 'pending'];
}

/** @return array{status:string,id?:string,failure_reason?:string,error_description?:string}|null */
function verifyPartnerRefundStatus(string $provider, array $refund, string $providerRefundId): ?array
{
    if (!function_exists('resolveTransactionRefundContext') && is_file(__DIR__ . '/refunds.php')) {
        require_once __DIR__ . '/refunds.php';
    }
    if (!function_exists('fetchRazorpayRefund') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }
    $provider = strtolower(trim($provider));
    $providerRefundId = trim($providerRefundId);
    if ($providerRefundId === '') {
        return null;
    }

    if ($provider === 'razorpay') {
        $paymentId = (string)($refund['payment_id'] ?? '');
        $data = fetchRazorpayRefund($paymentId, $providerRefundId);
        if (!$data || (string)($data['id'] ?? '') !== $providerRefundId) {
            return null;
        }
        return [
            'status' => strtolower((string)($data['status'] ?? 'pending')),
            'id' => (string)$data['id'],
            'error_description' => (string)($data['error_description'] ?? ''),
        ];
    }

    if ($provider === 'cashfree') {
        $ctx = resolveTransactionRefundContext($refund);
        $orderId = (string)($ctx['provider_order_id'] ?? '');
        if ($orderId === '') {
            return null;
        }
        $data = fetchCashfreeRefund($orderId, $providerRefundId);
        if (!$data) {
            return null;
        }
        $cfId = (string)($data['cf_refund_id'] ?? $data['refund_id'] ?? $providerRefundId);
        if ($cfId !== $providerRefundId && (string)($data['refund_id'] ?? '') !== $providerRefundId) {
            return null;
        }
        return [
            'status' => strtolower((string)($data['refund_status'] ?? $data['status'] ?? 'pending')),
            'id' => $cfId,
            'failure_reason' => (string)($data['status_description'] ?? $data['refund_message'] ?? ''),
        ];
    }

    if ($provider === 'payu') {
        $paymentId = (string)($refund['payment_id'] ?? '');
        $tokenId = (string)($refund['refund_id'] ?? '');
        $data = fetchPayuRefundStatus($paymentId, $tokenId);
        if (!$data) {
            return null;
        }
        $status = strtolower((string)($data['status'] ?? $data['Refund Status'] ?? 'pending'));
        return [
            'status' => $status === 'success' ? 'processed' : ($status === 'failure' || $status === 'failed' ? 'failed' : $status),
            'id' => $providerRefundId,
            'failure_reason' => (string)($data['msg'] ?? $data['message'] ?? ''),
        ];
    }

    return null;
}

/**
 * Poll pending partner refunds (cron / auto-audit safe retry).
 *
 * @return array{ok:bool,checked:int,updated:int,errors:int}
 */
function reconcilePendingRefunds(int $limit = 40): array
{
    if (!function_exists('resolveTransactionRefundContext') && is_file(__DIR__ . '/refunds.php')) {
        require_once __DIR__ . '/refunds.php';
    }
    $st = getDB()->prepare(
        "SELECT r.*, t.utr AS payment_id, t.txn_id, t.payment_method
         FROM refunds r
         JOIN transactions t ON t.id = r.transaction_id
         WHERE r.status = 'pending'
           AND r.provider IN ('razorpay','cashfree','payu')
           AND r.provider_refund_id IS NOT NULL
           AND r.provider_refund_id != ''
         ORDER BY r.created_at ASC
         LIMIT ?"
    );
    $st->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
    $updated = 0;
    $errors = 0;
    foreach ($rows as $row) {
        try {
            $result = applyPartnerRefundWebhookEvent((string)$row['provider'], [
                'provider_refund_id' => (string)$row['provider_refund_id'],
                'event_type' => 'poll',
            ]);
            if (!empty($result['ok']) && empty($result['duplicate']) && in_array((string)($result['status'] ?? ''), ['completed', 'failed'], true)) {
                $updated++;
            }
        } catch (Throwable $e) {
            $errors++;
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'Pending refund poll failed.', [
                    'refund_id' => (string)($row['refund_id'] ?? ''),
                    'provider' => (string)($row['provider'] ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
    return ['ok' => true, 'checked' => count($rows), 'updated' => $updated, 'errors' => $errors];
}
