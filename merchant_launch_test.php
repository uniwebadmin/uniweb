<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
if (!$merchant) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Your session expired. Refresh and try again.');
    } elseif (($_POST['action'] ?? '') === 'prepare_test') {
        $result = ensureMerchantLaunchTestPack((int)$merchant['id']);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    }
    redirect('merchant_launch_test.php');
}

$test = getMerchantLaunchTestData((int)$merchant['id']);
$hasTestLink = !empty($test['link']) && !empty($test['url']);
$success = $test['success'] ?? null;
$pageTitle = 'Launch Test';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">
    <section class="glass rounded-2xl p-5 sm:p-7 border border-sky-500/25">
        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-400">Safe Launch Test</p>
        <h1 class="text-2xl sm:text-3xl font-bold text-white mt-2">Prove your payment flow before you collect real money.</h1>
        <p class="text-sm text-gray-400 mt-3 max-w-2xl">This uses a ₹1 Test Mode checkout only. It cannot collect live money, and a completed result appears here only after the payment record is verified.</p>
    </section>

    <?php if ($success): ?>
    <section class="rounded-2xl border border-emerald-500/35 bg-emerald-500/10 p-5 sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-emerald-400">Launch test passed</p>
                <h2 class="text-lg font-semibold text-white mt-1">Your first test payment is verified.</h2>
                <p class="text-sm text-gray-300 mt-2">₹<?= number_format((float)$success['amount'], 2) ?> · <?= e(strtoupper((string)($success['payment_method'] ?? 'test'))) ?> · <?= e(formatDate((string)$success['created_at'])) ?></p>
                <p class="text-xs text-emerald-200/80 mt-2">Transaction: <?= e((string)$success['txn_id']) ?></p>
            </div>
            <a href="merchant_launch.php" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-3 rounded-xl font-semibold text-sm">Back to Launch Center →</a>
        </div>
    </section>
    <?php endif; ?>

    <section class="glass rounded-2xl overflow-hidden border border-gray-800">
        <div class="divide-y divide-gray-800">
            <div class="flex items-center gap-4 p-5 sm:p-6">
                <span class="w-9 h-9 shrink-0 rounded-full flex items-center justify-center font-bold <?= $hasTestLink ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-sky-500/15 text-sky-300 border border-sky-500/30' ?>"><?= $hasTestLink ? '✓' : '1' ?></span>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-white">Prepare a ₹1 Test Mode checkout</p>
                    <p class="text-xs text-gray-500 mt-1"><?= $hasTestLink ? 'Your active test checkout is ready.' : 'Create one safe checkout from your enabled payment methods.' ?></p>
                </div>
                <?php if (!$hasTestLink): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="prepare_test">
                    <button type="submit" class="btn-primary text-sm px-4 py-2.5">Prepare Test →</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4 p-5 sm:p-6 <?= !$hasTestLink ? 'opacity-50' : '' ?>">
                <span class="w-9 h-9 shrink-0 rounded-full flex items-center justify-center font-bold <?= $success ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-sky-500/15 text-sky-300 border border-sky-500/30' ?>"><?= $success ? '✓' : '2' ?></span>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-white">Complete the safe test checkout</p>
                    <p class="text-xs text-gray-500 mt-1">Use the test payment button on the checkout page. No real bank payment is used.</p>
                </div>
                <?php if ($hasTestLink): ?>
                <a href="<?= e($test['url']) ?>" target="_blank" rel="noopener" class="text-sm text-sky-400 hover:text-sky-300 whitespace-nowrap">Open Test Checkout ↗</a>
                <?php endif; ?>
            </div>

            <div class="flex items-center gap-4 p-5 sm:p-6 <?= !$success ? 'opacity-50' : '' ?>">
                <span class="w-9 h-9 shrink-0 rounded-full flex items-center justify-center font-bold <?= $success ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-gray-800 text-gray-500 border border-gray-700' ?>"><?= $success ? '✓' : '3' ?></span>
                <div class="flex-1 min-w-0">
                    <p class="font-semibold text-white">See the verified result</p>
                    <p class="text-xs text-gray-500 mt-1"><?= $success ? 'Verified transaction is shown above.' : 'Return here after checkout. Refresh once if the new result is still processing.' ?></p>
                </div>
                <?php if (!$success): ?>
                <a href="merchant_launch_test.php" class="text-sm text-sky-400 hover:text-sky-300 whitespace-nowrap">Check Result ↻</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <div class="flex flex-wrap gap-3 text-sm">
        <a href="merchant_launch.php" class="text-gray-400 hover:text-white">← Launch Center</a>
        <a href="merchant_payment_pack.php" class="text-sky-400 hover:text-sky-300">View all test links →</a>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php';
