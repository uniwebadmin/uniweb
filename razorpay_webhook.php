<?php
require_once __DIR__ . '/config.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_POST)) {
    pgWebhookHealthResponse('razorpay');
}

$raw = file_get_contents('php://input') ?: '';
$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!verifyRazorpayWebhookSignature($raw, $signature)) {
    if (financialTablesReady()) {
        registerGatewayEvent('razorpay', $_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '', 'unknown', $raw, false);
    }
    logPgWebhook('razorpay', 'invalid_signature', null, null, null, '');
    jsonResponse(['error' => 'Invalid signature'], 401);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    logPgWebhook('razorpay', 'invalid_json', null, null, null, '');
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

$event = (string)($payload['event'] ?? '');
$entity = $payload['payload']['payment']['entity'] ?? [];
$paymentId = (string)($entity['id'] ?? '');
$refundEntity = $payload['payload']['refund']['entity'] ?? [];
$refundProviderId = (string)($refundEntity['id'] ?? '');
$payoutEntity = $payload['payload']['payout']['entity'] ?? [];
$payoutProviderId = (string)($payoutEntity['id'] ?? '');
$eventReference = $paymentId ?: ($refundProviderId ?: $payoutProviderId);
$eventId = (string)($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? ($event . ':' . $eventReference));
$gatewayEvent = registerGatewayEvent('razorpay', $eventId, $event, $raw, true);
if (!empty($gatewayEvent['duplicate'])) {
    jsonResponse(['ok' => true, 'duplicate' => true]);
}
logPgWebhook('razorpay', 'received', $event, $eventReference, null, '');

if (in_array($event, ['refund.processed', 'refund.failed'], true) && $refundProviderId !== '') {
    try {
        $refundSt = getDB()->prepare("SELECT r.*,t.utr AS payment_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.provider='razorpay' AND r.provider_refund_id=? LIMIT 1");
        $refundSt->execute([$refundProviderId]);
        $refund = $refundSt->fetch();
        if (!$refund) {
            throw new RuntimeException('Razorpay refund is not linked to a local refund request.');
        }
        $providerRefund = fetchRazorpayRefund((string)$refund['payment_id'], $refundProviderId);
        if (!$providerRefund || (string)($providerRefund['id'] ?? '') !== $refundProviderId) {
            throw new RuntimeException('Razorpay refund server verification failed.');
        }
        $providerStatus = strtolower((string)($providerRefund['status'] ?? ''));
        if ($event === 'refund.processed' && $providerStatus === 'processed') {
            $result = completeProviderRefund((string)$refund['refund_id'], $refundProviderId);
        } elseif ($event === 'refund.failed' || $providerStatus === 'failed') {
            getDB()->prepare("UPDATE refunds SET status='failed',provider_status='failed',failure_reason=?,processed_at=NOW() WHERE id=? AND status='pending'")
                ->execute([mb_substr((string)($providerRefund['error_description'] ?? 'Razorpay marked the refund failed.'), 0, 500), (int)$refund['id']]);
            $result = ['ok' => true, 'status' => 'failed'];
        } else {
            $result = ['ok' => true, 'status' => $providerStatus ?: 'pending'];
        }
        setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        logPlatformError('error', 'Razorpay refund webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Refund processing failed'], 422);
    }
}

