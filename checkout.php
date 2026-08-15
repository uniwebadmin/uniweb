<?php
require_once __DIR__ . '/config.php';
if (!function_exists('initErrorCatcher') && is_file(__DIR__ . '/includes/error_catcher.php')) {
    require_once __DIR__ . '/includes/error_catcher.php';
}
// Defense in depth: live config.php is gitignored and may omit 'qr_svg' from $__includes.
if (!function_exists('qrImageUrl')) {
    require_once __DIR__ . '/includes/qr_svg.php';
}
if (!function_exists('getMerchantEnabledMethods') && is_file(__DIR__ . '/includes/provision.php')) {
    require_once __DIR__ . '/includes/provision.php';
}
if (!function_exists('getCheckoutPaymentMethods') && is_file(__DIR__ . '/includes/collection.php')) {
    require_once __DIR__ . '/includes/collection.php';
}

require_once __DIR__ . '/includes/checkout_mode_banner.php';
require_once __DIR__ . '/includes/checkout_customize.php';

/**
 * Render a branded, navigable checkout error page instead of a bare white die() screen.
 * Used for every dead-end (missing / invalid / expired / inactive) payment link so a
 * customer always lands on a page with clear next steps.
 */
function renderCheckoutUnavailable(string $heading, string $detail, int $status = 404): void
{
    http_response_code($status);
    $pageTitle = $heading;
    require_once __DIR__ . '/header.php';
    echo '<section class="pt-28 pb-20 px-4"><div class="max-w-lg mx-auto glass rounded-2xl p-8 text-center">'
        . '<h1 class="text-xl font-semibold mb-2">' . e($heading) . '</h1>'
        . '<p class="text-sm text-gray-400 mb-6">' . e($detail) . '</p>'
        . '<a href="index.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Home</a>'
        . '</div></section>';
    require_once __DIR__ . '/footer.php';
    exit;
}

