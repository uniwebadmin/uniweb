<?php
declare(strict_types=1);

function isValidUpiReference(string $utr): bool
{
    $utr = strtoupper(trim($utr));
    if (strlen($utr) < 10 || strlen($utr) > 22) {
        return false;
    }
    return (bool)preg_match('/^[A-Z0-9]+$/', $utr);
}

function confirmUpiPaymentForLink(array $link, string $utr, bool $isTestCheckout): array
{
    ensureWalletEngine();
    $db = getDB();
    $payAmount = sanitizePaymentAmount((float)($link['amount'] ?? 0), $isTestCheckout);
    $utr = strtoupper(trim($utr));

    if (!isValidUpiReference($utr)) {
        return ['ok' => false, 'error' => 'Please enter a valid UPI reference (10–22 alphanumeric characters).'];
    }

    $dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
    $dup->execute([$utr]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'This UPI reference was already used.'];
    }

    if (($link['link_status'] ?? $link['status'] ?? '') === 'paid') {
        return ['ok' => true, 'duplicate' => true, 'message' => 'Payment already confirmed.'];
    }

    if (!$isTestCheckout) {
        return [
            'ok' => false,
            'error' => 'Manual UPI reference confirmation is disabled in Live Mode. Payment will update only after verified bank or gateway confirmation.',
        ];
    }

    $order = createBoundPaymentOrder($link, 'sandbox', 'test-upi:' . $utr);
    bindProviderOrder((int)$order['id'], 'sandbox', (string)$order['order_ref']);
    $result = captureVerifiedPaymentOrder([
        'provider' => 'sandbox',
        'provider_order_id' => (string)$order['order_ref'],
        'provider_payment_id' => 'sandbox_' . $utr,
        'amount' => $payAmount,
        'currency' => 'INR',
        'captured' => true,
        'signature_verified' => true,
        'provider_verified' => true,
        'reference' => $utr,
    ]);
    $txnId = null;
    if (!empty($result['transaction_id'])) {
        $st = $db->prepare('SELECT txn_id FROM transactions WHERE id=?');
        $st->execute([(int)$result['transaction_id']]);
        $txnId = $st->fetchColumn() ?: null;
    }

    return ['ok' => true, 'txn_id' => $txnId ?: null];
}

