<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
if (!function_exists('cloudModulesAutoKycAdminEducation') && is_file(__DIR__ . '/includes/cloud_modules_workflow.php')) {
    require_once __DIR__ . '/includes/cloud_modules_workflow.php';
}
if (!function_exists('autoKycRiskAdminEducation') && is_file(__DIR__ . '/includes/auto_kyc_risk_workflow.php')) {
    require_once __DIR__ . '/includes/auto_kyc_risk_workflow.php';
}
if (!function_exists('runAutoKycEngine') && is_file(__DIR__ . '/includes/auto_kyc.php')) {
    require_once __DIR__ . '/includes/auto_kyc.php';
}

if (!function_exists('setSetting')) {
    function setSetting(string $key, string $value): void {
        $db = getDB();
        $st = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?');
        $st->execute([$key, $value, $value]);
    }
}

$lastRun = getLastAutoKycRun();
$forwardQueue = [];
$autoKycEdu = function_exists('cloudModulesAutoKycAdminEducation') ? cloudModulesAutoKycAdminEducation() : null;
$autoKycRiskEdu = function_exists('autoKycRiskAdminEducation') ? autoKycRiskAdminEducation() : null;
$autoKycRiskReady = function_exists('autoKycRiskReadinessReport') ? autoKycRiskReadinessReport() : null;
$autoKycReady = function_exists('cloudModulesAutoKycReadinessReport') ? cloudModulesAutoKycReadinessReport() : null;

// Manual trigger
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_video_required') {
        $current = getSetting('video_kyc_required_for_auto', '0');
        $newVal = $current === '1' ? '0' : '1';
        setSetting('video_kyc_required_for_auto', $newVal);
        flash('success', 'Video KYC requirement for auto-KYC: ' . ($newVal === '1' ? 'REQUIRED (strict)' : 'NOT REQUIRED (test/demo lenient)'));
        redirect('admin_auto_kyc.php');
    }
    if ($action === 'toggle_video_optional') {
        // Legacy toggle — kept for backward compat, redirects to new setting
        $current = getSetting('video_kyc_required_for_auto', '0');
        $newVal = $current === '1' ? '0' : '1';
        setSetting('video_kyc_required_for_auto', $newVal);
        flash('success', 'Video KYC requirement for auto-KYC: ' . ($newVal === '1' ? 'REQUIRED (strict)' : 'NOT REQUIRED (test/demo lenient)'));
        redirect('admin_auto_kyc.php');
    }
    if ($action === 'run_now' && function_exists('runAutoKycEngine')) {
        $result = runAutoKycEngine();
        flash('success', "Auto KYC run complete: {$result['merchants_verified']} verified, {$result['docs_auto_approved']} docs approved, {$result['merchants_checked']} checked.");
        redirect('admin_auto_kyc.php');
    }
    if ($action === 'process_forward' && function_exists('processPartnerForwardQueue')) {
        $fwd = processPartnerForwardQueue();
        flash('success', "Partner forward: {$fwd['forwarded']} forwarded, {$fwd['processed']} processed.");
        redirect('admin_auto_kyc.php');
    }
    $merchantId = (int)($_POST['merchant_id'] ?? 0);
    if ($merchantId && function_exists('pausePartnerForward')) {
        if ($action === 'pause_forward') {
            pausePartnerForward($merchantId, (int)($_SESSION['admin_id'] ?? 0));
            flash('success', 'Partner forward paused.');
        } elseif ($action === 'resume_forward') {
            resumePartnerForward($merchantId);
            flash('success', 'Partner forward resumed with fresh hold window.');
        } elseif ($action === 'cancel_forward') {
            cancelPartnerForward($merchantId, trim((string)($_POST['reason'] ?? '')));
            flash('success', 'Partner forward cancelled.');
        }
        redirect('admin_auto_kyc.php');
    }
}

if (function_exists('getPartnerForwardQueue')) {
    $forwardQueue = getPartnerForwardQueue(50);
}

