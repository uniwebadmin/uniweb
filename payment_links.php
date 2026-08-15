<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensurePaymentPackSchema();
$merchant = getMerchant();
$db = getDB();
$catalog = getPaymentMethodCatalog();

// E1: Use get_available_pay_methods() as single source of truth
if (!function_exists('get_available_pay_methods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
$availableMethods = get_available_pay_methods((int)$merchant['id']);
$enabledMethods = array_column($availableMethods, 'key');

$methodChoices = [
    '' => ['label' => 'All enabled methods', 'hint' => 'Customer chooses UPI / Card / etc. on checkout'],
];
foreach ($availableMethods as $am) {
    $methodChoices[$am['key']] = [
        'label' => $am['label'],
        'hint' => 'Dedicated ' . $am['label'] . ' checkout',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('create_links');

    // E7: Rate limit link creation (20 per 10 minutes per merchant)
    $rateKey = 'link_create_' . (int)$merchant['id'];
    $rateFile = sys_get_temp_dir() . '/uniweb_rl_' . md5($rateKey);
    $rateCount = 0;
    if (is_file($rateFile)) {
        $rateData = json_decode((string)file_get_contents($rateFile), true);
        if ($rateData && (time() - $rateData['ts']) < 600) {
            $rateCount = (int)$rateData['count'];
        }
    }
    if ($rateCount >= 20) {
        flash('error', 'Too many links created recently. Please wait a few minutes and try again.');
        redirect('payment_links.php');
    }
    file_put_contents($rateFile, json_encode(['ts' => time(), 'count' => $rateCount + 1]));

    $amount = (float)($_POST['amount'] ?? 0);
    $isTest = isMerchantPaymentTest($merchant);
    $amount = sanitizePaymentAmount($amount, $isTest);
    $methodKey = trim((string)($_POST['payment_method'] ?? ''));
    if ($methodKey !== '' && !isset($methodChoices[$methodKey])) {
        $methodKey = '';
    }
    // E1: Validate selected method is actually available
    if ($methodKey !== '' && !in_array($methodKey, $enabledMethods, true)) {
        flash('error', 'Selected payment method is not available. Please choose from the enabled methods.');
        redirect('payment_links.php');
    }
    if ($amount < 1) {
        flash('error', $isTest ? 'Test mode: amount ₹1–₹100 only.' : 'Minimum amount is ₹1.');
    } else {
        if (!$isTest) {
            requireKycDocumentsUploaded();
        }
        $isTestFlag = $isTest ? 1 : 0;
        $linkId = generateId('LNK');
        $expiryHours = trim((string)($_POST['expiry_hours'] ?? '24'));
        $expiresAt = $expiryHours === 'never' ? null : date('Y-m-d H:i:s', time() + (int)$expiryHours * 3600);
        $description = trim($_POST['description'] ?? '');
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $gateway = null;
        $label = null;
        $collMode = null;
        if ($methodKey !== '' && isset($catalog[$methodKey])) {
            $gateway = $catalog[$methodKey]['gateway'];
            $label = $catalog[$methodKey]['label'];
            $collMode = $catalog[$methodKey]['collection_mode'];
            if ($description === '') {
                $description = 'Payment — ' . $label;
            }
        }
        // E2: Idempotency key — prevent duplicate link creation on double-submit
        $idempotencyKey = 'LNK_' . md5((int)$merchant['id'] . '_' . $amount . '_' . $methodKey . '_' . time() . '_' . random_bytes(4));
        try {
            $db->prepare('INSERT INTO payment_links (link_id,merchant_id,amount,description,customer_name,customer_phone,expires_at,is_test,payment_method,gateway_code,link_label,link_collection_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone,
                    $expiresAt, $isTestFlag, $methodKey !== '' ? $methodKey : null, $gateway, $label, $collMode,
                ]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO payment_links (link_id,merchant_id,amount,description,customer_name,customer_phone,expires_at,is_test) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchant['id'], $amount, $description, $customerName, $customerPhone, $expiresAt, $isTestFlag]);
        }
        $createdMsg = 'Payment link created' . ($label ? ' for ' . $label : '') . '!';
        if ($isTest) {
            $createdMsg .= ' Test Mode — sandbox only, no real money.';
        }
        flash('success', $createdMsg);
        redirect('payment_links.php?created=' . rawurlencode($linkId));
    }
}

ensurePaymentLinkAnalytics();
$pageTitle = 'Payment Links';
$testMode = isDashboardTestMode($merchant);
$modeFilter = $testMode ? 1 : 0;
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$linkStatus = trim($_GET['status'] ?? 'all');
if (!in_array($linkStatus, ['all', 'paid', 'unpaid', 'active', 'inactive', 'expired'], true)) $linkStatus = 'all';
$linkWhere = 'pl.merchant_id = ? AND pl.is_test = ?';
$linkParams = [$merchant['id'], $modeFilter];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $linkWhere .= " AND (LOWER(TRIM(COALESCE(pl.link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.description,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.status,''))) LIKE ? OR CAST(pl.amount AS CHAR) LIKE ?)";
    array_push($linkParams, $like, $like, $like, $like, $like);
}
if (in_array($linkStatus, ['active', 'inactive', 'expired'], true)) {
    $linkWhere .= ' AND pl.status = ?';
    $linkParams[] = $linkStatus;
}
$paidCountSql = "(SELECT COUNT(*) FROM transactions t WHERE t.payment_link_id = pl.id AND t.status = 'success')";
$having = $linkStatus === 'paid' ? ' HAVING paid_count > 0' : ($linkStatus === 'unpaid' ? ' HAVING paid_count = 0' : '');
$listParams = listPageParams(20);
$countStmt = $db->prepare("SELECT COUNT(*) FROM (SELECT pl.id, $paidCountSql AS paid_count FROM payment_links pl WHERE $linkWhere$having) x");
$countStmt->execute($linkParams);
$linkTotal = (int)$countStmt->fetchColumn();
$links = $db->prepare("SELECT pl.*, $paidCountSql AS paid_count FROM payment_links pl WHERE $linkWhere$having ORDER BY pl.created_at DESC LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
$links->execute($linkParams);
$paymentLinks = $links->fetchAll();
require_once __DIR__ . '/header.php';
$payuReady = isGatewayConfigured('payu');
$rzpReady = isGatewayConfigured('razorpay');
$cfReady = isGatewayConfigured('cashfree');
$createdId = trim((string)($_GET['created'] ?? ''));
$createdUrl = '';
if ($createdId !== '') {
    $createdStmt = $db->prepare('SELECT link_id, payment_method FROM payment_links WHERE link_id = ? AND merchant_id = ? LIMIT 1');
    $createdStmt->execute([$createdId, $merchant['id']]);
    $createdRow = $createdStmt->fetch();
    if ($createdRow) {
        $createdCat = $catalog[$createdRow['payment_method'] ?? ''] ?? null;
        $createdUrl = buildPaymentLinkUrl((string)$createdRow['link_id'], $createdCat['pay_key'] ?? null);
    }
}
?>
<?php if ($createdUrl !== ''): ?>
<div class="bg-emerald-500/10 border border-emerald-500/30 rounded-xl px-4 py-4 mb-6 text-sm">
    <p class="text-emerald-300 font-semibold mb-2">Public checkout link (customer opens this URL)</p>
    <p class="font-mono text-xs text-gray-300 break-all mb-3"><?= e($createdUrl) ?></p>
    <div class="flex flex-wrap gap-2">
        <a href="<?= e($createdUrl) ?>" target="_blank" rel="noopener" class="text-xs bg-sky-600 text-white px-3 py-1.5 rounded-lg">Open checkout</a>
        <button type="button" data-copy-url="<?= e($createdUrl) ?>" onclick="copyPayUrl(this)" class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1.5 rounded-lg">Copy</button>
    </div>
</div>
<?php endif; ?>
<?php if ($testMode): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-lg px-4 py-3 mb-6 text-sm text-amber-300">
    <?= accountModeBadge($merchant) ?> <?= merchantCanGoLive($merchant) ? 'Showing test payment links only. Switch to Live Mode for production links.' : 'Payment links are <strong>sandbox only</strong> until KYC is approved.' ?>
</div>
<?php endif; ?>

<div class="glass rounded-xl p-4 mb-6 border border-sky-500/20 text-xs text-gray-400 flex flex-wrap items-center justify-between gap-3">
    <p>
        <strong class="text-sky-300">Tip:</strong> Choose <strong class="text-white">UPI</strong> for QR.
        <strong class="text-amber-300">Test Mode</strong> = Instant Test Pay (sandbox).
        <strong class="text-emerald-300">Live Mode</strong> = real UPI ID + UTR (or Axis/webhooks).
        Card / Netbanking need PayU keys<?= $payuReady ? ' <span class="text-emerald-400">(configured)</span>' : ' <span class="text-amber-400">(not configured — Test Mode uses Instant Test)</span>' ?>.
    </p>
    <a href="merchant_payment_pack.php" class="text-sky-400 hover:text-sky-300 whitespace-nowrap">Payment Pack (₹1 per method) →</a>
</div>
<form method="GET" data-live-search-form data-results-target="payment-link-results" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[220px]"><label class="text-[10px] text-gray-600 uppercase">Search payment links</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="Link ID / Name / Amount / Status" class="input-field mt-1 text-sm" autocomplete="off"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','paid'=>'Paid','unpaid'=>'Unpaid','active'=>'Active','expired'=>'Expired','inactive'=>'Inactive'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $linkStatus===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Create Payment Link</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400">Amount (₹) *</label>
                <input type="number" name="amount" required min="1" step="0.01" class="input-field mt-1" placeholder="<?= $testMode ? '1–100 (test)' : 'Min ₹1' ?>" value="1">
            </div>
            <div>
                <label class="text-sm text-gray-400">Payment method *</label>
                <select name="payment_method" class="input-field mt-1">
                    <?php foreach ($methodChoices as $val => $meta): ?>
                    <option value="<?= e($val) ?>"><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-600 mt-1">Dedicated method = customer sees only that option on checkout.</p>
                <?php if (count($availableMethods) === 0): ?>
                <p class="text-[11px] text-amber-400 mt-1">No methods unlocked yet. Open <a href="collection_settings.php" class="underline">Payment Methods</a> or use Payment Pack.</p>
                <?php elseif (count($availableMethods) === 1): ?>
                <p class="text-[11px] text-gray-500 mt-1">More methods: turn them ON under <a href="collection_settings.php" class="text-sky-400 hover:underline">Payment Methods</a>.</p>
                <?php endif; ?>
            </div>
            <div>
                <label class="text-sm text-gray-400">Description</label>
                <input type="text" name="description" class="input-field mt-1" placeholder="Payment for...">
            </div>
            <div>
                <label class="text-sm text-gray-400">Customer Name</label>
                <input type="text" name="customer_name" class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm text-gray-400">Customer Phone</label>
                <input type="tel" name="customer_phone" maxlength="10" class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm text-gray-400">Valid for (expiry)</label>
                <select name="expiry_hours" class="input-field mt-1">
                    <option value="1">1 Hour</option>
                    <option value="6">6 Hours</option>
                    <option value="24" selected>24 Hours</option>
                    <option value="72">3 Days</option>
                    <option value="168">7 Days</option>
                    <option value="never">No Expiry</option>
                </select>
            </div>
            <button type="submit" formnovalidate class="w-full btn-primary py-3">Generate Link</button>
        </form>
        <?php if (!$payuReady && !$rzpReady && !$cfReady): ?>
        <p class="text-[11px] text-amber-400/90 mt-4">Card / Cashfree / Razorpay links need platform partner keys (admin pastes them in Partner Registry). Until then use <strong>UPI</strong> or Test Instant Pay.</p>
        <?php endif; ?>
    </div>
    <div id="payment-link-results" class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap justify-between items-center gap-2">
            <h2 class="font-semibold">Your Payment Links</h2>
            <div class="flex gap-2 items-center">
                <?= renderExportCsvLink('export_payment_links.php?' . http_build_query(['q' => $q, 'status' => $linkStatus])) ?>
                <a href="merchant_payment_pack.php" class="text-xs text-sky-400">Generate ₹1 pack (all methods) →</a>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Link ID</th><th class="px-5 py-3 text-left">Method</th><th class="px-5 py-3 text-left">Amount</th>
                    <th class="px-5 py-3 text-left">Views</th><th class="px-5 py-3 text-left">Paid</th><th class="px-5 py-3 text-left">Conv.</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Expires</th><th class="px-5 py-3 text-left">Action</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($paymentLinks)): ?><tr><td colspan="9" class="p-0"><?= renderMerchantEmptyState(
                        'No payment links yet',
                        $testMode ? 'Create a link on the left (pick UPI or Card), or open Payment Pack for ₹1 method-wise test links.' : 'Create a live payment link to collect real payments.',
                        'merchant_payment_pack.php',
                        'Open Payment Pack →'
                    ) ?></td></tr>
                    <?php else: foreach ($paymentLinks as $link):
                        $cat = $catalog[$link['payment_method'] ?? ''] ?? null;
                        $payUrl = buildPaymentLinkUrl($link['link_id'], $cat['pay_key'] ?? null);
                        $methodLabel = $link['link_label'] ?? ($cat['label'] ?? 'All Methods');
                    ?>
                    <tr<?= uiRowClick($payUrl) ?>>
                        <td class="px-5 py-3 font-mono text-xs">
                            <a href="<?= e($payUrl) ?>" target="_blank" class="text-sky-400 hover:underline"<?= uiStopClick() ?>><?= e($link['link_id']) ?></a>
                        </td>
                        <td class="px-5 py-3 text-xs"><?= e($methodLabel) ?></td>
                        <td class="px-5 py-3 font-semibold"><?= formatMoney(capStatAmount((float)$link['amount'])) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?= (int)($link['view_count'] ?? 0) ?></td>
                        <td class="px-5 py-3 text-xs text-emerald-400"><?= (int)($link['paid_count'] ?? 0) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?php $v = (int)($link['view_count'] ?? 0); $p = (int)($link['paid_count'] ?? 0); echo $v > 0 ? round($p / $v * 100) . '%' : '—'; ?></td>
                        <td class="px-5 py-3"><?= statusBadge($link['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($link['expires_at']) ?></td>
                        <td class="px-5 py-3 whitespace-nowrap"<?= uiStopClick() ?>>
                            <?php if ($link['status'] === 'active'):
                                $shareText = rawurlencode("Pay " . formatMoney((float)$link['amount']) . " via " . ($merchant['business_name'] ?? APP_NAME) . "\n" . $payUrl);
                                $qrImgUrl = APP_URL . '/qr_image.php?d=' . rawurlencode(base64_encode(strtr($payUrl, '+/', '-_'))) . '&s=400&logo=1';
                                $embedHtml = '<a href="' . e($payUrl) . '" target="_blank" style="display:inline-block;padding:12px 20px;background:#10b981;color:#fff;border-radius:8px;text-decoration:none;font-weight:600;">Pay Now</a>';
                            ?>
                            <a href="<?= e($payUrl) ?>" target="_blank" class="text-xs bg-sky-600/20 text-sky-400 px-3 py-1 rounded-lg mr-1">Open</a>
                            <button type="button" data-copy-url="<?= e($payUrl) ?>" onclick="copyPayUrl(this)" class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1 rounded-lg mr-1">Copy</button>
                            <button type="button" onclick="openLinkShareModal(<?= (int)$link['id'] ?>)" class="text-xs bg-emerald-600/20 text-emerald-400 px-3 py-1 rounded-lg mr-1">Share</button>
                            <button type="button" onclick="openLinkWebsiteModal(<?= (int)$link['id'] ?>)" class="text-xs bg-violet-600/20 text-violet-400 px-3 py-1 rounded-lg">Website</button>

                            <div id="link-share-<?= (int)$link['id'] ?>" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) this.classList.add('hidden')">
                                <div class="glass rounded-xl p-5 max-w-sm w-full">
                                    <h3 class="font-semibold mb-3">Share <?= e($link['link_id']) ?></h3>
                                    <div class="grid grid-cols-2 gap-2 text-xs">
                                        <a href="https://api.whatsapp.com/send?text=<?= e($shareText) ?>" target="_blank" class="text-center py-2 rounded-lg border border-emerald-500/30 text-emerald-400">WhatsApp</a>
                                        <a href="https://t.me/share/url?url=<?= rawurlencode($payUrl) ?>&text=<?= rawurlencode('Payment link') ?>" target="_blank" class="text-center py-2 rounded-lg border border-sky-500/30 text-sky-400">Telegram</a>
                                        <a href="mailto:?subject=<?= rawurlencode('Payment link — ' . ($merchant['business_name'] ?? APP_NAME)) ?>&body=<?= e($shareText) ?>" class="text-center py-2 rounded-lg border border-amber-500/30 text-amber-400">Email</a>
                                        <a href="sms:?body=<?= e($shareText) ?>" class="text-center py-2 rounded-lg border border-violet-500/30 text-violet-400">SMS</a>
                                    </div>
                                    <button type="button" onclick="this.closest('.fixed').classList.add('hidden')" class="mt-4 w-full py-2 rounded-lg border border-gray-700 text-gray-400">Close</button>
                                </div>
                            </div>

                            <div id="link-website-<?= (int)$link['id'] ?>" class="hidden fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onclick="if(event.target===this) this.classList.add('hidden')">
                                <div class="glass rounded-xl p-5 max-w-md w-full">
                                    <h3 class="font-semibold mb-3">Add to Website — <?= e($link['link_id']) ?></h3>
                                    <label class="text-[11px] text-gray-500 uppercase">Direct Link</label>
                                    <input type="text" value="<?= e($payUrl) ?>" readonly class="input-field text-xs w-full mb-3" onclick="this.select()">
                                    <label class="text-[11px] text-gray-500 uppercase">HTML Button</label>
                                    <textarea readonly rows="3" class="input-field text-xs w-full mb-3 font-mono" onclick="this.select()"><?= e($embedHtml) ?></textarea>
                                    <label class="text-[11px] text-gray-500 uppercase">QR Image URL</label>
                                    <input type="text" value="<?= e($qrImgUrl) ?>" readonly class="input-field text-xs w-full mb-3" onclick="this.select()">
                                    <p class="text-[11px] text-gray-500">Paste the link or button code on your website, blog or invoice.</p>
                                    <button type="button" onclick="this.closest('.fixed').classList.add('hidden')" class="mt-4 w-full py-2 rounded-lg border border-gray-700 text-gray-400">Close</button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= renderListPagination($listParams['page'], $linkTotal, $listParams['perPage'], ['q' => $q, 'status' => $linkStatus]) ?>
    </div>
</div>
<script>
function copyPayUrl(btn) {
    var url = btn.getAttribute('data-copy-url') || '';
    if (!url) return;
    var old = btn.textContent;
    navigator.clipboard.writeText(url).then(function () {
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = old; }, 1500);
    });
}
function openLinkShareModal(id) {
    document.getElementById('link-share-' + id).classList.remove('hidden');
}
function openLinkWebsiteModal(id) {
    document.getElementById('link-website-' + id).classList.remove('hidden');
}
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
