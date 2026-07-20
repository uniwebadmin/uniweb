<?php
require_once __DIR__ . '/config.php';
requireLogin();

$merchant = getMerchant();
$db = getDB();

$businessName = trim((string)($merchant['business_name'] ?? '')) ?: 'Merchant';
$collectionMode = (string)($merchant['collection_mode'] ?? '');

// Prefer the merchant's own VPA; fall back to an Axis virtual-account UPI when that
// collection mode is active. This QR pays STRAIGHT into the merchant's bank UPI —
// it is not routed through UniWeb checkout/settlement.
$vpa = trim((string)($merchant['upi_id'] ?? ''));
if ($collectionMode === 'axis_va' && !empty($merchant['axis_va_upi'])) {
    $vpa = trim((string)$merchant['axis_va_upi']);
}

$amount = null;
if (isset($_GET['amount']) && $_GET['amount'] !== '') {
    $amount = (float)$_GET['amount'];
    if ($amount < 1) {
        $amount = null;
    } elseif ($amount > 500000) {
        $amount = 500000.0;
    }
}

$note = trim((string)($_GET['note'] ?? ''));
if (mb_strlen($note) > 60) {
    $note = mb_substr($note, 0, 60);
}

$hasVpa = $vpa !== '' && str_contains($vpa, '@');
$intent = $hasVpa ? buildUpiPayIntent($vpa, $businessName, $amount, $note) : '';
$qrImage = $hasVpa ? qrImageUrl($intent, 480) : '';

$pageTitle = 'Instant UPI QR';
require_once __DIR__ . '/header.php';
?>
<style>
@media print {
    body * { visibility: hidden !important; }
    #upi-poster, #upi-poster * { visibility: visible !important; }
    #upi-poster {
        position: absolute; top: 0; left: 0; right: 0; margin: 0 auto;
        width: 340px; box-shadow: none !important; border: 1px solid #d1d5db !important;
        background: #ffffff !important; color: #111827 !important;
    }
    #upi-poster * { color: #111827 !important; }
    #upi-poster .upi-mono { color: #047857 !important; }
}
</style>

<div class="max-w-3xl mx-auto space-y-6">
    <div class="no-print flex flex-wrap items-center justify-between gap-3">
        <div>
            <a href="qr_code.php" class="text-sm text-brand-400 hover:text-brand-300">&larr; Back to QR Codes</a>
            <h1 class="text-2xl font-bold mt-2">Instant UPI QR</h1>
            <p class="text-sm text-gray-500 mt-1 max-w-xl">
                A standard <span class="font-mono">upi://pay</span> QR that opens directly in GPay, PhonePe, Paytm or any UPI app.
                Money settles straight into your UPI-linked bank account (P2M) &mdash; it is not routed through UniWeb checkout.
            </p>
        </div>
    </div>

    <?php if (!$hasVpa): ?>
    <div class="glass rounded-xl p-8 text-center no-print">
        <p class="text-4xl mb-3">&#9888;</p>
        <h2 class="font-semibold text-lg">Add your UPI ID first</h2>
        <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">
            An Instant UPI QR needs a valid VPA (e.g. <span class="font-mono">yourbusiness@bank</span>).
            Add or update it in Collection Settings, then come back to print your QR.
        </p>
        <a href="collection_settings.php" class="inline-block mt-5 bg-brand-600 hover:bg-brand-500 text-white px-5 py-2.5 rounded-xl font-semibold">Open Collection Settings</a>
    </div>
    <?php else: ?>

    <div class="grid lg:grid-cols-3 gap-6">
        <div class="glass rounded-xl p-6 lg:col-span-1 no-print">
            <h2 class="font-semibold mb-1">Customise QR</h2>
            <p class="text-xs text-gray-500 mb-5">Leave amount blank for an open-amount QR (customer types the amount in their UPI app).</p>
            <form method="GET" class="space-y-4">
                <div>
                    <label class="text-sm text-gray-400">Fixed Amount (&#8377;)</label>
                    <input type="number" name="amount" min="1" max="500000" step="0.01" value="<?= $amount !== null ? e((string)$amount) : '' ?>" class="input-field mt-1" placeholder="Open amount">
                </div>
                <div>
                    <label class="text-sm text-gray-400">Payment Note</label>
                    <input type="text" name="note" maxlength="60" value="<?= e($note) ?>" class="input-field mt-1" placeholder="e.g. Counter 1 / Table 5">
                </div>
                <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold">Update QR</button>
            </form>
            <div class="mt-5 space-y-2">
                <button type="button" onclick="window.print()" class="w-full border border-gray-700 py-2.5 rounded-xl text-sm text-sky-400 hover:bg-white/5">&#128424; Print QR</button>
                <a href="<?= e($qrImage) ?>&s=600" download="uniweb-upi-<?= e(preg_replace('/[^a-z0-9]+/i', '-', $businessName)) ?>.png" class="block w-full text-center border border-gray-700 py-2.5 rounded-xl text-sm text-emerald-400 hover:bg-white/5">&#11015; Download PNG</a>
                <button type="button" id="copy-intent" data-intent="<?= e($intent) ?>" class="w-full border border-gray-700 py-2.5 rounded-xl text-sm text-violet-300 hover:bg-white/5">&#128203; Copy UPI link</button>
            </div>
        </div>

        <div class="lg:col-span-2">
            <div id="upi-poster" class="bg-white text-gray-900 rounded-2xl p-8 mx-auto max-w-sm text-center shadow-xl border border-gray-200">
                <p class="text-[11px] tracking-widest uppercase text-gray-400">Scan &amp; Pay with any UPI app</p>
                <h2 class="text-xl font-bold mt-1"><?= e($businessName) ?></h2>
                <?php if ($amount !== null): ?>
                <p class="text-3xl font-extrabold text-emerald-600 mt-2"><?= formatMoney($amount) ?></p>
                <?php else: ?>
                <p class="text-sm text-gray-500 mt-2">Enter amount in your UPI app</p>
                <?php endif; ?>
                <div class="bg-white rounded-2xl p-4 mt-4 border-2 border-emerald-100 inline-block">
                    <img src="<?= e($qrImage) ?>" alt="UPI QR for <?= e($businessName) ?>" width="260" height="260" class="mx-auto">
                </div>
                <?php if ($note !== ''): ?>
                <p class="text-sm text-gray-600 mt-3"><?= e($note) ?></p>
                <?php endif; ?>
                <p class="upi-mono font-mono text-sm text-emerald-700 mt-3 break-all"><?= e($vpa) ?></p>
                <div class="flex items-center justify-center gap-2 mt-4 text-[10px] text-gray-400 tracking-widest uppercase">
                    <span>GPay</span><span>&middot;</span><span>PhonePe</span><span>&middot;</span><span>Paytm</span><span>&middot;</span><span>BHIM</span>
                </div>
                <p class="text-[10px] text-gray-400 mt-2">Powered by <?= e(APP_NAME) ?></p>
            </div>
            <p class="text-xs text-gray-500 mt-4 text-center no-print">
                This is a direct UPI QR. For UniWeb-tracked checkout QRs (cards, netbanking, wallets &amp; history),
                use the <a href="qr_code.php" class="text-sky-400 hover:underline">QR Code Generator</a>.
            </p>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('copy-intent')?.addEventListener('click', function () {
    var link = this.getAttribute('data-intent') || '';
    navigator.clipboard.writeText(link).then(function () {
        var btn = document.getElementById('copy-intent');
        var original = btn.textContent;
        btn.textContent = 'Copied!';
        setTimeout(function () { btn.textContent = original; }, 1500);
    });
});
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
