<?php
/** Reusable trust / compliance strip for public pages and checkout */
$activePg = getSetting('active_payment_gateway', 'razorpay');
$trustPartners = array_values(array_filter([
    isGatewayConfigured('razorpay') ? 'Razorpay' : null,
    isGatewayConfigured('cashfree') ? 'Cashfree' : null,
    isGatewayConfigured('payu') ? 'PayU' : null,
    isGatewayConfigured('axis') ? 'Axis Bank' : null,
    'UPI',
]));
if (count($trustPartners) < 2) {
    $trustPartners = ['Razorpay', 'Cashfree', 'PayU', 'Axis Bank', 'UPI'];
}
$badges = ['256-bit SSL', 'PCI DSS Ready', 'RBI UPI 0% MDR', 'GST Registered', 'T+1 Settlement', 'Signed Webhooks'];
if ((int)getSetting('whatsapp_enabled', '0') === 1) {
    $badges[] = 'WhatsApp OTP';
}
if (isGatewayConfigured($activePg)) {
    $badges[] = ucfirst($activePg) . ' Live';
}
?>
<div class="trust-strip">
    <div class="flex flex-wrap justify-center gap-3 mb-4">
        <?php foreach ($badges as $badge): ?>
        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-[11px] font-medium bg-white/5 border border-gray-700 text-gray-300">
            <svg class="w-3 h-3 text-emerald-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?= e($badge) ?>
        </span>
        <?php endforeach; ?>
    </div>
    <div class="flex flex-wrap justify-center items-center gap-4 sm:gap-6 opacity-80">
        <?php foreach ($trustPartners as $p): ?>
        <span class="text-xs sm:text-sm font-bold tracking-wide text-gray-400 uppercase"><?= e($p) ?></span>
        <?php endforeach; ?>
    </div>
</div>