function decentroSandboxCheckoutAvailable(array $link): bool
{
    if (!function_exists('isDecentroSandboxEnvironment') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    return paymentModeForLink($link) === 'test'
        && isDecentroSandboxEnvironment()
        && isGatewayConfigured('decentro')
        && decentroConsumerUrn() !== '';
}

function createDecentroSandboxCheckoutQr(array $link): array
{
    $linkId = (string)($link['link_id'] ?? '');
    $cached = $_SESSION['_decentro_checkout_qr'][$linkId] ?? null;
    if (is_array($cached) && (int)($cached['expires_at'] ?? 0) > time() && !empty($cached['image_url']) && !empty($cached['decentro_txn_id'])) {
        return $cached;
    }
    if (!decentroSandboxCheckoutAvailable($link)) {
        return ['ok' => false, 'error' => 'Decentro sandbox QR is unavailable for this payment link.'];
    }

    try {
        $order = createBoundPaymentOrder($link, 'decentro', 'decentro_qr:' . generateId());
        $response = createDecentroDynamicQr(
            (float)$order['expected_amount'],
            (string)($order['description'] ?: 'UniWeb payment'),
            (string)$order['order_ref'],
            10
        );
        if (!is_array($response)) {
            return ['ok' => false, 'error' => 'Decentro could not create a payment QR.'];
        }
        $txnId = trim((string)($response['decentro_txn_id'] ?? ''));
        $imageUrl = trim((string)($response['data']['dynamic_qr_image'] ?? ''));
        if (($response['api_status'] ?? '') !== 'SUCCESS' || $txnId === '' || !filter_var($imageUrl, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($imageUrl), 'https://')) {
            return ['ok' => false, 'error' => 'Decentro could not create a payment QR.'];
        }
        bindProviderOrder((int)$order['id'], 'decentro', $txnId);
        $result = ['ok' => true, 'image_url' => $imageUrl, 'decentro_txn_id' => $txnId, 'expires_at' => time() + 600];
        $_SESSION['_decentro_checkout_qr'][$linkId] = $result;
        return $result;
    } catch (Throwable $e) {
        logPlatformError('error', 'Decentro checkout QR creation failed.', ['link_id' => $link['link_id'] ?? '', 'error' => $e->getMessage()]);
        return ['ok' => false, 'error' => 'Decentro QR is temporarily unavailable. Please refresh and try again.'];
    }
}

function syncDecentroCheckoutPayment(string $linkId): array
{
    $db = getDB();
    $orders = $db->prepare("SELECT o.id, o.provider_order_id, o.expected_amount, o.currency
        FROM payment_orders o
        JOIN payment_links pl ON pl.id=o.payment_link_id
        WHERE pl.link_id=? AND o.provider='decentro' AND o.status IN ('created','pending','authorized')
        ORDER BY o.id DESC LIMIT 10");
    $orders->execute([$linkId]);

    foreach ($orders->fetchAll() as $order) {
        $providerOrderId = trim((string)$order['provider_order_id']);
        if ($providerOrderId === '') {
            continue;
        }
        $response = fetchDecentroTransactionStatus($providerOrderId);
        if (!is_array($response) || ($response['api_status'] ?? '') !== 'SUCCESS') {
            continue;
        }
        $description = is_array($response['data']['transaction_description'] ?? null) ? $response['data']['transaction_description'] : [];
        if (strtoupper((string)($description['transaction_status'] ?? '')) !== 'SUCCESS') {
            continue;
        }
        $reportedAmount = $description['transaction_amount'] ?? null;
        if ($reportedAmount !== null && (!is_numeric($reportedAmount) || abs((float)$reportedAmount - (float)$order['expected_amount']) > 0.001)) {
            logPlatformError('error', 'Decentro checkout amount mismatch.', ['link_id' => $linkId, 'order_id' => (int)$order['id']]);
            continue;
        }
        $providerPaymentId = trim((string)($description['bank_reference_number'] ?? $description['npci_txn_id'] ?? $providerOrderId));
        if ($providerPaymentId === '') {
            $providerPaymentId = $providerOrderId;
        }
        try {
            $captured = captureVerifiedPaymentOrder([
                'provider' => 'decentro',
                'provider_order_id' => $providerOrderId,
                'provider_payment_id' => $providerPaymentId,
                'amount' => (float)$order['expected_amount'],
                'currency' => (string)$order['currency'],
                'captured' => true,
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => (string)($description['reference_id'] ?? $providerOrderId),
            ]);
            return ['ok' => true, 'paid' => true, 'transaction_id' => (int)($captured['transaction_id'] ?? 0)];
        } catch (Throwable $e) {
            logPlatformError('error', 'Decentro checkout capture failed.', ['link_id' => $linkId, 'order_id' => (int)$order['id'], 'error' => $e->getMessage()]);
        }
    }

    return ['ok' => true, 'paid' => false];
}

function getCheckoutPaymentStatus(string $linkId): array
{
    $db = getDB();
    $st = $db->prepare('SELECT pl.id, pl.link_id, pl.status, pl.amount, pl.is_test FROM payment_links pl WHERE pl.link_id = ?');
    $st->execute([$linkId]);
    $link = $st->fetch();
    if (!$link) {
        return ['ok' => false, 'paid' => false];
    }
    if ($link['status'] !== 'paid') {
        $synced = syncDecentroCheckoutPayment($linkId);
        if (!empty($synced['paid'])) {
            $link['status'] = 'paid';
        }
    }
    if ($link['status'] === 'paid') {
        $txn = $db->prepare('SELECT txn_id, utr, amount FROM transactions WHERE payment_link_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
        $txn->execute([(int)$link['id'], 'success']);
        $t = $txn->fetch();
        return ['ok' => true, 'paid' => true, 'txn_id' => $t['txn_id'] ?? null, 'utr' => $t['utr'] ?? null];
    }
    return ['ok' => true, 'paid' => false, 'status' => $link['status']];
}
