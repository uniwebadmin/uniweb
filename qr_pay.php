<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/qr_events.php';
ensureMerchantQrCodes();

// E3: Load method availability helper
if (!function_exists('get_available_pay_methods')) {
    require_once __DIR__ . '/includes/payment_methods.php';
}

/**
 * Branded dead-end for the public QR scan path — a customer who scans a stale,
 * inactive or malformed QR must never see a bare white exit() screen during a
 * merchant/bank demo. Mirrors checkout.php's renderCheckoutUnavailable().
 */
function renderQrUnavailable(string $heading, string $detail, int $status = 404): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Cache-Control: no-store');
    }
    $pageTitle = $heading;
    $hideNav = true;
    $footerVariant = 'checkout';
    require_once __DIR__ . '/header.php';
    echo '<div class="min-h-screen flex items-center justify-center px-4 py-12"><div class="w-full max-w-md">'
        . '<div class="glass rounded-2xl p-8 text-center">'
        . '<h1 class="text-xl font-semibold mb-2">' . e($heading) . '</h1>'
        . '<p class="text-sm text-gray-400 mb-6">' . e($detail) . '</p>'
        . '<a href="index.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Go to UniWeb</a>'
        . ' <a href="contact.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Contact support</a>'
        . '</div></div></div>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$code = trim((string)($_GET['code'] ?? ''));
if ($code === '' || !preg_match('/^QR[A-F0-9]{16}$/', $code)) {
    renderQrUnavailable(
        'QR code not found',
        'This QR does not point to a valid UniWeb payment code. Please rescan the merchant QR, or contact the merchant for a fresh one.'
    );
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

$now = time();
$notYetValid = !empty($qr['valid_from']) && strtotime($qr['valid_from']) > $now;
$expired = !empty($qr['expires_at']) && strtotime($qr['expires_at']) < $now;

// Instant UPI QRs are tracked/redirected by qr_upi_redirect.php, not this checkout-link flow.
if ($qr && ($qr['qr_type'] ?? '') === 'instant_upi') {
    header('Cache-Control: no-store');
    redirect('qr_upi_redirect.php?code=' . rawurlencode($code));
}

// Log scan event for analytics/audit if QR exists and is scannable
if ($qr && function_exists('logQrEvent')) {
    logQrEvent($db, (int)$qr['id'], (int)$qr['merchant_id'], 'scan', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null]);
}

if (!$qr || $qr['status'] !== 'active' || $qr['merchant_status'] !== 'active' || $notYetValid || $expired) {
    if ($qr && $notYetValid) {
        renderQrUnavailable(
            'QR not active yet',
            'This payment QR will be active from ' . date('d M Y H:i', strtotime($qr['valid_from'])) . '. Please try again later.',
            403
        );
    }
    if ($qr && $expired) {
        renderQrUnavailable(
            'This QR code has expired',
            'This payment QR is no longer valid. Please ask the merchant for a current QR code to complete your payment.',
            410
        );
    }
    renderQrUnavailable(
        'This QR code is inactive',
        'This payment QR is no longer active. Please ask the merchant for a current QR code to complete your payment.',
        410
    );
}

$qrType = (string)($qr['qr_type'] ?? 'fixed');
$isTest = !empty($qr['is_test']);
// E3: Check if UPI methods are available for this merchant
$upiMethods = get_available_pay_methods((int)$qr['merchant_id']);
$upiKeys = array_column($upiMethods, 'key');
$upiAvailable = in_array('upi_p2m', $upiKeys, true) || in_array('axis_va', $upiKeys, true) || in_array('payu_upi', $upiKeys, true);
$createCheckout = static function (float $amount, bool $upiOnly) use ($db, $qr, $isTest, $upiAvailable): ?string {
    // E3: Block UPI-only checkout if UPI method is disabled
    if ($upiOnly && !$upiAvailable) {
        return null;
    }
    // QR payments are not throttled by UniWeb per scan; gateway/bank rails enforce limits.
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
        renderQrUnavailable(
            'QR amount unavailable',
            'This QR has an invalid preset amount and a payment could not be started. Please ask the merchant to regenerate the QR code.',
            400
        );
    }
    header('Cache-Control: no-store');
    redirect('checkout.php?link=' . rawurlencode($linkId));
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } else {
        // E7: Rate limit pay attempts (10 per 10 minutes per IP)
        $rateKey = 'qr_pay_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rateFile = sys_get_temp_dir() . '/uniweb_rl_' . md5($rateKey);
        $rateCount = 0;
        if (is_file($rateFile)) {
            $rateData = json_decode((string)file_get_contents($rateFile), true);
            if ($rateData && (time() - $rateData['ts']) < 600) {
                $rateCount = (int)$rateData['count'];
            }
        }
        if ($rateCount >= 10) {
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } else {
            file_put_contents($rateFile, json_encode(['ts' => time(), 'count' => $rateCount + 1]));
        }
    }
    if ($error === '') {
        $liveCap = livePaymentAmountCap();
        $amount = sanitizePaymentAmount((float)($_POST['amount'] ?? 0), $isTest);
        if ($amount < 1) {
            $error = 'Enter an amount of at least ₹1.';
        } elseif ($isTest && $amount > 100) {
            $error = 'Test Mode amount must be ₹1–₹100.';
        } elseif (!$isTest && $amount > $liveCap) {
            $error = 'Amount exceeds the maximum allowed limit.';
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
                    <input type="number" name="amount" min="1" step="0.01" required autofocus class="input-field mt-1 text-2xl font-bold" placeholder="0.00">
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
