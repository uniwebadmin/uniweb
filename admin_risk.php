<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/risk.php';
requireStaffAccess(['super', 'ceo', 'ops', 'finance']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_threshold' && isset($_POST['threshold'])) {
        setAmlHighValueThreshold((int)($_POST['threshold'] ?? 50000));
        flash('success', 'High-value threshold updated.');
        redirect('admin_risk.php');
    }

    if ($action === 'add_watchlist') {
        $ok = addAmlWatchlistEntry(
            $_POST['watchlist_type'] ?? '',
            trim($_POST['watchlist_value'] ?? ''),
            trim($_POST['watchlist_source'] ?? 'manual'),
            trim($_POST['watchlist_reason'] ?? ''),
            !empty($_POST['watchlist_sanction'])
        );
        flash($ok ? 'success' : 'error', $ok ? 'Watchlist entry added.' : 'Could not add entry.');
        redirect('admin_risk.php');
    }

    if ($action === 'remove_watchlist' && !empty($_POST['id'])) {
        removeAmlWatchlistEntry((int)$_POST['id']);
        flash('success', 'Watchlist entry removed.');
        redirect('admin_risk.php');
    }

    if ($action === 'add_blacklist') {
        $ok = addBlacklistEntry(
            $_POST['blacklist_scope'] ?? '',
            $_POST['blacklist_target_type'] ?? '',
            trim($_POST['blacklist_target'] ?? ''),
            trim($_POST['blacklist_reason'] ?? '')
        );
        flash($ok ? 'success' : 'error', $ok ? 'Blacklist entry added.' : 'Could not add entry.');
        redirect('admin_risk.php');
    }

    if ($action === 'remove_blacklist' && !empty($_POST['id'])) {
        removeBlacklistEntry((int)$_POST['id']);
        flash('success', 'Blacklist entry removed.');
        redirect('admin_risk.php');
    }

    if ($action === 'recalculate_all') {
        $n = recalculateRiskScoresForAll();
        flash('success', 'Recalculated risk scores for ' . $n . ' merchant(s).');
        redirect('admin_risk.php');
    }
}

$threshold = getAmlHighValueThreshold();
$risky = getRiskyMerchants(50);
$watchlist = getAmlWatchlistEntries(100);
$blacklist = getBlacklistEntries(100);

