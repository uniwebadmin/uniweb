<?php
require_once __DIR__ . '/config.php';

$orderId = $_GET['order_id'] ?? '';
$linkId = $_GET['link_id'] ?? '';

if (!$orderId || !$linkId) {
    flash('error', 'Invalid payment return.');
    redirect('index.php');
}

$cfOrder = fetchCashfreeOrder($orderId);
if (!$cfOrder || ($cfOrder['order_status'] ?? '') !== 'PAID') {
    flash('error', 'Payment not completed. Status: ' . ($cfOrder['order_status'] ?? 'unknown'));
    redirect('checkout.php?link=' . urlencode($linkId));
}

$db = getDB();
$stmt = $db->prepare("SELECT pl.*, m.id AS merchant_id, m.commission_rate, m.collection_mode, m.business_name, m.account_mode
    FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ?");
$stmt->execute([$linkId]);
$link = $stmt->fetch();
if (!$link) {
    die('Invalid payment link.');
}

$exists = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
$exists->execute([$orderId]);
if (!$exists->fetch()) {
    $method = getMerchantCollectionMode($link) === 'cashfree_route' ? 'cashfree_route' : 'cashfree';
    createTransactionFromPayment($link, $method, 'success', $orderId, merchantAccountMode($link) === 'test');
    finalizePaymentLink((int)$link['id'], (int)$link['merchant_id'], (float)$link['amount'], formatMoney((float)$link['amount']) . ' received via Cashfree. Order: ' . $orderId);
}

$pageTitle = 'Payment Success';
$hideNav = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="glass rounded-2xl p-8 text-center max-w-md w-full">
        <div class="w-16 h-16 bg-brand-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h2 class="text-xl font-bold mb-2">Payment Successful!</h2>
        <p class="text-3xl font-bold text-brand-400 my-3"><?= formatMoney((float)$link['amount']) ?></p>
        <p class="text-gray-500 text-xs mt-2">Cashfree Order: <?= e($orderId) ?></p>
        <?php if (getMerchantCollectionMode($link) === 'cashfree_route'): ?>
        <p class="text-xs text-gray-500 mt-4">Easy Split — vendor payout initiated.</p>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
