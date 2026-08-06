<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'risk']);

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'dashboard';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'resolve_event' && isset($_POST['event_id'])) {
        resolveRiskEvent((int)$_POST['event_id'], $adminId, trim($_POST['note'] ?? ''));
        flash('success', 'Risk event resolved.');
        redirect('admin_risk_engine.php?tab=events');
    }

    if ($action === 'toggle_rule' && isset($_POST['rule_id'])) {
        toggleRiskRule((int)$_POST['rule_id'], ($_POST['active'] ?? '0') === '1');
        flash('success', 'Rule updated.');
        redirect('admin_risk_engine.php?tab=rules');
    }

    if ($action === 'update_limits' && isset($_POST['merchant_id'])) {
        $mid = (int)$_POST['merchant_id'];
        updateMerchantRiskLimits($mid, [
            'max_txn_amount' => !empty($_POST['max_txn_amount']) ? (float)$_POST['max_txn_amount'] : null,
            'max_txn_count_hour' => !empty($_POST['max_txn_count_hour']) ? (int)$_POST['max_txn_count_hour'] : null,
            'max_txn_count_day' => !empty($_POST['max_txn_count_day']) ? (int)$_POST['max_txn_count_day'] : null,
            'max_volume_day' => !empty($_POST['max_volume_day']) ? (float)$_POST['max_volume_day'] : null,
            'auto_hold_threshold' => (int)($_POST['auto_hold_threshold'] ?? 70),
            'auto_block_threshold' => (int)($_POST['auto_block_threshold'] ?? 85),
        ]);
        flash('success', 'Merchant risk limits updated.');
        redirect('admin_risk_engine.php?tab=limits&merchant_id=' . $mid);
    }

    if ($action === 'recalculate') {
        $count = recalculateRiskScoresForAll();
        flash('success', "Recalculated risk scores for {$count} merchants.");
        redirect('admin_risk_engine.php');
    }

    if ($action === 'override_event' && isset($_POST['event_id'])) {
        $newAction = trim($_POST['new_action'] ?? 'dismiss');
        $reason = trim($_POST['reason'] ?? '');
        if (overrideRiskEvent((int)$_POST['event_id'], $newAction, $adminId, $reason)) {
            flash('success', "Risk event overridden to {$newAction}.");
        } else {
            flash('error', 'Failed to override risk event.');
        }
        redirect('admin_risk_engine.php?tab=events');
    }

    if ($action === 'export_csv') {
        $days = (int)($_POST['days'] ?? 30);
        $csv = exportRiskReport($days);
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="risk_report_' . date('Y-m-d') . '.csv"');
        echo $csv;
        exit;
    }
}

$stats = getRiskEngineStats();
$riskEvents = getRiskEvents(100, '', true);
$allRules = getAllRiskRules();
$riskyMerchants = getRiskyMerchants(20);

$selectedMerchant = null;
$merchantLimits = null;
$selectedMerchantId = (int)($_GET['merchant_id'] ?? 0);
if ($selectedMerchantId > 0) {
    $st = getDB()->prepare("SELECT id, merchant_code, business_name FROM merchants WHERE id=?");
    $st->execute([$selectedMerchantId]);
    $selectedMerchant = $st->fetch();
    if ($selectedMerchant) {
        $merchantLimits = getMerchantRiskLimits($selectedMerchantId);
    }
}

