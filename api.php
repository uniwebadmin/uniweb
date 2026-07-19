<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin && (rtrim($origin, '/') === rtrim(APP_URL, '/') || str_starts_with($origin, rtrim(APP_URL, '/')))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-API-Secret');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'Method not allowed'], 405); }

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
$action = $input['action'] ?? '';

if (!$apiKey) { jsonResponse(['error' => 'API key required'], 401); }

$db = getDB();
$stmt = $db->prepare('SELECT * FROM merchants WHERE (api_key = ? OR test_api_key = ?) AND status = ?');
$stmt->execute([$apiKey, $apiKey, 'active']);
$merchant = $stmt->fetch();

if (!$merchant) { jsonResponse(['error' => 'Invalid API key'], 401); }

$usingTestKey = ($merchant['test_api_key'] ?? '') === $apiKey;
$liveAllowed = isMerchantLive($merchant) && !$usingTestKey;

switch ($action) {
    case 'create_payment_link':
        $amount = (float)($input['amount'] ?? 0);
        $description = trim($input['description'] ?? '');
        $customerPhone = trim($input['customer_phone'] ?? '');
        $customerName = trim($input['customer_name'] ?? '');

        if ($amount < 1) { jsonResponse(['error' => 'Minimum amount is 1'], 400); }
        if (!$liveAllowed && !$usingTestKey) {
            jsonResponse(['error' => 'Account in Test Mode. Use test API key or complete KYC for live payments.'], 403);
        }

        $linkId = generateId('LNK');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $isTest = $usingTestKey || isMerchantTest($merchant) ? 1 : 0;
        try {
            $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, customer_name, customer_phone, expires_at, is_test) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone, $expiresAt, $isTest]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, customer_name, customer_phone, expires_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone, $expiresAt]);
        }

        jsonResponse([
            'success' => true,
            'mode' => $isTest ? 'test' : 'live',
            'link_id' => $linkId,
            'payment_url' => APP_URL . '/checkout.php?link=' . $linkId,
            'amount' => $amount,
            'expires_at' => $expiresAt,
        ]);
        break;

    case 'check_status':
        $txnId = $input['txn_id'] ?? '';
        if (!$txnId) { jsonResponse(['error' => 'txn_id required'], 400); }

        $stmt = $db->prepare('SELECT txn_id, amount, status, payment_method, utr, created_at FROM transactions WHERE txn_id = ? AND merchant_id = ?');
        $stmt->execute([$txnId, $merchant['id']]);
        $txn = $stmt->fetch();

        if (!$txn) { jsonResponse(['error' => 'Transaction not found'], 404); }
        jsonResponse(['success' => true, 'transaction' => $txn]);
        break;

    case 'list_transactions':
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $stmt = $db->prepare("SELECT txn_id, amount, status, payment_method, created_at FROM transactions WHERE merchant_id = ? ORDER BY created_at DESC LIMIT $limit");
        $stmt->execute([$merchant['id']]);
        jsonResponse(['success' => true, 'transactions' => $stmt->fetchAll()]);
        break;

    case 'get_balance':
        $collected = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM transactions WHERE merchant_id = ? AND status = 'success'");
        $collected->execute([$merchant['id']]);
        $settled = $db->prepare("SELECT COALESCE(SUM(net_amount),0) as total FROM settlements WHERE merchant_id = ? AND status = 'completed'");
        $settled->execute([$merchant['id']]);
        $total = (float)$collected->fetch()['total'];
        $settledAmt = (float)$settled->fetch()['total'];
        jsonResponse(['success' => true, 'balance' => ['collected' => $total, 'settled' => $settledAmt, 'available' => $total - $settledAmt]]);
        break;

    case 'create_refund':
        if (!$liveAllowed && !$usingTestKey) {
            jsonResponse(['error' => 'Account in Test Mode. Use test API key or complete KYC for live refunds.'], 403);
        }
        $txnId = trim($input['txn_id'] ?? '');
        if (!$txnId) { jsonResponse(['error' => 'txn_id required'], 400); }
        $st = $db->prepare('SELECT id FROM transactions WHERE txn_id = ? AND merchant_id = ? AND status = ?');
        $st->execute([$txnId, $merchant['id'], 'success']);
        $txn = $st->fetch();
        if (!$txn) { jsonResponse(['error' => 'Successful transaction not found'], 404); }
        $amount = (float)($input['amount'] ?? 0);
        $reason = trim($input['reason'] ?? 'API refund request');
        $result = processRefund((int)$txn['id'], $amount, $reason);
        if (!$result['ok']) { jsonResponse(['error' => $result['error'] ?? 'Refund failed'], 400); }
        jsonResponse(['success' => true, 'refund_id' => $result['refund_id'], 'amount' => $result['amount']]);
        break;

    case 'list_refunds':
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        jsonResponse(['success' => true, 'refunds' => getMerchantRefunds((int)$merchant['id'], $limit)]);
        break;

    case 'list_payment_links':
        ensurePaymentLinkAnalytics();
        $limit = min(50, max(1, (int)($input['limit'] ?? 20)));
        $st = $db->prepare('SELECT link_id, amount, status, view_count, expires_at, created_at FROM payment_links WHERE merchant_id = ? ORDER BY created_at DESC LIMIT ' . $limit);
        $st->execute([$merchant['id']]);
        jsonResponse(['success' => true, 'payment_links' => $st->fetchAll()]);
        break;

    case 'get_payment_link':
        ensurePaymentLinkAnalytics();
        $linkId = trim($input['link_id'] ?? '');
        if (!$linkId) { jsonResponse(['error' => 'link_id required'], 400); }
        $st = $db->prepare('SELECT link_id, amount, status, view_count, description, expires_at, created_at FROM payment_links WHERE link_id = ? AND merchant_id = ?');
        $st->execute([$linkId, $merchant['id']]);
        $row = $st->fetch();
        if (!$row) { jsonResponse(['error' => 'Payment link not found'], 404); }
        $row['payment_url'] = APP_URL . '/checkout.php?link=' . $linkId;
        jsonResponse(['success' => true, 'payment_link' => $row]);
        break;

    default:
        jsonResponse(['error' => 'Unknown action. Available: create_payment_link, check_status, list_transactions, get_balance, create_refund, list_refunds, list_payment_links, get_payment_link'], 400);
}
