<?php
require_once __DIR__ . '/config.php';

$slug = strtolower(trim((string)($_GET['s'] ?? '')));
if (!preg_match('/^[a-z0-9-]{3,80}$/', $slug) || !merchantStorefrontTableAvailable()) {
    http_response_code(404);
    $pageTitle = 'Sales page not found';
    require_once __DIR__ . '/header.php';
    echo '<main class="max-w-xl mx-auto px-4 py-24 text-center"><div class="glass rounded-2xl p-8"><h1 class="text-2xl font-bold text-white">Sales page not found</h1><p class="text-sm text-gray-400 mt-3">This page is unavailable or no longer published.</p><a href="index.php" class="inline-block mt-6 text-sky-400">Go to UniWeb →</a></div></main>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$stmt = getDB()->prepare('SELECT sf.*, m.id AS merchant_id, m.business_name, m.name, m.email, m.phone FROM merchant_storefronts sf JOIN merchants m ON m.id=sf.merchant_id WHERE sf.storefront_slug=? AND sf.is_published=1 AND m.status != \'deleted\' LIMIT 1');
$stmt->execute([$slug]);
$store = $stmt->fetch();
if (!$store) {
    http_response_code(404);
    $pageTitle = 'Sales page not found';
    require_once __DIR__ . '/header.php';
    echo '<main class="max-w-xl mx-auto px-4 py-24 text-center"><div class="glass rounded-2xl p-8"><h1 class="text-2xl font-bold text-white">Sales page not found</h1><p class="text-sm text-gray-400 mt-3">This page is unavailable or no longer published.</p><a href="index.php" class="inline-block mt-6 text-sky-400">Go to UniWeb →</a></div></main>';
    require_once __DIR__ . '/footer.php';
    exit;
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
        'accent' => 'from-orange-500 via-amber-400 to-yellow-300',
        'border' => 'border-orange-400/30',
        'chip' => 'bg-orange-500/15 text-orange-300 border-orange-400/30',
        'btn' => 'bg-orange-500 hover:bg-orange-400',
        'ring' => 'ring-orange-400/40',
        'label' => 'Shop online',
        'section_label' => 'Choose an item',
        'layout' => 'grid',
    ],
    'invoice' => [
        'accent' => 'from-cyan-500 via-sky-400 to-indigo-400',
        'border' => 'border-cyan-400/30',
        'chip' => 'bg-sky-500/15 text-sky-300 border-sky-400/30',
        'btn' => 'bg-sky-500 hover:bg-sky-400',
        'ring' => 'ring-sky-400/40',
        'label' => 'Book or pay securely',
        'section_label' => 'Pay an invoice or booking',
        'layout' => 'list',
    ],
    'services' => [
        'accent' => 'from-violet-600 via-fuchsia-500 to-pink-400',
        'border' => 'border-violet-400/30',
        'chip' => 'bg-violet-500/15 text-violet-300 border-violet-400/30',
        'btn' => 'bg-violet-600 hover:bg-violet-500',
        'ring' => 'ring-violet-400/40',
        'label' => 'Services',
        'section_label' => 'Choose a service',
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

<main class="min-h-[75vh] px-4 py-10 sm:py-16 bg-dark-950">
    <section class="max-w-3xl mx-auto overflow-hidden rounded-3xl border <?= $theme['border'] ?> bg-dark-900/75 shadow-2xl shadow-black/30">
        <div class="h-2 bg-gradient-to-r <?= $theme['accent'] ?>"></div>
        <div class="p-6 sm:p-10">
            <div class="flex items-center gap-4">
                <?php if ($logoUrl !== ''): ?>
                <img src="<?= e($logoUrl) ?>" alt="<?= e($businessName) ?>" class="w-16 h-16 rounded-2xl object-cover border border-gray-800 ring-2 <?= $theme['ring'] ?> shrink-0" loading="lazy">
                <?php else: ?>
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-br <?= $theme['accent'] ?> flex items-center justify-center text-2xl font-bold text-white shrink-0 ring-2 <?= $theme['ring'] ?>"><?= e($initial) ?></div>
                <?php endif; ?>
                <div>
                    <span class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.15em] px-2.5 py-1 rounded-full border <?= $theme['chip'] ?>"><?= e($theme['label']) ?></span>
                    <p class="text-sm text-gray-400 mt-1.5">Sold by <span class="text-gray-200 font-medium"><?= e($businessName) ?></span></p>
                </div>
            </div>

            <h1 class="text-3xl sm:text-4xl font-bold text-white mt-6 leading-tight"><?= e($store['headline']) ?></h1>
            <p class="text-lg text-gray-300 mt-4 leading-relaxed whitespace-pre-line"><?= e($store['description']) ?></p>

            <?php if ($contactText !== '' || $whatsappUrl): ?>
            <div class="mt-6 flex flex-wrap items-center gap-3">
                <?php if ($contactText !== ''): ?>
                <span class="text-sm text-gray-400">📞 <?= e($contactText) ?></span>
                <?php endif; ?>
                <?php if ($whatsappUrl): ?>
                <a href="<?= e($whatsappUrl) ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border border-emerald-500/40 text-emerald-300 hover:bg-emerald-500/10">WhatsApp us</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($products): ?>
            <section class="mt-8">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-400"><?= e($theme['section_label']) ?></h2>
                    <span class="text-xs text-gray-600">🔒 Secure checkout</span>
                </div>
                <div class="grid <?= $theme['layout'] === 'grid' ? 'sm:grid-cols-3' : 'sm:grid-cols-2' ?> gap-3">
                    <?php foreach ($products as $product): $productUrl = buildPaymentLinkUrl((string)$product['link_id']); ?>
                    <article class="group rounded-2xl border border-gray-800 bg-black/20 hover:border-gray-700 transition p-4 flex flex-col gap-4">
                        <div class="flex-1">
                            <?php if ($theme['layout'] === 'grid'): ?>
                            <div class="w-full aspect-square rounded-xl bg-gradient-to-br <?= $theme['accent'] ?> opacity-20 mb-3 flex items-center justify-center text-3xl">🛍️</div>
                            <?php endif; ?>
                            <h3 class="font-semibold text-white leading-snug"><?= e($product['description'] ?: 'Pay securely') ?></h3>
                            <p class="text-xl font-bold text-emerald-300 mt-2"><?= formatMoney((float)$product['amount']) ?></p>
                        </div>
                        <a href="<?= e($productUrl) ?>" class="<?= $theme['btn'] ?> text-white font-semibold px-4 py-2.5 rounded-xl text-center text-sm transition">Pay securely →</a>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php else: ?>
            <div class="mt-6 rounded-2xl border border-amber-400/25 bg-amber-500/5 p-5 text-sm text-amber-200">Online payment is temporarily unavailable. Please contact the business directly.</div>
            <?php endif; ?>

            <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-2 text-[11px] text-gray-500 border-t border-gray-800 pt-5">
                <span>🔒 Secured by UniWeb</span>
                <span>💳 UPI · Cards · Netbanking</span>
                <span>⚡ Instant confirmation</span>
            </div>
            <p class="text-[11px] text-gray-600 mt-3">Payments are processed securely by UniWeb. Never share your OTP or UPI PIN.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/footer.php';