$pageTitle = 'Zero-Touch Auto KYC';
$adminSection = 'kyc';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-white mb-2">Zero-Touch Auto KYC Engine</h1>
    <p class="text-sm text-gray-500 mb-4">Automatically approves clean KYC documents and verifies eligible merchants — same KYC Review pages, not a new product.</p>
    <?php if (is_array($autoKycEdu)): ?>
    <div class="glass rounded-xl p-4 mb-4 border border-sky-500/25 text-xs text-gray-400">
        <p class="font-semibold text-sky-300 mb-1"><?= e($autoKycEdu['title']) ?></p>
        <p class="mb-2"><?= e($autoKycEdu['policy']) ?></p>
        <p class="text-gray-500">Cron: <code class="text-gray-300"><?= e($autoKycEdu['cron_script']) ?></code> · <?= e($autoKycEdu['cron_schedule']) ?> · Bridge: <?= e($autoKycEdu['bridge_module']) ?></p>
        <?php if (is_array($autoKycReady) && empty($autoKycReady['ok'])): ?>
        <p class="text-amber-400 mt-2"><?= e($autoKycReady['message'] ?? '') ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if (is_array($autoKycRiskEdu)): ?>
    <div class="glass rounded-xl p-4 mb-4 border border-emerald-500/25 text-xs text-gray-400">
        <p class="font-semibold text-emerald-300 mb-1"><?= e($autoKycRiskEdu['title']) ?></p>
        <p class="mb-2"><?= e($autoKycRiskEdu['policy']) ?></p>
        <p class="text-gray-500 mb-2">Manual assist after <strong class="text-gray-300"><?= (int)($autoKycRiskEdu['threshold'] ?? 3) ?></strong> verify_failed · Escape: <a href="admin_kyc.php" class="text-sky-400 underline">KYC Review</a></p>
        <ul class="space-y-1 text-[11px]">
            <?php foreach ($autoKycRiskEdu['gates'] ?? [] as $gate): ?>
            <li><span class="text-gray-500"><?= (int)($gate['order'] ?? 0) ?>.</span> <?= e($gate['label'] ?? '') ?> — <span class="text-amber-300/90"><?= e($gate['fail_mode'] ?? '') ?></span></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    <div class="glass rounded-xl p-4 mb-6 border border-violet-500/20 text-xs text-gray-400">
        <strong class="text-violet-300">Partner auto-KYC / forward:</strong> runs on this existing queue after Admin Verify when partner <strong class="text-gray-300">keys + commercial</strong> are set. Without keys, rows stay queued / unassigned until you paste credentials in Partner Registry.
    </div>

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

    <?php if (!empty($forwardQueue)): ?>
    <div class="glass rounded-xl p-6 border border-violet-500/20 mb-8">
        <h2 class="font-semibold text-lg mb-2">Partner Forward Queue</h2>
        <p class="text-xs text-gray-500 mb-4">Forwards verified merchants when partner keys + contract path are ready. Hold window applies. Pause / Resume / Cancel stay here — not a separate KYC product.</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase">
                    <tr>
                        <th class="px-3 py-2 text-left">Merchant</th>
                        <th class="px-3 py-2 text-left">Status</th>
                        <th class="px-3 py-2 text-left">Scheduled</th>
                        <th class="px-3 py-2 text-left">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($forwardQueue as $q): $qStatus = $q['status'] ?? 'queued'; ?>
                    <tr>
                        <td class="px-3 py-2">
                            <p class="font-medium text-sm"><?= e($q['business_name'] ?? '') ?></p>
                            <p class="text-xs text-gray-500 font-mono"><?= e($q['merchant_code'] ?? '') ?> · KYC: <?= e($q['kyc_status'] ?? '') ?><?php if (!empty($q['partner_key'])): ?> · <?= e($q['partner_key']) ?><?php endif; ?></p>
                        </td>
                        <td class="px-3 py-2">
                            <?php if ($qStatus === 'queued' || $qStatus === 'retry'): ?><span class="text-xs px-2 py-1 bg-sky-600/20 text-sky-400 rounded"><?= $qStatus === 'retry' ? 'Retry' : 'Queued' ?></span>
                            <?php elseif ($qStatus === 'paused'): ?><span class="text-xs px-2 py-1 bg-amber-600/20 text-amber-400 rounded">Paused</span>
                            <?php elseif ($qStatus === 'forwarded' || $qStatus === 'success'): ?><span class="text-xs px-2 py-1 bg-emerald-600/20 text-emerald-400 rounded">Forwarded</span>
                            <?php elseif ($qStatus === 'failed' || $qStatus === 'cancelled'): ?><span class="text-xs px-2 py-1 bg-red-600/20 text-red-400 rounded"><?= $qStatus === 'cancelled' ? 'Cancelled' : 'Failed' ?></span>
                            <?php else: ?><span class="text-xs px-2 py-1 bg-gray-700/40 text-gray-400 rounded"><?= e($qStatus) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($q['admin_note'])): ?><p class="text-xs text-gray-500 mt-1"><?= e($q['admin_note']) ?></p><?php endif; ?>
                            <?php if (!empty($q['error_message']) && empty($q['admin_note'])): ?><p class="text-xs text-gray-500 mt-1"><?= e($q['error_message']) ?></p><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-xs text-gray-500">
                            <?= e(formatDate($q['scheduled_at'] ?? $q['schedule_at'] ?? '')) ?>
                            <?php if (!empty($q['forwarded_at'])): ?><br><span class="text-emerald-400">Forwarded: <?= e(formatDate($q['forwarded_at'])) ?></span><?php endif; ?>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex gap-1 flex-wrap">
                                <?php if ($qStatus === 'queued' || $qStatus === 'retry'): ?>
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="pause_forward"><input type="hidden" name="merchant_id" value="<?= (int)$q['merchant_id'] ?>"><button class="text-xs bg-amber-600/20 text-amber-400 px-2 py-1 rounded">Pause</button></form>
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="cancel_forward"><input type="hidden" name="merchant_id" value="<?= (int)$q['merchant_id'] ?>"><input name="reason" placeholder="Reason" class="text-xs bg-gray-900 border border-gray-700 rounded px-1 py-0.5 w-20"><button class="text-xs bg-red-600/20 text-red-400 px-2 py-1 rounded">Cancel</button></form>
                                <?php elseif ($qStatus === 'paused'): ?>
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="resume_forward"><input type="hidden" name="merchant_id" value="<?= (int)$q['merchant_id'] ?>"><button class="text-xs bg-sky-600/20 text-sky-400 px-2 py-1 rounded">Resume</button></form>
                                <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="cancel_forward"><input type="hidden" name="merchant_id" value="<?= (int)$q['merchant_id'] ?>"><button class="text-xs bg-red-600/20 text-red-400 px-2 py-1 rounded">Cancel</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="POST" class="mt-4 inline">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="process_forward">
            <button type="submit" class="px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-700 text-white text-sm font-medium">Process Forward Queue Now</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl p-5 border border-amber-500/20 mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm font-semibold text-white">Video KYC Requirement for Auto-KYC</p>
            <p class="text-xs text-gray-500 mt-1">When REQUIRED, merchants must complete Video KYC before zero-touch auto-verify. When NOT REQUIRED, test/demo merchants can be auto-verified without it. Other checks (PAN, docs, risk, name) always apply.</p>
        </div>
        <div class="flex items-center gap-3">
            <?php $vidRequired = getSetting('video_kyc_required_for_auto', '0'); ?>
            <span class="text-xs <?= $vidRequired === '1' ? 'text-red-400' : 'text-emerald-400' ?>"><?= $vidRequired === '1' ? 'REQUIRED (strict)' : 'NOT REQUIRED (test/demo)' ?></span>
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="toggle_video_required">
                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium <?= $vidRequired === '1' ? 'bg-emerald-600/20 text-emerald-400 hover:bg-emerald-600/30' : 'bg-red-600/20 text-red-400 hover:bg-red-600/30' ?>">
                    <?= $vidRequired === '1' ? 'Set NOT REQUIRED' : 'Set REQUIRED' ?>
                </button>
            </form>
        </div>
    </div>

    <div class="flex gap-3 flex-wrap">
    <form method="POST" class="inline">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="run_now">
        <button type="submit" class="px-5 py-2.5 rounded-lg bg-sky-600 hover:bg-sky-700 text-white text-sm font-medium">Run KYC Engine Now</button>
    </form>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
