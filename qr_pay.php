<?php
require_once __DIR__ . '/config.php';
ensureMerchantQrCodes();

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !preg_match('/^QR[A-F0-9]{16}$/', $code)) {
    http_response_code(404);
    exit('QR code not found.');
}

$db = getDB();
$stmt = $db->prepare("SELECT q.*, pl.link_id, pl.status AS link_status, pl.expires_at,
        m.business_name, m.status AS merchant_status, m.upi_id
    FROM merchant_qr_codes q
    JOIN merchants m ON m.id=q.merchant_id
    LEFT JOIN payment_links pl ON pl.id=q.payment_link_id AND pl.merchant_id=q.merchant_id
    WHERE q.qr_code=? LIMIT 1");
$stmt->execute([$code]);
$qr = $stmt->fetch();

if (!$qr || $qr['status'] !== 'active' || $qr['merchant_status'] !== 'active') {
    http_response_code(410);
    exit('This QR code is inactive.');
}

$qrType = (string)($qr['qr_type'] ?? 'fixed');
$isTest = !empty($qr['is_test']);
$createCheckout = static function (float $amount, bool $upiOnly) use ($db, $qr, $isTest): ?string {
    // No per-scan velocity block — one QR must sustain up to ~10 lakh payments/day
    // (shared store NAT used to trip the old 20/10min limit). Abuse is handled by
    // payment_fail velocity + gateway/bank rails.
    $amount = sanitizePaymentAmount($amount, $isTest);
    if ($amount < 1) {
        return null;
    }
    $linkId = generateId('LNK');
    $description = trim((string)($qr['description'] ?? ''));
    $db->prepare('INSERT INTO payment_links
        (link_id, merchant_id, amount, description, expires_at, is_test, status, payment_method, gateway_code, link_label, link_collection_mode, qr_code_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $linkId,
            (int)$qr['merchant_id'],
            $amount,
            $description !== '' ? $description : ('QR payment — ' . $qr['label']),
            date('Y-m-d H:i:s', time() + 86400),
            $isTest ? 1 : 0,
            'active',
            $upiOnly ? 'upi_p2m' : null,
            $upiOnly ? 'direct' : null,
            'QR: ' . $qr['label'],
            $upiOnly ? 'direct_upi' : null,
            (int)$qr['id'],
        ]);
    return $linkId;
};

if ($qrType === 'fixed') {
    $db->prepare('UPDATE merchant_qr_codes SET scan_count=scan_count+1 WHERE id=?')
        ->execute([(int)$qr['id']]);
    $linkId = $createCheckout((float)$qr['amount'], false);
    if ($linkId === null) {
        http_response_code(400);
        exit('Invalid QR amount.');
    }
    header('Cache-Control: no-store');
    redirect('checkout.php?link=' . rawurlencode($linkId));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } else {
        $liveCap = livePaymentAmountCap();
        $amount = sanitizePaymentAmount((float)($_POST['amount'] ?? 0), $isTest);
        if ($amount < 1) {
            $error = 'Enter an amount of at least ₹1.';
        } elseif ($isTest && $amount > 100) {
            $error = 'Test Mode amount must be ₹1–₹100.';
        } elseif (!$isTest && $amount > $liveCap) {
            $error = 'Maximum amount is ₹20,00,00,000 (₹20 crore).';
        } else {
            $upiOnly = $qrType === 'upi_dynamic';
            $linkId = $createCheckout($amount, $upiOnly);
            if ($linkId === null) {
                $error = 'Could not create payment. Check the amount and try again.';
            } else {
                header('Cache-Control: no-store');
                redirect('checkout.php?link=' . rawurlencode($linkId) . ($upiOnly ? '&pay=upi' : ''));
            }
        }
    }
} else {
    $db->prepare('UPDATE merchant_qr_codes SET scan_count=scan_count+1 WHERE id=?')
        ->execute([(int)$qr['id']]);
}

$pageTitle = 'Enter Payment Amount';
$hideNav = true;
$footerVariant = 'checkout';
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex items-center justify-center px-4 py-12">
    <div class="w-full max-w-md">
        <div class="text-center mb-7">
            <?php $logoHref = 'index.php'; $logoSize = 'lg'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <p class="text-xs mt-3 <?= $isTest ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $isTest ? 'TEST MODE · No real money' : 'LIVE PAYMENT' ?></p>
        </div>
        <div class="glass rounded-2xl p-8">
            <p class="text-xs text-gray-500 uppercase"><?= $qrType === 'all_methods' ? 'All Payment Methods QR' : 'Dynamic UPI QR' ?></p>
            <h1 class="text-xl font-bold mt-1"><?= e($qr['label']) ?></h1>
            <p class="text-sm text-gray-500 mt-1 mb-6"><?= e($qr['business_name']) ?></p>
            <?php if (!empty($qr['description'])): ?><p class="text-sm text-gray-400 mb-5"><?= e($qr['description']) ?></p><?php endif; ?>
            <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm p-3 rounded-xl mb-4"><?= e($error) ?></div><?php endif; ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div>
                    <label class="text-sm text-gray-400">Enter Amount (₹)</label>
                    <input type="number" name="amount" min="1" max="<?= $isTest ? 100 : (int)livePaymentAmountCap() ?>" step="0.01" required autofocus class="input-field mt-1 text-2xl font-bold" placeholder="0.00">
                </div>
                <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-4 rounded-xl font-semibold text-lg">
                    <?= $qrType === 'all_methods' ? 'Continue to Payment Methods' : 'Continue to UPI' ?> →
                </button>
            </form>
            <p class="text-[11px] text-gray-600 text-center mt-4">
                <?= $qrType === 'all_methods' ? 'UPI · Cards · Netbanking · Wallets' : 'Secure UPI checkout' ?>
            </p>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
