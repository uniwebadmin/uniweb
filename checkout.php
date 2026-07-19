<?php
require_once __DIR__ . '/config.php';

$linkId = $_GET['link'] ?? $_POST['udf1'] ?? '';
if (!$linkId) {
    http_response_code(404);
    $pageTitle = 'Payment Link Not Found';
    require_once __DIR__ . '/header.php';
    echo '<section class="pt-28 pb-20 px-4"><div class="max-w-lg mx-auto glass rounded-2xl p-8 text-center">'
        . '<h1 class="text-xl font-semibold mb-2">Payment link not found</h1>'
        . '<p class="text-sm text-gray-400 mb-6">This checkout URL is missing a valid payment link. Open a link from your merchant dashboard, or try the demo.</p>'
        . '<a href="demo.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Try Demo Payment</a>'
        . ' <a href="index.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Home</a>'
        . '</div></section>';
    require_once __DIR__ . '/footer.php';
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate');

$db = getDB();
$stmt = $db->prepare("SELECT pl.id, pl.link_id, pl.amount AS payment_amount, pl.description, pl.customer_name, pl.customer_phone, pl.status AS link_status, pl.expires_at, pl.is_test, pl.merchant_id AS link_merchant_id, pl.payment_method, pl.gateway_code, pl.link_label, pl.link_collection_mode, pl.pack_id,
    m.id AS merchant_id, m.business_name, m.upi_id, m.merchant_code, m.account_mode, m.kyc_status,
    m.collection_mode, m.commission_rate, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, m.payu_child_key,
    m.razorpay_linked_account_id, m.cashfree_vendor_id, m.email AS merchant_email, m.phone AS merchant_phone
    FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ?");
$stmt->execute([$linkId]);
$link = $stmt->fetch();
if (!$link) { http_response_code(404); die('Payment link expired or not found.'); }
$amtOnly = $db->prepare('SELECT amount, status FROM payment_links WHERE link_id = ?');
$amtOnly->execute([$linkId]);
$plRow = $amtOnly->fetch();
$isTestCheckout = !empty($link['is_test']) || merchantAccountMode($link) === 'test';
$isDemoMerchant = strcasecmp((string)($link['merchant_email'] ?? ''), 'demo@uniweb.co.in') === 0;
$payAmount = sanitizePaymentAmount(round((float)($plRow['amount'] ?? 0), 2), $isTestCheckout || $isDemoMerchant);
$link['amount'] = $payAmount;
$link['status'] = $plRow['status'] ?? 'active';
// Instant Test Pay: Test Mode links, OR demo store (approval walkthrough)
$allowInstantPay = $isTestCheckout || ($isDemoMerchant && $payAmount <= 100);
if ($link['status'] !== 'active') { die('This payment link is no longer active.'); }
if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
    $db->prepare("UPDATE payment_links SET status = 'expired' WHERE id = ?")->execute([$link['id']]);
    die('This payment link has expired.');
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    incrementPaymentLinkView((int)$link['id']);
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
$upiPa = trim((string)($link['upi_id'] ?? $link['axis_va_upi'] ?? ''));
$whatsappLink = buildWhatsAppPaymentLink($link);
$paymentMethods = getCheckoutPaymentMethods($link);
$lockedMethod = !empty($link['payment_method']) ? ($link['link_label'] ?? $link['payment_method']) : null;
$selectedPay = $_GET['pay'] ?? $_POST['pay'] ?? 'upi';
$validKeys = array_column($paymentMethods, 'key');
if (!in_array($selectedPay, $validKeys, true)) $selectedPay = $validKeys[0] ?? 'upi';
$currentMethod = null;
foreach ($paymentMethods as $m) { if ($m['key'] === $selectedPay) { $currentMethod = $m; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_pay' && $allowInstantPay) {
    $method = preg_replace('/[^a-z0-9_]/i', '', $selectedPay) ?: 'test';
    $txnDbId = createTransactionFromPayment($link, $method, 'success', 'TEST' . time(), true);
    finalizePaymentLink((int)$link['id'], (int)$link['merchant_id'], $payAmount, formatMoney($payAmount) . ' test payment — ' . ($currentMethod['label'] ?? $method) . '.');
    $txnRow = getDB()->prepare('SELECT txn_id FROM transactions WHERE id = ?');
    $txnRow->execute([$txnDbId]);
    $successTxnId = $txnRow->fetchColumn() ?: null;
    $success = true;
}

$razorpayKey = getSetting('razorpay_key_id', '');
$razorpayOrder = null;
$cashfreeSession = null;
$payuForms = [];
$axisVa = null;
$withPayuSplit = $handler === 'payu_split';

if ($handler === 'axis_va') {
    $axisVa = ensureAxisVirtualAccount((int)$link['merchant_id']);
    if ($axisVa) {
        $link['axis_va_upi'] = $axisVa['va_upi'] ?? $link['axis_va_upi'];
        $upiData = buildMerchantUpiIntent($link);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isGatewayConfigured('payu')) {
        foreach ($paymentMethods as $m) {
            if (($m['type'] ?? '') === 'payu' && !empty($m['pg'])) {
                $payuForms[$m['key']] = buildPayUPaymentForm($link, $link, $withPayuSplit, $m['pg'], $m['key'], $payAmount);
            }
        }
    }
    $smartRouted = null;
    $bothTabsAvailable = in_array('razorpay', $validKeys, true) && in_array('cashfree', $validKeys, true);
    if (($selectedPay === 'razorpay' || $selectedPay === 'cashfree') && $handler !== 'razorpay_route' && $handler !== 'cashfree_route' && $bothTabsAvailable) {
        // Smart routing: try the healthier gateway first, auto-divert to the other tab on failure — only when both tabs exist for this merchant.
        $returnUrl = APP_URL . '/payment_cashfree_return.php?link_id=' . urlencode($linkId);
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
            $razorpayOrder = $handler === 'razorpay_route'
                ? createRazorpayOrderWithRoute($payAmount, 'PL_' . $link['link_id'], $link, ['link_id' => $link['link_id']])
                : createRazorpayOrder($payAmount, 'PL_' . $link['link_id'], ['link_id' => $link['link_id']]);
        }
    } elseif ($selectedPay === 'cashfree' && isGatewayConfigured('cashfree')) {
        $orderId = 'CF_' . $link['link_id'] . '_' . time();
        $returnUrl = APP_URL . '/payment_cashfree_return.php?link_id=' . urlencode($linkId);
        $cf = $handler === 'cashfree_route'
            ? createCashfreeOrderWithSplit($orderId, $payAmount, $link, $link['customer_phone'] ?? '', $link['customer_email'] ?? COMPANY_SUPPORT_EMAIL, $returnUrl)
            : createCashfreeOrder($orderId, $payAmount, $link['customer_phone'] ?? '', $link['customer_email'] ?? COMPANY_SUPPORT_EMAIL, $returnUrl, $linkId);
        $cashfreeSession = $cf['payment_session_id'] ?? null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedPay === 'upi' && in_array($handler, ['direct_upi', 'axis_va'], true)) {
    $utr = trim($_POST['utr'] ?? '');
    $velocity = checkVelocityBlock('payment_fail');
    if ($velocity['blocked']) {
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
            recordVelocityEvent('payment_fail', $link['link_id'] ?? null);
        } else {
            $successTxnId = $result['txn_id'] ?? null;
            $success = true;
        }
    }
}

$pageTitle = 'Secure Payment — ' . APP_NAME;
$hideNav = true;
$hideFooter = true;
$footerVariant = 'checkout';
$bodyClass = 'bg-dark-950';
require_once __DIR__ . '/header.php';
?>

<div class="min-h-screen flex flex-col">
    <header class="border-b border-gray-800 bg-dark-900/95 px-4 py-4">
        <div class="max-w-lg mx-auto flex items-center justify-between">
            <?php $logoHref = 'index.php'; $logoSize = 'md'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <span class="text-xs text-sky-400 hidden sm:inline">Secure Checkout</span>
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
                <p class="text-gray-400 text-sm">Your payment has been confirmed. A receipt will be sent to the merchant.</p>
                <?php if ($successTxnId): ?>
                <p class="text-xs text-gray-500 mt-3 font-mono">Transaction ID: <?= e($successTxnId) ?></p>
                <?php endif; ?>
                <a href="payment_status.php<?= $successTxnId ? '?txn_id=' . rawurlencode((string)$successTxnId) : '' ?>" class="inline-block mt-6 text-sm text-sky-400 hover:underline">Track payment status →</a>
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
                    <?php if ($split['platform_fee'] > 0 || $split['merchant_net'] > 0): ?>
                    <div class="mt-4 pt-4 border-t border-gray-800/80 text-left text-xs space-y-1 max-w-xs mx-auto">
                        <div class="flex justify-between text-gray-500"><span>Gross amount</span><span><?= formatMoney($payAmount) ?></span></div>
                        <?php if ($split['platform_fee'] > 0): ?>
                        <div class="flex justify-between text-gray-500"><span>Platform fee</span><span><?= formatMoney((float)$split['platform_fee']) ?></span></div>
                        <?php endif; ?>
                        <div class="flex justify-between text-emerald-400 font-medium"><span>Merchant receives</span><span><?= formatMoney((float)$split['merchant_net']) ?></span></div>
                    </div>
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
                    <?php if ($currentMethod): ?>
                    <p class="text-sm font-medium text-gray-300 mb-1"><?= e((string)($currentMethod['label'] ?? '')) ?></p>
                    <p class="text-xs text-gray-500 mb-4"><?= e((string)($currentMethod['sub'] ?? '')) ?></p>
                    <?php endif; ?>

                    <?php if ($selectedPay === 'upi'): ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="mb-4">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="upi">
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">⚡ Instant Test Pay <?= formatMoney($payAmount) ?> — UPI</button>
                        <p class="text-xs text-amber-400/80 text-center mt-2">Recommended for demos / bank approval — completes instantly (no real UPI transfer).</p>
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
                    <p class="text-xs text-center text-gray-500 mb-3" id="upi-poll-status"><?= $allowInstantPay ? 'Sandbox — Instant Test Pay above marks this link paid.' : 'Waiting for payment… enter UTR after you pay, or wait for webhook.' ?></p>
                    <?php if ($upiPa !== ''): ?>
                    <a href="<?= e($whatsappLink) ?>" target="_blank" class="block text-center bg-emerald-600/20 text-emerald-400 border border-emerald-500/30 py-2 rounded-xl text-sm mb-4">WhatsApp Pay Link</a>
                    <?php endif; ?>
                    <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-4"><?= e($error) ?></div><?php endif; ?>
                    <form method="POST" class="space-y-3">
                        <input type="text" name="customer_name" placeholder="Your Name" class="input-field" value="<?= e($link['customer_name'] ?? '') ?>">
                        <input type="tel" name="customer_phone" placeholder="Phone" class="input-field" value="<?= e($link['customer_phone'] ?? '') ?>">
                        <input type="text" name="utr" placeholder="UPI UTR / Reference (required after live pay)" class="input-field" <?= $allowInstantPay ? '' : 'required' ?>>
                        <button type="submit" class="w-full <?= $allowInstantPay ? 'border border-gray-700 text-gray-300' : 'bg-sky-600 hover:bg-sky-500 text-white' ?> py-3 rounded-xl font-semibold">Confirm UPI Payment</button>
                        <p class="text-[11px] text-gray-600 text-center"><?= $allowInstantPay
                            ? 'Sandbox: prefer Instant Test Pay. UTR confirm also works with any valid test reference (10–22 chars).'
                            : 'Live: pay to the UPI ID / QR above, then enter the bank UTR here. Auto-confirm needs Axis VA / gateway webhooks.' ?></p>
                    </form>
                    <?php if ($allowInstantPay): ?></details><?php endif; ?>

                    <?php elseif (($currentMethod['type'] ?? '') === 'payu' && !empty($payuForms[$selectedPay])): ?>
                    <?php $pf = $payuForms[$selectedPay]; ?>
                    <?php if ($allowInstantPay): ?>
                    <form method="POST" class="space-y-3 mb-4">
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="<?= e($selectedPay) ?>">
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
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="razorpay">
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
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="cashfree">
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
                        <input type="hidden" name="action" value="test_pay">
                        <input type="hidden" name="pay" value="<?= e($selectedPay) ?>">
                        <p class="text-xs text-amber-400 text-center">Test Mode — instant demo payment (no gateway redirect)</p>
                        <button type="submit" class="w-full bg-amber-500 hover:bg-amber-400 text-dark-900 py-4 rounded-xl font-semibold text-lg">
                            Instant Test Pay <?= formatMoney($payAmount) ?> — <?= e($currentMethod['label'] ?? 'Demo') ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <p class="text-center text-gray-500 text-sm py-6">This payment method needs gateway keys. Switch dashboard to <strong class="text-amber-300">Test Mode</strong> for Instant Test Pay, use a <strong class="text-white">UPI</strong> link, or paste PayU / Razorpay / Cashfree keys.</p>
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
