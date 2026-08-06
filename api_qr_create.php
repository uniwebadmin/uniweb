<?php
declare(strict_types=1);

/**
 * Fast QR Create API endpoint.
 *
 * POST /api_qr_create.php
 * Headers: X-API-Key: <merchant_api_key>
 * Body: {"qr_type": "fixed|upi_dynamic|all_methods", "label": "QR Name", "amount": 100, "description": "optional", "items": [...]}
 *
 * Single QR: {label, amount, description}
 * Batch: {items: [{label, amount, description}, ...]} (max 100 per request)
 */

require_once __DIR__ . '/config.php';

// CORS + content type
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!function_exists('fastQrCreate')) {
    require_once __DIR__ . '/includes/fast_qr_api.php';
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit;
}

// Authenticate via API key
$apiKey = '';
if (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = trim($_SERVER['HTTP_X_API_KEY']);
} elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $auth = trim($_SERVER['HTTP_AUTHORIZATION']);
    if (str_starts_with(strtolower($auth), 'bearer ')) {
        $apiKey = trim(substr($auth, 7));
    }
}

$merchant = fastQrAuthenticate($apiKey);
if (!$merchant) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or missing API key.']);
    exit;
}

// Parse request body
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body || !is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body.']);
    exit;
}

// Rate limit check
if (function_exists('checkRateLimit')) {
    $rlConfig = getRateLimitConfig();
    $isBatch = !empty($body['items']);
    $scope = $isBatch ? 'qr_batch' : 'qr_create';
    $limit = $rlConfig[$scope] ?? 60;
    if (!checkRateLimit(sha1($apiKey), $scope, $limit)) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Max ' . $limit . ' requests/minute for ' . $scope . '.']);
        exit;
    }
}

$merchantId = (int)$merchant['id'];
$isTest = (string)($merchant['mode'] ?? 'test') === 'test' || isMerchantPaymentTest($merchant);
$qrType = (string)($body['qr_type'] ?? 'fixed');
if (!in_array($qrType, ['fixed', 'upi_dynamic', 'all_methods'], true)) {
    $qrType = 'fixed';
}

// KYC check for live mode
if (!$isTest && ($merchant['kyc_status'] ?? '') !== 'verified') {
    http_response_code(403);
    echo json_encode(['error' => 'Live QR requires verified KYC.']);
    exit;
}

// UPI QR needs UPI ID
$upiId = trim((string)($merchant['upi_id'] ?? ''));
if (!$isTest && $qrType === 'upi_dynamic' && $upiId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'UPI QR requires a UPI ID set in account settings.']);
    exit;
}

// Batch create
if (!empty($body['items']) && is_array($body['items'])) {
    $items = array_slice($body['items'], 0, 100); // max 100 per request
    $result = fastQrBatchCreate($merchantId, $qrType, $items, $isTest);
    http_response_code($result['ok'] ? 200 : 400);
    echo json_encode($result);
    exit;
}

// Single create
$label = trim((string)($body['label'] ?? ''));
if ($label === '' || mb_strlen($label) > 120) {
    http_response_code(400);
    echo json_encode(['error' => 'Label is required (max 120 chars).']);
    exit;
}

$amount = $qrType === 'fixed' ? (float)($body['amount'] ?? 0) : 0.0;
if ($qrType === 'fixed' && $amount < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Amount must be at least ₹1 for fixed QR.']);
    exit;
}
if ($isTest && $amount > 100) {
    http_response_code(400);
    echo json_encode(['error' => 'Test mode amount must be ₹1–₹100.']);
    exit;
}

$description = trim((string)($body['description'] ?? ''));

$result = fastQrCreate($merchantId, $qrType, $label, $amount, $description, $isTest);
http_response_code($result['ok'] ? 200 : 500);
echo json_encode($result);
