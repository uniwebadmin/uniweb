<?php
require_once __DIR__ . '/config.php';

$post = array_merge($_GET, $_POST);
$linkId = $post['udf1'] ?? '';
$status = strtolower($post['status'] ?? $_GET['status'] ?? '');

if (!$linkId) {
    flash('error', 'Invalid payment response.');
    redirect('index.php');
}

$db = getDB();
$stmt = $db->prepare("SELECT pl.*, m.id AS merchant_id, m.business_name, m.commission_rate, m.collection_mode, m.account_mode, m.kyc_status FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ?");
$stmt->execute([$linkId]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    $pageTitle = 'Payment link not found';
    $hideNav = true;
    require_once __DIR__ . '/header.php';
    echo '<div class="min-h-screen flex items-center justify-center px-4 py-12 bg-dark-950">'
        . '<div class="glass rounded-2xl p-8 text-center max-w-md w-full border border-red-500/30">'
        . '<h2 class="text-xl font-bold mb-2">Payment link not found</h2>'
        . '<p class="text-gray-400 text-sm mb-6">We could not match this return to a valid payment link. If money was debited it will auto-reconcile from the signed webhook; contact support with your bank reference if needed.</p>'
        . '<a href="index.php" class="inline-block btn-primary px-6 py-2 text-sm">Go to UniWeb</a>'
        . ' <a href="contact.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Contact support</a>'
        . '</div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$verified = verifyPayUResponseHash($post);
$success = $verified && in_array($status, ['success', 'successful'], true);

if ($success) {
    $mihpayid = $post['mihpayid'] ?? $post['txnid'] ?? '';
    $dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
    $dup->execute([$mihpayid]);
    if (!$dup->fetch()) {
        createTransactionFromPayment($link, 'payu', 'success', $mihpayid, merchantAccountMode($link) === 'test');
        finalizePaymentLink((int)$link['id'], (int)$link['merchant_id'], (float)$link['amount'], formatMoney((float)$link['amount']) . ' received.');
    }
    $pageTitle = 'Payment Successful';
} else {
    $pageTitle = 'Payment Failed';
}

$hideNav = true;
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/includes/checkout_mode_banner.php';
renderCheckoutModeBanner($link);
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12 bg-dark-950">
    <div class="glass rounded-2xl p-8 text-center max-w-md w-full border <?= $success ? 'border-emerald-500/30' : 'border-red-500/30' ?>">
        <?php $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo.php'; ?>
        <h2 class="text-xl font-bold mt-6 mb-2"><?= $success ? 'Payment Successful!' : 'Payment Failed' ?></h2>
        <?php if ($success): ?>
        <p class="text-3xl font-bold text-sky-400 my-3"><?= formatMoney((float)$link['amount']) ?></p>
        <p class="text-gray-400 text-sm">Reference: <?= e($post['mihpayid'] ?? '') ?></p>
        <p class="text-xs text-gray-500 mt-4">Settlement initiated — merchant share sent directly.</p>
        <?php else: ?>
        <p class="text-gray-400 text-sm mt-4">Payment was not completed. Please try again.</p>
        <a href="checkout.php?link=<?= e($linkId) ?>" class="inline-block mt-6 btn-primary px-6 py-2">Retry Payment</a>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
