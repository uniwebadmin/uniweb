<?php
require_once __DIR__ . '/config.php';

$secret = getSetting('axis_webhook_secret', '');
$raw = file_get_contents('php://input');
$headers = function_exists('getallheaders') ? getallheaders() : [];

axisLogApi('/axis_webhook.php', $_SERVER['REQUEST_METHOD'] ?? 'POST', $raw, json_encode($headers), 200, null, 'webhook_in');

$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$vaNumber = $data['virtualAccountNumber'] ?? $data['vaNumber'] ?? $data['vanNumber'] ?? ($data['Data']['virtualAccountNumber'] ?? '');
$amount = (float)($data['amount'] ?? $data['txnAmount'] ?? ($data['Data']['amount'] ?? 0));
$utr = $data['utr'] ?? $data['bankRefNo'] ?? $data['txnRefNo'] ?? ($data['Data']['utr'] ?? '');

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['status' => 'received', 'app' => APP_NAME]);

if (!$vaNumber || $amount <= 0) {
    exit;
}

$db = getDB();
$m = $db->prepare('SELECT * FROM merchants WHERE axis_va_number = ? LIMIT 1');
$m->execute([$vaNumber]);
$merch = $m->fetch();
if (!$merch) {
    axisLogApi('webhook', 'POST', $raw, 'merchant not found for VA ' . $vaNumber, 404, null, 'webhook_skip');
    exit;
}

$dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
$dup->execute([$utr ?: ('AXIS_' . $vaNumber . '_' . $amount)]);
if ($dup->fetch()) exit;

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
$txnId = createTransactionFromPayment($link, 'axis_va', 'success', $utr ?: ('AXIS' . time()), merchantAccountMode($merch) === 'test');
createNotification((int)$merch['id'], 'Axis VA Payment', formatMoney($amount) . ' received in Virtual Account.');

axisLogApi('webhook', 'POST', $raw, 'credited txn ' . $txnId, 200, (int)$merch['id'], 'webhook_ok');
