<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
ensureCustomerPortalSchema();
$pageTitle = 'Track Payment';
$txn = null;
$txnList = null;
$error = '';
$notice = '';
$otpStep = false;
$trackVerified = false;
$prefillTxn = trim($_GET['txn_id'] ?? $_POST['txn_id'] ?? '');
$trackSig = trim($_GET['sig'] ?? '');
$trackExp = (int)($_GET['exp'] ?? 0);

if (isset($_GET['cancel_otp'])) {
    unset($_SESSION['pending_customer_phone'], $_SESSION['pending_customer_txn'], $_SESSION['customer_lookup_demo_otp']);
    redirect('payment_status.php');
}

/** Signed link from checkout success — no phone OTP required. */
function paymentStatusViaSignedTrack(string $txnId, string $sig, int $exp): ?array
{
    if (!verifyPaymentTrackSignature($txnId, $sig, $exp)) {
        return null;
    }
    return fetchPaymentStatusTransaction($txnId);
}

/** Merchant viewing their own transaction while logged in. */
function paymentStatusViaMerchant(string $txnId): ?array
{
    if (!isLoggedIn() || isAdminLoggedIn()) {
        return null;
    }
    $merchant = getMerchant();
    $stmt = getDB()->prepare(
        'SELECT t.*, m.business_name, pl.link_id AS recovery_link_id, pl.status AS recovery_link_status, pl.expires_at AS recovery_link_expires_at
         FROM transactions t
         JOIN merchants m ON t.merchant_id = m.id
         LEFT JOIN payment_links pl ON pl.id = t.payment_link_id
         WHERE t.txn_id = ? AND t.merchant_id = ? LIMIT 1'
    );
    $stmt->execute([$txnId, (int)$merchant['id']]);
    $row = $stmt->fetch();
    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } elseif (isset($_POST['otp_code'])) {
        $phone = (string)($_SESSION['pending_customer_phone'] ?? '');
        if ($phone === '') {
            redirect('payment_status.php');
        }
        $res = verifyCustomerOtp($phone, (string)$_POST['otp_code']);
        if ($res['ok']) {
            unset($_SESSION['customer_lookup_demo_otp']);
            $pendingTxn = (string)($_SESSION['pending_customer_txn'] ?? '');
            unset($_SESSION['pending_customer_phone'], $_SESSION['pending_customer_txn']);
            if ($pendingTxn !== '' && findCustomerOwnedTransaction($phone, $pendingTxn)) {
                $txn = fetchPaymentStatusTransaction($pendingTxn);
            } else {
                $stmt = getDB()->prepare('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id = m.id WHERE t.customer_phone = ? ORDER BY t.created_at DESC LIMIT 10');
                $stmt->execute([$phone]);
                $txnList = $stmt->fetchAll();
                if (empty($txnList)) {
                    $error = 'No payments found for this number.';
                }
            }
            if ($pendingTxn !== '' && !$txn) {
                $error = 'Could not verify payment ownership.';
            }
        } else {
            if (function_exists('recordVelocityEvent')) {
                recordVelocityEvent('customer_lookup', $phone);
            }
            $error = $res['message'];
            $otpStep = true;
        }
    } else {
        $txnId = trim($_POST['txn_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($txnId) {
            $phoneDigits = customerNormalizePhone($phone);
            if (isCustomerLoggedIn()) {
                $owned = findCustomerOwnedTransaction(currentCustomerPhone(), $txnId);
                if ($owned) {
                    $txn = fetchPaymentStatusTransaction($txnId);
                } else {
                    $error = 'This payment is not linked to your account.';
                }
            } elseif ($phoneDigits !== '') {
                $owned = findCustomerOwnedTransaction($phoneDigits, $txnId);
                if ($owned) {
                    $res = requestCustomerOtp($phoneDigits);
                    if (!$res['ok']) {
                        $error = $res['message'];
                    } else {
                        $_SESSION['pending_customer_phone'] = $phoneDigits;
                        $_SESSION['pending_customer_txn'] = $txnId;
                        if (($res['channel'] ?? '') === 'demo' && !empty($res['demo_otp'])) {
                            $_SESSION['customer_lookup_demo_otp'] = $res['demo_otp'];
                        } else {
                            unset($_SESSION['customer_lookup_demo_otp']);
                        }
                        $notice = $res['message'];
                        $otpStep = true;
                    }
                } else {
                    $error = 'No payment found for this Transaction ID and phone number.';
                }
            } else {
                $error = 'Enter the mobile number used at checkout to view this payment.';
            }
        } elseif ($phone) {
            $digits = customerNormalizePhone($phone);
            if ($digits === '') {
                $error = 'Enter a valid 10-digit mobile number.';
            } elseif (function_exists('checkVelocityBlock') && checkVelocityBlock('customer_lookup')['blocked']) {
                $v = checkVelocityBlock('customer_lookup');
                $error = 'Too many attempts. Please try again in ~' . $v['retry_after_minutes'] . ' min.';
            } else {
                $res = requestCustomerOtp($digits);
                if (!$res['ok']) {
                    unset($_SESSION['pending_customer_phone'], $_SESSION['pending_customer_txn']);
                    $error = $res['message'];
                } else {
                    $_SESSION['pending_customer_phone'] = $digits;
                    unset($_SESSION['pending_customer_txn']);
                    if (($res['channel'] ?? '') === 'demo' && !empty($res['demo_otp'])) {
                        $_SESSION['customer_lookup_demo_otp'] = $res['demo_otp'];
                    } else {
                        unset($_SESSION['customer_lookup_demo_otp']);
                    }
                    $notice = $res['message'];
                    $otpStep = true;
                }
            }
        }
    }
} elseif ($prefillTxn !== '') {
    if ($trackSig !== '' && $trackExp > 0) {
        $txn = paymentStatusViaSignedTrack($prefillTxn, $trackSig, $trackExp);
        if ($txn) {
            $trackVerified = true;
        } elseif (hasCheckoutTrackAccess($prefillTxn)) {
            $txn = fetchPaymentStatusTransaction($prefillTxn);
            if ($txn) {
                $trackVerified = true;
            }
        } elseif (($merchantTxn = paymentStatusViaMerchant($prefillTxn)) !== null) {
            $txn = $merchantTxn;
            $trackVerified = true;
        } else {
            $error = 'This secure track link could not be verified. Enter the mobile number used at checkout, or pay again and use the new Track link from the success page.';
        }
    } elseif (hasCheckoutTrackAccess($prefillTxn)) {
        $txn = fetchPaymentStatusTransaction($prefillTxn);
        if ($txn) {
            $trackVerified = true;
        }
    } elseif (isCustomerLoggedIn()) {
        $owned = findCustomerOwnedTransaction(currentCustomerPhone(), $prefillTxn);
        if ($owned) {
            $txn = fetchPaymentStatusTransaction($prefillTxn);
        } else {
            $error = 'This payment is not linked to your account.';
        }
    } elseif (($merchantTxn = paymentStatusViaMerchant($prefillTxn)) !== null) {
        $txn = $merchantTxn;
    }
    // Unsigned txn_id only — user must enter phone + OTP (no public leak).
}

