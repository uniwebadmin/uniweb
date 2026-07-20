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

function getCheckoutPaymentStatus(string $linkId): array
{
    $db = getDB();
    $st = $db->prepare('SELECT pl.id, pl.link_id, pl.status, pl.amount, pl.is_test FROM payment_links pl WHERE pl.link_id = ?');
    $st->execute([$linkId]);
    $link = $st->fetch();
    if (!$link) {
        return ['ok' => false, 'paid' => false];
    }
    if ($link['status'] === 'paid') {
        $txn = $db->prepare('SELECT txn_id, utr, amount FROM transactions WHERE payment_link_id = ? AND status = ? ORDER BY id DESC LIMIT 1');
        $txn->execute([(int)$link['id'], 'success']);
        $t = $txn->fetch();
        return ['ok' => true, 'paid' => true, 'txn_id' => $t['txn_id'] ?? null, 'utr' => $t['utr'] ?? null];
    }
    return ['ok' => true, 'paid' => false, 'status' => $link['status']];
}
