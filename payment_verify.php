<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$paymentId = $_POST['razorpay_payment_id'] ?? '';
$orderId = $_POST['razorpay_order_id'] ?? '';
$signature = $_POST['razorpay_signature'] ?? '';
$linkId = $_POST['link_id'] ?? '';

if (!$paymentId || !$orderId || !$signature || !verifyRazorpayPayment($orderId, $paymentId, $signature)) {
    flash('error', 'Payment verification failed.');
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

$dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
$dup->execute([$paymentId]);
if (!$dup->fetch()) {
    $method = getMerchantCollectionMode($link) === 'razorpay_route' ? 'razorpay_route' : 'razorpay';
    createTransactionFromPayment($link, $method, 'success', $paymentId, merchantAccountMode($link) === 'test');
    finalizePaymentLink((int)$link['id'], (int)$link['merchant_id'], (float)$link['amount'], formatMoney((float)$link['amount']) . ' received via Razorpay. Ref: ' . $paymentId);
}

$pageTitle = 'Payment Successful — ' . APP_NAME;
$hideNav = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12 bg-dark-950">
    <div class="glass rounded-2xl p-8 text-center max-w-md w-full border border-brand-500/20">
        <div class="flex items-center justify-center mb-6">
            <?php $logoHref = 'index.php'; $logoSize = 'md'; $showWordmark = true; require __DIR__ . '/includes/brand_logo.php'; ?>
        </div>
        <div class="w-16 h-16 bg-brand-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h2 class="text-xl font-bold mb-2">Payment Successful!</h2>
        <p class="text-3xl font-bold text-brand-400 my-3"><?= formatMoney((float)$link['amount']) ?></p>
        <p class="text-gray-400 text-sm">Payment ID: <?= e($paymentId) ?></p>
        <?php if (getMerchantCollectionMode($link) === 'razorpay_route'): ?>
        <p class="text-xs text-gray-500 mt-4">Route split — merchant share transferred automatically.</p>
        <?php endif; ?>
        <p class="text-gray-500 text-xs mt-4">Thank you for paying via <?= APP_NAME ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
