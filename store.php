<?php
require_once __DIR__ . '/config.php';

$slug = strtolower(trim((string)($_GET['s'] ?? '')));
// Bare / invalid hub → home. Published storefront ?s=slug stays (merchant sales page).
if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug) || !merchantStorefrontTableAvailable()) {
    redirect('index.php');
}

$stmt = getDB()->prepare('SELECT sf.*, m.id AS merchant_id, m.business_name, m.name, m.email, m.phone FROM merchant_storefronts sf JOIN merchants m ON m.id=sf.merchant_id WHERE sf.storefront_slug=? AND sf.is_published=1 AND m.status != \'deleted\' LIMIT 1');
$stmt->execute([$slug]);
$store = $stmt->fetch();
if (!$store) {
    redirect('index.php');
}

$linkStmt = getDB()->prepare("SELECT link_id, amount, description FROM payment_links WHERE merchant_id=? AND status='active' AND is_test=0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 12");
$linkStmt->execute([(int)$store['merchant_id']]);
$products = $linkStmt->fetchAll() ?: [];
$template = (string)($store['template_key'] ?? 'services');
$businessName = (string)($store['business_name'] ?: $store['name']);
$logoUrl = trim((string)($store['logo_url'] ?? ''));
$initial = mb_strtoupper(mb_substr($businessName !== '' ? $businessName : 'U', 0, 1));

$themes = [
    'retail' => [
        'accent' => 'from-orange-600 to-amber-500',
        'accent_soft' => 'from-orange-500/15 to-amber-500/5',
        'chip' => 'bg-orange-500/10 text-orange-300 border-orange-500/20',
        'btn' => 'bg-orange-500 hover:bg-orange-400',
        'ring' => 'ring-orange-500/25',
        'label' => 'Retail Store',
        'section_label' => 'Products',
        'layout' => 'grid',
    ],
    'invoice' => [
        'accent' => 'from-sky-600 to-indigo-500',
        'accent_soft' => 'from-sky-500/15 to-indigo-500/5',
        'chip' => 'bg-sky-500/10 text-sky-300 border-sky-500/20',
        'btn' => 'bg-sky-500 hover:bg-sky-400',
        'ring' => 'ring-sky-500/25',
        'label' => 'Bookings & Invoices',
        'section_label' => 'Pay an invoice or booking',
        'layout' => 'list',
    ],
    'services' => [
        'accent' => 'from-violet-600 to-fuchsia-500',
        'accent_soft' => 'from-violet-500/15 to-fuchsia-500/5',
        'chip' => 'bg-violet-500/10 text-violet-300 border-violet-500/20',
        'btn' => 'bg-violet-600 hover:bg-violet-500',
        'ring' => 'ring-violet-500/25',
        'label' => 'Services',
        'section_label' => 'Our services',
        'layout' => 'list',
    ],
];
$theme = $themes[$template] ?? $themes['services'];

$contactText = trim((string)($store['contact_text'] ?? ''));
$contactDigits = preg_replace('/\D/', '', $contactText);
$whatsappUrl = (strlen((string)$contactDigits) >= 10)
    ? 'https://wa.me/' . (strlen($contactDigits) === 10 ? '91' . $contactDigits : $contactDigits) . '?text=' . rawurlencode('Hi ' . $businessName . ', I found your page on UniWeb.')
    : null;

$pageTitle = $businessName . ' — Pay online';
require_once __DIR__ . '/header.php';
?>

