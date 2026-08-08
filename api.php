<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-API-Secret, Idempotency-Key');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    $normalizedOrigin = normalizeApiOrigin($origin);
    $allowed = false;
    if ($normalizedOrigin && financialTablesReady()) {
        $st = getDB()->prepare("SELECT id FROM api_credentials WHERE status='active' AND JSON_CONTAINS(allowed_origins, JSON_QUOTE(?)) LIMIT 1");
        $st->execute([$normalizedOrigin]);
        $allowed = (bool)$st->fetchColumn();
    }
    if (!$allowed) {
        jsonResponse(['error' => 'Origin not allowed'], 403);
    }
    header('Access-Control-Allow-Origin: ' . $normalizedOrigin);
    header('Vary: Origin');
    http_response_code(204);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { jsonResponse(['error' => 'Method not allowed'], 405); }

define('API_VERSION', 'v1');

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? '';
$apiSecret = $_SERVER['HTTP_X_API_SECRET'] ?? '';
$rawInput = file_get_contents('php://input') ?: '';
$input = json_decode($rawInput, true);
if (!is_array($input)) {
    jsonResponse(['error' => 'A valid JSON body is required'], 400);
}
$action = $input['action'] ?? '';
$requiredScope = apiScopeForAction($action);

if (!$requiredScope) {
    jsonResponse(['error' => 'Unknown action'], 400);
}
if (!$apiKey || !$apiSecret) {
    jsonResponse(['error' => 'X-API-Key and X-API-Secret are required'], 401);
}
try {
    $merchant = authenticateMerchantApiCredential($apiKey, $apiSecret, $requiredScope);
} catch (RuntimeException $e) {
    if ($e->getMessage() === 'API rate limit exceeded.') {
        header('Retry-After: 60');
        jsonResponse(['error' => $e->getMessage()], 429);
    }
    jsonResponse(['error' => 'API authentication failed'], 401);
}
if (!$merchant) {
    jsonResponse(['error' => 'Invalid credentials or insufficient scope'], 401);
}
if ($origin !== '') {
    $credential = ['allowed_origins' => $merchant['api_allowed_origins'] ?? null];
    if (!apiOriginAllowed($credential, $origin)) {
        jsonResponse(['error' => 'Origin not allowed'], 403);
    }
    header('Access-Control-Allow-Origin: ' . normalizeApiOrigin($origin));
    header('Vary: Origin');
}

$db = getDB();
$apiMode = (string)$merchant['api_mode'];
$usingTestKey = $apiMode === 'test';
$liveAllowed = $apiMode === 'live' && isMerchantLive($merchant);
$modeFlag = $usingTestKey ? 1 : 0;
$idempotency = null;

