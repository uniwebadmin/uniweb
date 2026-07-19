<?php
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('cashfree');
}

$raw = file_get_contents('php://input') ?: '';$signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
$timestamp = $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '';

if (!verifyCashfreeWebhookSignature($raw, $signature, $timestamp)) {
    logPgWebhook('cashfree', 'invalid_signature', null, null, null, $raw);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    logPgWebhook('cashfree', 'invalid_json', null, null, null, $raw);
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$event = (string)($payload['type'] ?? $payload['event'] ?? '');
$data = $payload['data'] ?? $payload;
$order = $data['order'] ?? $data;
$orderId = (string)($order['order_id'] ?? $data['order_id'] ?? '');
$orderStatus = strtoupper((string)($order['order_status'] ?? $data['order_status'] ?? ''));
$linkId = (string)($order['order_tags']['link_id'] ?? $data['order_tags']['link_id'] ?? parseCashfreeLinkIdFromOrder($orderId));
$amount = (float)($order['order_amount'] ?? $data['order_amount'] ?? 0);

logPgWebhook('cashfree', 'received', $event, $orderId, $linkId, $raw);

if ($orderStatus !== 'PAID' || $orderId === '' || $linkId === '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$method = 'cashfree';
$result = fulfillGatewayPayment('cashfree', $linkId, $orderId, $method, $amount);
logPgWebhook('cashfree', $result['ok'] ? 'processed' : 'failed', $event, $orderId, $linkId, json_encode($result));

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $result]);
