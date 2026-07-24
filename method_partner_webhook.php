<?php
/**
 * Partner decision callback for payment-method onboarding.
 * Accepts UniWeb JSON and common Razorpay / Cashfree / PayU shapes.
 *
 * Auth: header X-UniWeb-Method-Secret OR query ?key=…
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';
require_once __DIR__ . '/includes/method_partner_adapters.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode([
        'ok' => true,
        'service' => 'method_partner_webhook',
        'formats' => ['uniweb', 'razorpay_envelope', 'cashfree_vendor', 'payu_flat'],
        'hint' => 'POST body. Auth via X-UniWeb-Method-Secret or ?key=',
    ]);
    exit;
}

$secret = (string)($_SERVER['HTTP_X_UNIWEB_METHOD_SECRET']
    ?? $_SERVER['HTTP_X_UNIWEB_WEBHOOK_SECRET']
    ?? $_GET['key']
    ?? '');

// Also accept signed Razorpay/Cashfree headers as auth when body verifies.
$raw = file_get_contents('php://input') ?: '';
$rzpSig = (string)($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '');
$cfSig = (string)($_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? $_SERVER['HTTP_X_CASHFREE_SIGNATURE'] ?? '');
$cfTs = (string)($_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? $_SERVER['HTTP_X_CASHFREE_TIMESTAMP'] ?? '');
$authOk = verifyMethodPartnerWebhookSecret($secret);
if (!$authOk && $rzpSig !== '' && function_exists('verifyRazorpayWebhookSignature')) {
    $authOk = verifyRazorpayWebhookSignature($raw, $rzpSig);
}
if (!$authOk && $cfSig !== '' && function_exists('verifyCashfreeWebhookSignature')) {
    $authOk = verifyCashfreeWebhookSignature($raw, $cfSig, $cfTs);
}
if (!$authOk) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = array_merge($_GET, $_POST);
}

$result = applyNormalizedPartnerMethodWebhook($data, $raw, 'partner_webhook');
$gw = $result['normalized']['gateway'] ?? 'method_partner';
if (function_exists('logPgWebhook')) {
    logPgWebhook(
        is_string($gw) ? $gw : 'method_partner',
        'method_decision',
        !empty($result['ok']) ? 'ok' : 'fail',
        (string)(($result['normalized']['partner_ref'] ?? '') ?: ''),
        null,
        $raw !== '' ? $raw : json_encode($data)
    );
}

http_response_code(!empty($result['ok']) ? 200 : 422);
echo json_encode($result);
