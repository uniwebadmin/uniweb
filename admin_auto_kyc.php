<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
if (!function_exists('runAutoKycEngine') && is_file(__DIR__ . '/includes/auto_kyc.php')) {
    require_once __DIR__ . '/includes/auto_kyc.php';
}

$lastRun = getLastAutoKycRun();

// Manual trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'run_now' && function_exists('runAutoKycEngine')) {
        $result = runAutoKycEngine();
        flash('success', "Auto KYC run complete: {$result['merchants_verified']} verified, {$result['docs_auto_approved']} docs approved, {$result['merchants_checked']} checked.");
        redirect('admin_auto_kyc.php');
    }
}

$pageTitle = 'Zero-Touch Auto KYC';
$adminSection = 'kyc';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Zero-Touch Auto KYC Engine</h1>
    <p class="text-sm text-gray-500 mb-6">Automatically approves clean KYC documents and verifies eligible merchants without manual intervention.</p>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="glass rounded-xl p-5 stat-card border border-emerald-500/20">
            <p class="text-xs text-gray-500">Last Run</p>
            <p class="text-lg font-bold text-white mt-1"><?= htmlspecialchars($lastRun['ran_at'] ?? 'Never') ?></p>
        </div>
        <div class="glass rounded-xl p-5 stat-card border border-sky-500/20">
            <p class="text-xs text-gray-500">Merchants Checked</p>
            <p class="text-2xl font-bold text-sky-400 mt-1"><?= (int)($lastRun['summary']['merchants_checked'] ?? 0) ?></p>
        </div>
        <div class="glass rounded-xl p-5 stat-card border border-emerald-500/20">
            <p class="text-xs text-gray-500">Auto-Verified</p>
            <p class="text-2xl font-bold text-emerald-400 mt-1"><?= (int)($lastRun['summary']['merchants_verified'] ?? 0) ?></p>
        </div>
        <div class="glass rounded-xl p-5 stat-card border border-amber-500/20">
            <p class="text-xs text-gray-500">Docs Auto-Approved</p>
            <p class="text-2xl font-bold text-amber-400 mt-1"><?= (int)($lastRun['summary']['docs_auto_approved'] ?? 0) ?></p>
        </div>
    </div>

    <div class="glass rounded-xl p-6 border border-sky-500/20 mb-8">
        <h2 class="font-semibold text-lg mb-4">How It Works</h2>
        <ul class="space-y-2 text-sm text-gray-400">
            <li class="flex gap-2"><span class="text-sky-400">1.</span> Finds merchants with <code class="text-xs bg-gray-800 px-1 rounded">kyc_status = submitted</code></li>
            <li class="flex gap-2"><span class="text-sky-400">2.</span> Checks for risk flags (blocked/suspended/rejected)</li>
            <li class="flex gap-2"><span class="text-sky-400">3.</span> Checks Video KYC is verified (if required)</li>
            <li class="flex gap-2"><span class="text-sky-400">4.</span> Auto-approves docs with <code class="text-xs bg-gray-800 px-1 rounded">scan_status = clean</code></li>
            <li class="flex gap-2"><span class="text-sky-400">5.</span> Verifies merchant if all required docs are approved</li>
            <li class="flex gap-2"><span class="text-sky-400">6.</span> Triggers post-KYC automation (method requests, partner forward)</li>
        </ul>
    </div>

    <?php if (!empty($lastRun['summary'])): ?>
    <div class="glass rounded-xl p-6 border border-gray-700 mb-8">
        <h2 class="font-semibold text-lg mb-4">Last Run Breakdown</h2>
        <div class="grid sm:grid-cols-2 gap-3 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Merchants Checked</span><span class="text-white"><?= (int)$lastRun['summary']['merchants_checked'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Docs Auto-Approved</span><span class="text-emerald-400"><?= (int)$lastRun['summary']['docs_auto_approved'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Merchants Verified</span><span class="text-emerald-400"><?= (int)$lastRun['summary']['merchants_verified'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Skipped (Risk)</span><span class="text-amber-400"><?= (int)$lastRun['summary']['skipped_risk'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Skipped (Video KYC)</span><span class="text-amber-400"><?= (int)$lastRun['summary']['skipped_video'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Skipped (Missing Docs)</span><span class="text-amber-400"><?= (int)$lastRun['summary']['skipped_missing_docs'] ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Errors</span><span class="text-red-400"><?= (int)$lastRun['summary']['errors'] ?></span></div>
        </div>
    </div>
    <?php endif; ?>

    <form method="POST" class="inline">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="run_now">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium">Run Now</button>
    </form>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
