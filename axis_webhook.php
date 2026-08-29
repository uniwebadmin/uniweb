<?php
require_once __DIR__ . '/config.php';
if (!function_exists('pgWebhookVerifyPartner') && is_file(__DIR__ . '/includes/pg_webhooks.php')) {
    require_once __DIR__ . '/includes/pg_webhooks.php';
}
if (!function_exists('axisPartnerSetting') && is_file(__DIR__ . '/includes/partner_control.php')) {
    require_once __DIR__ . '/includes/partner_control.php';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$raw = pgWebhookReadRawBody();
$verify = pgWebhookVerifyPartner('axis', $raw);
if (!$verify['ok']) {
    axisLogApi('/axis_webhook.php', 'POST', '', 'webhook signature failed', (int)$verify['http_code'], null, 'webhook_rejected');
    pgWebhookRejectJson('axis', (string)$verify['reason'], (int)$verify['http_code']);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    jsonResponse(['error' => 'Invalid JSON payload'], 400);
}

$vaNumber = $data['virtualAccountNumber'] ?? $data['vaNumber'] ?? $data['vanNumber'] ?? ($data['Data']['virtualAccountNumber'] ?? '');
$amount = (float)($data['amount'] ?? $data['txnAmount'] ?? ($data['Data']['amount'] ?? 0));
$utr = trim((string)($data['utr'] ?? $data['bankRefNo'] ?? $data['txnRefNo'] ?? ($data['Data']['utr'] ?? '')));

if (!$vaNumber || $amount <= 0 || $utr === '') {
    jsonResponse(['error' => 'Missing virtual account, amount, or bank reference'], 400);
}

$db = getDB();
if (!function_exists('findMerchantByVirtualAccountNumber')) {
    require_once __DIR__ . '/includes/va_manager.php';
}
$merch = findMerchantByVirtualAccountNumber($vaNumber);
if (!$merch) {
    axisLogApi('webhook', 'POST', '', 'merchant not found for VA', 404, null, 'webhook_skip');
    jsonResponse(['error' => 'Virtual account not found'], 404);
}

$eventId = 'axis:' . $utr;
if (function_exists('registerGatewayEvent') && financialTablesReady()) {
    $gatewayEvent = registerGatewayEvent('axis', $eventId, 'va_credit', $raw, true);
    if (!empty($gatewayEvent['duplicate'])) {
        jsonResponse(['status' => 'duplicate', 'app' => APP_NAME]);
    }
}

$dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
$dup->execute([$utr]);
if ($dup->fetch()) {
    jsonResponse(['status' => 'duplicate', 'app' => APP_NAME]);
}

if (!function_exists('webhookFastAck')) {
    require_once __DIR__ . '/includes/webhook_queue.php';
}
webhookFastAck(['status' => 'received', 'app' => APP_NAME]);

$link = [
    'merchant_id' => (int)$merch['id'],
    'amount' => $amount,
    'description' => 'Axis VA Collection',
    'commission_rate' => $merch['commission_rate'],
    'collection_mode' => 'axis_va',
    'account_mode' => $merch['account_mode'] ?? 'test',
    'kyc_status' => $merch['kyc_status'] ?? '',
    'id' => 0,
];
$txnId = createTransactionFromPayment($link, 'axis_va', 'success', $utr, merchantAccountMode($merch) === 'test');
createNotification((int)$merch['id'], 'Axis VA Payment', formatMoney($amount) . ' received in Virtual Account.');
if (!empty($merch['va_row_id'])) {
    recordVirtualAccountUsage((int)$merch['va_row_id']);
}

axisLogApi('webhook', 'POST', '', 'credited txn ' . $txnId, 200, (int)$merch['id'], 'webhook_ok');
jsonResponse(['status' => 'processed', 'transaction_id' => $txnId, 'app' => APP_NAME]);