<main class="min-h-[75vh] bg-dark-950">
    <!-- Cover -->
    <div class="h-36 sm:h-48 bg-gradient-to-br <?= $theme['accent'] ?> relative overflow-hidden">
        <div class="absolute inset-0 opacity-[0.07]" style="background-image:radial-gradient(circle at 20% 30%,#fff 0,transparent 45%),radial-gradient(circle at 80% 70%,#fff 0,transparent 45%);"></div>
    </div>

    <div class="max-w-3xl mx-auto px-4 sm:px-6 -mt-14 sm:-mt-16 pb-16">
        <div class="bg-dark-900 border border-gray-800 rounded-2xl shadow-xl shadow-black/40">
            <div class="p-6 sm:p-9">

                <!-- Identity -->
                <div class="flex items-end gap-4">
                    <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($businessName) ?>" class="w-20 h-20 rounded-2xl object-cover bg-dark-900 border-4 border-dark-900 ring-1 ring-gray-800 shrink-0 -mt-16 shadow-lg" loading="lazy">
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br <?= $theme['accent'] ?> border-4 border-dark-900 flex items-center justify-center text-3xl font-bold text-white shrink-0 -mt-16 shadow-lg"><?= e($initial) ?></div>
                    <?php endif; ?>
                    <div class="pb-0.5 min-w-0">
                        <div class="flex items-center gap-1.5">
                            <h1 class="text-xl sm:text-2xl font-bold text-white truncate"><?= e($businessName) ?></h1>
                            <svg class="w-[18px] h-[18px] text-sky-400 shrink-0" viewBox="0 0 24 24" fill="currentColor" aria-label="Verified on UniWeb"><path d="M12 1.5l2.6 1.3 2.9-.3 1.3 2.6 2.6 1.3-.3 2.9 1.3 2.6-1.9 2.2.3 2.9-2.9.3-1.9 2.2-2.6-1.3-2.9.3-1.3-2.6-2.6-1.3.3-2.9-1.3-2.6 1.9-2.2-.3-2.9 2.9-.3z"/><path d="M9.5 12.5l1.8 1.8 3.2-4" stroke="#0f172a" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </div>
                        <span class="inline-flex items-center gap-1 mt-1.5 text-[11px] font-semibold px-2 py-0.5 rounded-md border <?= $theme['chip'] ?>"><?= e($theme['label']) ?></span>
                    </div>
                </div>

                <h2 class="text-2xl sm:text-[28px] font-bold text-white mt-6 leading-snug tracking-tight"><?= e($store['headline']) ?></h2>
                <p class="text-[15px] text-gray-400 mt-3 leading-relaxed whitespace-pre-line max-w-2xl"><?= e($store['description']) ?></p>

                <?php if ($contactText !== '' || $whatsappUrl): ?>
                <div class="mt-5 flex flex-wrap items-center gap-2.5">
                    <?php if ($contactText !== ''): ?>
                    <span class="inline-flex items-center gap-1.5 text-xs text-gray-400 bg-dark-800/70 border border-gray-800 rounded-lg px-3 py-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h2.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                        <?= e($contactText) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($whatsappUrl): ?>
                    <a href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-300 bg-emerald-500/10 border border-emerald-500/25 hover:bg-emerald-500/15 rounded-lg px-3 py-1.5 transition">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.46 1.32 4.97L2 22l5.28-1.39a9.9 9.9 0 004.76 1.21h.005c5.46 0 9.9-4.45 9.9-9.91C21.96 6.45 17.5 2 12.04 2z"/></svg>
                        WhatsApp
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="my-7 border-t border-gray-800"></div>

                <!-- Products -->
                <?php if ($products): ?>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <h3 class="text-[13px] font-semibold uppercase tracking-wider text-gray-500"><?= e($theme['section_label']) ?></h3>
                    <span class="text-[11px] text-gray-600"><?= count($products) ?> <?= count($products) === 1 ? 'item' : 'items' ?></span>
                </div>
                <div class="grid <?= $theme['layout'] === 'grid' ? 'sm:grid-cols-2' : 'sm:grid-cols-1' ?> gap-3">
                    <?php foreach ($products as $product): $productUrl = buildPaymentLinkUrl((string)$product['link_id']); ?>
                    <article class="group flex items-center gap-4 rounded-xl border border-gray-800 bg-gradient-to-br <?= $theme['accent_soft'] ?> hover:border-gray-700 transition p-4">
                        <div class="w-11 h-11 rounded-lg bg-dark-900/60 border border-gray-800 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-semibold text-white text-sm leading-snug truncate"><?= e($product['description'] ?: 'Pay securely') ?></h4>
                            <p class="text-lg font-bold text-white mt-0.5"><?= formatMoney((float)$product['amount']) ?></p>
                        </div>
                        <a href="<?= e($productUrl) ?>" class="<?= $theme['btn'] ?> text-white font-semibold px-4 py-2 rounded-lg text-center text-sm shrink-0 transition">Pay</a>
                    </article>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-5 text-sm text-amber-200/90">Online payment is temporarily unavailable. Please contact the business directly.</div>
                <?php endif; ?>

                <!-- Trust bar -->
                <div class="mt-8 pt-6 border-t border-gray-800 grid grid-cols-3 gap-2 text-center">
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <span class="text-[10.5px] text-gray-500 leading-tight">Secured by<br>UniWeb</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 10h18M3 6h18v12H3V6z"/></svg>
                        <span class="text-[10.5px] text-gray-500 leading-tight">UPI, Cards<br>&amp; Netbanking</span>
                    </div>
                    <div class="flex flex-col items-center gap-1.5">
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 2L4.5 13H11l-1 9L19.5 11H13l1-9z"/></svg>
                        <span class="text-[10.5px] text-gray-500 leading-tight">Instant<br>Confirmation</span>
                    </div>
                </div>
                <p class="text-[11px] text-gray-600 mt-6 text-center">Never share your OTP or UPI PIN with anyone, including the seller.</p>
            </div>
        </div>

        <a href="<?= APP_URL ?>/index.php" class="flex items-center justify-center gap-1.5 mt-6 text-[11px] text-gray-600 hover:text-gray-400 transition">
            <span>Powered by</span>
            <span class="font-semibold text-gray-500"><?= APP_NAME ?></span>
        </a>
    </div>
</main>

<?php require_once __DIR__ . '/footer.php';
