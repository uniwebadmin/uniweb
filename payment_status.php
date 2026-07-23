<?php
require_once __DIR__ . '/config.php';
$pageTitle = 'Track Payment';
$txn = null;
$txnList = null;
$error = '';
$otpStep = false;
$prefillTxn = trim($_GET['txn_id'] ?? $_POST['txn_id'] ?? '');

if (isset($_GET['cancel_otp'])) {
    unset($_SESSION['pending_customer_phone']);
    redirect('payment_status.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Security token expired. Refresh and try again.';
    } elseif (isset($_POST['otp_code'])) {
        $phone = $_SESSION['pending_customer_phone'] ?? '';
        if ($phone && verifyOTP('customer_phone_' . $phone, $_POST['otp_code'], 'customer_lookup')) {
            unset($_SESSION['pending_customer_phone']);
            $stmt = getDB()->prepare('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id = m.id WHERE t.customer_phone = ? ORDER BY t.created_at DESC LIMIT 10');
            $stmt->execute([$phone]);
            $txnList = $stmt->fetchAll();
            if (empty($txnList)) {
                $error = 'No payments found for this number.';
            } else {
                flash('success', count($txnList) . ' recent payment(s) loaded.');
            }
        } else {
            recordVelocityEvent('customer_lookup', $phone);
            $error = 'Invalid or expired code. Please try again.';
            $otpStep = true;
        }
    } else {
        $txnId = trim($_POST['txn_id'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        if ($txnId !== '') {
            $stmt = getDB()->prepare('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id = m.id WHERE t.txn_id = ?');
            $stmt->execute([$txnId]);
            $txn = $stmt->fetch();
            if (!$txn) {
                $error = 'No payment found with that Transaction ID.';
            } else {
                flash('success', 'Payment details loaded.');
            }
        } elseif ($phone !== '') {
            $digits = preg_replace('/\D/', '', $phone);
            if (strlen($digits) !== 10) {
                $error = 'Enter a valid 10-digit mobile number.';
            } elseif (checkVelocityBlock('customer_lookup')['blocked']) {
                $v = checkVelocityBlock('customer_lookup');
                $error = 'Too many attempts. Please try again in ~' . $v['retry_after_minutes'] . ' min.';
            } else {
                $otp = generateOTP('customer_phone_' . $digits, 'customer_lookup');
                $waResult = ['ok' => false];
                if (getSetting('whatsapp_enabled', '0') === '1') {
                    $waResult = sendWhatsAppOtp($digits, $otp);
                }
                if (empty($waResult['ok'])) {
                    unset($_SESSION['pending_customer_phone']);
                    $error = 'Phone verification is temporarily unavailable. Use your Transaction ID or try again later.';
                } else {
                    $_SESSION['pending_customer_phone'] = $digits;
                    $otpStep = true;
                }
            }
        }
    }
} elseif ($prefillTxn !== '') {
    $stmt = getDB()->prepare('SELECT t.*, m.business_name FROM transactions t JOIN merchants m ON t.merchant_id = m.id WHERE t.txn_id = ?');
    $stmt->execute([$prefillTxn]);
    $txn = $stmt->fetch();
    if (!$txn) {
        $error = 'No payment found with that Transaction ID.';
    }
}

if (isset($_SESSION['pending_customer_phone'])) {
    $otpStep = true;
}

require_once __DIR__ . '/header.php';
?>
<div class="pt-24 pb-16 max-w-2xl mx-auto px-4">
    <h1 class="text-3xl font-bold text-center mb-2">Track Your <span class="gradient-text">Payment</span></h1>
    <p class="text-gray-500 text-center text-sm mb-8">Check payment status using Transaction ID or Phone Number</p>

    <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg mb-6"><?= e($error) ?></div><?php endif; ?>

    <?php if ($otpStep): ?>
    <div class="glass rounded-2xl p-8 mb-8">
        <p class="text-xs text-gray-500 text-center mb-4">Verification code sent to your WhatsApp.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <p class="text-sm text-gray-400 text-center">We need to confirm this is your number before showing payment history.</p>
            <div><label class="text-sm text-gray-400">Verification Code</label><input type="text" name="otp_code" required maxlength="6" pattern="[0-9]{6}" class="input-field mt-1 text-center text-2xl tracking-widest" placeholder="000000" autofocus></div>
            <button type="submit" class="w-full btn-primary py-3">Verify &amp; View Payments</button>
            <p class="text-center text-xs mt-2"><a href="payment_status.php?cancel_otp=1" class="text-gray-500 hover:text-white">← Try a different number</a></p>
        </form>
    </div>
    <?php else: ?>
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
