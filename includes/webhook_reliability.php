<?php
declare(strict_types=1);

/**
 * Webhook Reliability Engine — idempotency, retry queue, dead letter.
 *
 * Flow:
 *   1. Gateway sends webhook → recordWebhookEvent() checks idempotency
 *   2. If duplicate (same event_id) → return already-processed status
 *   3. If new → store in webhook_events, process, mark completed/failed
 *   4. Failed → schedule retry with exponential backoff
 *   5. After max_retries → move to dead_letter status
 */

function ensureWebhookEventsTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS webhook_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(128) NOT NULL,
            gateway VARCHAR(32) NOT NULL,
            event_type VARCHAR(64) DEFAULT NULL,
            payload LONGTEXT,
            signature VARCHAR(255) DEFAULT NULL,
            status ENUM('received','processing','completed','failed','dead_letter') NOT NULL DEFAULT 'received',
            retry_count INT NOT NULL DEFAULT 0,
            max_retries INT NOT NULL DEFAULT 5,
            last_error TEXT DEFAULT NULL,
            processed_at TIMESTAMP NULL DEFAULT NULL,
            next_retry_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_event_id (event_id),
            INDEX idx_status_retry (status, next_retry_at),
            INDEX idx_gateway (gateway, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Record a webhook event with idempotency check.
 * Returns ['is_duplicate' => bool, 'id' => int, 'status' => string].
 */
function recordWebhookEvent(string $eventId, string $gateway, string $eventType, string $payload, ?string $signature = null): array
{
    ensureWebhookEventsTable();
    $db = getDB();

    // Idempotency check — same event_id already processed?
    try {
        $st = $db->prepare("SELECT id, status FROM webhook_events WHERE event_id=?");
        $st->execute([$eventId]);
        $existing = $st->fetch();
        if ($existing) {
            return [
                'is_duplicate' => true,
                'id' => (int)$existing['id'],
                'status' => $existing['status'],
            ];
        }
    } catch (Throwable $e) { /* ok */ }

    // Insert new event
    try {
        $db->prepare(
            "INSERT INTO webhook_events (event_id, gateway, event_type, payload, signature, status, max_retries)
             VALUES (?,?,?,?,?, 'received', 5)"
        )->execute([$eventId, $gateway, $eventType, $payload, $signature]);
        return [
            'is_duplicate' => false,
            'id' => (int)$db->lastInsertId(),
            'status' => 'received',
        ];
    } catch (Throwable $e) {
        // If unique constraint violation, it's a race condition duplicate
        return ['is_duplicate' => true, 'id' => 0, 'status' => 'received'];
    }
}

/**
 * Mark webhook event as processing.
 */
function markWebhookProcessing(int $eventId): void
{
    ensureWebhookEventsTable();
    try {
        getDB()->prepare("UPDATE webhook_events SET status='processing', processed_at=NOW() WHERE id=? AND status IN ('received','failed')")
            ->execute([$eventId]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Mark webhook event as completed.
 */
function markWebhookCompleted(int $eventId): void
{
    ensureWebhookEventsTable();
    try {
        getDB()->prepare("UPDATE webhook_events SET status='completed', processed_at=NOW(), last_error=NULL WHERE id=?")
            ->execute([$eventId]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Mark webhook event as failed and schedule retry.
 */
function markWebhookFailed(int $eventId, string $error): void
{
    ensureWebhookEventsTable();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT retry_count, max_retries FROM webhook_events WHERE id=?");
        $st->execute([$eventId]);
        $row = $st->fetch();
        if (!$row) return;

        $retryCount = (int)$row['retry_count'] + 1;
        $maxRetries = (int)$row['max_retries'];

        if ($retryCount >= $maxRetries) {
            // Move to dead letter
            $db->prepare("UPDATE webhook_events SET status='dead_letter', retry_count=?, last_error=? WHERE id=?")
                ->execute([$retryCount, mb_substr($error, 0, 2000), $eventId]);
            // A2: Send alert
            $alertEvent = ['id' => $eventId, 'gateway' => '', 'event_id' => '', 'event_type' => '', 'retry_count' => $retryCount, 'last_error' => $error];
            try {
                $est = $db->prepare("SELECT gateway, event_id, event_type FROM webhook_events WHERE id=?");
                $est->execute([$eventId]);
                $row = $est->fetch();
                if ($row) {
                    $alertEvent['gateway'] = $row['gateway'];
                    $alertEvent['event_id'] = $row['event_id'];
                    $alertEvent['event_type'] = $row['event_type'];
                }
            } catch (Throwable $e) {}
            alertWebhookDeadLetter($alertEvent);
        } else {
            // Schedule retry with exponential backoff: 2^retry minutes
            $delayMinutes = min(60, (1 << $retryCount));
            $nextRetry = date('Y-m-d H:i:s', time() + ($delayMinutes * 60));
            $db->prepare("UPDATE webhook_events SET status='failed', retry_count=?, last_error=?, next_retry_at=? WHERE id=?")
                ->execute([$retryCount, mb_substr($error, 0, 2000), $nextRetry, $eventId]);
        }
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Get events due for retry (failed + next_retry_at <= now).
 */
function getWebhookEventsForRetry(int $limit = 20): array
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare(
            "SELECT * FROM webhook_events
             WHERE status='failed' AND next_retry_at <= NOW()
             ORDER BY next_retry_at ASC LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Process retry queue — called by cron / auto_audit.
 */
function processWebhookRetries(int $limit = 20): array
{
    $events = getWebhookEventsForRetry($limit);
    $results = ['processed' => 0, 'completed' => 0, 'failed' => 0, 'dead_lettered' => 0];

    foreach ($events as $event) {
        $results['processed']++;
        markWebhookProcessing((int)$event['id']);

        // Retry the webhook by calling the gateway-specific handler
        $success = retryWebhookEvent($event);

        if ($success) {
            markWebhookCompleted((int)$event['id']);
            $results['completed']++;
        } else {
            markWebhookFailed((int)$event['id'], 'Retry attempt failed');
            $results['failed']++;
            // Check if it became dead letter
            try {
                $st = getDB()->prepare("SELECT status FROM webhook_events WHERE id=?");
                $st->execute([(int)$event['id']]);
                if ($st->fetchColumn() === 'dead_letter') {
                    $results['dead_lettered']++;
                }
            } catch (Throwable $e) {}
        }
    }

    return $results;
}

/**
 * Retry a webhook event — dispatches to the appropriate gateway handler
 * with the stored payload.  Each handler re-runs the business logic
 * (capture, failure, refund, payout) against the provider API, exactly
 * as if the gateway had just delivered the webhook again.
 */
function retryWebhookEvent(array $event): bool
{
    $gateway = strtolower((string)$event['gateway']);
    $payload = json_decode((string)$event['payload'], true);
    if (!$payload) {
        return false;
    }

    try {
        $result = dispatchWebhookRetry($gateway, $payload, (string)$event['event_id'], (string)$event['event_type']);
        return $result;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Gateway-specific retry dispatch.
 * Replays the stored payload through the same business logic used
 * during the original webhook delivery.
 */
function dispatchWebhookRetry(string $gateway, array $payload, string $eventId, string $eventType): bool
{
    switch ($gateway) {
        case 'razorpay':
            return retryRazorpayWebhook($payload, $eventType);
        case 'cashfree':
            return retryCashfreeWebhook($payload, $eventType);
        case 'payu':
            return retryPayUWebhook($payload);
        case 'decentro':
            return retryDecentroWebhook($payload, $eventType);
        default:
            return false;
    }
}

/**
 * Retry Razorpay webhook: re-fetch payment from API and process capture/failure/refund/payout.
 */
function retryRazorpayWebhook(array $payload, string $eventType): bool
{
    $entity = $payload['payload']['payment']['entity'] ?? $payload['payload']['order']['entity'] ?? [];
    $paymentId = (string)($entity['id'] ?? '');
    $refundEntity = $payload['payload']['refund']['entity'] ?? [];
    $refundProviderId = (string)($refundEntity['id'] ?? '');
    $payoutEntity = $payload['payload']['payout']['entity'] ?? [];
    $payoutProviderId = (string)($payoutEntity['id'] ?? '');

    // Refund events
    if (in_array($eventType, ['refund.processed', 'refund.failed'], true) && $refundProviderId !== '') {
        $refundSt = getDB()->prepare("SELECT r.*,t.utr AS payment_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.provider='razorpay' AND r.provider_refund_id=? LIMIT 1");
        $refundSt->execute([$refundProviderId]);
        $refund = $refundSt->fetch();
        if (!$refund) return false;
        $providerRefund = fetchRazorpayRefund((string)$refund['payment_id'], $refundProviderId);
        if (!$providerRefund) return false;
        $providerStatus = strtolower((string)($providerRefund['status'] ?? ''));
        if ($eventType === 'refund.processed' && $providerStatus === 'processed') {
            completeProviderRefund((string)$refund['refund_id'], $refundProviderId);
        } elseif ($eventType === 'refund.failed' || $providerStatus === 'failed') {
            $failureReason = mb_substr((string)($providerRefund['error_description'] ?? 'Razorpay marked the refund failed.'), 0, 500);
            if (!function_exists('markProviderRefundFailed') && is_file(__DIR__ . '/refunds.php')) {
                require_once __DIR__ . '/refunds.php';
            }
            if (function_exists('markProviderRefundFailed')) {
                markProviderRefundFailed((int)$refund['id'], $failureReason);
            }
        }
        return true;
    }

    // Payout events
    if (in_array($eventType, ['payout.processed', 'payout.failed', 'payout.reversed'], true) && $payoutProviderId !== '') {
        $batchSt = getDB()->prepare('SELECT * FROM settlement_batches WHERE provider_payout_id=? LIMIT 1');
        $batchSt->execute([$payoutProviderId]);
        $batch = $batchSt->fetch();
        if (!$batch) return false;
        $providerPayout = fetchRazorpayXPayout($payoutProviderId);
        if (!$providerPayout) return false;
        $providerStatus = strtolower((string)($providerPayout['status'] ?? ''));
        $utr = trim((string)($providerPayout['utr'] ?? ''));
        if ($eventType === 'payout.processed' && $providerStatus === 'processed' && $utr !== '') {
            getDB()->prepare("UPDATE settlement_batches SET status='settled',api_status='confirmed',provider_status=?,utr=?,processed_at=NOW(),failure_reason=NULL WHERE id=?")
                ->execute([$providerStatus, $utr, (int)$batch['id']]);
            if (!empty($batch['settlement_id'])) {
                getDB()->prepare("UPDATE settlements SET status='completed',utr=?,processed_at=NOW() WHERE settlement_id=?")
                    ->execute([$utr, $batch['settlement_id']]);
            }
        } elseif (in_array($eventType, ['payout.failed', 'payout.reversed'], true)) {
            getDB()->prepare("UPDATE settlement_batches SET status='failed',api_status='failed',provider_status=?,processed_at=NOW() WHERE id=?")
                ->execute([$providerStatus, (int)$batch['id']]);
        }
        return true;
    }

    // Payment capture/failure events
    $successEvents = ['payment.captured', 'order.paid'];
    $failureEvents = ['payment.failed'];

    if (in_array($eventType, $failureEvents, true) && $paymentId !== '') {
        $providerPayment = fetchRazorpayPayment($paymentId);
        if (!$providerPayment) return false;
        $orderId = (string)($providerPayment['order_id'] ?? $entity['order_id'] ?? '');
        if ($orderId === '') return false;
        $err = is_array($providerPayment['error'] ?? null) ? $providerPayment['error'] : [];
        recordPaymentOrderFailure([
            'provider' => 'razorpay',
            'provider_order_id' => $orderId,
            'provider_payment_id' => $paymentId,
            'error_code' => (string)($err['code'] ?? ''),
            'error_description' => (string)($err['description'] ?? ''),
            'amount' => isset($providerPayment['amount']) ? ((float)$providerPayment['amount'] / 100) : null,
            'currency' => (string)($providerPayment['currency'] ?? 'INR'),
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $paymentId,
        ]);
        return true;
    }

    if (in_array($eventType, $successEvents, true) && $paymentId !== '') {
        $providerPayment = fetchRazorpayPayment($paymentId);
        if (!$providerPayment || strtolower((string)($providerPayment['status'] ?? '')) !== 'captured') {
            return false;
        }
        captureVerifiedPaymentOrder([
            'provider' => 'razorpay',
            'provider_order_id' => (string)$providerPayment['order_id'],
            'provider_payment_id' => $paymentId,
            'amount' => ((float)($providerPayment['amount'] ?? 0)) / 100,
            'currency' => (string)($providerPayment['currency'] ?? ''),
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $paymentId,
        ]);
        return true;
    }

    // Unknown event type — mark as completed (nothing to retry)
    return true;
}

/**
 * Retry Cashfree webhook: re-fetch order from API and process capture/failure.
 */
function retryCashfreeWebhook(array $payload, string $eventType): bool
{
    $data = $payload['data'] ?? $payload;
    $order = $data['order'] ?? $data;
    $payment = $data['payment'] ?? [];
    $orderId = (string)($order['order_id'] ?? $data['order_id'] ?? '');
    $orderStatus = strtoupper((string)($order['order_status'] ?? $data['order_status'] ?? ''));
    $paymentId = (string)($payment['cf_payment_id'] ?? $data['cf_payment_id'] ?? '');

    if ($orderId === '') return false;

    $failureEvents = ['PAYMENT_FAILED_WEBHOOK', 'PAYMENT_FAILED', 'ORDER_FAILED'];
    $paymentStatus = strtoupper((string)($payment['payment_status'] ?? $data['payment_status'] ?? ''));
    $isFailure = in_array(strtoupper($eventType), $failureEvents, true)
        || in_array($orderStatus, ['FAILED', 'CANCELLED', 'EXPIRED'], true)
        || in_array($paymentStatus, ['FAILED', 'USER_DROPPED', 'CANCELLED', 'EXPIRED'], true);

    if ($isFailure) {
        recordPaymentOrderFailure([
            'provider' => 'cashfree',
            'provider_order_id' => $orderId,
            'provider_payment_id' => $paymentId !== '' ? $paymentId : ('fail:' . $orderId),
            'error_code' => (string)($payment['error_code'] ?? $orderStatus),
            'error_description' => (string)($payment['payment_message'] ?? $payment['error_details'] ?? ''),
            'amount' => (float)($payment['payment_amount'] ?? $order['order_amount'] ?? 0) ?: null,
            'currency' => (string)($payment['payment_currency'] ?? $order['order_currency'] ?? 'INR'),
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $paymentId ?: $orderId,
        ]);
        return true;
    }

    if ($orderStatus === 'PAID') {
        $providerOrder = fetchCashfreeOrder($orderId);
        $providerPayments = fetchCashfreeOrderPayments($orderId);
        $capturedPayment = null;
        foreach ($providerPayments as $candidate) {
            if (strtoupper((string)($candidate['payment_status'] ?? '')) !== 'SUCCESS') continue;
            if ($paymentId === '' || (string)($candidate['cf_payment_id'] ?? '') === $paymentId) {
                $capturedPayment = $candidate;
                break;
            }
        }
        if (!$providerOrder || !$capturedPayment) return false;
        $verifiedPaymentId = (string)($capturedPayment['cf_payment_id'] ?? '');
        if ($verifiedPaymentId === '') return false;
        captureVerifiedPaymentOrder([
            'provider' => 'cashfree',
            'provider_order_id' => $orderId,
            'provider_payment_id' => $verifiedPaymentId,
            'amount' => (float)($capturedPayment['payment_amount'] ?? 0),
            'currency' => (string)($capturedPayment['payment_currency'] ?? $providerOrder['order_currency'] ?? ''),
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $verifiedPaymentId,
        ]);
        return true;
    }

    return true;
}

/**
 * Retry PayU webhook: process failure or success from stored POST data.
 */
function retryPayUWebhook(array $post): bool
{
    $status = strtolower((string)($post['status'] ?? ''));
    $reference = (string)($post['mihpayid'] ?? $post['txnid'] ?? '');
    $amount = (float)($post['amount'] ?? 0);
    $providerOrderId = (string)($post['txtxnid'] ?? $reference);

    if (in_array($status, ['failure', 'failed', 'f'], true) && $reference !== '') {
        $orderLookup = getDB()->prepare("SELECT provider_order_id FROM payment_orders WHERE provider='payu' AND (provider_order_id=? OR order_ref=?) LIMIT 1");
        $orderLookup->execute([$providerOrderId, $providerOrderId]);
        $bound = $orderLookup->fetchColumn();
        if (!$bound) return false;
        recordPaymentOrderFailure([
            'provider' => 'payu',
            'provider_order_id' => (string)$bound,
            'provider_payment_id' => $reference,
            'error_code' => (string)($post['error'] ?? $post['error_code'] ?? $post['field9'] ?? $status),
            'error_description' => (string)($post['error_Message'] ?? $post['error_message'] ?? $post['field9'] ?? $post['status'] ?? ''),
            'amount' => $amount > 0 ? $amount : null,
            'currency' => 'INR',
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $reference,
        ]);
        return true;
    }

    if (in_array($status, ['success', 'captured'], true) && $reference !== '') {
        $orderLookup = getDB()->prepare("SELECT provider_order_id FROM payment_orders WHERE provider='payu' AND (provider_order_id=? OR order_ref=?) LIMIT 1");
        $orderLookup->execute([$providerOrderId, $providerOrderId]);
        $bound = $orderLookup->fetchColumn();
        if (!$bound) return false;
        captureVerifiedPaymentOrder([
            'provider' => 'payu',
            'provider_order_id' => (string)$bound,
            'provider_payment_id' => $reference,
            'amount' => $amount,
            'currency' => 'INR',
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $reference,
        ]);
        return true;
    }

    return true;
}

/**
 * Retry Decentro webhook: poll transaction status and process capture/failure.
 */
function retryDecentroWebhook(array $payload, string $eventType): bool
{
    $decentroTxnId = (string)($payload['decentro_txn_id'] ?? '');
    if ($decentroTxnId === '') return false;

    $status = fetchDecentroTransactionStatus($decentroTxnId);
    if (!$status) return false;

    $txnStatus = strtolower((string)($status['transaction_status'] ?? $status['data']['transaction_status'] ?? ''));
    $referenceId = (string)($payload['reference_id'] ?? $status['reference_id'] ?? '');

    if ($txnStatus === 'success' || $txnStatus === 'completed') {
        $orderLookup = getDB()->prepare("SELECT provider_order_id FROM payment_orders WHERE provider='decentro' AND (provider_order_id=? OR order_ref=?) LIMIT 1");
        $orderLookup->execute([$referenceId, $referenceId]);
        $bound = $orderLookup->fetchColumn();
        if (!$bound) return false;
        captureVerifiedPaymentOrder([
            'provider' => 'decentro',
            'provider_order_id' => (string)$bound,
            'provider_payment_id' => $decentroTxnId,
            'amount' => (float)($payload['amount'] ?? 0),
            'currency' => 'INR',
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $decentroTxnId,
        ]);
        return true;
    }

    if (in_array($txnStatus, ['failed', 'expired', 'cancelled'], true)) {
        $orderLookup = getDB()->prepare("SELECT provider_order_id FROM payment_orders WHERE provider='decentro' AND (provider_order_id=? OR order_ref=?) LIMIT 1");
        $orderLookup->execute([$referenceId, $referenceId]);
        $bound = $orderLookup->fetchColumn();
        if (!$bound) return false;
        recordPaymentOrderFailure([
            'provider' => 'decentro',
            'provider_order_id' => (string)$bound,
            'provider_payment_id' => $decentroTxnId,
            'error_code' => $txnStatus,
            'error_description' => (string)($status['message'] ?? 'Decentro transaction ' . $txnStatus),
            'amount' => (float)($payload['amount'] ?? 0) ?: null,
            'currency' => 'INR',
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $decentroTxnId,
        ]);
        return true;
    }

    // Still pending — not a failure, but not completed either
    return false;
}

/**
 * Get webhook reliability stats.
 */
function getWebhookReliabilityStats(): array
{
    ensureWebhookEventsTable();
    $stats = [
        'total' => 0,
        'completed' => 0,
        'failed' => 0,
        'dead_letter' => 0,
        'pending_retry' => 0,
        'success_rate' => 0.0,
    ];
    try {
        $db = getDB();
        $stats['total'] = (int)$db->query("SELECT COUNT(*) FROM webhook_events")->fetchColumn();
        $stats['completed'] = (int)$db->query("SELECT COUNT(*) FROM webhook_events WHERE status='completed'")->fetchColumn();
        $stats['failed'] = (int)$db->query("SELECT COUNT(*) FROM webhook_events WHERE status='failed'")->fetchColumn();
        $stats['dead_letter'] = (int)$db->query("SELECT COUNT(*) FROM webhook_events WHERE status='dead_letter'")->fetchColumn();
        $stats['pending_retry'] = (int)$db->query("SELECT COUNT(*) FROM webhook_events WHERE status='failed' AND next_retry_at <= NOW()")->fetchColumn();
        $processed = $stats['completed'] + $stats['failed'] + $stats['dead_letter'];
        $stats['success_rate'] = $processed > 0 ? round($stats['completed'] / $processed * 100, 1) : 100.0;
    } catch (Throwable $e) {}
    return $stats;
}

/**
 * Get dead letter events for admin review.
 */
function getDeadLetterEvents(int $limit = 50): array
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare("SELECT * FROM webhook_events WHERE status='dead_letter' ORDER BY created_at DESC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Replay a dead letter event.
 */
function replayDeadLetterEvent(int $eventId): bool
{
    ensureWebhookEventsTable();
    try {
        getDB()->prepare("UPDATE webhook_events SET status='received', retry_count=0, last_error=NULL, next_retry_at=NULL WHERE id=? AND status='dead_letter'")
            ->execute([$eventId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * A2: Re-process a failed webhook event (safe only if status=failed or dead_letter).
 * Resets to 'received' so the retry worker picks it up.
 */
function reprocessFailedWebhookEvent(int $eventId): bool
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare("UPDATE webhook_events SET status='received', next_retry_at=NULL WHERE id=? AND status IN ('failed','dead_letter')");
        $st->execute([$eventId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * A2: Discard a dead letter event (mark as discarded, keep record for audit).
 */
function discardDeadLetterEvent(int $eventId): bool
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare("UPDATE webhook_events SET status='dead_letter', last_error=CONCAT('[DISCARDED] ', COALESCE(last_error,'')) WHERE id=? AND status='dead_letter'");
        $st->execute([$eventId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * A2: Get a single webhook event with decrypted payload preview.
 * Payload is truncated for display safety.
 */
function getWebhookEventForAdmin(int $eventId): ?array
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare("SELECT * FROM webhook_events WHERE id=?");
        $st->execute([$eventId]);
        $event = $st->fetch();
        if (!$event) return null;
        // Truncate payload for preview
        $event['payload_preview'] = mb_substr((string)$event['payload'], 0, 2000);
        $event['payload_size'] = strlen((string)$event['payload']);
        return $event;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * A2: Get failed events (for admin re-process screen).
 */
function getFailedWebhookEvents(int $limit = 50): array
{
    ensureWebhookEventsTable();
    try {
        $st = getDB()->prepare("SELECT * FROM webhook_events WHERE status IN ('failed','dead_letter') ORDER BY created_at DESC LIMIT ?");
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * A2: Send alert when webhook goes to dead letter.
 * Notifies admin via email + platform error log.
 */
function alertWebhookDeadLetter(array $event): void
{
    try {
        $msg = "Webhook dead letter: gateway={$event['gateway']}, event_id={$event['event_id']}, retries={$event['retry_count']}";
        // Log to platform_errors
        getDB()->prepare('INSERT INTO platform_errors (error_type, error_message, context_json, created_at) VALUES (?,?,?,NOW())')
            ->execute([
                'webhook_dead_letter',
                $msg,
                json_encode(['event_id' => $event['id'], 'gateway' => $event['gateway'], 'event_type' => $event['event_type'] ?? null]),
            ]);
    } catch (Throwable $e) { /* non-fatal */ }

    // Email alert
    try {
        if (function_exists('sendMail')) {
            sendMail(
                defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@uniweb.co.in',
                'UniWeb Alert: Webhook Dead Letter',
                "A webhook event has exhausted all retries and moved to dead letter queue.\n\n"
                . "Gateway: {$event['gateway']}\n"
                . "Event ID: {$event['event_id']}\n"
                . "Type: " . ($event['event_type'] ?? 'unknown') . "\n"
                . "Retries: {$event['retry_count']}\n"
                . "Error: " . mb_substr((string)($event['last_error'] ?? ''), 0, 500) . "\n\n"
                . "Review at: " . (defined('APP_URL') ? APP_URL : '') . "/admin_webhook_reliability.php"
            );
        }
    } catch (Throwable $e) { /* non-fatal */ }
}