if (in_array($event, ['payout.processed', 'payout.failed', 'payout.reversed'], true) && $payoutProviderId !== '') {
    try {
        $batchSt = getDB()->prepare('SELECT * FROM settlement_batches WHERE provider_payout_id=? LIMIT 1');
        $batchSt->execute([$payoutProviderId]);
        $batch = $batchSt->fetch();
        if (!$batch) {
            throw new RuntimeException('RazorpayX payout is not linked to a settlement batch.');
        }
        $providerPayout = fetchRazorpayXPayout($payoutProviderId);
        if (!$providerPayout
            || (string)($providerPayout['id'] ?? '') !== $payoutProviderId
            || (string)($providerPayout['reference_id'] ?? '') !== (string)$batch['batch_code']
            || abs(((float)($providerPayout['amount'] ?? 0) / 100) - (float)$batch['net_amount']) > 0.001
        ) {
            throw new RuntimeException('RazorpayX payout server verification mismatch.');
        }
        $providerStatus = strtolower((string)($providerPayout['status'] ?? ''));
        $utr = trim((string)($providerPayout['utr'] ?? ''));
        if ($event === 'payout.processed' && $providerStatus === 'processed' && $utr !== '') {
            getDB()->prepare("UPDATE settlement_batches SET status='settled',api_status='confirmed',provider_status=?,utr=?,processed_at=NOW(),failure_reason=NULL WHERE id=?")
                ->execute([$providerStatus, $utr, (int)$batch['id']]);
            if (!empty($batch['settlement_id'])) {
                getDB()->prepare("UPDATE settlements SET status='completed',utr=?,processed_at=NOW() WHERE settlement_id=?")
                    ->execute([$utr, $batch['settlement_id']]);
            }
            createNotification((int)$batch['merchant_id'], 'Settlement Complete', formatMoney((float)$batch['net_amount']) . ' transferred. UTR: ' . $utr);
            $result = ['ok' => true, 'status' => 'settled', 'utr' => $utr];
        } elseif (in_array($event, ['payout.failed', 'payout.reversed'], true) || in_array($providerStatus, ['failed', 'reversed', 'rejected'], true)) {
            getDB()->prepare("UPDATE settlement_batches SET status='failed',api_status='failed',provider_status=?,failure_reason=?,processed_at=NOW() WHERE id=?")
                ->execute([$providerStatus, mb_substr((string)($providerPayout['failure_reason'] ?? 'Provider payout failed or reversed.'), 0, 500), (int)$batch['id']]);
            if (!empty($batch['settlement_id'])) {
                getDB()->prepare("UPDATE settlements SET status='failed',processed_at=NOW() WHERE settlement_id=?")->execute([$batch['settlement_id']]);
            }
            postMerchantWalletMovement(
                (int)$batch['merchant_id'],
                (float)$batch['net_amount'],
                'payout_reversal',
                'batch:' . $batch['batch_code'],
                'Provider payout failed or reversed'
            );
            $result = ['ok' => true, 'status' => 'failed'];
        } else {
            getDB()->prepare("UPDATE settlement_batches SET provider_status=?,api_status='submitted' WHERE id=?")
                ->execute([$providerStatus, (int)$batch['id']]);
            $result = ['ok' => true, 'status' => $providerStatus ?: 'processing'];
        }
        setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
        jsonResponse(['ok' => true, 'result' => $result]);
    } catch (Throwable $e) {
        setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
        logPlatformError('error', 'RazorpayX payout webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
        jsonResponse(['error' => 'Payout processing failed'], 422);
    }
}

$successEvents = ['payment.captured', 'order.paid'];
if (!in_array($event, $successEvents, true) || $paymentId === '') {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'processed');
    jsonResponse(['ok' => true, 'ignored' => true]);
}

$providerPayment = fetchRazorpayPayment($paymentId);
try {
    if (!$providerPayment
        || (string)($providerPayment['id'] ?? '') !== $paymentId
        || strtolower((string)($providerPayment['status'] ?? '')) !== 'captured'
        || empty($providerPayment['captured'])
        || empty($providerPayment['order_id'])
    ) {
        throw new RuntimeException('Razorpay server verification did not return a captured payment.');
    }
    $result = captureVerifiedPaymentOrder([
        'provider' => 'razorpay',
        'provider_order_id' => (string)$providerPayment['order_id'],
        'provider_payment_id' => $paymentId,
        'amount' => ((float)($providerPayment['amount'] ?? 0)) / 100,
        'currency' => (string)($providerPayment['currency'] ?? ''),
        'captured' => true,
        'signature_verified' => true,
        'provider_verified' => true,
        'reference' => $paymentId,
    ]);
    setGatewayEventStatus((int)$gatewayEvent['id'], !empty($result['duplicate']) ? 'duplicate' : 'processed');
    logPgWebhook('razorpay', 'processed', $event, $paymentId, null, json_encode(['transaction_id' => $result['transaction_id'] ?? null]));
} catch (Throwable $e) {
    setGatewayEventStatus((int)$gatewayEvent['id'], 'failed', null, $e->getMessage());
    logPgWebhook('razorpay', 'failed', $event, $paymentId, null, json_encode(['error' => $e->getMessage()]));
    logPlatformError('error', 'Razorpay webhook processing failed.', ['event_id' => $eventId, 'error' => $e->getMessage()]);
    jsonResponse(['error' => 'Processing failed'], 422);
}

jsonResponse(['ok' => true, 'result' => $result]);
