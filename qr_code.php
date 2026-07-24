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

$stmt = $db->prepare('SELECT q.*, pl.link_id, pl.status AS link_status
    FROM merchant_qr_codes q
    LEFT JOIN payment_links pl ON pl.id=q.payment_link_id
    WHERE q.merchant_id=? AND q.is_test=?
    ORDER BY q.created_at DESC');
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
        <p class="text-sm text-gray-500 mt-1">High-throughput QR — up to <strong class="text-gray-300">10 lakh payments/day</strong>. Small amounts (e.g. ₹100 × 10 lakh) are <strong class="text-gray-300">not</strong> blocked as high-frequency. Amounts up to ₹20 crore (bank/UPI per-txn limits may still apply).</p>
    </div>
    <div class="text-right text-xs">
        <p class="font-mono text-gray-400">MID <?= e($merchant['merchant_code'] ?? '') ?></p>
        <span class="inline-block mt-1 px-2 py-0.5 rounded-full <?= $isTest ? 'bg-amber-500/20 text-amber-400' : 'bg-emerald-500/20 text-emerald-400' ?>">
            <?= $isTest ? 'Test QR' : 'Live QR' ?>
        </span>
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
        <p class="text-xs text-gray-500 mb-5"><?= $isTest ? 'Sandbox QR — Instant Test Pay, no real money.' : 'Live QR — 10 lakh payments/day · no high-frequency account lock · amounts up to ₹20 crore (UPI/bank rails may cap each txn lower).' ?></p>
        <form method="POST" class="space-y-4">
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
                <input type="number" id="qr-amount" name="amount" min="1" max="<?= $isTest ? 100 : (int)livePaymentAmountCap() ?>" step="0.01" value="1" class="input-field mt-1">
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
    </div>

    <div class="lg:col-span-2">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            <form method="GET" class="flex gap-2 items-center">
                <label class="sr-only" for="qr-q">Search QR codes</label>
                <input id="qr-q" type="search" name="q" value="<?= e($qrQ) ?>" placeholder="Name / QR code" class="input-field text-sm">
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
            </form>
            <?= renderExportCsvLink('export_qr_codes.php?q=' . rawurlencode($qrQ)) ?>
        </div>
        <?php if (empty($qrCodes)): ?>
        <div class="glass rounded-xl p-10 text-center h-full flex flex-col items-center justify-center">
            <p class="text-4xl mb-3">▦</p>
            <h2 class="font-semibold">No <?= $isTest ? 'Test' : 'Live' ?> QR codes yet</h2>
            <p class="text-sm text-gray-500 mt-2">Create your first fixed-amount QR from the form.</p>
        </div>
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
                    <img src="<?= e($qrImage) ?>" alt="<?= e($qr['label']) ?> QR" width="200" height="200" class="mx-auto rounded-lg mt-2">
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
                        class="text-center border border-gray-700 py-2 rounded-lg text-amber-300">Print</button>
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

function printQr(btn) {
    const img = btn.getAttribute('data-img');
    const label = btn.getAttribute('data-label') || '';
    const business = btn.getAttribute('data-business') || '';
    const amount = btn.getAttribute('data-amount') || '';
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const w = window.open('', '_blank', 'width=420,height=640');
    if (!w) return;
    w.document.write(
        '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(label) + ' QR</title>' +
        '<style>*{box-sizing:border-box;font-family:Arial,Helvetica,sans-serif}' +
        'body{margin:0;padding:24px;display:flex;justify-content:center;background:#fff;color:#111827}' +
        '.poster{width:340px;text-align:center;border:1px solid #d1d5db;border-radius:16px;padding:28px 24px}' +
        '.hint{font-size:11px;letter-spacing:2px;text-transform:uppercase;color:#9ca3af}' +
        '.biz{font-size:20px;font-weight:800;margin:6px 0 0}' +
        '.amt{font-size:26px;font-weight:800;color:#059669;margin:6px 0 0}' +
        '.lbl{font-size:13px;color:#6b7280;margin:2px 0 0}' +
        '.qrbox{border:2px solid #d1fae5;border-radius:16px;padding:14px;margin:16px auto 0;display:inline-block}' +
        '.qrbox img{display:block;width:240px;height:240px}' +
        '.foot{font-size:10px;color:#9ca3af;margin-top:14px}</style></head><body>' +
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
