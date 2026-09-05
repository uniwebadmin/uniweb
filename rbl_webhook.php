<?php
/**
 * RBL Bank sandbox/live VA collection webhook.
 * Register this URL with RBL when they provide callback config.
 * Keys/signatures: Partner Registry → RBL → webhook secret (pg webhook verify).
 */
require_once __DIR__ . '/config.php';
if (!function_exists('pgWebhookVerifyPartner') && is_file(__DIR__ . '/includes/pg_webhooks.php')) {
    require_once __DIR__ . '/includes/pg_webhooks.php';
}
if (!function_exists('rblPartnerCredential') && is_file(__DIR__ . '/includes/rbl.php')) {
    require_once __DIR__ . '/includes/rbl.php';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (function_exists('pgWebhookHealthResponse')) {
        pgWebhookHealthResponse('rbl');
    }
    echo json_encode(['ok' => true, 'partner' => 'rbl', 'mode' => 'sandbox-first']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$raw = function_exists('pgWebhookReadRawBody') ? pgWebhookReadRawBody() : (string)file_get_contents('php://input');
$verify = function_exists('pgWebhookVerifyPartner')
    ? pgWebhookVerifyPartner('rbl', $raw)
    : ['ok' => true, 'http_code' => 200, 'reason' => ''];
if (empty($verify['ok'])) {
    if (function_exists('pgWebhookRejectJson')) {
        pgWebhookRejectJson('rbl', (string)($verify['reason'] ?? 'verify_failed'), (int)($verify['http_code'] ?? 401));
    }
    jsonResponse(['error' => 'Webhook verification failed'], 401);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    jsonResponse(['error' => 'Invalid JSON payload'], 400);
}

$nodes = [$data];
foreach (['Body', 'Header', 'Data', 'create_VA'] as $wrap) {
    if (!empty($data[$wrap]) && is_array($data[$wrap])) {
        $nodes[] = $data[$wrap];
        if (!empty($data[$wrap]['Body']) && is_array($data[$wrap]['Body'])) {
            $nodes[] = $data[$wrap]['Body'];
        }
    }
}
$vaNumber = '';
$amount = 0.0;
$utr = '';
foreach ($nodes as $node) {
    if ($vaNumber === '') {
        foreach (['Full_VA_Number', 'VA_Number', 'virtualAccountNumber', 'vaNumber', 'vanNumber'] as $k) {
            if (!empty($node[$k])) {
                $vaNumber = trim((string)$node[$k]);
                break;
            }
        }
    }
    if ($amount <= 0) {
        foreach (['amount', 'txnAmount', 'Amount'] as $k) {
            if (isset($node[$k]) && (float)$node[$k] > 0) {
                $amount = (float)$node[$k];
                break;
            }
        }
    }
    if ($utr === '') {
        foreach (['utr', 'UTR', 'bankRefNo', 'txnRefNo', 'TranID'] as $k) {
            if (!empty($node[$k])) {
                $utr = trim((string)$node[$k]);
                break;
            }
        }
    }
}

if ($vaNumber === '' || $amount <= 0 || $utr === '') {
    jsonResponse(['error' => 'Missing virtual account, amount, or bank reference'], 400);
}

$db = getDB();
if (!function_exists('findMerchantByVirtualAccountNumber')) {
    require_once __DIR__ . '/includes/va_manager.php';
}
$merch = findMerchantByVirtualAccountNumber($vaNumber);
if (!$merch) {
    jsonResponse(['error' => 'Virtual account not found'], 404);
}

$eventId = 'rbl:' . $utr;
$gatewayEvent = null;
if (function_exists('registerGatewayEvent') && function_exists('financialTablesReady') && financialTablesReady()) {
    $gatewayEvent = registerGatewayEvent('rbl', $eventId, 'va_credit', $raw, true);
    if (!empty($gatewayEvent['duplicate'])) {
        jsonResponse(['status' => 'duplicate', 'app' => APP_NAME]);
    }
}

$dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
$dup->execute([$utr]);
if ($dup->fetch()) {
    if ($gatewayEvent !== null && !empty($gatewayEvent['id']) && function_exists('setGatewayEventStatus')) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'duplicate');
    }
    jsonResponse(['status' => 'duplicate', 'app' => APP_NAME]);
}

if (!function_exists('webhookFastAck')) {
    require_once __DIR__ . '/includes/webhook_queue.php';
}
webhookFastAck(['status' => 'received', 'app' => APP_NAME, 'partner' => 'rbl']);

$link = [
    'merchant_id' => (int)$merch['id'],
    'amount' => $amount,
    'description' => 'RBL VA Collection',
    'commission_rate' => $merch['commission_rate'],
    'collection_mode' => 'rbl_va',
    'account_mode' => $merch['account_mode'] ?? 'test',
    'kyc_status' => $merch['kyc_status'] ?? '',
    'id' => 0,
];
$txnId = createTransactionFromPayment($link, 'rbl_va', 'success', $utr, merchantAccountMode($merch) === 'test');
if (function_exists('createNotification')) {
    createNotification((int)$merch['id'], 'RBL VA Payment', formatMoney($amount) . ' received in Virtual Account.');
}
if ($gatewayEvent !== null && !empty($gatewayEvent['id']) && function_exists('setGatewayEventStatus')) {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
}
jsonResponse(['status' => 'ok', 'txn_id' => $txnId, 'app' => APP_NAME]);
