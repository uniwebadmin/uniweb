<?php
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST) && empty($_GET['status'])) {
    pgWebhookHealthResponse('payu');
}

$post = array_merge($_GET, $_POST);$raw = json_encode($post);
$linkId = (string)($post['udf1'] ?? '');
$status = strtolower((string)($post['status'] ?? ''));
$reference = (string)($post['mihpayid'] ?? $post['txnid'] ?? '');
$amount = (float)($post['amount'] ?? 0);

logPgWebhook('payu', 'received', $status, $reference, $linkId, $raw);

if (!verifyPayUResponseHash($post)) {
    logPgWebhook('payu', 'invalid_hash', $status, $reference, $linkId, $raw);
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid hash']);
    exit;
}

if (!in_array($status, ['success', 'successful'], true) || $linkId === '' || $reference === '') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$result = fulfillGatewayPayment('payu', $linkId, $reference, 'payu', $amount);
logPgWebhook('payu', $result['ok'] ? 'processed' : 'failed', $status, $reference, $linkId, json_encode($result));

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => $result]);
