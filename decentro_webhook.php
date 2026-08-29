<?php
require_once __DIR__ . '/config.php';
if (!function_exists('recordWebhookEvent')) {
    require_once __DIR__ . '/includes/webhook_reliability.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('decentro');
}

$raw = pgWebhookReadRawBody();

$verify = pgWebhookVerifyPartner('decentro', $raw);
if (!$verify['ok']) {
    if (function_exists('financialTablesReady') && financialTablesReady()) {
        registerGatewayEvent('decentro', $_SERVER['HTTP_X_DECENTRO_EVENT_ID'] ?? '', 'unknown', $raw, false);
    }
    logPgWebhookVerifyFailure('decentro', (string)$verify['reason'], null, null, null, [
        'has_signature' => (string)($_SERVER['HTTP_X_DECENTRO_SIGNATURE'] ?? $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '') !== '',
        'event_id' => substr((string)($_SERVER['HTTP_X_DECENTRO_EVENT_ID'] ?? ''), 0, 64),
        'body_bytes' => strlen($raw),
        'scheme' => (string)$verify['scheme'],
    ]);
    pgWebhookRejectJson('decentro', (string)$verify['reason'], (int)$verify['http_code']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    logPgWebhook('decentro', 'invalid_json', null, null, null, $raw);
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

$decentroTxnId = (string)($payload['decentro_txn_id'] ?? '');
$eventType = (string)($payload['event_type'] ?? $payload['type'] ?? 'transaction_status');
$txnStatus = strtolower((string)($payload['transaction_status'] ?? $payload['data']['transaction_status'] ?? ''));
$referenceId = (string)($payload['reference_id'] ?? '');
$amount = (float)($payload['amount'] ?? $payload['data']['amount'] ?? 0);

$eventId = (string)($_SERVER['HTTP_X_DECENTRO_EVENT_ID'] ?? ('decentro:' . $decentroTxnId));
$eventReference = $decentroTxnId ?: $referenceId;

$gatewayEvent = registerGatewayEvent('decentro', $eventId, $eventType, $raw, true);
if (!empty($gatewayEvent['duplicate'])) {
    jsonResponse(['ok' => true, 'duplicate' => true]);
}
$webhookEv = recordWebhookEvent($eventId, 'decentro', $eventType, $raw, $signature);
if (!empty($webhookEv['is_duplicate'])) {
    jsonResponse(['ok' => true, 'duplicate' => true]);
}
markWebhookProcessing((int)$webhookEv['id']);
logPgWebhook('decentro', 'received', $eventType, $eventReference, null, $raw);

if (!function_exists('webhookFastAck')) {
    require_once __DIR__ . '/includes/webhook_queue.php';
}
webhookFastAck(['ok' => true, 'received' => true]);

// G5: Handle mandate/recurring events from Decentro
$mandateEventTypes = ['mandate_registered', 'mandate_authorised', 'mandate_cancelled', 'mandate_failed', 'mandate_revoked', 'autopay_debit_success', 'autopay_debit_failed', 'enach_debit_success', 'enach_debit_failed'];
if (in_array($eventType, $mandateEventTypes, true) || str_contains($eventType, 'mandate') || str_contains($eventType, 'autopay_debit') || str_contains($eventType, 'enach_debit')) {
    try {
        $mandateId = (string)($payload['mandate_id'] ?? $payload['data']['mandate_id'] ?? '');
        $mandateStatus = strtolower((string)($payload['mandate_status'] ?? $payload['data']['mandate_status'] ?? $txnStatus));
        $debitRef = (string)($payload['reference_id'] ?? $payload['data']['reference_id'] ?? '');

        if ($mandateId !== '' && function_exists('updateMandateStatusFromWebhook')) {
            updateMandateStatusFromWebhook('decentro', $mandateId, $mandateStatus, $payload);
        }

        // G5: Handle debit success/fail — update mandate_debits
        if (str_contains($eventType, 'debit_success') && $debitRef !== '') {
            try {
                $db = getDB();
                $db->prepare("UPDATE mandate_debits SET status='success', processed_at=NOW() WHERE mandate_ref=? OR idempotency_key=?")
                    ->execute([$debitRef, $debitRef]);
            } catch (Throwable $e) {}
        } elseif (str_contains($eventType, 'debit_failed') && $debitRef !== '') {
            $failReason = (string)($payload['message'] ?? $payload['data']['error'] ?? 'Decentro debit failed');
            $mappedReason = function_exists('mapMandateFailureReason') ? mapMandateFailureReason($failReason) : $failReason;
            try {
                $db = getDB();
                $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason=?, mapped_reason=? WHERE mandate_ref=? OR idempotency_key=?")
                    ->execute([$mappedReason, $mappedReason, $debitRef, $debitRef]);
            } catch (Throwable $e) {}
        }

        setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
        markWebhookCompleted((int)$webhookEv['id']);
        logPgWebhook('decentro', 'mandate_event', $eventType, $mandateId ?: $debitRef, null, json_encode(['status' => $mandateStatus]));
        jsonResponse(['ok' => true, 'mandate_event' => true]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        logPlatformError('error', 'Decentro mandate webhook failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Mandate processing failed'], 422);
    }
}

// Server-side verification: fetch transaction status from Decentro API
if ($decentroTxnId !== '') {
    try {
        $serverStatus = fetchDecentroTransactionStatus($decentroTxnId);
        if (!$serverStatus) {
            throw new RuntimeException('Decentro server verification returned no response.');
        }

        $serverTxnStatus = strtolower((string)($serverStatus['transaction_status']
            ?? $serverStatus['data']['transaction_status']
            ?? $txnStatus));

        $serverAmount = (float)($serverStatus['amount']
            ?? $serverStatus['data']['amount']
            ?? $amount);

        if ($serverTxnStatus === 'success' || $serverTxnStatus === 'completed') {
            // Find bound payment order
            $orderLookup = getDB()->prepare(
                "SELECT provider_order_id FROM payment_orders
                 WHERE provider='decentro' AND (provider_order_id=? OR order_ref=?)
                 LIMIT 1"
            );
            $orderLookup->execute([$referenceId, $referenceId]);
            $bound = $orderLookup->fetchColumn();

            if ($bound) {
                $result = captureVerifiedPaymentOrder([
                    'provider' => 'decentro',
                    'provider_order_id' => (string)$bound,
                    'provider_payment_id' => $decentroTxnId,
                    'amount' => $serverAmount > 0 ? $serverAmount : $amount,
                    'currency' => 'INR',
                    'captured' => true,
                    'signature_verified' => true,
                    'provider_verified' => true,
                    'reference' => $decentroTxnId,
                ]);
                setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) ? 'duplicate' : 'processed');
                markWebhookCompleted((int)$webhookEv['id']);
                logPgWebhook('decentro', 'processed', $eventType, $decentroTxnId, null, json_encode(['transaction_id' => $result['transaction_id'] ?? null]));
                jsonResponse(['ok' => true, 'result' => $result]);
            } else {
                setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
                markWebhookCompleted((int)$webhookEv['id']);
                logPgWebhook('decentro', 'no_bound_order', $eventType, $decentroTxnId, null, '');
                jsonResponse(['ok' => true, 'ignored' => true, 'reason' => 'no_bound_order']);
            }
        } elseif (in_array($serverTxnStatus, ['failed', 'expired', 'cancelled'], true)) {
            $orderLookup = getDB()->prepare(
                "SELECT provider_order_id FROM payment_orders
                 WHERE provider='decentro' AND (provider_order_id=? OR order_ref=?)
                 LIMIT 1"
            );
            $orderLookup->execute([$referenceId, $referenceId]);
            $bound = $orderLookup->fetchColumn();

            if ($bound) {
                $result = recordPaymentOrderFailure([
                    'provider' => 'decentro',
                    'provider_order_id' => (string)$bound,
                    'provider_payment_id' => $decentroTxnId,
                    'error_code' => $serverTxnStatus,
                    'error_description' => (string)($serverStatus['message'] ?? 'Decentro transaction ' . $serverTxnStatus),
                    'amount' => $serverAmount > 0 ? $serverAmount : ($amount > 0 ? $amount : null),
                    'currency' => 'INR',
                    'signature_verified' => true,
                    'provider_verified' => true,
                    'reference' => $decentroTxnId,
                ]);
                setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) || !empty($result['ignored']) ? 'duplicate' : 'processed');
                markWebhookCompleted((int)$webhookEv['id']);
                logPgWebhook('decentro', 'processed_failure', $eventType, $decentroTxnId, null, json_encode($result));
                jsonResponse(['ok' => true, 'result' => $result]);
            } else {
                setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
                markWebhookCompleted((int)$webhookEv['id']);
                logPgWebhook('decentro', 'ignored_failure', $eventType, $decentroTxnId, null, '{"reason":"no_bound_order"}');
                jsonResponse(['ok' => true, 'ignored' => true, 'reason' => 'no_bound_order']);
            }
        } else {
            // Still pending — mark as completed (webhook received, transaction not yet final)
            setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
            markWebhookCompleted((int)$webhookEv['id']);
            logPgWebhook('decentro', 'pending', $eventType, $decentroTxnId, null, json_encode(['status' => $serverTxnStatus]));
            jsonResponse(['ok' => true, 'status' => $serverTxnStatus]);
        }
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        logPgWebhook('decentro', 'failed', $eventType, $decentroTxnId, null, json_encode(['error' => $e->getMessage()]));
        logPlatformError('error', 'Decentro webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Processing failed'], 422);
    }
}

// No decentro_txn_id — can't process
setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
markWebhookCompleted((int)$webhookEv['id']);
logPgWebhook('decentro', 'ignored', $eventType, null, null, '{"reason":"no_txn_id"}');
jsonResponse(['ok' => true, 'ignored' => true, 'reason' => 'no_txn_id']);