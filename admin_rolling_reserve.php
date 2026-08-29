<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);
if (!function_exists('getRollingReserveStats') && is_file(__DIR__ . '/includes/rolling_reserve.php')) {
    require_once __DIR__ . '/includes/rolling_reserve.php';
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'dashboard';

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_config' && isset($_POST['merchant_id'])) {
        $mid = (int)$_POST['merchant_id'];
        updateRollingReserveConfig(
            $mid,
            (float)$_POST['hold_percentage'],
            (int)$_POST['release_days'],
            ($_POST['auto_release'] ?? '0') === '1',
            trim($_POST['applies_to'] ?? 'all')
        );
        flash('success', 'Rolling reserve config updated.');
        redirect('admin_rolling_reserve.php?tab=config&merchant_id=' . $mid);
    }

    if ($action === 'manual_release' && isset($_POST['hold_id'])) {
        manuallyReleaseHold((int)$_POST['hold_id'], $adminId, trim($_POST['note'] ?? ''));
        flash('success', 'Hold manually released.');
        redirect('admin_rolling_reserve.php?tab=holds');
    }

    if ($action === 'auto_release') {
        $count = autoReleaseReserveHolds();
        flash('success', "Auto-released {$count} holds.");
        redirect('admin_rolling_reserve.php');
    }
}

$stats = getRollingReserveStats();
$dueHolds = getHoldsDueForRelease();

// Holds list
$holdsMerchantId = (int)($_GET['merchant_id'] ?? 0);
$holdsStatus = trim($_GET['status'] ?? '');
$allHolds = [];
if ($holdsMerchantId > 0) {
    $allHolds = getMerchantReserveHolds($holdsMerchantId, $holdsStatus, 200);
}

// Config for selected merchant
$selectedMerchant = null;
$reserveConfig = null;
$configMerchantId = (int)($_GET['merchant_id'] ?? 0);
if ($activeTab === 'config' && $configMerchantId > 0) {
    $st = getDB()->prepare("SELECT id, merchant_code, business_name FROM merchants WHERE id=?");
    $st->execute([$configMerchantId]);
    $selectedMerchant = $st->fetch();
    if ($selectedMerchant) {
        $reserveConfig = getRollingReserveConfig($configMerchantId);
    }
}