$pageTitle = 'Risk & AML';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Risk &amp; AML</h2>
            <p class="text-sm text-gray-400 mt-1">Merchant risk scores, chargeback ratios, watchlist and blacklist management.</p>
        </div>
        <form method="POST" class="inline-block">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="recalculate_all">
            <button type="submit" class="btn-primary px-4 py-2 text-sm" onclick="return confirm('Recalculate all merchant risk scores?')">Recalculate All</button>
        </form>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">High-value threshold</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= formatMoney((float)$threshold) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Watchlist entries</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= count($watchlist) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Blacklist entries</p><p class="text-2xl font-bold text-red-400 mt-1"><?= count($blacklist) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">High-risk merchants</p><p class="text-2xl font-bold text-red-400 mt-1"><?= count(array_filter($risky, fn($m) => ($m['score'] ?? 0) >= 80)) ?></p></div>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">High-Value Threshold</h3>
        <form method="POST" class="flex flex-col sm:flex-row gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="set_threshold">
            <div class="flex-1 min-w-0">
                <label class="text-sm text-gray-400">Amount (INR)</label>
                <input type="number" name="threshold" value="<?= (int)$threshold ?>" min="1" step="1" class="input-field mt-1 w-full">
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Update</button>
        </form>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Add AML Watchlist / Sanctions Entry</h3>
        <form method="POST" class="grid sm:grid-cols-6 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_watchlist">
            <div class="sm:col-span-1"><label class="text-sm text-gray-400">Type</label><select name="watchlist_type" class="input-field mt-1 w-full"><option value="individual">Individual</option><option value="entity">Entity</option><option value="phone">Phone</option><option value="email">Email</option><option value="upi">UPI</option><option value="account">Account</option></select></div>
            <div class="sm:col-span-1"><label class="text-sm text-gray-400">Value</label><input type="text" name="watchlist_value" class="input-field mt-1 w-full" required></div>
            <div class="sm:col-span-1"><label class="text-sm text-gray-400">Source</label><input type="text" name="watchlist_source" value="manual" class="input-field mt-1 w-full"></div>
            <div class="sm:col-span-1"><label class="text-sm text-gray-400">Reason</label><input type="text" name="watchlist_reason" class="input-field mt-1 w-full"></div>
            <div class="sm:col-span-1 flex items-center h-full"><label class="flex items-center gap-2 text-sm text-gray-400"><input type="checkbox" name="watchlist_sanction" value="1" class="rounded border-gray-600"> Sanction</label></div>
            <div class="sm:col-span-1"><button type="submit" class="btn-primary w-full px-4 py-2.5">Add</button></div>
        </form>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Add Blacklist Entry</h3>
        <form method="POST" class="grid sm:grid-cols-5 gap-3 items-end">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_blacklist">
            <div><label class="text-sm text-gray-400">Scope</label><select name="blacklist_scope" class="input-field mt-1 w-full"><option value="merchant">Merchant</option><option value="customer">Customer</option></select></div>
            <div><label class="text-sm text-gray-400">Target type</label><select name="blacklist_target_type" class="input-field mt-1 w-full"><option value="merchant_id">Merchant ID</option><option value="phone">Phone</option><option value="email">Email</option><option value="upi">UPI</option><option value="ip">IP</option></select></div>
            <div><label class="text-sm text-gray-400">Target</label><input type="text" name="blacklist_target" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Reason</label><input type="text" name="blacklist_reason" class="input-field mt-1 w-full"></div>
            <div><button type="submit" class="btn-primary w-full px-4 py-2.5">Add</button></div>
        </form>
    </div>

    <div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
        <div class="glass rounded-xl overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Watchlist</h3></div>
            <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Value</th><th class="px-4 py-3 text-left">Reason</th><th class="px-4 py-3 text-left">Sanction</th><th class="px-4 py-3 text-left"></th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($watchlist)): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No entries.</td></tr>
                    <?php else: foreach ($watchlist as $w): ?>
                    <tr>
                        <td class="px-4 py-3 text-xs"><?= e($w['type']) ?></td>
                        <td class="px-4 py-3 font-mono text-xs"><?= e($w['value']) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-400"><?= e($w['reason'] ?: '—') ?></td>
                        <td class="px-4 py-3 text-xs"><?= $w['is_sanction'] ? '<span class="text-red-400">Yes</span>' : 'No' ?></td>
                        <td class="px-4 py-3">
                            <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="remove_watchlist"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><button type="submit" class="text-xs text-red-400 hover:underline">Remove</button></form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table></div>
        </div>

        <div class="glass rounded-xl overflow-hidden">
            <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Blacklist</h3></div>
            <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Scope</th><th class="px-4 py-3 text-left">Target</th><th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Reason</th><th class="px-4 py-3 text-left"></th></tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($blacklist)): ?><tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">No entries.</td></tr>
                    <?php else: foreach ($blacklist as $b): ?>
                    <tr>
                        <td class="px-4 py-3 text-xs"><?= e($b['scope']) ?></td>
                        <td class="px-4 py-3 font-mono text-xs"><?= e($b['target']) ?></td>
                        <td class="px-4 py-3 text-xs"><?= e($b['target_type']) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-400"><?= e($b['reason'] ?: '—') ?></td>
                        <td class="px-4 py-3">
                            <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="remove_blacklist"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button type="submit" class="text-xs text-red-400 hover:underline">Remove</button></form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Merchant Risk Scores</h3></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Score</th><th class="px-4 py-3 text-left">Chargeback %</th><th class="px-4 py-3 text-left">Reasons</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($risky)): ?><tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">No merchants.</td></tr>
                <?php else: foreach ($risky as $m): ?>
                <tr>
                    <td class="px-4 py-3">
                        <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="text-sky-400 hover:underline"><?= e($m['business_name'] ?: $m['merchant_code']) ?></a>
                        <p class="text-xs text-gray-500"><?= e($m['merchant_code']) ?> · <?= e($m['kyc_status']) ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= ($m['score'] ?? 0) >= 80 ? 'bg-red-500/15 text-red-400' : (($m['score'] ?? 0) >= 50 ? 'bg-amber-500/15 text-amber-400' : 'bg-emerald-500/15 text-emerald-400') ?>"><?= (int)($m['score'] ?? 0) ?></span>
                    </td>
                    <td class="px-4 py-3 text-xs"><?= number_format(getChargebackRatio((int)$m['id'], 90) * 100, 2) ?>%</td>
                    <td class="px-4 py-3 text-xs text-gray-400"><?= e(implode(', ', json_decode((string)($m['reasons'] ?? '[]'), true) ?: [])) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
