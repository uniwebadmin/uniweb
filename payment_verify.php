<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$paymentId = $_POST['razorpay_payment_id'] ?? '';
$orderId = $_POST['razorpay_order_id'] ?? '';
$signature = $_POST['razorpay_signature'] ?? '';

if (!$paymentId || !$orderId || !$signature || !verifyRazorpayPayment($orderId, $paymentId, $signature)) {
    flash('error', 'Payment verification failed.');
    redirect('index.php');
}

$providerPayment = fetchRazorpayPayment($paymentId);
if (!$providerPayment
    || (string)($providerPayment['order_id'] ?? '') !== $orderId
    || strtolower((string)($providerPayment['status'] ?? '')) !== 'captured'
    || empty($providerPayment['captured'])
) {
    flash('error', 'Payment could not be confirmed yet.');
    redirect('index.php');
}

$event = registerGatewayEvent('razorpay', 'return:' . $paymentId, 'checkout.return', json_encode($providerPayment), true);
try {
    $result = captureVerifiedPaymentOrder([
        'provider' => 'razorpay',
        'provider_order_id' => $orderId,
        'provider_payment_id' => $paymentId,
        'amount' => ((float)($providerPayment['amount'] ?? 0)) / 100,
        'currency' => (string)($providerPayment['currency'] ?? ''),
        'captured' => true,
        'signature_verified' => true,
        'provider_verified' => true,
        'reference' => $paymentId,
    ]);
    setGatewayEventStatus((int)$event['id'], !empty($result['duplicate']) ? 'duplicate' : 'processed');
} catch (Throwable $e) {
    setGatewayEventStatus((int)$event['id'], 'failed', null, $e->getMessage());
    logPlatformError('error', 'Razorpay return verification failed.', ['order_id' => $orderId, 'error' => $e->getMessage()]);
    flash('error', 'Payment could not be verified against the original order. Support has been notified.');
    redirect('index.php');
}

$stmt = getDB()->prepare('SELECT o.expected_amount AS amount, pl.link_id, pl.description, m.business_name, m.collection_mode FROM payment_orders o JOIN payment_links pl ON pl.id=o.payment_link_id JOIN merchants m ON m.id=o.merchant_id WHERE o.provider=? AND o.provider_order_id=?');
$stmt->execute(['razorpay', $orderId]);
$link = $stmt->fetch();

$pageTitle = 'Payment Successful — ' . APP_NAME;
$hideNav = true;
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/includes/checkout_mode_banner.php';
$rzLink = is_array($link) ? $link : [];
if ($link && !isset($rzLink['is_test']) && !empty($link['link_id'])) {
    $pl = getDB()->prepare('SELECT is_test FROM payment_links WHERE link_id=?');
    $pl->execute([$link['link_id']]);
    $rzLink['is_test'] = (int)$pl->fetchColumn();
}
renderCheckoutModeBanner($rzLink ?: null);
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
        <p class="text-gray-500 text-xs mt-4">Thank you for paying via <?= APP_NAME ?></p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
