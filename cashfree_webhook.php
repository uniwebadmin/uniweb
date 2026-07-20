<?php
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('cashfree');
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

if (!verifyCashfreeWebhookSignature($raw, $signature, $timestamp)) {
    if (financialTablesReady()) {
        registerGatewayEvent('cashfree', $_SERVER['HTTP_X_WEBHOOK_ID'] ?? '', 'unknown', $raw, false);
    }
    logPgWebhook('cashfree', 'invalid_signature', null, null, null, '');
    jsonResponse(['error' => 'Invalid signature or stale timestamp'], 401);
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

logPgWebhook('cashfree', 'received', $event, $paymentId ?: $orderId, null, '');

if ($orderStatus !== 'PAID' || $orderId === '') {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
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
    $result = captureVerifiedPaymentOrder([
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
    setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) ? 'duplicate' : 'processed');
    logPgWebhook('cashfree', 'processed', $event, $verifiedPaymentId, null, json_encode(['transaction_id' => $result['transaction_id'] ?? null]));
} catch (Throwable $e) {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
    logPgWebhook('cashfree', 'failed', $event, $paymentId ?: $orderId, null, json_encode(['error' => $e->getMessage()]));
    logPlatformError('error', 'Cashfree webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
    jsonResponse(['error' => 'Processing failed'], 422);
}

jsonResponse(['ok' => true, 'result' => $result]);
