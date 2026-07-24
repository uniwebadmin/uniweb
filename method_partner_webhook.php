<?php
/**
 * Partner decision callback for payment-method onboarding.
 * Partner/bank systems POST here when a merchant method is approved or rejected.
 *
 * Auth: header X-UniWeb-Method-Secret OR query ?key=… matching Gateway Settings
 * (method_partner_webhook_secret, or Razorpay/PayU/Cashfree webhook secrets).
 *
 * Body JSON example:
 * {"partner_ref":"GS123","decision":"approved","note":"MID live","gateway":"payu"}
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/method_requests.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode([
        'ok' => true,
        'service' => 'method_partner_webhook',
        'hint' => 'POST partner_ref + decision (approved|rejected). Auth via X-UniWeb-Method-Secret.',
    ]);
    exit;
}

$secret = (string)($_SERVER['HTTP_X_UNIWEB_METHOD_SECRET']
    ?? $_SERVER['HTTP_X_UNIWEB_WEBHOOK_SECRET']
    ?? $_GET['key']
    ?? '');
if (!verifyMethodPartnerWebhookSecret($secret)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = array_merge($_GET, $_POST);
}

$partnerRef = trim((string)($data['partner_ref'] ?? $data['reference'] ?? $data['ref'] ?? ''));
$decision = strtolower(trim((string)($data['decision'] ?? $data['status'] ?? '')));
$note = trim((string)($data['note'] ?? $data['reason'] ?? ''));
$gateway = trim((string)($data['gateway'] ?? ''));
$gateway = $gateway !== '' ? preg_replace('/[^a-z_]/', '', strtolower($gateway)) : null;

$approved = in_array($decision, ['approved', 'approve', 'active', 'enabled', 'success', 'ok'], true);
$rejected = in_array($decision, ['rejected', 'reject', 'failed', 'declined', 'inactive'], true);
if (!$approved && !$rejected) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'decision must be approved or rejected']);
    exit;
}

if (function_exists('logPgWebhook')) {
    logPgWebhook($gateway ?: 'method_partner', 'method_decision', $decision, $partnerRef, null, $raw !== '' ? $raw : json_encode($data));
}

$result = applyPartnerMethodDecisionByRef($partnerRef, $approved, 'partner_webhook', $note, $gateway);
http_response_code(!empty($result['ok']) ? 200 : 422);
echo json_encode($result);