switch ($action) {
    case 'create_payment_link':
        $amount = (float)($input['amount'] ?? 0);
        $description = trim($input['description'] ?? '');
        $customerPhone = trim($input['customer_phone'] ?? '');
        $customerName = trim($input['customer_name'] ?? '');

        if ($amount < 1 || $amount > livePaymentAmountCap()) { jsonResponse(['error' => 'Amount must be between 1 and 200000000'], 400); }
        if (mb_strlen($description) > 255) { jsonResponse(['error' => 'Description is too long'], 400); }
        if (!$liveAllowed && !$usingTestKey) {
            jsonResponse(['error' => 'Account in Test Mode. Use test API key or complete KYC for live payments.'], 403);
        }
        try {
            $idempotency = claimApiIdempotency((int)$merchant['id'], $apiMode, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', $input);
            if (!empty($idempotency['replay'])) {
                http_response_code((int)$idempotency['response_code']);
                echo $idempotency['response_body'];
                exit;
            }
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 409);
        }

        $linkId = generateId('LNK');
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $isTest = $modeFlag;
        try {
            $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, customer_name, customer_phone, expires_at, is_test) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone, $expiresAt, $isTest]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, customer_name, customer_phone, expires_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone, $expiresAt]);
        }

        $response = [
            'success' => true,
            'api_version' => API_VERSION,
            'mode' => $isTest ? 'test' : 'live',
            'link_id' => $linkId,
            'payment_url' => APP_URL . '/checkout.php?link=' . $linkId,
            'amount' => $amount,
            'expires_at' => $expiresAt,
        ];
        completeApiIdempotency((int)$idempotency['id'], 200, $response);
        jsonResponse($response);
        break;

    case 'check_status':
        $txnId = $input['txn_id'] ?? '';
        if (!$txnId) { jsonResponse(['error' => 'txn_id required'], 400); }

        $stmt = $db->prepare('SELECT txn_id, amount, status, payment_method, utr, created_at FROM transactions WHERE txn_id = ? AND merchant_id = ? AND COALESCE(is_test,0)=?');
        $stmt->execute([$txnId, $merchant['id'], $modeFlag]);
        $txn = $stmt->fetch();

        if (!$txn) { jsonResponse(['error' => 'Transaction not found'], 404); }
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'transaction' => $txn]);
        break;

    case 'list_transactions':
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $offset = max(0, (int)($input['offset'] ?? 0));
        $countStmt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id = ? AND COALESCE(is_test,0)=?");
        $countStmt->execute([$merchant['id'], $modeFlag]);
        $totalCount = (int)$countStmt->fetchColumn();
        $stmt = $db->prepare("SELECT txn_id, amount, status, payment_method, created_at FROM transactions WHERE merchant_id = ? AND COALESCE(is_test,0)=? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute([$merchant['id'], $modeFlag]);
        $rows = $stmt->fetchAll();
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'transactions' => $rows, 'has_more' => ($offset + count($rows)) < $totalCount, 'total_count' => $totalCount, 'offset' => $offset, 'limit' => $limit]);
        break;

    case 'get_balance':
        $collected = $db->prepare("SELECT COALESCE(SUM(amount),0) as total FROM transactions WHERE merchant_id = ? AND status = 'success' AND COALESCE(is_test,0)=?");
        $collected->execute([$merchant['id'], $modeFlag]);
        $total = (float)$collected->fetch()['total'];
        $available = merchantLedgerBalance((int)$merchant['id'], $apiMode);
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'mode' => $apiMode, 'balance' => ['collected' => $total, 'available' => $available]]);
        break;

    case 'create_refund':
        if (!$liveAllowed && !$usingTestKey) {
            jsonResponse(['error' => 'Account in Test Mode. Use test API key or complete KYC for live refunds.'], 403);
        }
        $txnId = trim($input['txn_id'] ?? '');
        if (!$txnId) { jsonResponse(['error' => 'txn_id required'], 400); }
        $st = $db->prepare('SELECT id FROM transactions WHERE txn_id = ? AND merchant_id = ? AND status = ? AND COALESCE(is_test,0)=?');
        $st->execute([$txnId, $merchant['id'], 'success', $modeFlag]);
        $txn = $st->fetch();
        if (!$txn) { jsonResponse(['error' => 'Successful transaction not found'], 404); }
        $amount = (float)($input['amount'] ?? 0);
        $reason = trim($input['reason'] ?? 'API refund request');
        try {
            $idempotency = claimApiIdempotency((int)$merchant['id'], $apiMode, $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '', $input);
            if (!empty($idempotency['replay'])) {
                http_response_code((int)$idempotency['response_code']);
                echo $idempotency['response_body'];
                exit;
            }
        } catch (Throwable $e) {
            jsonResponse(['error' => $e->getMessage()], 409);
        }
        $result = processRefund((int)$txn['id'], $amount, $reason);
        if (!$result['ok']) {
            $response = ['error' => $result['error'] ?? 'Refund failed'];
            completeApiIdempotency((int)$idempotency['id'], 400, $response);
            jsonResponse($response, 400);
        }
        $response = ['success' => true, 'api_version' => API_VERSION, 'refund_id' => $result['refund_id'], 'amount' => $result['amount'], 'status' => $result['status'] ?? 'pending'];
        completeApiIdempotency((int)$idempotency['id'], 200, $response);
        jsonResponse($response);
        break;

    case 'list_refunds':
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $offset = max(0, (int)($input['offset'] ?? 0));
        $countSt = $db->prepare("SELECT COUNT(*) FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.merchant_id=? AND COALESCE(t.is_test,0)=?");
        $countSt->execute([(int)$merchant['id'], $modeFlag]);
        $totalCount = (int)$countSt->fetchColumn();
        $st = $db->prepare("SELECT r.* FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.merchant_id=? AND COALESCE(t.is_test,0)=? ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
        $st->execute([(int)$merchant['id'], $modeFlag]);
        $rows = $st->fetchAll();
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'refunds' => $rows, 'has_more' => ($offset + count($rows)) < $totalCount, 'total_count' => $totalCount, 'offset' => $offset, 'limit' => $limit]);
        break;

    case 'list_payment_links':
        ensurePaymentLinkAnalytics();
        $limit = min(100, max(1, (int)($input['limit'] ?? 20)));
        $offset = max(0, (int)($input['offset'] ?? 0));
        $countSt = $db->prepare('SELECT COUNT(*) FROM payment_links WHERE merchant_id = ? AND COALESCE(is_test,0)=?');
        $countSt->execute([$merchant['id'], $modeFlag]);
        $totalCount = (int)$countSt->fetchColumn();
        $st = $db->prepare('SELECT link_id, amount, status, view_count, expires_at, created_at FROM payment_links WHERE merchant_id = ? AND COALESCE(is_test,0)=? ORDER BY created_at DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
        $st->execute([$merchant['id'], $modeFlag]);
        $rows = $st->fetchAll();
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'payment_links' => $rows, 'has_more' => ($offset + count($rows)) < $totalCount, 'total_count' => $totalCount, 'offset' => $offset, 'limit' => $limit]);
        break;

    case 'get_payment_link':
        ensurePaymentLinkAnalytics();
        $linkId = trim($input['link_id'] ?? '');
        if (!$linkId) { jsonResponse(['error' => 'link_id required'], 400); }
        $st = $db->prepare('SELECT link_id, amount, status, view_count, description, expires_at, created_at FROM payment_links WHERE link_id = ? AND merchant_id = ? AND COALESCE(is_test,0)=?');
        $st->execute([$linkId, $merchant['id'], $modeFlag]);
        $row = $st->fetch();
        if (!$row) { jsonResponse(['error' => 'Payment link not found'], 404); }
        $row['payment_url'] = APP_URL . '/checkout.php?link=' . $linkId;
        jsonResponse(['success' => true, 'api_version' => API_VERSION, 'payment_link' => $row]);
        break;

    default:
        jsonResponse(['error' => 'Unknown action. Available: create_payment_link, check_status, list_transactions, get_balance, create_refund, list_refunds, list_payment_links, get_payment_link'], 400);
}