if (isset($_SESSION['pending_customer_phone'])) {
    $otpStep = true;
}

require_once __DIR__ . '/header.php';
?>
<div class="pt-24 pb-16 max-w-2xl mx-auto px-4">
    <h1 class="text-3xl font-bold text-center mb-2">Track Your <span class="gradient-text">Payment</span></h1>
    <p class="text-gray-500 text-center text-sm mb-8">Check payment status using Transaction ID or Phone Number</p>

    <?php if ($trackVerified && $txn): ?>
    <div class="bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm px-4 py-3 rounded-lg mb-6 flex items-center gap-2 justify-center">
        <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-500/20 text-emerald-200">✓</span>
        <span><strong>UniWeb Verified</strong> — signed secure track link</span>
    </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="bg-sky-500/10 border border-sky-500/30 text-sky-300 text-sm px-4 py-3 rounded-lg mb-6"><?= e($notice) ?></div><?php endif; ?>

    <?php if ($otpStep): ?>
    <div class="glass rounded-2xl p-8 mb-8">
        <?php if (!empty($_SESSION['customer_lookup_demo_otp'])): ?>
        <div class="bg-amber-500/10 border border-amber-500/30 text-amber-200 text-sm px-4 py-3 rounded-lg mb-4 text-center">
            Test / demo mode — your code: <strong class="font-mono tracking-widest"><?= e((string)$_SESSION['customer_lookup_demo_otp']) ?></strong>
        </div>
        <?php else: ?>
        <p class="text-xs text-gray-500 text-center mb-4">Verification code sent to your mobile.</p>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <p class="text-sm text-gray-400 text-center">We need to confirm this is your number before showing payment history.</p>
            <div><label class="text-sm text-gray-400">Verification Code</label><input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field mt-1 text-center text-2xl tracking-widest" placeholder="000000" autofocus></div>
            <button type="submit" class="w-full btn-primary py-3">Verify &amp; View Payments</button>
            <p class="text-center text-xs mt-2"><a href="payment_status.php?cancel_otp=1" class="text-gray-500 hover:text-white">← Try a different number</a></p>
        </form>
    </div>
    <?php elseif (!$txn && empty($txnList)): ?>
    <div class="glass rounded-2xl p-8 mb-8">
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Transaction ID</label><input type="text" name="txn_id" class="input-field mt-1 font-mono" placeholder="TXN..." value="<?= e($prefillTxn) ?>"></div>
            <p class="text-center text-gray-600 text-xs">— OR —</p>
            <div><label class="text-sm text-gray-400">Phone Number</label><input type="tel" name="phone" maxlength="10" class="input-field mt-1" placeholder="10-digit mobile" value="<?= e($_POST['phone']??'') ?>"></div>
            <p class="text-[11px] text-gray-600">We'll send a one-time verification code before showing your payment history.</p>
            <button type="submit" class="w-full btn-primary py-3">Track Payment</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($txn): ?>
    <div class="glass rounded-2xl p-8">
        <h2 class="font-semibold mb-4">Payment Details</h2>
        <div class="space-y-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Transaction ID</span><span class="font-mono"><?= e($txn['txn_id']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Merchant</span><span><?= e($txn['business_name']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Amount</span><span class="font-bold text-brand-400"><?= formatMoney((float)$txn['amount']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Method</span><span class="uppercase"><?= e($txn['payment_method']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Status</span><?= statusBadge($txn['status']) ?></div>
            <div class="flex justify-between"><span class="text-gray-500">Date</span><span><?= formatDate($txn['created_at']) ?></span></div>
            <?php if ($txn['utr']): ?><div class="flex justify-between"><span class="text-gray-500">UTR</span><span class="font-mono"><?= e($txn['utr']) ?></span></div><?php endif; ?>
        </div>
        <?php $txnStatus = strtolower((string)($txn['status'] ?? '')); ?>
        <?php if (in_array($txnStatus, ['pending', 'processing', 'initiated'], true)): ?>
        <div class="mt-5 rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm">
            <p class="font-semibold text-amber-200">Payment is still being confirmed</p>
            <p class="text-amber-100/80 text-xs mt-1">Your bank or payment provider has not sent final confirmation yet. Do not pay again. This page refreshes automatically while we wait.</p>
            <button type="button" onclick="window.location.reload()" class="mt-3 text-xs text-sky-300 hover:text-sky-200">Refresh status now ↻</button>
        </div>
        <script>
        setTimeout(function () { window.location.reload(); }, 15000);
        </script>
        <?php endif; ?>
        <?php
        $canRetry = in_array($txnStatus, ['failed', 'error', 'cancelled', 'canceled'], true)
            && !empty($txn['recovery_link_id'])
            && ($txn['recovery_link_status'] ?? '') === 'active'
            && (empty($txn['recovery_link_expires_at']) || strtotime((string)$txn['recovery_link_expires_at']) > time());
        ?>
        <?php if ($canRetry): ?>
        <div class="mt-5 rounded-xl border border-sky-500/30 bg-sky-500/10 p-4 text-sm">
            <p class="font-semibold text-sky-200">Try payment again</p>
            <p class="text-sky-100/80 text-xs mt-1">This payment was not completed. You can return to checkout and choose any supported payment method. Only retry if your bank has not already debited you.</p>
            <a href="checkout.php?link=<?= rawurlencode((string)$txn['recovery_link_id']) ?>" class="inline-block mt-3 bg-sky-600 hover:bg-sky-500 text-white px-4 py-2 rounded-lg text-xs font-semibold">Retry payment →</a>
        </div>
        <?php endif; ?>
    </div>
    <?php elseif (!empty($txnList)): ?>
    <div class="glass rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent Payments</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Txn ID</th><th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($txnList as $t): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 font-mono text-xs"><?= e($t['txn_id']) ?></td>
                    <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                    <td class="px-5 py-3"><?= statusBadge($t['status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($t['created_at']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