$pageTitle = 'Rolling Reserve';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Hold % on new/high-risk merchants, auto-release after T+N days</p>
        <div class="flex gap-2 text-xs">
            <a href="?tab=dashboard" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'dashboard' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Dashboard</a>
            <a href="?tab=holds" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'holds' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Holds</a>
            <a href="?tab=config" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'config' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Config</a>
        </div>
    </div>

    <?php if ($activeTab === 'dashboard'): ?>
    <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Held Amount</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= formatMoney($stats['total_held']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total Released</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= formatMoney($stats['total_released']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Active Holds</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($stats['active_holds']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Due for Release Today</p><p class="text-2xl font-bold text-orange-400 mt-1"><?= number_format($stats['due_today']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Merchants with Reserve</p><p class="text-2xl font-bold text-violet-400 mt-1"><?= number_format($stats['merchants_with_reserve']) ?></p></div>
    </div>

    <?php if ($stats['due_today'] > 0): ?>
    <div class="flex gap-3">
        <form method="POST" class="inline-block">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="auto_release">
            <button type="submit" class="btn-primary px-6 py-2.5">Auto-Release <?= $stats['due_today'] ?> Due Holds</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if (!empty($dueHolds)): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold text-amber-400">Holds Due for Release</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Txn ID</th><th class="px-4 py-3 text-left">Held Amount</th><th class="px-4 py-3 text-left">Release Date</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($dueHolds as $h): ?>
                <tr>
                    <td class="px-4 py-3 text-xs"><?= e($h['business_name']) ?> <span class="font-mono text-gray-500"><?= e($h['merchant_code']) ?></span></td>
                    <td class="px-4 py-3 text-xs font-mono"><?= (int)$h['transaction_id'] ?></td>
                    <td class="px-4 py-3 text-xs"><?= formatMoney((float)$h['held_amount']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($h['release_date']) ?></td>
                    <td class="px-4 py-3">
                        <form method="POST" class="inline-block">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
                            <button type="submit" name="action" value="manual_release" class="text-xs text-emerald-400 hover:underline">Release</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($activeTab === 'holds'): ?>
    <div class="glass rounded-xl p-4 sm:p-6 mb-4">
        <form method="GET" class="flex flex-col sm:flex-row gap-3 items-end">
            <div><label class="text-sm text-gray-400">Merchant</label>
                <?= renderAdminMerchantSelect('merchant_id', $holdsMerchantId, false, true, 'Select merchant…') ?>
            </div>
            <div><label class="text-sm text-gray-400">Status</label>
                <select name="status" class="input-field mt-1 w-full">
                    <option value="">All</option>
                    <option value="held" <?= $holdsStatus === 'held' ? 'selected' : '' ?>>Held</option>
                    <option value="released" <?= $holdsStatus === 'released' ? 'selected' : '' ?>>Released</option>
                    <option value="manually_released" <?= $holdsStatus === 'manually_released' ? 'selected' : '' ?>>Manually Released</option>
                    <option value="cancelled" <?= $holdsStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <input type="hidden" name="tab" value="holds">
            <button type="submit" class="btn-primary px-4 py-2">Load</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Reserve Holds</h2></div>
        <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Txn ID</th><th class="px-4 py-3 text-left">Held Amount</th><th class="px-4 py-3 text-left">Hold %</th><th class="px-4 py-3 text-left">Held At</th><th class="px-4 py-3 text-left">Release Date</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Action</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($allHolds)): ?><tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No holds found. Select a merchant above.</td></tr>
                <?php else: foreach ($allHolds as $h): ?>
                <tr>
                    <td class="px-4 py-3 text-xs font-mono"><?= (int)$h['transaction_id'] ?></td>
                    <td class="px-4 py-3 text-xs"><?= formatMoney((float)$h['held_amount']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= (float)$h['hold_percentage'] ?>%</td>
                    <td class="px-4 py-3 text-xs"><?= formatDate($h['held_at']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($h['release_date']) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($h['status']) ?></td>
                    <td class="px-4 py-3"><?php if ($h['status'] === 'held'): ?>
                        <form method="POST" class="inline-block">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="hold_id" value="<?= (int)$h['id'] ?>">
                            <button type="submit" name="action" value="manual_release" class="text-xs text-emerald-400 hover:underline">Release</button>
                        </form>
                    <?php endif; ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'config'): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Rolling Reserve Configuration</h3>
        <form method="GET" class="flex gap-3 items-end mb-6">
            <div><label class="text-sm text-gray-400">Merchant</label>
                <?= renderAdminMerchantSelect('merchant_id', $configMerchantId, false, true, 'Select merchant…') ?>
            </div>
            <input type="hidden" name="tab" value="config">
            <button type="submit" class="btn-primary px-4 py-2">Load</button>
        </form>

        <?php if ($selectedMerchant && $reserveConfig): ?>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="update_config">
            <input type="hidden" name="merchant_id" value="<?= $configMerchantId ?>">
            <p class="text-sm text-gray-400"><?= e($selectedMerchant['business_name']) ?> (<?= e($selectedMerchant['merchant_code']) ?>)</p>
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Hold Percentage (%)</label>
                    <input type="number" step="0.01" min="0" max="100" name="hold_percentage" value="<?= (float)$reserveConfig['hold_percentage'] ?>" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Release Days (T+N)</label>
                    <input type="number" min="1" max="90" name="release_days" value="<?= (int)$reserveConfig['release_days'] ?>" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Auto Release</label>
                    <select name="auto_release" class="input-field mt-1 w-full">
                        <option value="1" <?= (int)$reserveConfig['auto_release'] === 1 ? 'selected' : '' ?>>Yes</option>
                        <option value="0" <?= (int)$reserveConfig['auto_release'] === 0 ? 'selected' : '' ?>>No (manual only)</option>
                    </select></div>
                <div><label class="text-sm text-gray-400">Applies To</label>
                    <select name="applies_to" class="input-field mt-1 w-full">
                        <option value="all" <?= $reserveConfig['applies_to'] === 'all' ? 'selected' : '' ?>>All transactions</option>
                        <option value="new_merchants" <?= $reserveConfig['applies_to'] === 'new_merchants' ? 'selected' : '' ?>>New merchants only</option>
                        <option value="high_risk" <?= $reserveConfig['applies_to'] === 'high_risk' ? 'selected' : '' ?>>High-risk merchants only</option>
                    </select></div>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Configuration</button>
        </form>
        <?php else: ?>
        <p class="text-gray-500 text-sm">Select a merchant to configure rolling reserve.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
