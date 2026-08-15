<?php
require_once __DIR__ . '/config.php';
// Defense in depth: live config.php is gitignored and may omit 'qr_svg' from $__includes.
if (!function_exists('qrImageUrl')) {
    require_once __DIR__ . '/includes/qr_svg.php';
}
if (!function_exists('listPageParams')) {
    require_once __DIR__ . '/includes/page_ux_compat.php';
}
requireLogin();
ensureMerchantQrCodes();
ensurePaymentPackSchema();

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$db = getDB();
$isTest = isMerchantPaymentTest($merchant);
$upiId = trim((string)($merchant['upi_id'] ?? ''));

// E3: Use get_available_pay_methods() to check if UPI methods are available
if (!function_exists('get_available_pay_methods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}
if (!function_exists('getPaymentMethodCatalog')) {
    require_once __DIR__ . '/includes/provision.php';
}
$availableMethods = get_available_pay_methods($merchantId);
$availableKeys = array_column($availableMethods, 'key');
$upiAvailable = in_array('upi_p2m', $availableKeys, true) || in_array('axis_va', $availableKeys, true) || in_array('payu_upi', $availableKeys, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireMerchantTeamCapability('create_links');
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Security token expired. Refresh and try again.');
        redirect('qr_code.php');
    }
    $action = (string)($_POST['action'] ?? 'create');
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $db->prepare('SELECT id, payment_link_id, status FROM merchant_qr_codes WHERE id=? AND merchant_id=? LIMIT 1');
        $row->execute([$id, $merchantId]);
        $qr = $row->fetch();
        if ($qr) {
            $newStatus = $qr['status'] === 'active' ? 'inactive' : 'active';
            $db->prepare('UPDATE merchant_qr_codes SET status=? WHERE id=? AND merchant_id=?')
                ->execute([$newStatus, $id, $merchantId]);
            if (!empty($qr['payment_link_id'])) {
                $db->prepare('UPDATE payment_links SET status=? WHERE id=? AND merchant_id=?')
                    ->execute([$newStatus === 'active' ? 'active' : 'cancelled', (int)$qr['payment_link_id'], $merchantId]);
            }
            flash('success', 'QR status updated.');
        }
        redirect('qr_code.php');
    }

    if ($action === 'bulk_create') {
        $qrType = (string)($_POST['qr_type'] ?? 'fixed');
        if (!in_array($qrType, ['all_methods', 'upi_dynamic', 'fixed'], true)) {
            $qrType = 'fixed';
        }
        $description = trim((string)($_POST['description'] ?? ''));
        $amount = $qrType === 'fixed'
            ? sanitizePaymentAmount((float)($_POST['amount'] ?? 0), $isTest)
            : 0.0;
        $names = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string)($_POST['bulk_names'] ?? ''))), static fn($n) => $n !== ''));
        $names = array_slice($names, 0, 50);

        if (empty($names)) {
            flash('error', 'Enter at least one QR name (one per line).');
        } elseif (count(array_filter($names, static fn($n) => mb_strlen($n) > 120)) > 0) {
            flash('error', 'Each QR name must be 120 characters or less.');
        } elseif ($qrType === 'fixed' && $amount < 1) {
            flash('error', 'Amount must be at least ₹1.');
        } elseif ($qrType === 'fixed' && $isTest && $amount > 100) {
            flash('error', 'Test Mode QR amount must be ₹1–₹100.');
        } elseif (!$isTest && ($merchant['kyc_status'] ?? '') !== 'verified') {
            flash('error', 'Live QR needs verified KYC.');
        } elseif (!$isTest && $qrType === 'upi_dynamic' && $upiId === '') {
            flash('error', 'UPI QR needs a real UPI ID in My Account.');
        } elseif ($qrType === 'upi_dynamic' && !$upiAvailable) {
            // E3: Disabled UPI blocks new UPI QR charge path
            flash('error', 'UPI payment method is not enabled. Contact support or complete partner setup.');
        } else {
            $created = 0;
            try {
                $db->beginTransaction();
                $insert = $db->prepare('INSERT INTO merchant_qr_codes
                    (qr_code, merchant_id, payment_link_id, qr_type, label, amount, description, is_test)
                    VALUES (?,?,NULL,?,?,?,?,?)');
                foreach ($names as $name) {
                    $qrCode = 'QR' . strtoupper(bin2hex(random_bytes(8)));
                    $insert->execute([$qrCode, $merchantId, $qrType, $name, $amount, $description !== '' ? $description : null, $isTest ? 1 : 0]);
                    $created++;
                }
                $db->commit();
                flash('success', $created . ' QR code(s) created. Download them as a ZIP or scroll down to print each one.');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                logPlatformError('error', 'Bulk QR create failed: ' . $e->getMessage());
                flash('error', 'Could not create QR codes. Try again.');
            }
        }
        redirect('qr_code.php');
    }

    $qrType = (string)($_POST['qr_type'] ?? 'fixed');
    if (!in_array($qrType, ['all_methods', 'upi_dynamic', 'fixed'], true)) {
        $qrType = 'fixed';
    }
    $label = trim((string)($_POST['label'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $amount = $qrType === 'fixed'
        ? sanitizePaymentAmount((float)($_POST['amount'] ?? 0), $isTest)
        : 0.0;
    if ($label === '' || mb_strlen($label) > 120) {
        flash('error', 'Enter a QR name (maximum 120 characters).');
    } elseif ($qrType === 'fixed' && $amount < 1) {
        flash('error', 'Amount must be at least ₹1.');
    } elseif ($qrType === 'fixed' && $isTest && $amount > 100) {
        flash('error', 'Test Mode QR amount must be ₹1–₹100.');
    } elseif (!$isTest && ($merchant['kyc_status'] ?? '') !== 'verified') {
        flash('error', 'Live QR needs verified KYC.');
    } elseif (!$isTest && $qrType === 'upi_dynamic' && $upiId === '') {
        flash('error', 'UPI QR needs a real UPI ID in My Account.');
    } elseif ($qrType === 'upi_dynamic' && !$upiAvailable) {
        // E3: Disabled UPI blocks new UPI QR charge path
        flash('error', 'UPI payment method is not enabled. Contact support or complete partner setup.');
    } else {
        $qrCode = 'QR' . strtoupper(bin2hex(random_bytes(8)));
        try {
            $db->beginTransaction();
            $paymentLinkId = null;
            $db->prepare('INSERT INTO merchant_qr_codes
                (qr_code, merchant_id, payment_link_id, qr_type, label, amount, description, is_test)
                VALUES (?,?,?,?,?,?,?,?)')->execute([
                    $qrCode,
                    $merchantId,
                    $paymentLinkId,
                    $qrType,
                    $label,
                    $amount,
                    $description !== '' ? $description : null,
                    $isTest ? 1 : 0,
                ]);
            $db->commit();
            flash('success', $label . ' QR created.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            logPlatformError('error', 'QR create failed: ' . $e->getMessage());
            flash('error', 'Could not create QR. Try again.');
        }
    }
    redirect('qr_code.php');
}

$stmt = $db->prepare("SELECT q.*, pl.link_id, pl.status AS link_status
    FROM merchant_qr_codes q
    LEFT JOIN payment_links pl ON pl.id=q.payment_link_id
    WHERE q.merchant_id=? AND q.is_test=? AND q.qr_type != 'instant_upi'
    ORDER BY q.created_at DESC");
$stmt->execute([$merchantId, $isTest ? 1 : 0]);
$allQrCodes = $stmt->fetchAll();
$qrQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(12);
if ($qrQ !== '') {
    $allQrCodes = array_values(array_filter($allQrCodes, static function ($qr) use ($qrQ) {
        $hay = strtolower(($qr['label'] ?? '') . ' ' . ($qr['qr_code'] ?? '') . ' ' . ($qr['qr_type'] ?? ''));
        return str_contains($hay, strtolower($qrQ));
    }));
}
$qrTotal = count($allQrCodes);
$qrCodes = array_slice($allQrCodes, $listParams['offset'], $listParams['perPage']);

// Per-QR collection summary (successful payments) — one grouped query reused
// across every card. Maps transactions -> payment_links.qr_code_id, the same
// join transactions.php uses for its ?qr_id= filter.
$qrPaidSummary = [];
if (!empty($qrCodes)) {
    $sumStmt = $db->prepare("SELECT pl.qr_code_id AS qid, COUNT(*) AS paid_count, COALESCE(SUM(t.amount),0) AS paid_total
        FROM transactions t
        JOIN payment_links pl ON pl.id = t.payment_link_id
        WHERE t.merchant_id = ? AND t.is_test = ? AND t.status = 'success' AND pl.qr_code_id IS NOT NULL
        GROUP BY pl.qr_code_id");
    $sumStmt->execute([$merchantId, $isTest ? 1 : 0]);
    foreach ($sumStmt->fetchAll() as $row) {
        $qrPaidSummary[(int)$row['qid']] = $row;
    }
}

$pageTitle = 'QR Code Generator';
require_once __DIR__ . '/header.php';
?>

<div class="flex flex-wrap items-start justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold">QR Code Generator</h1>
        <p class="text-sm text-gray-500 mt-1">Generate QR codes for in-store and online payments. Transaction limits follow your bank / UPI rail.</p>
    </div>
    <div class="text-right text-xs">
        <p class="font-mono text-gray-400">MID <?= e($merchant['merchant_code'] ?? '') ?></p>
        <span class="inline-block mt-1 px-2 py-0.5 rounded-full <?= $isTest ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400' ?>">
            <?= $isTest ? 'Test QR' : 'Live QR' ?>
        </span>
        <a href="qr_analytics.php" class="block mt-2 text-sky-400 hover:underline">View Analytics →</a>
    </div>
</div>

<div class="glass rounded-xl p-5 mb-6 flex flex-wrap items-center justify-between gap-4 border border-emerald-500/20">
    <div class="min-w-0">
        <h2 class="font-semibold flex items-center gap-2">Instant UPI QR <span class="text-[10px] bg-emerald-500/15 text-emerald-400 px-2 py-0.5 rounded-full uppercase tracking-wide">Direct P2M</span></h2>
        <p class="text-xs text-gray-500 mt-1 max-w-xl">Printable <span class="font-mono">upi://pay</span> QR that opens in GPay / PhonePe / Paytm and settles straight to your bank UPI. Ideal for counters and bank demos.</p>
    </div>
    <a href="qr_upi_print.php" class="whitespace-nowrap bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl font-semibold text-sm">Open &amp; Print →</a>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <div class="glass rounded-xl p-6 lg:col-span-1">
        <h2 class="font-semibold mb-1">Create New QR</h2>
        <p class="text-xs text-gray-500 mb-5"><?= $isTest ? 'Sandbox QR — Instant Test Pay, no real money.' : 'Live QR — share once, receive UPI / card / wallet payments directly to your account.' ?></p>
        <form id="create-qr" method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="text-sm text-gray-400">QR Type *</label>
                <select name="qr_type" id="qr-type" class="input-field mt-1" onchange="toggleQrAmount()">
                    <option value="all_methods">All Payment Methods — customer enters amount</option>
                    <option value="upi_dynamic">Dynamic UPI — customer enters amount</option>
                    <option value="fixed">Fixed Amount QR</option>
                </select>
                <p id="qr-type-help" class="text-[11px] text-gray-600 mt-1">Customer enters amount, then chooses UPI, Card, Netbanking or Wallet.</p>
            </div>
            <div>
                <label class="text-sm text-gray-400">QR Name *</label>
                <input type="text" name="label" maxlength="120" required class="input-field mt-1" placeholder="Counter 1 / Product / Branch">
            </div>
            <div id="qr-amount-wrap" class="hidden">
                <label class="text-sm text-gray-400">Fixed Amount (₹) *</label>
                <input type="number" id="qr-amount" name="amount" min="1" step="0.01" value="1" class="input-field mt-1">
            </div>
            <div>
                <label class="text-sm text-gray-400">Description</label>
                <input type="text" name="description" maxlength="255" class="input-field mt-1" placeholder="Optional payment note">
            </div>
            <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold">Generate QR</button>
        </form>
        <?php if (!$isTest && $upiId === ''): ?>
        <p class="text-xs text-amber-400 mt-4">Add a real UPI ID in <a href="my_account.php" class="underline">My Account</a> before creating Live QR.</p>
        <?php endif; ?>

        <details class="mt-6 border-t border-gray-800 pt-4">
            <summary class="cursor-pointer text-sm font-semibold text-brand-400">Bulk generate (multiple counters/branches)</summary>
            <form method="POST" class="space-y-4 mt-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="bulk_create">
                <div>
                    <label class="text-sm text-gray-400">QR Type *</label>
                    <select name="qr_type" id="bulk-qr-type" class="input-field mt-1" onchange="toggleBulkAmount()">
                        <option value="all_methods">All Payment Methods — customer enters amount</option>
                        <option value="upi_dynamic">Dynamic UPI — customer enters amount</option>
                        <option value="fixed">Fixed Amount QR</option>
                    </select>
                </div>
                <div>
                    <label class="text-sm text-gray-400">QR Names * — one per line (max 50)</label>
                    <textarea name="bulk_names" rows="5" required class="input-field mt-1 font-mono text-xs" placeholder="Counter 1&#10;Counter 2&#10;Counter 3&#10;Branch A&#10;Branch B"></textarea>
                </div>
                <div id="bulk-amount-wrap" class="hidden">
                    <label class="text-sm text-gray-400">Fixed Amount (₹) — applies to all *</label>
                    <input type="number" id="bulk-amount" name="amount" min="1" step="0.01" value="1" class="input-field mt-1">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Description — applies to all</label>
                    <input type="text" name="description" maxlength="255" class="input-field mt-1" placeholder="Optional payment note">
                </div>
                <button type="submit" class="w-full border border-brand-500/40 text-brand-300 hover:bg-brand-500/10 py-3 rounded-xl font-semibold">Generate All QR Codes</button>
            </form>
        </details>

        <details class="mt-4 border-t border-gray-800 pt-4">
            <summary class="cursor-pointer text-sm font-semibold text-violet-400">High-Volume Wizard (100+ QRs)</summary>
            <div class="space-y-4 mt-4">
                <p class="text-xs text-gray-500">Generate many QR codes with a naming pattern, or upload a CSV. Uses the fast QR API for bulk creation.</p>
                <form method="POST" class="space-y-3">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="bulk_create">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div><label class="text-sm text-gray-400">QR Type</label>
                            <select name="qr_type" class="input-field mt-1">
                                <option value="all_methods">All Payment Methods</option>
                                <option value="upi_dynamic">Dynamic UPI</option>
                                <option value="fixed">Fixed Amount</option>
                            </select>
                        </div>
                        <div><label class="text-sm text-gray-400">Fixed Amount (₹) — if Fixed type</label>
                            <input type="number" name="amount" min="1" step="0.01" value="1" class="input-field mt-1">
                        </div>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-3">
                        <div><label class="text-sm text-gray-400">Prefix</label>
                            <input type="text" name="hv_prefix" maxlength="50" class="input-field mt-1" placeholder="Counter">
                        </div>
                        <div><label class="text-sm text-gray-400">Start Number</label>
                            <input type="number" name="hv_start" min="1" value="1" class="input-field mt-1">
                        </div>
                        <div><label class="text-sm text-gray-400">Count (max 50)</label>
                            <input type="number" name="hv_count" min="1" max="50" value="10" class="input-field mt-1">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500">Generates: Counter 1, Counter 2, Counter 3, ...</p>
                    <button type="button" class="w-full border border-violet-500/40 text-violet-300 hover:bg-violet-500/10 py-2.5 rounded-xl font-semibold text-sm" onclick="generateHvNames()">Generate Names →</button>
                </form>
                <div id="hv-preview" class="hidden">
                    <p class="text-xs text-gray-400 mb-2">Preview (copy to bulk textarea above):</p>
                    <textarea id="hv-names" rows="4" class="input-field font-mono text-xs" readonly></textarea>
                </div>
                <script>
                function generateHvNames() {
                    const prefix = document.querySelector('[name="hv_prefix"]').value || 'QR';
                    const start = parseInt(document.querySelector('[name="hv_start"]').value) || 1;
                    const count = Math.min(50, parseInt(document.querySelector('[name="hv_count"]').value) || 10);
                    const names = [];
                    for (let i = start; i < start + count; i++) names.push(prefix + ' ' + i);
                    document.getElementById('hv-names').value = names.join('\n');
                    document.getElementById('hv-preview').classList.remove('hidden');
                    // Also populate the bulk textarea
                    const bulkTa = document.querySelector('[name="bulk_names"]');
                    if (bulkTa) bulkTa.value = names.join('\n');
                }
                </script>
            </div>
        </details>
    </div>

    <div class="lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <form method="GET" class="flex gap-2 items-center">
                <label class="sr-only" for="qr-q">Search QR codes</label>
                <input id="qr-q" type="search" name="q" value="<?= e($qrQ) ?>" placeholder="Name / QR code" class="input-field text-sm">
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
            </form>
            <?= renderExportCsvLink('export_qr_codes.php?q=' . rawurlencode($qrQ)) ?>
            <a href="qr_download_zip.php" class="glass px-3 py-2 rounded-lg text-xs text-emerald-400 hover:text-emerald-300 no-print">Download ZIP</a>
        </div>
        <?php if (empty($qrCodes)): ?>
        <?= renderMerchantEmptyState(
            'No ' . ($isTest ? 'Test' : 'Live') . ' QR codes yet',
            'Create a fixed-amount QR from the form on this page. Share it at the counter or print it.',
            '#create-qr',
            'Create a QR →'
        ) ?>
        <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <?php foreach ($qrCodes as $qr):
                $scanUrl = APP_URL . '/qr_pay.php?code=' . rawurlencode($qr['qr_code']);
                $qrImage = qrImageUrl($scanUrl, 300);
                $isFixed = ($qr['qr_type'] ?? 'fixed') === 'fixed';
                $amountLabel = $isFixed ? formatMoney((float)$qr['amount']) : 'Open Amount';
                $summary = $qrPaidSummary[(int)$qr['id']] ?? null;
                $paidCount = $summary ? (int)$summary['paid_count'] : 0;
                $paidTotal = $summary ? (float)$summary['paid_total'] : 0.0;
                $businessName = trim((string)($merchant['business_name'] ?? '')) ?: (APP_NAME . ' Merchant');
            ?>
            <div class="glass rounded-xl p-5 <?= $qr['status'] === 'active' ? '' : 'opacity-60' ?>">
                <div class="flex justify-between gap-3 mb-3">
                    <div class="min-w-0">
                        <h3 class="font-semibold truncate"><?= e($qr['label']) ?></h3>
                        <p class="text-2xl font-bold text-brand-400 mt-1"><?= $amountLabel ?></p>
                    </div>
                    <?= statusBadge($qr['status']) ?>
                </div>
                <div class="bg-white rounded-2xl px-4 pt-5 pb-4 text-center mb-3 border border-gray-200 shadow-lg shadow-emerald-900/10">
                    <p class="text-[11px] font-semibold text-gray-800 truncate px-2"><?= e($businessName) ?></p>
                    <?php if ($isFixed): ?><p class="text-lg font-extrabold text-emerald-600 leading-tight"><?= formatMoney((float)$qr['amount']) ?></p><?php endif; ?>
                    <div class="relative inline-block mt-2">
                        <img src="<?= e($qrImage) ?>" alt="<?= e($qr['label']) ?> QR" width="200" height="200" class="rounded-lg">
                        <img src="<?= e(APP_URL) ?>/assets/img/uniweb-logo.svg" alt="<?= e(APP_NAME) ?>" width="32" height="32" class="absolute w-8 h-8 bg-white rounded-full p-0.5 shadow" style="top:50%;left:50%;transform:translate(-50%,-50%)">
                    </div>
                    <p class="text-[10px] text-gray-400 mt-2 tracking-widest uppercase">Scan &amp; Pay · Powered by <?= e(APP_NAME) ?></p>
                </div>
                <div class="grid grid-cols-2 gap-2 mb-3">
                    <div class="rounded-lg bg-emerald-500/10 border border-emerald-500/20 px-3 py-2">
                        <p class="text-[10px] text-emerald-300/80 uppercase tracking-wide">Collected</p>
                        <p class="text-sm font-bold text-emerald-300"><?= formatMoney($paidTotal) ?></p>
                    </div>
                    <div class="rounded-lg bg-white/5 border border-gray-700 px-3 py-2">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wide">Payments</p>
                        <p class="text-sm font-bold text-gray-200"><?= $paidCount ?> paid · <?= (int)$qr['scan_count'] ?> scans</p>
                    </div>
                </div>
                <div class="text-xs text-gray-500 space-y-1 mb-4">
                    <p class="font-mono truncate"><?= e($qr['qr_code']) ?></p>
                    <p>
                        <?= !empty($qr['is_test']) ? 'Test' : 'Live' ?> ·
                        <?= match($qr['qr_type'] ?? 'fixed') {
                            'all_methods' => 'All Methods',
                            'upi_dynamic' => 'Dynamic UPI',
                            default => 'Fixed Amount',
                        } ?>
                    </p>
                    <?php if (!empty($qr['description'])): ?><p class="truncate"><?= e($qr['description']) ?></p><?php endif; ?>
                </div>
                <div class="grid grid-cols-3 gap-2 text-xs mb-2">
                    <a href="<?= e($scanUrl) ?>" target="_blank" class="text-center border border-gray-700 py-2 rounded-lg text-sky-400">Open</a>
                    <a href="<?= e($qrImage) ?>&s=600" download="uniweb-<?= e($qr['qr_code']) ?>.png" class="text-center border border-gray-700 py-2 rounded-lg text-emerald-400">Download</a>
                    <button type="button"
                        onclick="printQr(this)"
                        data-img="<?= e($qrImage) ?>&s=600"
                        data-label="<?= e($qr['label']) ?>"
                        data-business="<?= e($businessName) ?>"
                        data-amount="<?= $isFixed ? e(formatMoney((float)$qr['amount'])) : 'Open Amount' ?>"
                        data-template="<?= e($qr['print_template'] ?? 'default') ?>"
                        class="text-center border border-gray-700 py-2 rounded-lg text-amber-300">Print</button>
                </div>
                <div class="grid grid-cols-4 gap-2 text-xs mb-2">
                    <?php
                    $shareText = rawurlencode('Pay ' . ($isFixed ? formatMoney((float)$qr['amount']) . ' to ' : '') . $businessName . " via UniWeb QR\n" . $scanUrl);
                    $wa = 'https://api.whatsapp.com/send?text=' . $shareText;
                    $tg = 'https://t.me/share/url?url=' . rawurlencode($scanUrl) . '&text=' . rawurlencode('Pay ' . $businessName);
                    $mailto = 'mailto:?subject=' . rawurlencode('QR Payment — ' . $businessName) . '&body=' . $shareText;
                    $sms = 'sms:?body=' . $shareText;
                    ?>
                    <a href="<?= e($wa) ?>" target="_blank" class="text-center border border-gray-800 py-1.5 rounded-lg text-emerald-400 hover:bg-white/5">WhatsApp</a>
                    <a href="<?= e($tg) ?>" target="_blank" class="text-center border border-gray-800 py-1.5 rounded-lg text-sky-400 hover:bg-white/5">Telegram</a>
                    <a href="<?= e($mailto) ?>" class="text-center border border-gray-800 py-1.5 rounded-lg text-amber-400 hover:bg-white/5">Email</a>
                    <a href="<?= e($sms) ?>" class="text-center border border-gray-800 py-1.5 rounded-lg text-violet-400 hover:bg-white/5">SMS</a>
                </div>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <a href="transactions.php?qr_id=<?= (int)$qr['id'] ?>" class="text-center border border-violet-500/30 py-2 rounded-lg text-violet-300">View payments</a>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$qr['id'] ?>">
                        <button class="w-full border border-gray-700 py-2 rounded-lg <?= $qr['status'] === 'active' ? 'text-red-400' : 'text-emerald-400' ?>">
                            <?= $qr['status'] === 'active' ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?= renderListPagination($listParams['page'], $qrTotal, $listParams['perPage'], ['q' => $qrQ]) ?>
        <?php endif; ?>
    </div>
</div>

<div class="glass rounded-xl p-5 text-xs text-gray-500">
    <strong class="text-gray-300">How it works:</strong>
    Scan → tracked UniWeb checkout → UPI payment. Test QR shows Instant Test Pay; Live QR uses the merchant's verified UPI collection route.
</div>

<script>
function toggleQrAmount() {
    const type = document.getElementById('qr-type').value;
    const wrap = document.getElementById('qr-amount-wrap');
    const amount = document.getElementById('qr-amount');
    const help = document.getElementById('qr-type-help');
    const fixed = type === 'fixed';
    wrap.classList.toggle('hidden', !fixed);
    amount.required = fixed;
    help.textContent = type === 'all_methods'
        ? 'Customer enters amount, then chooses UPI, Card, Netbanking or Wallet.'
        : (type === 'upi_dynamic'
            ? 'Customer enters amount, then continues directly to UPI checkout.'
            : 'Amount is locked by merchant; customer scans and pays that exact amount.');
}
toggleQrAmount();

function toggleBulkAmount() {
    const type = document.getElementById('bulk-qr-type').value;
    const wrap = document.getElementById('bulk-amount-wrap');
    const amount = document.getElementById('bulk-amount');
    const fixed = type === 'fixed';
    wrap.classList.toggle('hidden', !fixed);
    amount.required = fixed;
}

function printQr(btn) {
    const img = btn.getAttribute('data-img');
    const label = btn.getAttribute('data-label') || '';
    const business = btn.getAttribute('data-business') || '';
    const amount = btn.getAttribute('data-amount') || '';
    const template = btn.getAttribute('data-template') || 'default';
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const w = window.open('', '_blank', 'width=420,height=640');
    if (!w) return;

    const styles = {
        default: '.poster{width:340px;text-align:center;border:1px solid #d1d5db;border-radius:16px;padding:28px 24px}' +
                 '.hint{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af}' +
                 '.biz{font-size:20px;font-weight:800;margin:6px 0 0}' +
                 '.amt{font-size:26px;font-weight:800;color:#059669;margin:6px 0 0}' +
                 '.lbl{font-size:13px;color:#6b7280;margin:2px 0 0}' +
                 '.qrbox{border:2px solid #d1fae5;border-radius:16px;padding:14px;margin:16px auto 0;display:inline-block}' +
                 '.qrbox img{display:block;width:240px;height:240px}' +
                 '.foot{font-size:10px;color:#9ca3af;margin-top:14px}',
        compact: '.poster{width:260px;text-align:center;border:1px solid #d1d5db;border-radius:12px;padding:18px 16px}' +
                 '.hint{font-size:9px;letter-spacing:1px;text-transform:uppercase;color:#9ca3af}' +
                 '.biz{font-size:16px;font-weight:800;margin:4px 0 0}' +
                 '.amt{font-size:20px;font-weight:800;color:#059669;margin:4px 0 0}' +
                 '.lbl{font-size:11px;color:#6b7280;margin:2px 0 0}' +
                 '.qrbox{border:1px solid #d1fae5;border-radius:12px;padding:10px;margin:10px auto 0;display:inline-block}' +
                 '.qrbox img{display:block;width:180px;height:180px}' +
                 '.foot{font-size:8px;color:#9ca3af;margin-top:8px}',
        poster: '.poster{width:380px;text-align:center;border:2px solid #059669;border-radius:20px;padding:36px 28px}' +
                '.hint{font-size:13px;letter-spacing:3px;text-transform:uppercase;color:#059669}' +
                '.biz{font-size:26px;font-weight:900;margin:10px 0 0}' +
                '.amt{font-size:34px;font-weight:900;color:#059669;margin:8px 0 0}' +
                '.lbl{font-size:15px;color:#374151;margin:4px 0 0}' +
                '.qrbox{border:3px solid #d1fae5;border-radius:20px;padding:18px;margin:20px auto 0;display:inline-block}' +
                '.qrbox img{display:block;width:280px;height:280px}' +
                '.foot{font-size:12px;color:#9ca3af;margin-top:18px}'
    };
    const style = styles[template] || styles.default;

    w.document.write(
        '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(label) + ' QR</title>' +
        '<style>*{box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}' +
        'body{margin:0;padding:24px;display:flex;justify-content:center;background:#fff;color:#111827}' +
        style + '</style></head><body>' +
        '<div class="poster">' +
        '<p class="hint">Scan &amp; Pay</p>' +
        '<p class="biz">' + esc(business) + '</p>' +
        (amount && amount !== 'Open Amount' ? '<p class="amt">' + esc(amount) + '</p>' : '<p class="lbl">Enter amount after scan</p>') +
        '<p class="lbl">' + esc(label) + '</p>' +
        '<div class="qrbox"><img src="' + esc(img) + '" alt="QR"></div>' +
        '<p class="foot">Powered by <?= e(APP_NAME) ?></p>' +
        '</div></body></html>'
    );
    w.document.close();
    const doPrint = () => { w.focus(); w.print(); };
    const im = w.document.querySelector('img');
    if (im && !im.complete) { im.onload = doPrint; setTimeout(doPrint, 1200); }
    else { setTimeout(doPrint, 300); }
}
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
