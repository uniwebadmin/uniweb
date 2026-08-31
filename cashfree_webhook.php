<?php
require_once __DIR__ . '/config.php';
if (!function_exists('applyPartnerPaymentReconcile') && is_file(__DIR__ . '/includes/payment_reconcile.php')) {
    require_once __DIR__ . '/includes/payment_reconcile.php';
}
if (!function_exists('recordWebhookEvent')) {
    require_once __DIR__ . '/includes/webhook_reliability.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('cashfree');
}

$raw = pgWebhookReadRawBody();
$verify = pgWebhookVerifyPartner('cashfree', $raw);
if (!$verify['ok']) {
    if (financialTablesReady()) {
        registerGatewayEvent('cashfree', $_SERVER['HTTP_X_WEBHOOK_ID'] ?? '', 'unknown', $raw, false);
    }
    logPgWebhookVerifyFailure('cashfree', (string)$verify['reason'], null, null, null, [
        'has_signature' => (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '') !== '',
        'has_timestamp' => (string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '') !== '',
        'event_id' => substr((string)($_SERVER['HTTP_X_WEBHOOK_ID'] ?? ''), 0, 64),
        'body_bytes' => strlen($raw),
        'scheme' => (string)$verify['scheme'],
    ]);
    pgWebhookRejectJson('cashfree', (string)$verify['reason'], (int)$verify['http_code']);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    logPgWebhook('cashfree', 'invalid_json', null, null, null, '');
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

$event = (string)($payload['type'] ?? $payload['event'] ?? '');
$data = $payload['data'] ?? $payload;
$order = $data['order'] ?? $data;
$payment = $data['payment'] ?? [];
$orderId = (string)($order['order_id'] ?? $data['order_id'] ?? '');
$orderStatus = strtoupper((string)($order['order_status'] ?? $data['order_status'] ?? ''));
$paymentId = (string)($payment['cf_payment_id'] ?? $data['cf_payment_id'] ?? '');
$eventId = (string)($_SERVER['HTTP_X_WEBHOOK_ID'] ?? ($event . ':' . ($paymentId ?: $orderId)));
$gatewayEvent = registerGatewayEvent('cashfree', $eventId, $event, $raw, true);
if (!empty($gatewayEvent['duplicate'])) {
    jsonResponse(['ok' => true, 'duplicate' => true]);
}
$webhookEv = recordWebhookEvent($eventId, 'cashfree', $event, $raw, (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? ''));
if (!empty($webhookEv['is_duplicate'])) {
    jsonResponse(['ok' => true, 'duplicate' => true]);
}
markWebhookProcessing((int)$webhookEv['id']);
logPgWebhook('cashfree', 'received', $event, $paymentId ?: $orderId, null, '');
if (!function_exists('webhookFastAck')) {
    require_once __DIR__ . '/includes/webhook_queue.php';
}
webhookFastAck(['ok' => true, 'received' => true]);

$refundEvents = ['REFUND_SUCCESS_WEBHOOK', 'REFUND_FAILED_WEBHOOK', 'REFUND_STATUS_WEBHOOK'];
$refundBlock = $data['refund'] ?? $data;
$refundId = (string)($refundBlock['refund_id'] ?? $refundBlock['cf_refund_id'] ?? $data['refund_id'] ?? '');
$refundStatus = strtoupper((string)($refundBlock['refund_status'] ?? $data['refund_status'] ?? ''));
if (in_array(strtoupper($event), $refundEvents, true) || $refundId !== '') {
    try {
        if (!function_exists('applyPartnerRefundWebhookEvent') && is_file(__DIR__ . '/includes/refund_webhooks.php')) {
            require_once __DIR__ . '/includes/refund_webhooks.php';
        }
        $eventTime = (string)($payload['event_time'] ?? $refundBlock['processed_at'] ?? '');
        if (function_exists('pgWebhookEventTooOld') && pgWebhookEventTooOld($eventTime)) {
            setGatewayEventStatus((int)$gatewayEvent['id'], 'ignored', null, 'event_too_old');
            markWebhookCompleted((int)$webhookEv['id']);
            jsonResponse(['ok' => true, 'ignored' => true, 'reason' => 'event_too_old']);
        }
        if ($refundId === '') {
            throw new RuntimeException('Cashfree refund webhook missing refund_id.');
        }
        $terminal = in_array($refundStatus, ['SUCCESS', 'PROCESSED'], true) ? 'processed'
            : (in_array($refundStatus, ['FAILED', 'CANCELLED'], true) ? 'failed' : '');
        $result = applyPartnerRefundWebhookEvent('cashfree', [
            'provider_refund_id' => $refundId,
            'event_type' => $event,
            'terminal' => $terminal,
            'failure_reason' => (string)($refundBlock['status_description'] ?? $refundBlock['refund_message'] ?? ''),
        ]);
        if (empty($result['ok'])) {
            throw new RuntimeException((string)($result['error'] ?? 'Refund apply failed.'));
        }
        setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
        markWebhookCompleted((int)$webhookEv['id']);
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        logPlatformError('error', 'Cashfree refund webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Refund processing failed'], 422);
    }
}

