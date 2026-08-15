<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensurePaymentPackSchema();
$db = getDB();

if (isset($_GET['action']) && $_GET['action'] === 'regenerate_pack' && verifyCsrf($_GET['token'] ?? '')) {
    $pack = generateMerchantPaymentPack((int)$merchant['id'], 1.0, isMerchantPaymentTest($merchant));
    flash($pack['ok'] ? 'success' : 'error', $pack['ok'] ? count($pack['links']) . ' links created.' : 'Could not create payment pack. Try again or contact support.');
    redirect('merchant_payment_pack.php');
}

$preview = merchantMethodPreview($merchant);
$packLinks = getMerchantPackLinks((int)$merchant['id'], $merchant['provision_pack_id'] ?? null);
$packQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(20);
if ($packQ !== '') {
    $packLinks = array_values(array_filter($packLinks, static function ($link) use ($packQ) {
        $hay = strtolower(($link['link_label'] ?? '') . ' ' . ($link['payment_method'] ?? '') . ' ' . ($link['gateway_code'] ?? ''));
        return str_contains($hay, strtolower($packQ));
    }));
}
$packTotal = count($packLinks);
$packLinks = array_slice($packLinks, $listParams['offset'], $listParams['perPage']);
$catalog = getPaymentMethodCatalog();
$pageTitle = 'Payment Pack — All Methods';
require_once __DIR__ . '/header.php';
?>

<?php if (!$preview['auto_provisioned'] && empty($packLinks)): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6 text-sm text-amber-200">
    <?= __('pack_not_ready') ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <div class="glass rounded-xl p-6 lg:col-span-1">
        <h2 class="font-semibold mb-2"><?= __('payment_profile') ?></h2>
        <p class="text-xs text-gray-500"><?= e($preview['profile_label']) ?></p>
        <p class="text-sm text-brand-400 mt-3"><?= e(collectionModeLabel($preview['collection_mode'])) ?></p>
        <p class="text-xs text-gray-600 mt-4"><?= count($preview['methods']) ?> <?= __('methods_enabled') ?></p>
    </div>
    <div class="glass rounded-xl p-6 lg:col-span-2">
        <h3 class="font-semibold text-sm mb-4"><?= __('method_preview_mdr') ?></h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <?php foreach ($preview['methods'] as $m): ?>
            <div class="bg-dark-900/50 rounded-lg p-3 flex items-center gap-3">
                <span class="text-2xl"><?= $m['icon'] ?></span>
                <div>
                    <p class="text-sm font-medium"><?= e($m['label']) ?></p>
                    <p class="text-xs text-gray-500"><?= e($m['gateway']) ?> · MDR <?= $m['mdr'] ?>%</p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap justify-between items-center gap-3">
        <h2 class="font-semibold"><?= __('payment_links_per_method') ?></h2>
        <form method="GET" class="flex gap-2 items-center">
            <label class="sr-only" for="pack-q">Search pack links</label>
            <input id="pack-q" type="search" name="q" value="<?= e($packQ) ?>" placeholder="Method / gateway" class="input-field text-sm">
            <button type="submit" class="btn-primary text-sm px-3 py-1.5">Filter</button>
        </form>
        <?php if (empty($packLinks) && $packQ === ''): ?>
        <a href="?action=regenerate_pack&token=<?= csrfToken() ?>" class="text-sm bg-sky-600 hover:bg-sky-500 text-white px-4 py-2 rounded-lg font-semibold" onclick="return confirm('Create ₹1 test links for each enabled method (UPI, Card, etc.)?')"><?= __('generate_payment_pack') ?></a>
        <?php else: ?>
        <a href="?action=regenerate_pack&token=<?= csrfToken() ?>" class="text-xs bg-sky-600/30 text-sky-400 px-3 py-1.5 rounded-lg" onclick="return confirm('Regenerate new ₹1 test links for all methods?')"><?= __('regenerate_pack') ?></a>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Method</th>
                    <th class="px-5 py-3 text-left">Gateway</th>
                    <th class="px-5 py-3 text-left">Amount</th>
                    <th class="px-5 py-3 text-left">Dedicated Link</th>
                    <th class="px-5 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($packLinks)): ?>
                <tr><td colspan="5" class="px-5 py-12 text-center text-gray-500">No pack links yet. Click <strong class="text-sky-400">Generate Payment Pack</strong> above to create ₹1 test links for each enabled method.</td></tr>
                <?php else: foreach ($packLinks as $link):
                    $cat = $catalog[$link['payment_method'] ?? ''] ?? null;
                    $payUrl = buildPaymentLinkUrl($link['link_id'], $cat['pay_key'] ?? null);
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3"><?= e($link['link_label'] ?? $link['payment_method'] ?? '—') ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= e($link['gateway_code'] ?? '—') ?></td>
                    <td class="px-5 py-3 font-semibold"><?= formatMoney(capStatAmount((float)$link['amount'])) ?></td>
                    <td class="px-5 py-3 font-mono text-[10px] text-gray-500 max-w-xs break-all"><a href="<?= e($payUrl) ?>" target="_blank" class="text-sky-400 hover:underline break-all"><?= e($payUrl) ?></a></td>
                    <td class="px-5 py-3">
                        <?php if ($link['status'] === 'active'): ?>
                        <a href="<?= e($payUrl) ?>" target="_blank" class="text-xs text-sky-400 mr-2">Open</a>
                        <button type="button" data-copy-url="<?= e($payUrl) ?>" onclick="var u=this.getAttribute('data-copy-url')||''; if(u){navigator.clipboard.writeText(u); this.textContent='Copied!';}" class="text-xs text-brand-400"><?= __('copy') ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?= renderListPagination($listParams['page'], $packTotal, $listParams['perPage'], ['q' => $packQ]) ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