$pageTitle = 'Risk Engine';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Transaction risk scoring, velocity rules, auto-actions</p>
        <div class="flex gap-2 text-xs">
            <a href="?tab=dashboard" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'dashboard' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Dashboard</a>
            <a href="?tab=events" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'events' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Risk Events</a>
            <a href="?tab=rules" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'rules' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Rules</a>
            <a href="?tab=limits" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'limits' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Merchant Limits</a>
            <a href="?tab=fraud" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'fraud' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Fraud Detection</a>
        </div>
    </div>

    <?php if ($activeTab === 'dashboard'): ?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Risk Events</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($stats['total_events']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Blocked</p><p class="text-2xl font-bold text-red-400 mt-1"><?= number_format($stats['blocked']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Held</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($stats['held']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Flagged</p><p class="text-2xl font-bold text-yellow-400 mt-1"><?= number_format($stats['flagged']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Unresolved</p><p class="text-2xl font-bold text-orange-400 mt-1"><?= number_format($stats['unresolved']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Active Rules</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($stats['active_rules']) ?></p></div>
    </div>

    <div class="flex gap-3">
        <form method="POST" class="inline-block">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="recalculate">
            <button type="submit" class="btn-primary px-6 py-2.5">Recalculate All Risk Scores</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Risky Merchants (Top 20)</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Code</th><th class="px-4 py-3 text-left">KYC</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Risk Score</th><th class="px-4 py-3 text-left">Reasons</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($riskyMerchants)): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No merchants found.</td></tr>
                <?php else: foreach ($riskyMerchants as $m): ?>
                <tr>
                    <td class="px-4 py-3"><?= e($m['business_name']) ?></td>
                    <td class="px-4 py-3 text-xs font-mono"><?= e($m['merchant_code']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($m['status']) ?></td>
                    <td class="px-4 py-3"><?php $s = (int)($m['score'] ?? 0); $cls = $s >= 80 ? 'text-red-400' : ($s >= 60 ? 'text-amber-400' : ($s >= 30 ? 'text-yellow-400' : 'text-emerald-400')); ?><span class="<?= $cls ?> font-bold"><?= $s ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?php $r = json_decode((string)($m['reasons'] ?? '[]'), true); echo e(implode(', ', $r ?: [])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'events'): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-red-400">Unresolved Risk Events</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Date</th><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Score</th><th class="px-4 py-3 text-left">Action</th><th class="px-4 py-3 text-left">Details</th><th class="px-4 py-3 text-left">Resolve</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($riskEvents)): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No unresolved events.</td></tr>
                <?php else: foreach ($riskEvents as $ev): ?>
                <tr>
                    <td class="px-4 py-3 text-xs"><?= formatDate($ev['created_at']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($ev['business_name'] ?? '—') ?> <span class="font-mono text-gray-500"><?= e($ev['merchant_code'] ?? '') ?></span></td>
                    <td class="px-4 py-3"><?php $s = (int)$ev['risk_score']; $cls = $s >= 80 ? 'text-red-400' : ($s >= 60 ? 'text-amber-400' : 'text-yellow-400'); ?><span class="<?= $cls ?> font-bold"><?= $s ?></span></td>
                    <td class="px-4 py-3"><?php $a = $ev['action_taken']; $bcls = $a === 'block' ? 'bg-red-500/10 text-red-400' : ($a === 'hold' ? 'bg-amber-500/10 text-amber-400' : 'bg-yellow-500/10 text-yellow-400'); ?><span class="px-2 py-1 rounded-full text-xs <?= $bcls ?>"><?= ucfirst($a) ?></span></td>
                    <td class="px-4 py-3 text-xs text-gray-400 max-w-xs truncate"><?php $d = json_decode((string)($ev['details'] ?? '{}'), true); echo e(implode(', ', $d['reasons'] ?? [])) ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="flex gap-1 items-center">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                            <select name="new_action" class="input-field text-xs w-20"><option value="allow">Allow</option><option value="flag">Flag</option><option value="hold">Hold</option><option value="block">Block</option><option value="dismiss">Dismiss</option></select>
                            <input type="text" name="reason" placeholder="Reason" class="input-field text-xs w-24" required>
                            <button type="submit" name="action" value="override_event" class="text-xs text-sky-400 hover:underline">Override</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'rules'): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Risk Rules</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Rule</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Scope</th><th class="px-4 py-3 text-left">Weight</th><th class="px-4 py-3 text-left">Action</th><th class="px-4 py-3 text-left">Active</th><th class="px-4 py-3"></th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($allRules as $r): ?>
                <tr>
                    <td class="px-4 py-3"><?= e($r['rule_name']) ?></td>
                    <td class="px-4 py-3 capitalize"><?= e($r['rule_type']) ?></td>
                    <td class="px-4 py-3 capitalize"><?= e($r['scope']) ?></td>
                    <td class="px-4 py-3"><?= (int)$r['score_weight'] ?></td>
                    <td class="px-4 py-3 capitalize"><?= e($r['action']) ?></td>
                    <td class="px-4 py-3"><?= (int)$r['is_active'] ? '<span class="text-emerald-400">Yes</span>' : '<span class="text-gray-500">No</span>' ?></td>
                    <td class="px-4 py-3">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
                            <input type="hidden" name="active" value="<?= (int)$r['is_active'] ? '0' : '1' ?>">
                            <button type="submit" name="action" value="toggle_rule" class="text-xs text-sky-400 hover:underline"><?= (int)$r['is_active'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'fraud'): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Fraud Pattern Detection</h3>
        <form method="GET" class="flex gap-3 items-end mb-6">
            <div><label class="text-sm text-gray-400">Merchant ID</label>
                <input type="number" name="merchant_id" value="<?= $selectedMerchantId ?: '' ?>" class="input-field mt-1 w-full" placeholder="Enter merchant ID">
            </div>
            <input type="hidden" name="tab" value="fraud">
            <button type="submit" class="btn-primary px-4 py-2">Scan</button>
        </form>
        <?php if ($selectedMerchantId > 0): $fraudAlerts = detectFraudPatterns($selectedMerchantId, 7); ?>
            <?php if (empty($fraudAlerts)): ?>
            <p class="text-emerald-400 text-sm">No fraud patterns detected in the last 7 days.</p>
            <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($fraudAlerts as $fa): ?>
                <div class="p-3 rounded-lg border <?= $fa['severity'] === 'high' ? 'border-red-500/30 bg-red-500/5' : 'border-amber-500/30 bg-amber-500/5' ?>">
                    <div class="flex items-center gap-2">
                        <span class="text-xs px-2 py-0.5 rounded-full <?= $fa['severity'] === 'high' ? 'bg-red-500/10 text-red-400' : 'bg-amber-500/10 text-amber-400' ?>"><?= ucfirst($fa['severity']) ?></span>
                        <span class="text-sm font-medium"><?= e(str_replace('_', ' ', $fa['type'])) ?></span>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?php foreach ($fa as $k => $v) { if (!in_array($k, ['type', 'severity'], true)) echo e($k) . ': ' . e((string)$v) . '  '; } ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-gray-500 text-sm">Enter a merchant ID to run fraud pattern detection.</p>
        <?php endif; ?>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6 mt-4">
        <h3 class="font-semibold mb-4">Export Risk Report</h3>
        <form method="POST" class="flex gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="export_csv">
            <div><label class="text-sm text-gray-400">Days</label>
                <input type="number" name="days" value="30" class="input-field mt-1 w-32">
            </div>
            <button type="submit" class="btn-primary px-4 py-2">Download CSV</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'limits'): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Merchant Risk Limits</h3>
        <form method="GET" class="flex gap-3 items-end mb-6">
            <div><label class="text-sm text-gray-400">Merchant ID</label>
                <input type="number" name="merchant_id" value="<?= $selectedMerchantId ?: '' ?>" class="input-field mt-1 w-full" placeholder="Enter merchant ID">
            </div>
            <input type="hidden" name="tab" value="limits">
            <button type="submit" class="btn-primary px-4 py-2">Load</button>
        </form>

        <?php if ($selectedMerchant && $merchantLimits): ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_limits">
            <input type="hidden" name="merchant_id" value="<?= $selectedMerchantId ?>">
            <p class="text-sm text-gray-400"><?= e($selectedMerchant['business_name']) ?> (<?= e($selectedMerchant['merchant_code']) ?>)</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Max Txn Amount (₹)</label>
                    <input type="number" step="0.01" name="max_txn_amount" value="<?= $merchantLimits['max_txn_amount'] ?? '' ?>" class="input-field mt-1 w-full" placeholder="No limit"></div>
                <div><label class="text-sm text-gray-400">Max Txns / Hour</label>
                    <input type="number" name="max_txn_count_hour" value="<?= $merchantLimits['max_txn_count_hour'] ?? '' ?>" class="input-field mt-1 w-full" placeholder="No limit"></div>
                <div><label class="text-sm text-gray-400">Max Txns / Day</label>
                    <input type="number" name="max_txn_count_day" value="<?= $merchantLimits['max_txn_count_day'] ?? '' ?>" class="input-field mt-1 w-full" placeholder="No limit"></div>
                <div><label class="text-sm text-gray-400">Max Volume / Day (₹)</label>
                    <input type="number" step="0.01" name="max_volume_day" value="<?= $merchantLimits['max_volume_day'] ?? '' ?>" class="input-field mt-1 w-full" placeholder="No limit"></div>
                <div><label class="text-sm text-gray-400">Auto-Hold Threshold (score)</label>
                    <input type="number" name="auto_hold_threshold" value="<?= (int)($merchantLimits['auto_hold_threshold'] ?? 70) ?>" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Auto-Block Threshold (score)</label>
                    <input type="number" name="auto_block_threshold" value="<?= (int)($merchantLimits['auto_block_threshold'] ?? 85) ?>" class="input-field mt-1 w-full"></div>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Limits</button>
        </form>
        <?php else: ?>
        <p class="text-gray-500 text-sm">Enter a merchant ID to configure risk limits.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