$linkId = $_GET['link'] ?? $_POST['udf1'] ?? '';
if (!$linkId) {
    renderCheckoutUnavailable(
        'Payment link not found',
        'This checkout URL is missing a valid payment link. Please open a link from your merchant dashboard.'
    );
}
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = getDB();
if (!function_exists('ensureMissingColumns') && is_file(__DIR__ . '/includes/schema_ensure.php')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
if (function_exists('ensureMissingColumns')) {
    ensureMissingColumns();
}
if (!function_exists('ensurePaymentPackSchema') && is_file(__DIR__ . '/includes/provision.php')) {
    require_once __DIR__ . '/includes/provision.php';
}
if (function_exists('ensurePaymentPackSchema')) {
    ensurePaymentPackSchema();
}
if (!function_exists('ensureMerchantQrCodes') && is_file(__DIR__ . '/includes/schema_ensure.php')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
if (function_exists('ensureMerchantQrCodes')) {
    ensureMerchantQrCodes();
}

$checkoutSelectFull = "SELECT pl.id, pl.link_id, pl.amount AS payment_amount, pl.description, pl.customer_name, pl.customer_phone, pl.status AS link_status, pl.expires_at, pl.is_test, pl.merchant_id AS link_merchant_id, pl.payment_method, pl.gateway_code, pl.link_label, pl.link_collection_mode, pl.pack_id, pl.qr_code_id,
    m.id AS merchant_id, m.business_name, m.upi_id, m.merchant_code, m.account_mode, m.kyc_status,
    m.collection_mode, m.commission_rate, m.enabled_methods, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, m.payu_child_key,
    m.razorpay_linked_account_id, m.cashfree_vendor_id, m.email AS merchant_email, m.phone AS merchant_phone
    FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ?";
$checkoutSelectBasic = "SELECT pl.id, pl.link_id, pl.amount AS payment_amount, pl.description, pl.customer_name, pl.customer_phone, pl.status AS link_status, pl.expires_at, pl.is_test, pl.merchant_id AS link_merchant_id, pl.payment_method, pl.gateway_code,
    m.id AS merchant_id, m.business_name, m.upi_id, m.merchant_code, m.account_mode, m.kyc_status,
    m.collection_mode, m.commission_rate, m.enabled_methods, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, m.payu_child_key,
    m.razorpay_linked_account_id, m.cashfree_vendor_id, m.email AS merchant_email, m.phone AS merchant_phone
    FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ?";
$link = false;
try {
    $stmt = $db->prepare($checkoutSelectFull);
    $stmt->execute([$linkId]);
    $link = $stmt->fetch();
} catch (Throwable $e) {
    logPlatformError('warning', 'Checkout full link query failed: ' . $e->getMessage(), ['link_id' => $linkId]);
    try {
        $stmt = $db->prepare($checkoutSelectBasic);
        $stmt->execute([$linkId]);
        $link = $stmt->fetch();
        if (is_array($link)) {
            $link['link_label'] = $link['link_label'] ?? null;
            $link['link_collection_mode'] = $link['link_collection_mode'] ?? null;
            $link['pack_id'] = $link['pack_id'] ?? null;
            $link['qr_code_id'] = $link['qr_code_id'] ?? null;
        }
    } catch (Throwable $e2) {
        logPlatformError('error', 'Checkout basic link query failed: ' . $e2->getMessage(), ['link_id' => $linkId]);
        $link = false;
    }
}
if (!$link) {
    renderCheckoutUnavailable(
        'Payment link not found',
        'This payment link has expired or does not exist. Please ask the merchant for a fresh link.'
    );
}
$amtOnly = $db->prepare('SELECT amount, status FROM payment_links WHERE link_id = ?');
$amtOnly->execute([$linkId]);
$plRow = $amtOnly->fetch();
$isTestCheckout = !empty($link['is_test']) || merchantAccountMode($link) === 'test';
$payAmount = sanitizePaymentAmount(round((float)($plRow['amount'] ?? 0), 2), $isTestCheckout);
$link['amount'] = $payAmount;
$link['status'] = $plRow['status'] ?? 'active';
// Checkout customization
$wlBrand = resolveCheckoutCustomize($link);
// Instant Test Pay: Test Mode links only
$allowInstantPay = $isTestCheckout;
if ($link['status'] !== 'active') {
    renderCheckoutUnavailable(
        'Payment link no longer active',
        'This payment link is no longer active. Please ask the merchant for a new link.',
        410
    );
}
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    $db->prepare("UPDATE payment_links SET status = 'expired' WHERE id = ?")->execute([$link['id']]);
    renderCheckoutUnavailable(
        'Payment link expired',
        'This payment link has expired. Please ask the merchant for a fresh link.',
        410
    );
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    incrementPaymentLinkView((int)$link['id']);
}
$checkoutPostBlocked = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isExternalPayuPost = !empty($_POST['hash']) && !empty($_POST['key']);
    if (!$isExternalPayuPost && !verifyCsrf($_POST['csrf_token'] ?? '')) {
        $checkoutPostBlocked = true;
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$checkoutPostBlocked) {
    persistCheckoutCustomerDetails($link, $_POST);
}
$checkoutCustomerError = '';
if ($checkoutPostBlocked) {
    $checkoutCustomerError = 'Security token expired. Refresh the page and try again.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customerCheck = validateCheckoutCustomerDetails($_POST);
    if (!$customerCheck['ok']) {
        $checkoutCustomerError = $customerCheck['message'];
    }
}

$handler = resolveCheckoutHandlerForLink($link);
$split = calculateSplitBreakdown($payAmount, $link);
$error = '';
$success = false;
$successTxnId = null;
$brandName = COMPANY_LEGAL_NAME;
$displayMerchant = $link['business_name'];
$logoUrl = APP_URL . '/assets/img/uniweb-logo.svg';
$upiData = buildMerchantUpiIntent($link);
$upiPa = trim((string)($link['upi_id'] ?? ''));
if ($upiPa === '') {
    $upiPa = trim((string)($link['axis_va_upi'] ?? ''));
}
$whatsappLink = buildWhatsAppPaymentLink($link);
$paymentMethods = [];
try {
    $paymentMethods = getCheckoutPaymentMethods($link);
} catch (Throwable $e) {
    logPlatformError('error', 'Checkout method list failed: ' . $e->getMessage(), ['link_id' => $linkId]);
    $paymentMethods = [['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'Google Pay · PhonePe · Paytm', 'icon' => '📱', 'type' => 'p2m']];
}
if ($paymentMethods === []) {
    $paymentMethods = [['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'No other methods enabled', 'icon' => '📱', 'type' => 'p2m']];
}
$lockedMethod = !empty($link['payment_method']) ? ($link['link_label'] ?? $link['payment_method']) : null;
$selectedPay = $_GET['pay'] ?? $_POST['pay'] ?? 'upi';
$validKeys = array_column($paymentMethods, 'key');
if (!in_array($selectedPay, $validKeys, true)) $selectedPay = $validKeys[0] ?? 'upi';
$currentMethod = null;
foreach ($paymentMethods as $m) { if ($m['key'] === $selectedPay) { $currentMethod = $m; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$checkoutPostBlocked && ($_POST['action'] ?? '') === 'test_pay' && $allowInstantPay) {
    if ($checkoutCustomerError !== '') {
        $error = $checkoutCustomerError;
    } else {
        // E7: Rate limit test pay attempts (10 per 10 minutes per IP)
        $rateKey = 'checkout_pay_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $rateFile = sys_get_temp_dir() . '/uniweb_rl_' . md5($rateKey);
        $rateCount = 0;
        if (is_file($rateFile)) {
            $rateData = json_decode((string)file_get_contents($rateFile), true);
            if ($rateData && (time() - $rateData['ts']) < 600) {
                $rateCount = (int)$rateData['count'];
            }
        }
        if ($rateCount >= 10) {
            $error = 'Too many payment attempts. Please wait a few minutes and try again.';
        } else {
            file_put_contents($rateFile, json_encode(['ts' => time(), 'count' => $rateCount + 1]));
            $method = preg_replace('/[^a-z0-9_]/i', '', $selectedPay) ?: 'test';
        $testReference = 'TEST' . strtoupper(bin2hex(random_bytes(6)));
        try {
        $order = createBoundPaymentOrder($link, 'sandbox', 'instant:' . $testReference);
        bindProviderOrder((int)$order['id'], 'sandbox', (string)$order['order_ref']);
        $captured = captureVerifiedPaymentOrder([
            'provider' => 'sandbox',
            'provider_order_id' => (string)$order['order_ref'],
            'provider_payment_id' => 'sandbox_' . $testReference,
            'amount' => $payAmount,
            'currency' => 'INR',
            'captured' => true,
            'signature_verified' => true,
            'provider_verified' => true,
            'reference' => $testReference,
        ]);
        $txnDbId = (int)($captured['transaction_id'] ?? 0);
        $txnRow = getDB()->prepare('SELECT txn_id FROM transactions WHERE id = ?');
        $txnRow->execute([$txnDbId]);
        $successTxnId = $txnRow->fetchColumn() ?: null;
        $success = true;
        } catch (Throwable $e) {
            $error = 'Test payment could not be recorded. Try again in a moment.';
            logPlatformError('error', 'Instant test pay failed: ' . $e->getMessage(), ['link_id' => $linkId]);
        }
        }
    }
}

$razorpayKey = function_exists('getPartnerSetting') ? getPartnerSetting('razorpay', 'razorpay_key_id', '') : getSetting('razorpay_key_id', '');
$razorpayOrder = null;
$cashfreeSession = null;
$payuForms = [];
$axisVa = null;
$decentroQr = null;
$withPayuSplit = $handler === 'payu_split';

if ($handler === 'axis_va') {
    if (!function_exists('pickLeastBusyVirtualAccount')) {
        require_once __DIR__ . '/includes/va_manager.php';
    }
    $pickedVa = pickLeastBusyVirtualAccount((int)$link['merchant_id']);
    if ($pickedVa) {
        $axisVa = ['va_number' => $pickedVa['va_number'], 'va_ifsc' => $pickedVa['ifsc'], 'va_upi' => $pickedVa['upi_id'], 'axis_va_id' => $pickedVa['va_id']];
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($success)) {
            recordVirtualAccountUsage((int)$pickedVa['id']);
        }
    } else {
        $axisVa = ensureAxisVirtualAccount((int)$link['merchant_id']);
    }
    if ($axisVa) {
        $link['axis_va_upi'] = $axisVa['va_upi'] ?? $link['axis_va_upi'];
        $upiData = buildMerchantUpiIntent($link);
        $upiPa = trim((string)($link['axis_va_upi'] ?? $link['upi_id'] ?? ''));
        if ($upiPa === '') {
            $upiPa = trim((string)($link['upi_id'] ?? ''));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  try {
    if ($selectedPay === 'upi' && function_exists('decentroSandboxCheckoutAvailable') && decentroSandboxCheckoutAvailable($link)) {
        $decentroQr = createDecentroSandboxCheckoutQr($link);
    }
    if (isGatewayConfigured('payu') && function_exists('buildPayUPaymentForm')) {
        foreach ($paymentMethods as $m) {
            if (($m['type'] ?? '') === 'payu' && !empty($m['pg'])) {
                try {
                    $payuForms[$m['key']] = buildPayUPaymentForm($link, $link, $withPayuSplit, $m['pg'], $m['key'], $payAmount);
                } catch (Throwable $e) {
                    logPlatformError('warning', 'PayU checkout form failed: ' . $e->getMessage(), ['link_id' => $linkId, 'pay' => $m['key']]);
                }
            }
        }
    }
    $smartRouted = null;
    $bothTabsAvailable = in_array('razorpay', $validKeys, true) && in_array('cashfree', $validKeys, true);
    if (($selectedPay === 'razorpay' || $selectedPay === 'cashfree') && $handler !== 'razorpay_route' && $handler !== 'cashfree_route' && $bothTabsAvailable) {
        // Smart routing: try the healthier gateway first, auto-divert to the other tab on failure — only when both tabs exist for this merchant.
        $returnUrl = APP_URL . '/payment_cashfree_return.php?order_id={order_id}';
        $smartRouted = createCardOrderWithSmartRouting($payAmount, $link, $returnUrl);
        if ($smartRouted['routed_to'] === 'razorpay') {
            $razorpayOrder = $smartRouted['razorpay'];
            $selectedPay = 'razorpay';
        } elseif ($smartRouted['routed_to'] === 'cashfree') {
            $cashfreeSession = $smartRouted['cashfree']['payment_session_id'] ?? null;
            $selectedPay = 'cashfree';
        }
        if ($smartRouted['diverted']) {
            foreach ($paymentMethods as $m) { if ($m['key'] === $selectedPay) { $currentMethod = $m; break; } }
        }
    } elseif ($selectedPay === 'razorpay' || ($handler === 'razorpay_route' && !isGatewayConfigured('payu'))) {
        if (isGatewayConfigured('razorpay')) {
            try {
                $razorpayOrder = createBoundGatewayCheckoutOrder($link, 'razorpay');
            } catch (Throwable $e) {
                $error = 'Razorpay checkout is temporarily unavailable.';
                logPlatformError('error', 'Bound Razorpay order creation failed.', ['error' => $e->getMessage(), 'link_id' => $linkId]);
            }
        }
    } elseif ($selectedPay === 'cashfree' && isGatewayConfigured('cashfree')) {
        $returnUrl = APP_URL . '/payment_cashfree_return.php?order_id={order_id}';
        $cf = null;
        try {
            $cf = createBoundGatewayCheckoutOrder($link, 'cashfree', $returnUrl);
        } catch (Throwable $e) {
            $cf = null;
            $error = 'Cashfree checkout is temporarily unavailable.';
            $level = str_contains($e->getMessage(), 'do not match the payment order mode') ? 'warning' : 'error';
            logPlatformError($level, 'Bound Cashfree order creation failed: ' . $e->getMessage(), ['link_id' => $linkId]);
        }
        $cashfreeSession = is_array($cf) ? ($cf['payment_session_id'] ?? null) : null;
        if ($cashfreeSession === null && ($error ?? '') === '') {
            $error = 'Cashfree is not available in this Test/Live mode. Use UPI or another method.';
        }
    }
  } catch (Throwable $e) {
    logPlatformError('error', 'Checkout gateway init failed: ' . $e->getMessage(), ['link_id' => $linkId]);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$checkoutPostBlocked && $selectedPay === 'upi' && in_array($handler, ['direct_upi', 'axis_va'], true) && !$success) {
    $utr = trim($_POST['utr'] ?? '');
    // QR payments are not throttled by UniWeb; payment_fail velocity stays for non-QR checkout only.
    $fromQr = !empty($link['qr_code_id']);
    $velocity = $fromQr ? ['blocked' => false, 'retry_after_minutes' => 0] : checkVelocityBlock('payment_fail');
    if ($checkoutCustomerError !== '' && $utr !== '') {
        $error = $checkoutCustomerError;
    } elseif (!empty($velocity['blocked'])) {
        $error = velocityBlockMessage('payment_fail') . ' (retry in ~' . $velocity['retry_after_minutes'] . ' min)';
    } elseif ($allowInstantPay && ($_POST['action'] ?? '') === 'test_pay') {
        // handled above
    } elseif ($utr === '') {
        $error = $allowInstantPay
            ? 'Use Instant Test Pay above, or enter a UPI reference (10–22 characters).'
            : 'After paying to the UPI ID, enter your UPI UTR / reference (10–22 characters).';
    } else {
        $result = confirmUpiPaymentForLink($link, $utr, $allowInstantPay);
        if (!$result['ok']) {
            $error = $result['error'] ?? 'Payment confirmation failed.';
            if (!$fromQr) {
                recordVelocityEvent('payment_fail', $link['link_id'] ?? null);
            }
        } else {
            $successTxnId = $result['txn_id'] ?? null;
            $success = true;
        }
    }
}

$pageTitle = $wlBrand['active'] && !empty($wlBrand['checkout_title'])
    ? $wlBrand['checkout_title']
    : 'Secure Payment — ' . ($wlBrand['active'] ? $wlBrand['brand_name'] : APP_NAME);
$hideNav = true;
$hideFooter = true;
$footerVariant = 'checkout';
$bodyClass = 'bg-dark-950';
require_once __DIR__ . '/header.php';
if ($wlBrand['active'] && !empty($wlBrand['css'])):
?>
<style><?= $wlBrand['css'] ?></style>
<?php
endif;
?>

<div class="min-h-screen flex flex-col">
    <header class="border-b border-gray-800 bg-dark-900/95 px-4 py-4">
        <div class="max-w-lg mx-auto flex items-center justify-between">
            <?php
            if ($wlBrand['active'] && !empty($wlBrand['logo_url'])):
            ?>
            <a href="index.php" class="flex items-center gap-2">
                <img src="<?= e($wlBrand['logo_url']) ?>" alt="<?= e($wlBrand['brand_name']) ?>" class="h-8 w-auto max-w-[180px]" onerror="this.style.display='none'">
            </a>
            <?php
            else:
                $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo.php';
            endif;
            ?>
            <span class="text-xs text-sky-400 hidden sm:inline"><?= $wlBrand['active'] && !empty($wlBrand['checkout_subtitle']) ? e($wlBrand['checkout_subtitle']) : 'Secure Checkout' ?></span>
        </div>
    </header>

    <?php if ($allowInstantPay): ?>
    <div class="bg-amber-500 text-dark-900 text-center text-sm font-semibold py-2 px-4">⚡ TEST MODE — Sandbox · Use Instant Test Pay · No real money</div>
    <?php elseif (!$isTestCheckout): ?>
    <div class="bg-emerald-600/20 border-b border-emerald-500/30 text-center text-xs text-emerald-300 py-2 px-4">● LIVE MODE — Real UPI settlement · For Instant Test Pay, switch merchant dashboard to Test Mode and create a new link</div>
    <?php endif; ?>
    <?php if ($lockedMethod): ?>
    <div class="bg-sky-500/10 border-b border-sky-500/20 text-center text-xs text-sky-300 py-2">Dedicated link: <?= e($lockedMethod) ?> · <?= e(checkoutHandlerLabel($handler)) ?></div>
    <?php else: ?>
    <div class="bg-dark-900 border-b border-gray-800 text-center text-xs text-gray-500 py-2"><?= e(checkoutHandlerLabel($handler)) ?></div>
    <?php endif; ?>

    <main class="flex-1 flex items-center justify-center px-4 py-4">
        <div class="w-full max-w-lg">
            <?php if ($success): ?>
            <div class="glass rounded-2xl p-8 text-center border border-brand-500/20">
                <div class="w-16 h-16 bg-brand-500/20 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-brand-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h2 class="text-xl font-bold mb-2">Payment Successful</h2>
                <p class="text-gray-400 text-sm"><?= e($wlBrand['active'] && !empty($wlBrand['success_message']) ? $wlBrand['success_message'] : 'Your payment has been confirmed. A receipt will be sent to the merchant.') ?></p>
                <?php if ($successTxnId): ?>
                <p class="text-xs text-gray-500 mt-3 font-mono">Transaction ID: <?= e($successTxnId) ?></p>
                <?php endif; ?>
                <?php if ($wlBrand['active'] && !empty($wlBrand['redirect_url'])): ?>
                <p class="text-xs text-gray-500 mt-4">You will be redirected shortly…</p>
                <script>setTimeout(function(){ window.location.href = <?= json_encode($wlBrand['redirect_url']) ?>; }, 3000);</script>
                <a href="<?= e($wlBrand['redirect_url']) ?>" class="inline-block mt-4 text-sm text-sky-400 hover:underline">Continue →</a>
                <?php else: ?>
                <a href="payment_status.php<?= $successTxnId ? '?txn_id=' . rawurlencode((string)$successTxnId) : '' ?>" class="inline-block mt-6 text-sm text-sky-400 hover:underline">Track payment status →</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="glass rounded-2xl overflow-hidden border border-gray-800">
                <div class="bg-gradient-to-r from-sky-600/20 to-cyan-600/10 px-6 py-5 border-b border-gray-800">
                    <p class="text-xs text-sky-400 uppercase tracking-wider mb-1"><?= $lockedMethod ? 'Payment Method' : 'Choose Payment Method' ?></p>
                    <h2 class="text-lg font-semibold"><?= e($displayMerchant) ?></h2>
                    <?php if ($link['description']): ?><p class="text-sm text-gray-400 mt-1"><?= e($link['description']) ?></p><?php endif; ?>
                </div>
                <div class="p-6 text-center border-b border-gray-800">
                    <p class="text-sm text-gray-500 mb-1">Amount Payable</p>
                    <p class="text-4xl font-bold text-sky-400"><?= formatMoney($payAmount) ?></p>
                    <p class="text-xs text-gray-600 mt-1 font-mono">Ref: <?= e($link['link_id']) ?></p>
                    <?php
                    $pgKeysMissing = !$isTestCheckout
                        && !isGatewayConfigured('payu')
                        && !isGatewayConfigured('razorpay')
                        && !isGatewayConfigured('cashfree');
                    ?>
                    <?php if ($pgKeysMissing): ?>
                    <p class="text-xs text-amber-300/90 mt-3">Card / Netbanking need partner keys. UPI / QR still works if a UPI ID is set.</p>
                    <?php endif; ?>
                    <?php if (!$isTestCheckout && $split['platform_fee'] > 0): ?>
                    <details class="mt-3">
                        <summary class="text-xs text-gray-600 cursor-pointer hover:text-gray-400">Fee breakdown</summary>
                        <div class="text-xs text-gray-500 mt-2 space-y-1 text-left max-w-[240px] mx-auto">
                            <div class="flex justify-between"><span>Amount</span><span class="font-mono"><?= formatMoney($split['gross']) ?></span></div>
                            <div class="flex justify-between"><span>Platform fee</span><span class="font-mono text-amber-400">-<?= formatMoney($split['platform_fee']) ?></span></div>
                            <div class="flex justify-between border-t border-gray-800 pt-1"><span>Merchant receives</span><span class="font-mono text-emerald-400"><?= formatMoney($split['merchant_net']) ?></span></div>
                        </div>
                    </details>
                    <?php endif; ?>
                </div>

                <!-- Payment method tabs -->
                <div class="px-4 pt-4 pb-2 border-b border-gray-800">
                    <?php if (count($paymentMethods) === 1): ?>
                    <div class="rounded-xl px-3 py-3 text-sm border border-sky-500 bg-sky-500/10 text-sky-300 text-center">
                        <span class="text-lg mr-1"><?= $paymentMethods[0]['icon'] ?></span>
                        <strong><?= e($paymentMethods[0]['label']) ?></strong>
                        <span class="block text-xs text-gray-500 mt-1"><?= e($paymentMethods[0]['sub'] ?? '') ?></span>
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-3 sm:grid-cols-3 gap-2">
                        <?php foreach ($paymentMethods as $m): ?>
                        <a href="checkout.php?link=<?= e($linkId) ?>&pay=<?= e($m['key']) ?>"
                           class="text-center rounded-xl px-2 py-3 text-xs border transition <?= $selectedPay === $m['key'] ? 'border-sky-500 bg-sky-500/10 text-sky-300' : 'border-gray-800 text-gray-400 hover:border-gray-600' ?>">
                            <span class="text-lg block mb-1"><?= $m['icon'] ?></span>
                            <span class="font-medium block leading-tight"><?= e($m['label']) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="px-6 py-5">
                    <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-4"><?= e($wlBrand['active'] && !empty($wlBrand['failure_message']) ? $wlBrand['failure_message'] . ' — ' . $error : $error) ?></div><?php endif; ?>
                    <?php if ($currentMethod): ?>
                    <p class="text-sm font-medium text-gray-300 mb-1"><?= e((string)($currentMethod['label'] ?? '')) ?></p>
                    <p class="text-xs text-gray-500 mb-4"><?= e((string)($currentMethod['sub'] ?? '')) ?></p>
                    <?php endif; ?>

                    <?php if ($selectedPay === 'upi'): ?>
                    <?php if (!empty($decentroQr['ok'])): ?>
                    <div class="bg-white rounded-2xl p-5 text-center mb-4 border-2 border-violet-200 shadow-lg shadow-violet-900/10">
                        <p class="text-[10px] text-violet-600 uppercase tracking-widest mb-2">Decentro Sandbox Dynamic QR</p>
                        <img src="<?= e($decentroQr['image_url']) ?>" alt="Decentro payment QR" class="mx-auto rounded-lg" width="220" height="220">
                        <p class="text-dark-900 text-xs mt-3">Scan with any UPI app to complete this sandbox payment.</p>
                    </div>
                    <p class="text-xs text-center text-violet-300 mb-4" id="upi-poll-status">Waiting for Decentro payment confirmation. Do not close this page.</p>
                    <?php else: ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="upi">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">⚡ Instant Test Pay <?= formatMoney($payAmount) ?> — UPI</button>
                        <p class="text-xs text-amber-400/80 text-center mt-2">Sandbox only — completes instantly without a real UPI transfer.</p>
                    </form>
                    <details class="mb-4">
                        <summary class="cursor-pointer text-xs text-gray-500 mb-2">Optional: scan QR / enter UTR (sandbox)</summary>
                    <?php endif; ?>
                    <?php if ($axisVa): ?>
                    <div class="bg-dark-900 rounded-xl p-4 mb-4 text-sm space-y-1">
                        <p class="text-sky-400 font-medium text-xs">Axis Virtual Account</p>
                        <p class="font-mono text-xs">A/C <?= e((string)($axisVa['va_number'] ?? '')) ?> · IFSC <?= e((string)($axisVa['ifsc'] ?? $axisVa['va_ifsc'] ?? '')) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php if ($upiPa === ''): ?>
                    <div class="bg-red-500/10 border border-red-500/30 text-red-300 text-sm px-4 py-3 rounded-xl mb-4">
                        No UPI ID on this merchant. Add a real VPA under My Account, or use <strong class="text-white">Test Mode → Instant Test Pay</strong>.
                    </div>
                    <?php else: ?>
                    <div class="bg-white rounded-2xl p-5 text-center mb-4 border-2 border-emerald-100 shadow-lg shadow-emerald-900/10">
                        <p class="text-[10px] text-gray-400 uppercase tracking-widest mb-2">Scan &amp; Pay via UPI</p>
                        <img src="<?= e(qrImageUrl($upiData, 200)) ?>" alt="UPI QR" class="mx-auto rounded-lg" width="200" height="200">
                        <p class="text-dark-900 text-xs mt-3 font-mono break-all"><?= e($upiPa) ?></p>
                        <p class="text-[10px] text-gray-400 mt-1">Secured by UniWeb</p>
                    </div>
                    <?php endif; ?>
                    <p class="text-xs text-center text-gray-500 mb-3" id="upi-poll-status"><?= $allowInstantPay ? 'Sandbox — Instant Test Pay above marks this link paid.' : 'Waiting for verified bank or gateway confirmation. Do not close this page.' ?></p>
                    <?php if ($upiPa !== ''): ?>
                    <a href="<?= e($upiData) ?>" class="block text-center bg-sky-600 hover:bg-sky-500 text-white py-3 rounded-xl font-semibold text-sm mb-2">Open UPI App →</a>
                    <p class="text-[11px] text-center text-gray-600 mb-3">Opens Google Pay, PhonePe, Paytm or another UPI app on your phone.</p>
                    <a href="<?= e($whatsappLink) ?>" target="_blank" rel="noopener" class="block text-center bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 py-2 rounded-xl text-sm mb-4">WhatsApp Pay Link</a>
                    <?php endif; ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <input type="text" name="utr" placeholder="Test UPI reference" class="input-field">
                        <button type="submit" class="w-full border border-gray-700 text-gray-300 py-3 rounded-xl font-semibold">Confirm Test UPI Payment</button>
                        <p class="text-[11px] text-gray-600 text-center">Sandbox only: use any unique 10–22 character test reference.</p>
                    </form>
                    <?php else: ?>
                    <div class="bg-sky-500/10 border border-sky-500/30 text-sky-200 text-xs px-4 py-3 rounded-xl">For your safety, manually entered UTRs cannot confirm a Live payment. UniWeb will update this page only after a signed bank or payment-partner event.</div>
                    <?php endif; ?>
                    <?php if ($allowInstantPay): ?></details><?php endif; ?>
                    <?php endif; ?>

                    <?php elseif (($currentMethod['type'] ?? '') === 'payu' && !empty($payuForms[$selectedPay])): ?>
                    <?php $pf = $payuForms[$selectedPay]; ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="<?= e($selectedPay) ?>">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <p class="text-xs text-amber-400 text-center">Test Mode — complete payment here (no redirect). Use Instant Test for demo / approval.</p>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">
                            Instant Test Pay <?= formatMoney($payAmount) ?> — <?= e($currentMethod['label'] ?? 'Card') ?>
                        </button>
                    </form>
                    <details class="text-xs text-gray-500 mb-2">
                        <summary class="cursor-pointer text-sky-400 mb-2">Optional: open PayU sandbox instead</summary>
                        <form method="POST" action="<?= e($pf['action']) ?>">
                            <?php foreach ($pf['fields'] as $k => $v): ?>
                            <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                            <?php endforeach; ?>
                            <button type="submit" class="w-full border border-gray-700 text-gray-300 py-3 rounded-xl font-semibold">
                                Continue to PayU Sandbox
                            </button>
                        </form>
                        <p class="text-center mt-2 text-gray-600">If PayU returns you without payment, use Instant Test above.</p>
                    </details>
                    <?php else: ?>
                    <form method="POST" action="<?= e($pf['action']) ?>">
                        <?php foreach ($pf['fields'] as $k => $v): ?>
                        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>">
                        <?php endforeach; ?>
                        <button type="submit" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-4 rounded-xl font-semibold text-lg">
                            Pay <?= formatMoney($payAmount) ?> via <?= e($currentMethod['label']) ?>
                        </button>
                    </form>
                    <p class="text-xs text-gray-600 text-center mt-3">Powered by PayU <?= $withPayuSplit ? '· Auto Split' : '' ?></p>
                    <?php endif; ?>

                    <?php elseif ($selectedPay === 'razorpay' && $razorpayOrder && $razorpayKey): ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="razorpay">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">
                            Instant Test Pay <?= formatMoney($payAmount) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <button id="pay-btn" type="button" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-4 rounded-xl font-semibold text-lg"><?= $allowInstantPay ? 'Open Razorpay Sandbox' : 'Pay with Razorpay' ?></button>
                    <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
                    <script>
                    document.getElementById('pay-btn').onclick=function(){
                        new Razorpay({
                            key:'<?= e($razorpayKey) ?>', amount:<?= (int)round($payAmount * 100) ?>, currency:'INR',
                            name:'<?= e(addslashes($brandName)) ?>', description:'<?= e(addslashes($displayMerchant)) ?>',
                            image:'<?= e($logoUrl) ?>', order_id:'<?= e($razorpayOrder['id'] ?? '') ?>',
                            handler:function(r){
                                var f=document.createElement('form');f.method='POST';f.action='payment_verify.php';
                                ['razorpay_payment_id','razorpay_order_id','razorpay_signature'].forEach(function(k){
                                    var i=document.createElement('input');i.type='hidden';i.name=k;i.value=r[k];f.appendChild(i);
                                });
                                var l=document.createElement('input');l.type='hidden';l.name='link_id';l.value='<?= e($linkId) ?>';f.appendChild(l);
                                document.body.appendChild(f);f.submit();
                            }, theme:{color:'#0284c7'}
                        }).open();
                    };
                    </script>

                    <?php elseif ($selectedPay === 'cashfree' && $cashfreeSession): ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3 mb-4">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="cashfree">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">
                            Instant Test Pay <?= formatMoney($payAmount) ?>
                        </button>
                    </form>
                    <?php endif; ?>
                    <button id="cf-button" type="button" class="w-full bg-sky-600 hover:bg-sky-500 text-white py-4 rounded-xl font-semibold text-lg"><?= $allowInstantPay ? 'Open Cashfree Sandbox' : 'Pay with Cashfree' ?></button>
                    <script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
                    <script>
                    const cashfree = Cashfree({ mode: "<?= getSetting('cashfree_environment','production') === 'sandbox' ? 'sandbox' : 'production' ?>" });
                    document.getElementById('cf-button').onclick=function(){
                        cashfree.checkout({ paymentSessionId: "<?= e($cashfreeSession) ?>", redirectTarget: "_self" });
                    };
                    </script>

                    <?php else: ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="<?= e($selectedPay) ?>">
                        <?php renderCheckoutCustomerFields($link); ?>
                        <p class="text-xs text-amber-400 text-center">Test Mode — instant demo payment (no gateway redirect)</p>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">
                            Instant Test Pay <?= formatMoney($payAmount) ?> — <?= e($currentMethod['label'] ?? 'Demo') ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="bg-dark-900/60 border border-gray-800 rounded-xl px-4 py-6 text-center">
                        <p class="text-sm text-gray-300 mb-1">This method is enabled, but partner keys are not set yet.</p>
                        <p class="text-xs text-gray-500">Use <strong class="text-white">UPI / QR</strong> if a UPI ID is set, or switch the merchant dashboard to <strong class="text-amber-300">Test Mode</strong> for Instant Test Pay.</p>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

            </div>
            <?php endif; ?>
        </div>
    </main>
    <?php require __DIR__ . '/includes/checkout_footer.php'; ?>
</div>
<?php if (!$success && $selectedPay === 'upi'): ?>
<script>
(function(){
  const linkId = <?= json_encode($linkId) ?>;
  const statusEl = document.getElementById('upi-poll-status');
  let tries = 0;
  const poll = () => {
    if (tries++ > 120) return;
    fetch('checkout_upi_status.php?link=' + encodeURIComponent(linkId), {cache:'no-store'})
      .then(r => r.json())
      .then(d => {
        if (d.paid) {
          if (statusEl) statusEl.textContent = 'Payment received! Redirecting…';
          window.location.reload();
        }
      })
      .catch(() => {});
  };
  setInterval(poll, 3000);
  poll();
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
