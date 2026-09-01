<?php
declare(strict_types=1);

/**
 * Fast QR Create API endpoint.
 *
 * GET  /api_qr_create.php — health JSON (webhook-style probe)
 * POST /api_qr_create.php
 * Headers: X-API-Key: <merchant_api_key>, Idempotency-Key: <required>
 * Body: {"qr_type": "fixed|upi_dynamic|all_methods", "label": "QR Name", "amount": 100, "description": "optional", "items": [...]}
 *
 * Uses the same Test/Live API key as API Settings (links:write scope).
 */

require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/includes/merchant_api_errors.php')) {
    require_once __DIR__ . '/includes/merchant_api_errors.php';
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization, Idempotency-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        'status' => 'ok',
        'endpoint' => 'api_qr_create',
        'app' => defined('APP_NAME') ? APP_NAME : 'UniWeb',
        'message' => 'Fast QR API is live. POST with X-API-Key and Idempotency-Key.',
        'auth' => 'X-API-Key (same as API Settings · links:write)',
        'idempotency' => 'Idempotency-Key header required on POST',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!function_exists('fastQrCreate')) {
    require_once __DIR__ . '/includes/fast_qr_api.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('method_not_allowed');
    }
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.', 'error_code' => 'method_not_allowed']);
    exit;
}

$apiKey = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim((string)$_SERVER['HTTP_X_API_KEY']);
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = trim((string)$_SERVER['HTTP_AUTHORIZATION']);
    if (str_starts_with(strtolower($auth), 'bearer ')) {
        $apiKey = trim(substr($auth, 7));
    }
}

if ($apiKey === '') {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('missing_credentials');
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'error_code' => 'missing_credentials', 'error' => 'X-API-Key header is required.']);
    exit;
}

$merchant = fastQrAuthenticate($apiKey);
if (!$merchant) {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('auth_failed');
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'error_code' => 'auth_failed', 'error' => 'Invalid or missing API key.']);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!$body || !is_array($body)) {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('invalid_json');
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error_code' => 'invalid_json', 'error' => 'Invalid JSON body.']);
    exit;
}

if (function_exists('checkRateLimit')) {
    $rlConfig = getRateLimitConfig();
    $isBatch = !empty($body['items']);
    $scope = $isBatch ? 'qr_batch' : 'qr_create';
    $limit = $rlConfig[$scope] ?? 60;
    if (!checkRateLimit(sha1($apiKey), $scope, $limit)) {
        header('Retry-After: 60');
        if (function_exists('merchantApiRespondError')) {
            merchantApiRespondError('rate_limited');
        }
        http_response_code(429);
        echo json_encode(['success' => false, 'error_code' => 'rate_limited', 'error' => 'Rate limit exceeded.']);
        exit;
    }
}

$merchantId = (int)$merchant['id'];
$apiMode = (string)($merchant['api_mode'] ?? ((string)($merchant['mode'] ?? 'test') === 'test' ? 'test' : 'live'));
$isTest = $apiMode === 'test' || isMerchantPaymentTest($merchant);

if (!function_exists('claimApiIdempotency')) {
    require_once __DIR__ . '/includes/financial_integrity.php';
}

try {
    $idempotency = claimApiIdempotency($merchantId, $apiMode, (string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''), $body);
    if (!empty($idempotency['replay'])) {
        http_response_code((int)$idempotency['response_code']);
        echo (string)$idempotency['response_body'];
        exit;
    }
} catch (InvalidArgumentException $e) {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('missing_idempotency_key');
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'error_code' => 'missing_idempotency_key', 'error' => 'Idempotency-Key header is required.']);
    exit;
} catch (RuntimeException $e) {
    if (function_exists('merchantApiRespondError')) {
        merchantApiRespondError('idempotency_conflict', $e->getMessage());
    }
    http_response_code(409);
    echo json_encode(['success' => false, 'error_code' => 'idempotency_conflict', 'error' => $e->getMessage()]);
    exit;
}

$qrType = (string)($body['qr_type'] ?? 'fixed');
if (!in_array($qrType, ['fixed', 'upi_dynamic', 'all_methods'], true)) {
    $qrType = 'fixed';
}

if (!$isTest && ($merchant['kyc_status'] ?? '') !== 'verified') {
    http_response_code(403);
    $payload = ['success' => false, 'error_code' => 'mode_mismatch', 'error' => 'Live QR requires verified KYC.'];
    completeApiIdempotency((int)$idempotency['id'], 403, $payload);
    echo json_encode($payload);
    exit;
}

$upiId = trim((string)($merchant['upi_id'] ?? ''));
if (!$isTest && $qrType === 'upi_dynamic' && $upiId === '') {
    http_response_code(400);
    $payload = ['success' => false, 'error_code' => 'validation_error', 'error' => 'UPI QR requires a UPI ID set in account settings.'];
    completeApiIdempotency((int)$idempotency['id'], 400, $payload);
    echo json_encode($payload);
    exit;
}

if (!empty($body['items']) && is_array($body['items'])) {
    $items = array_slice($body['items'], 0, 100);
    $result = fastQrBatchCreate($merchantId, $qrType, $items, $isTest);
    $code = $result['ok'] ? 200 : 400;
    $result['api_version'] = defined('API_VERSION') ? API_VERSION : 'v1';
    $result['mode'] = $isTest ? 'test' : 'live';
    http_response_code($code);
    completeApiIdempotency((int)$idempotency['id'], $code, $result);
    echo json_encode($result);
    exit;
}

$label = trim((string)($body['label'] ?? ''));
if ($label === '' || mb_strlen($label) > 120) {
    http_response_code(400);
    $payload = ['success' => false, 'error_code' => 'validation_error', 'error' => 'Label is required (max 120 chars).'];
    completeApiIdempotency((int)$idempotency['id'], 400, $payload);
    echo json_encode($payload);
    exit;
}

$amount = $qrType === 'fixed' ? (float)($body['amount'] ?? 0) : 0.0;
if ($qrType === 'fixed' && $amount < 1) {
    http_response_code(400);
    $payload = ['success' => false, 'error_code' => 'amount_out_of_range', 'error' => 'Amount must be at least ₹1 for fixed QR.'];
    completeApiIdempotency((int)$idempotency['id'], 400, $payload);
    echo json_encode($payload);
    exit;
}
if ($isTest && $amount > 100) {
    http_response_code(400);
    $payload = ['success' => false, 'error_code' => 'amount_out_of_range', 'error' => 'Test mode amount must be ₹1–₹100.'];
    completeApiIdempotency((int)$idempotency['id'], 400, $payload);
    echo json_encode($payload);
    exit;
}

$description = trim((string)($body['description'] ?? ''));
$result = fastQrCreate($merchantId, $qrType, $label, $amount, $description, $isTest);
$code = $result['ok'] ? 200 : 500;
$result['api_version'] = defined('API_VERSION') ? API_VERSION : 'v1';
$result['mode'] = $isTest ? 'test' : 'live';
http_response_code($code);
completeApiIdempotency((int)$idempotency['id'], $code, $result);
echo json_encode($result);
