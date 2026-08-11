<?php
/** Reusable trust / compliance strip for public pages and checkout */
if (!function_exists('getPublicLivePartners')) {
    require_once __DIR__ . '/partner_control.php';
}
$livePartners = getPublicLivePartners();
$badges = ['HTTPS Transport', 'Secure Sessions', 'GST Registered', 'Signed Provider Webhooks', 'Test / Live Separation'];
if ((int)getSetting('whatsapp_enabled', '0') === 1) {
    $badges[] = 'WhatsApp OTP';
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
    <?php if ($livePartners): ?><div class="flex flex-wrap justify-center items-center gap-4 sm:gap-6 opacity-80">
        <?php foreach ($livePartners as $p): ?>
        <span class="text-xs sm:text-sm font-bold tracking-wide text-gray-400 uppercase"><?= e($p['icon']) ?> <?= e($p['name']) ?></span>
        <?php endforeach; ?>
    </div><?php else: ?>
    <p class="text-center text-xs text-gray-600">Payment partners onboarding</p>
    <?php endif; ?>
</div>
