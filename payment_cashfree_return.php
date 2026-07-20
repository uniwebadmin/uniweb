<?php
require_once __DIR__ . '/config.php';

$orderId = $_GET['order_id'] ?? '';

if (!$orderId) {
    flash('error', 'Invalid payment return.');
    redirect('index.php');
}

$cfOrder = fetchCashfreeOrder($orderId);
$stmt = getDB()->prepare(
    'SELECT o.*, pl.link_id, pl.description, m.business_name
     FROM payment_orders o JOIN payment_links pl ON pl.id=o.payment_link_id JOIN merchants m ON m.id=o.merchant_id
     WHERE o.provider=? AND o.provider_order_id=? LIMIT 1'
);
$stmt->execute(['cashfree', $orderId]);
$link = $stmt->fetch();
if (!$link || !$cfOrder
    || (string)($cfOrder['order_id'] ?? '') !== $orderId
    || abs((float)($cfOrder['order_amount'] ?? 0) - (float)$link['expected_amount']) > 0.001
    || strtoupper((string)($cfOrder['order_currency'] ?? '')) !== (string)$link['currency']
) {
    flash('error', 'Cashfree return could not be matched to the original order.');
    redirect('index.php');
}
$isConfirmed = $link['status'] === 'paid';
$providerPaid = strtoupper((string)($cfOrder['order_status'] ?? '')) === 'PAID';

$pageTitle = $isConfirmed ? 'Payment Success' : 'Payment Verification';
$hideNav = true;
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/includes/checkout_mode_banner.php';
$cfLink = ['is_test' => !empty($link['mode']) && (string)$link['mode'] === 'test'];
renderCheckoutModeBanner($cfLink);
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="glass rounded-2xl p-8 text-center max-w-md w-full">
        <div class="w-16 h-16 <?= $isConfirmed ? 'bg-brand-500/20' : 'bg-amber-500/20' ?> rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-8 h-8 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <h2 class="text-xl font-bold mb-2"><?= $isConfirmed ? 'Payment Successful!' : ($providerPaid ? 'Verification in progress' : 'Payment not completed') ?></h2>
        <p class="text-3xl font-bold text-brand-400 my-3"><?= formatMoney((float)$link['expected_amount']) ?></p>
        <p class="text-gray-500 text-xs mt-2">Cashfree Order: <?= e($orderId) ?></p>
        <?php if (!$isConfirmed): ?><p class="text-xs text-amber-300 mt-4">UniWeb is waiting for Cashfree’s signed webhook before changing the payment or wallet status.</p><?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
