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

    $handler = resolveCheckoutHandlerForLink($link);
    $status = 'success';
    if (!$isTestCheckout && $handler === 'direct_upi' && !isGatewayConfigured('axis')) {
        $status = 'success';
    }

    createTransactionFromPayment($link, 'upi_p2m', $status, $utr, $isTestCheckout);
    finalizePaymentLink((int)$link['id'], (int)$link['merchant_id'], $payAmount, formatMoney($payAmount) . ' UPI payment confirmed. Ref: ' . $utr);

    $st = $db->prepare('SELECT txn_id FROM transactions WHERE payment_link_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([(int)$link['id']]);
    $txnId = $st->fetchColumn();

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
