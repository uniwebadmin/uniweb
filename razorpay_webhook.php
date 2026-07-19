<?php
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('razorpay');
}

$raw = file_get_contents('php://input') ?: '';$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!verifyRazorpayWebhookSignature($raw, $signature)) {
    logPgWebhook('razorpay', 'invalid_signature', null, null, null, $raw);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    logPgWebhook('razorpay', 'invalid_json', null, null, null, $raw);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$event = (string)($payload['event'] ?? '');
$entity = $payload['payload']['payment']['entity'] ?? $payload['payload']['order']['entity'] ?? [];
$paymentId = (string)($entity['id'] ?? '');
$notes = $entity['notes'] ?? [];
$linkId = (string)($notes['link_id'] ?? '');
$amount = isset($entity['amount']) ? ((float)$entity['amount'] / 100) : 0.0;

logPgWebhook('razorpay', 'received', $event, $paymentId, $linkId, $raw);

$successEvents = ['payment.captured', 'order.paid'];
if (!in_array($event, $successEvents, true) || $linkId === '' || $paymentId === '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$method = 'razorpay';
$result = fulfillGatewayPayment('razorpay', $linkId, $paymentId, $method, $amount);
logPgWebhook('razorpay', $result['ok'] ? 'processed' : 'failed', $event, $paymentId, $linkId, json_encode($result));

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $result]);