$splitEvents = ['VENDOR_SPLIT_SETTLEMENT', 'EASY_SPLIT_SETTLEMENT', 'SPLIT_SETTLEMENT', 'VENDOR_SETTLEMENT'];
if (in_array(strtoupper($event), $splitEvents, true) || str_contains(strtoupper($event), 'SPLIT')) {
    try {
        if (!function_exists('updatePartnerTransferFromWebhook') && is_file(__DIR__ . '/includes/route_split_partner_api.php')) {
            require_once __DIR__ . '/includes/route_split_partner_api.php';
        }
        $splitId = (string)($data['split_id'] ?? $data['cf_split_id'] ?? $orderId);
        $splitStatus = strtolower((string)($data['status'] ?? $data['settlement_status'] ?? 'processed'));
        if ($splitId !== '' && function_exists('updatePartnerTransferFromWebhook')) {
            updatePartnerTransferFromWebhook('cashfree', $splitId, $splitStatus);
        }
        setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
        markWebhookCompleted((int)$webhookEv['id']);
        logPgWebhook('cashfree', 'split_event', $event, $splitId, null, json_encode(['status' => $splitStatus]));
        jsonResponse(['ok' => true, 'split_event' => true]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        jsonResponse(['error' => 'Split processing failed'], 422);
    }
}

$failureEvents = ['PAYMENT_FAILED_WEBHOOK', 'PAYMENT_FAILED', 'ORDER_FAILED'];
$paymentStatus = strtoupper((string)($payment['payment_status'] ?? $data['payment_status'] ?? ''));
$isFailure = in_array(strtoupper($event), $failureEvents, true)
    || in_array($orderStatus, ['FAILED', 'CANCELLED', 'EXPIRED'], true)
    || in_array($paymentStatus, ['FAILED', 'USER_DROPPED', 'CANCELLED', 'EXPIRED'], true);

if ($isFailure && $orderId !== '') {
    try {
        $result = applyPartnerPaymentReconcile([
            'provider' => 'cashfree',
            'provider_order_id' => $orderId,
            'provider_payment_id' => $paymentId !== '' ? $paymentId : ('fail:' . $orderId),
            'error_code' => (string)($payment['error_code']
                ?? (is_array($payment['payment_gateway_details'] ?? null) ? ($payment['payment_gateway_details']['error_code'] ?? null) : null)
                ?? $data['error_code']
                ?? ($orderStatus !== '' ? $orderStatus : $paymentStatus)),
            'error_description' => (string)($payment['payment_message'] ?? $payment['error_details'] ?? $data['payment_message'] ?? $order['order_note'] ?? ''),
            'amount' => (float)($payment['payment_amount'] ?? $order['order_amount'] ?? $data['order_amount'] ?? 0) ?: null,
            'currency' => (string)($payment['payment_currency'] ?? $order['order_currency'] ?? 'INR'),
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $paymentId ?: $orderId,
            'reconcile_source' => 'webhook',
            'terminal' => 'failed',
        ]);
        setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) || !empty($result['ignored']) ? 'duplicate' : 'processed');
        markWebhookCompleted((int)$webhookEv['id']);
        logPgWebhook('cashfree', 'processed_failure', $event, $paymentId ?: $orderId, null, json_encode($result));
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        logPgWebhook('cashfree', 'failed', $event, $paymentId ?: $orderId, null, json_encode(['error' => $e->getMessage()]));
        logPlatformError('error', 'Cashfree payment failure webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Failure processing failed'], 422);
    }
}

if ($orderStatus !== 'PAID' || $orderId === '') {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
    markWebhookCompleted((int)$webhookEv['id']);
    jsonResponse(['ok' => true, 'ignored' => true]);
}

$providerOrder = fetchCashfreeOrder($orderId);
$providerPayments = fetchCashfreeOrderPayments($orderId);
$capturedPayment = null;
foreach ($providerPayments as $candidate) {
    if (strtoupper((string)($candidate['payment_status'] ?? '')) !== 'SUCCESS') {
        continue;
    }
    if ($paymentId === '' || (string)($candidate['cf_payment_id'] ?? '') === $paymentId) {
        $capturedPayment = $candidate;
        break;
    }
}
try {
    if (!$providerOrder
        || (string)($providerOrder['order_id'] ?? '') !== $orderId
        || strtoupper((string)($providerOrder['order_status'] ?? '')) !== 'PAID'
        || !$capturedPayment
    ) {
        throw new RuntimeException('Cashfree server verification did not return a successful payment.');
    }
    $verifiedPaymentId = (string)($capturedPayment['cf_payment_id'] ?? '');
    if ($verifiedPaymentId === '') {
        throw new RuntimeException('Cashfree payment ID is missing.');
    }
    $result = applyPartnerPaymentReconcile([
        'provider' => 'cashfree',
        'provider_order_id' => $orderId,
        'provider_payment_id' => $verifiedPaymentId,
        'amount' => (float)($capturedPayment['payment_amount'] ?? 0),
        'currency' => (string)($capturedPayment['payment_currency'] ?? $providerOrder['order_currency'] ?? ''),
        'captured' => true,
        'signature_verified' => true,
        'provider_verified' => true,
        'reference' => $verifiedPaymentId,
        'reconcile_source' => 'webhook',
    ]);
    setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) ? 'duplicate' : 'processed');
    markWebhookCompleted((int)$webhookEv['id']);
    logPgWebhook('cashfree', 'processed', $event, $verifiedPaymentId, null, json_encode(['transaction_id' => $result['transaction_id'] ?? null]));
} catch (Throwable $e) {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
    markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
    logPgWebhook('cashfree', 'failed', $event, $paymentId ?: $orderId, null, json_encode(['error' => $e->getMessage()]));
    logPlatformError('error', 'Cashfree webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
    jsonResponse(['error' => 'Processing failed'], 422);
}

jsonResponse(['ok' => true, 'result' => $result]);
