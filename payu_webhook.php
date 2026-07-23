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

$failureStatuses = ['failure', 'failed', 'f'];
if (in_array($status, $failureStatuses, true) && $reference !== '') {
    $providerOrderId = (string)($post['txnid'] ?? $reference);
    try {
        $orderLookup = getDB()->prepare("SELECT provider_order_id FROM payment_orders WHERE provider='payu' AND (provider_order_id=? OR order_ref=?) LIMIT 1");
        $orderLookup->execute([$providerOrderId, $providerOrderId]);
        $bound = $orderLookup->fetchColumn();
        if (!$bound) {
            logPgWebhook('payu', 'ignored_failure', $status, $reference, $linkId, '{"reason":"no_bound_order"}');
            header('Content-Type: application/json');
            echo json_encode(['ok' => true, 'ignored' => true, 'reason' => 'no_bound_order']);
            exit;
        }
        $providerOrderId = (string)$bound;
        $result = recordPaymentOrderFailure([
            'provider' => 'payu',
            'provider_order_id' => $providerOrderId,
            'provider_payment_id' => $reference,
            'error_code' => (string)($post['error'] ?? $post['error_code'] ?? $post['field9'] ?? $status),
            'error_description' => (string)($post['error_Message'] ?? $post['error_message'] ?? $post['field9'] ?? $post['status'] ?? ''),
            'amount' => $amount > 0 ? $amount : null,
            'currency' => 'INR',
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $reference,
        ]);
        logPgWebhook('payu', 'processed_failure', $status, $reference, $linkId, json_encode($result));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'result' => $result]);
        exit;
    } catch (Throwable $e) {
        logPgWebhook('payu', 'failed', $status, $reference, $linkId, json_encode(['error' => $e->getMessage()]));
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failure processing failed']);
        exit;
    }
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
