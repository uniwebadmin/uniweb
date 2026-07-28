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

$linkStmt = getDB()->prepare("SELECT link_id, amount, description FROM payment_links WHERE merchant_id=? AND status='active' AND is_test=0 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 6");
$linkStmt->execute([(int)$store['merchant_id']]);
$products = $linkStmt->fetchAll() ?: [];
$template = (string)($store['template_key'] ?? 'services');
$theme = match ($template) {
    'retail' => ['accent' => 'from-orange-500 via-amber-400 to-yellow-300', 'border' => 'border-orange-400/30', 'label' => 'Shop online'],
    'invoice' => ['accent' => 'from-cyan-500 via-sky-400 to-indigo-400', 'border' => 'border-cyan-400/30', 'label' => 'Book or pay securely'],
    default => ['accent' => 'from-violet-600 via-fuchsia-500 to-pink-400', 'border' => 'border-violet-400/30', 'label' => 'Services'],
};
$pageTitle = (string)($store['business_name'] ?? 'Merchant') . ' — Pay online';
require_once __DIR__ . '/header.php';
?>

<main class="min-h-[75vh] px-4 py-10 sm:py-16">
    <section class="max-w-3xl mx-auto overflow-hidden rounded-3xl border <?= $theme['border'] ?> bg-dark-900/75 shadow-2xl shadow-black/30">
        <div class="h-2 bg-gradient-to-r <?= $theme['accent'] ?>"></div>
        <div class="p-6 sm:p-10">
            <p class="text-xs uppercase tracking-[0.2em] text-gray-500"><?= e($theme['label']) ?></p>
            <h1 class="text-3xl sm:text-4xl font-bold text-white mt-3 leading-tight"><?= e($store['headline']) ?></h1>
            <p class="text-lg text-gray-300 mt-4 leading-relaxed whitespace-pre-line"><?= e($store['description']) ?></p>

            <div class="mt-8 rounded-2xl border border-gray-800 bg-black/20 p-5 sm:p-6">
                <p class="text-xs uppercase tracking-wider text-gray-500">Sold by</p>
                <p class="text-lg font-semibold text-white mt-1"><?= e($store['business_name'] ?: $store['name']) ?></p>
                <?php if (!empty($store['contact_text'])): ?><p class="text-sm text-gray-400 mt-2"><?= e($store['contact_text']) ?></p><?php endif; ?>
            </div>

            <?php if ($products): ?>
            <section class="mt-8">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-gray-400"><?= $template === 'services' ? 'Choose a service' : ($template === 'invoice' ? 'Pay an invoice or booking' : 'Choose an item') ?></h2>
                    <span class="text-xs text-gray-600">Secure checkout</span>
                </div>
                <div class="grid sm:grid-cols-2 gap-3">
                    <?php foreach ($products as $product): $productUrl = buildPaymentLinkUrl((string)$product['link_id']); ?>
                    <article class="rounded-2xl border border-gray-800 bg-black/20 p-4 flex flex-col gap-4">
                        <div class="flex-1">
                            <h3 class="font-semibold text-white leading-snug"><?= e($product['description'] ?: 'Pay securely') ?></h3>
                            <p class="text-xl font-bold text-emerald-300 mt-2"><?= formatMoney((float)$product['amount']) ?></p>
                        </div>
                        <a href="<?= e($productUrl) ?>" class="bg-emerald-600 hover:bg-emerald-500 text-white font-semibold px-4 py-2.5 rounded-xl text-center text-sm">Pay securely →</a>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>
            <?php else: ?>
            <div class="mt-6 rounded-2xl border border-amber-400/25 bg-amber-500/5 p-5 text-sm text-amber-200">Online payment is temporarily unavailable. Please contact the business directly.</div>
            <?php endif; ?>

            <p class="text-[11px] text-gray-600 mt-7">Payments are processed securely by UniWeb. Never share your OTP or UPI PIN.</p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/footer.php';
