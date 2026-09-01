<?php
require_once __DIR__ . '/config.php';
if (!function_exists('applyPartnerPaymentReconcile') && is_file(__DIR__ . '/includes/payment_reconcile.php')) {
    require_once __DIR__ . '/includes/payment_reconcile.php';
}
if (!function_exists('recordWebhookEvent')) {
    require_once __DIR__ . '/includes/webhook_reliability.php';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST) && empty($_GET['status'])) {
    pgWebhookHealthResponse('payu');
}

$post = array_merge($_GET, $_POST);
$raw = json_encode($post, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
$linkId = (string)($post['udf1'] ?? '');
$status = strtolower((string)($post['status'] ?? ''));
$reference = (string)($post['mihpayid'] ?? $post['txnid'] ?? '');
$amount = (float)($post['amount'] ?? 0);

$verify = pgWebhookVerifyPartner('payu', $raw, $post);
if (!$verify['ok']) {
    if (function_exists('financialTablesReady') && financialTablesReady()) {
        registerGatewayEvent('payu', 'payu:verify_fail:' . hash('sha256', $raw), 'verify_failed', $raw, false);
    }
    logPgWebhookVerifyFailure('payu', (string)$verify['reason'], $status, $reference, $linkId, [
        'status' => $status,
        'reference' => $reference !== '' ? $reference : null,
        'link_id' => $linkId !== '' ? $linkId : null,
        'scheme' => (string)$verify['scheme'],
    ]);
    pgWebhookRejectJson('payu', (string)$verify['reason'], (int)$verify['http_code'], $status, $reference);
}

logPgWebhook('payu', 'received', $status, $reference, $linkId, json_encode([
    'status' => $status,
    'reference' => $reference,
    'link_id' => $linkId,
    'body_bytes' => strlen($raw),
], JSON_UNESCAPED_UNICODE) ?: '{}');

$eventId = 'payu:' . ($reference ?: hash('sha256', $raw));
$gatewayEvent = registerGatewayEvent('payu', $eventId, $status, $raw, true);
if (!empty($gatewayEvent['duplicate'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'duplicate' => true]);
    exit;
}
$webhookEv = recordWebhookEvent($eventId, 'payu', $status, $raw, '');
if (!empty($webhookEv['is_duplicate'])) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'duplicate' => true]);
    exit;
}
markWebhookProcessing((int)$webhookEv['id']);

if (!function_exists('webhookFastAck')) {
    require_once __DIR__ . '/includes/webhook_queue.php';
}
webhookFastAck(['ok' => true, 'received' => true]);

$refundToken = trim((string)($post['token'] ?? $post['request_id'] ?? $post['udf3'] ?? ''));
$refundAction = strtolower((string)($post['action'] ?? $post['command'] ?? ''));
if ($status === 'refund' || $refundAction === 'refund' || ($refundToken !== '' && str_starts_with($refundToken, 'RFD'))) {
    try {
        if (!function_exists('applyPartnerRefundWebhookEvent') && is_file(__DIR__ . '/includes/refund_webhooks.php')) {
            require_once __DIR__ . '/includes/refund_webhooks.php';
        }
        $localRefundId = str_starts_with($refundToken, 'RFD') ? $refundToken : '';
        $payuStatus = strtolower((string)($post['error'] ?? $post['status'] ?? ''));
        $terminal = in_array($payuStatus, ['success', 'captured'], true) ? 'processed' : (in_array($payuStatus, ['failure', 'failed'], true) ? 'failed' : '');
        $result = applyPartnerRefundWebhookEvent('payu', [
            'refund_id' => $localRefundId,
            'provider_refund_id' => $localRefundId !== '' ? $localRefundId : $refundToken,
            'event_type' => 'payu.refund',
            'terminal' => $terminal,
            'failure_reason' => (string)($post['error_Message'] ?? $post['field9'] ?? ''),
        ]);
        logPgWebhook('payu', !empty($result['ok']) ? 'processed_refund' : 'refund_failed', $status, $reference, $linkId, json_encode($result));
        markWebhookCompleted((int)$webhookEv['id']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'result' => $result]);
        exit;
    } catch (Throwable $e) {
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Refund processing failed']);
        exit;
    }
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
        $result = applyPartnerPaymentReconcile([
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
            'reconcile_source' => 'webhook',
            'terminal' => 'failed',
        ]);
        logPgWebhook('payu', 'processed_failure', $status, $reference, $linkId, json_encode($result));
        markWebhookCompleted((int)$webhookEv['id']);
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'result' => $result]);
        exit;
    } catch (Throwable $e) {
        logPgWebhook('payu', 'failed', $status, $reference, $linkId, json_encode(['error' => $e->getMessage()]));
        markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Failure processing failed']);
        exit;
    }
}

if (!in_array($status, ['success', 'successful'], true) || $linkId === '' || $reference === '') {
    markWebhookCompleted((int)$webhookEv['id']);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

try {
    $providerOrderId = (string)($post['txnid'] ?? $reference);
    $orderSt = getDB()->prepare(
        "SELECT o.provider_order_id FROM payment_orders o
         JOIN payment_links pl ON pl.id = o.payment_link_id
         WHERE pl.link_id = ? AND o.provider = 'payu'
         ORDER BY o.id DESC LIMIT 1"
    );
    $orderSt->execute([$linkId]);
    $boundOrderId = (string)($orderSt->fetchColumn() ?: $providerOrderId);
    if ($boundOrderId === '') {
        throw new RuntimeException('PayU success webhook missing bound order.');
    }
    $result = applyPartnerPaymentReconcile([
        'provider' => 'payu',
        'provider_order_id' => $boundOrderId,
        'provider_payment_id' => $reference,
        'amount' => $amount > 0 ? $amount : null,
        'currency' => 'INR',
        'captured' => true,
        'signature_verified' => true,
        'provider_verified' => true,
        'reference' => $reference,
        'reconcile_source' => 'webhook',
    ]);
    setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) || !empty($result['ignored']) ? 'duplicate' : 'processed');
    logPgWebhook('payu', !empty($result['ok']) ? 'processed' : 'failed', $status, $reference, $linkId, json_encode($result));
    if (!empty($result['ok']) || !empty($result['duplicate']) || !empty($result['ignored'])) {
        markWebhookCompleted((int)$webhookEv['id']);
    } else {
        markWebhookFailed((int)$webhookEv['id'], (string)($result['error'] ?? 'reconcile failed'));
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'result' => $result]);
} catch (Throwable $e) {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
    logPgWebhook('payu', 'failed', $status, $reference, $linkId, json_encode(['error' => $e->getMessage()]));
    markWebhookFailed((int)$webhookEv['id'], $e->getMessage());
    logPlatformError('error', 'PayU success webhook reconcile failed.', ['link_id' => $linkId, 'reference' => $reference]);
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Payment processing failed']);
}
