<?php
require_once __DIR__ . '/config.php';
if (!function_exists('applyPartnerPaymentReconcile') && is_file(__DIR__ . '/includes/payment_reconcile.php')) {
    require_once __DIR__ . '/includes/payment_reconcile.php';
}

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

if ($success && function_exists('applyPartnerPaymentReconcile')) {
    $mihpayid = (string)($post['mihpayid'] ?? $post['txnid'] ?? '');
    $providerOrderId = (string)($post['txnid'] ?? $mihpayid);
    $orderSt = $db->prepare(
        "SELECT o.provider_order_id FROM payment_orders o
         JOIN payment_links pl ON pl.id=o.payment_link_id
         WHERE pl.link_id=? AND o.provider='payu'
         ORDER BY o.id DESC LIMIT 1"
    );
    $orderSt->execute([$linkId]);
    $boundOrderId = (string)($orderSt->fetchColumn() ?: $providerOrderId);
    if ($boundOrderId !== '' && $mihpayid !== '') {
        try {
            $event = registerGatewayEvent('payu', 'return:' . $mihpayid, 'checkout.return', json_encode($post), true);
            applyPartnerPaymentReconcile([
                'provider' => 'payu',
                'provider_order_id' => $boundOrderId,
                'provider_payment_id' => $mihpayid,
                'amount' => (float)($post['amount'] ?? $link['amount'] ?? 0),
                'currency' => 'INR',
                'captured' => true,
                'signature_verified' => true,
                'provider_verified' => true,
                'reference' => $mihpayid,
                'reconcile_source' => 'checkout',
            ]);
            setGatewayEventStatus((int)$event['id'], 'processed');
        } catch (Throwable $e) {
            logPlatformError('error', 'PayU checkout return reconcile failed.', ['link_id' => $linkId, 'error' => $e->getMessage()]);
        }
    }
    $stmt->execute([$linkId]);
    $link = $stmt->fetch();
    $pageTitle = 'Payment Successful';
} elseif ($success) {
    $mihpayid = $post['mihpayid'] ?? $post['txnid'] ?? '';
    $dup = $db->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
    $dup->execute([$mihpayid]);
    if (!$dup->fetch()) {
        createTransactionFromPayment($link, 'payu', 'success', $mihpayid, merchantAccountMode($link) === 'test');
        getDB()->prepare("UPDATE payment_links SET status='paid', paid_at=NOW() WHERE id=?")->execute([(int)$link['id']]);
    }
    $pageTitle = 'Payment Successful';
} else {
    $pageTitle = 'Payment Failed';
}

$hideNav = true;
$hideFooter = true;
$footerVariant = 'checkout';
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/includes/checkout_mode_banner.php';
renderCheckoutModeBanner($link);
?>
<div class="min-h-screen flex flex-col bg-dark-950">
<div class="flex-1 flex items-center justify-center px-4 py-12">
    <div class="glass rounded-2xl p-8 text-center max-w-md w-full border <?= $success ? 'border-emerald-500/30' : 'border-red-500/30' ?>">
        <?php $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo_safe.php'; ?>
        <h2 class="text-xl font-bold mt-6 mb-2"><?= $success ? 'Payment Successful!' : 'Payment Failed' ?></h2>
        <?php if ($success): ?>
        <p class="text-3xl font-bold text-sky-400 my-3"><?= formatMoney((float)$link['amount']) ?></p>
        <p class="text-gray-400 text-sm">Reference: <?= e($post['mihpayid'] ?? '') ?></p>
        <p class="text-xs text-gray-500 mt-4">Payment recorded. Merchant settlement follows your UniWeb schedule — not live marketplace Route/Easy Split unless Admin has enabled it.</p>
        <?php else: ?>
        <p class="text-gray-400 text-sm mt-4">Payment was not completed. Please try again.</p>
        <a href="checkout.php?link=<?= e($linkId) ?>" class="inline-block mt-6 btn-primary px-6 py-2">Retry Payment</a>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/checkout_footer.php'; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
