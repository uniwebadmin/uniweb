<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['form'] ?? '') === 'cron_key') {
        $newKey = trim((string)($_POST['settlement_cron_key'] ?? ''));
        if ($newKey === '' || str_contains($newKey, '****')) {
            flash('info', 'Cron key not changed (masked value submitted). Enter a new key to update.');
        } elseif (strlen($newKey) < 8) {
            flash('error', 'Cron key must be at least 8 characters.');
        } else {
            $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
                ->execute(['settlement_cron_key', $newKey, $newKey]);
            clearSettingCache('settlement_cron_key');
            flash('success', 'Settlement cron key updated.');
        }
        redirect('admin_settlement_settings.php');
    }
    savePlatformSettlementDefaults([
        'mode' => $_POST['default_settlement_mode'] ?? 'manual',
        'rail' => $_POST['default_settlement_rail'] ?? 'platform_pg',
        'cycle' => $_POST['settlement_cycle'] ?? 'T+1',
        'interval_minutes' => (int)($_POST['default_batch_interval_minutes'] ?? 0),
        'batch_enabled' => !empty($_POST['settlement_batch_enabled']),
    ]);
    flash('success', 'Settlement defaults saved (cycle ' . normalizeSettlementCycle((string)($_POST['settlement_cycle'] ?? 'T+1')) . ').');
    redirect('admin_settlement_settings.php');
}

if (isset($_GET['action']) && $_GET['action'] === 'run_batches' && verifyCsrf($_GET['token'] ?? '')) {
    $results = runScheduledSettlementBatches();
    logSettlementCronRun($results);
    $ok = count(array_filter($results, fn($r) => !empty($r['ok'])));
    flash('success', 'Batch runner finished. ' . $ok . ' merchant batch(es) processed.');
    redirect('admin_settlement_settings.php');
}

$defaults = getPlatformSettlementDefaults();
$cronStatus = getSettlementCronStatus();
$intervals = getSettlementBatchIntervals();
$cycles = getSettlementCycleOptions();
$rails = getSettlementRails();
$modes = getSettlementModes();
$recentBatches = $db->query('SELECT sb.*, m.business_name FROM settlement_batches sb JOIN merchants m ON sb.merchant_id=m.id ORDER BY sb.created_at DESC LIMIT 25')->fetchAll();

$pageTitle = 'Settlement Engine';
require_once __DIR__ . '/header.php';
?>

<div class="glass rounded-xl p-5 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Settlement cycle: T+0 / T+1 / T+2</p>
    <p class="text-xs text-gray-500">Admin decides the platform default (Owner recommendation: <strong class="text-gray-300">T+1</strong>). Merchants inherit it unless they override. Same settlement pages — no new product. Current default: <strong class="text-sky-300"><?= e($defaults['cycle_label']) ?></strong>.</p>
</div>

<div class="mb-6 flex flex-wrap gap-3">
    <a href="admin_settlements.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300 hover:text-white">All Settlements →</a>
    <a href="admin_settlement_batches.php" class="glass px-4 py-2 rounded-xl text-sm text-violet-300 hover:text-violet-200">Batch Ledger →</a>
    <a href="gateway_settings.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-400">Platform Settings</a>
</div>

<div class="grid lg:grid-cols-3 gap-4 mb-8">
    <div class="glass rounded-xl p-5 border border-violet-500/20">
        <p class="text-xs text-gray-500 uppercase">Option B</p>
        <p class="font-semibold text-violet-300 mt-1">Platform PG Pool</p>
        <p class="text-xs text-gray-500 mt-2">PayU / Razorpay / Cashfree — batched payout to merchant bank</p>
        <p class="text-[10px] text-amber-400 mt-2">Live bank payout API not wired — wallet debit only</p>
    </div>
    <div class="glass rounded-xl p-5 border border-cyan-500/20">
        <p class="text-xs text-gray-500 uppercase">Option C</p>
        <p class="font-semibold text-cyan-300 mt-1">Axis Virtual Account</p>
        <p class="text-xs text-gray-500 mt-2">VA collections → scheduled sweep</p>
        <p class="text-[10px] text-amber-400 mt-2">VA provision OK — sweep API pending</p>
    </div>
    <div class="glass rounded-xl p-5 border border-emerald-500/20">
        <p class="text-xs text-gray-500 uppercase">Manual</p>
        <p class="font-semibold text-emerald-300 mt-1">Settle Now Button</p>
        <p class="text-xs text-gray-500 mt-2">Merchant clicks → instant wallet → bank transfer</p>
        <p class="text-[10px] text-emerald-400 mt-2">Active in test mode</p>
    </div>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold text-lg mb-1">Platform Defaults</h2>
        <p class="text-xs text-gray-500 mb-6">New merchants inherit these unless they set their own. Cycle binds to existing batch timing.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400">Settlement cycle (Admin decide)</label>
                <select name="settlement_cycle" class="input-field mt-1">
                    <?php foreach ($cycles as $code => $meta): ?>
                    <option value="<?= e($code) ?>" <?= $defaults['cycle'] === $code ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[11px] text-gray-600 mt-1">Saves to <code class="text-gray-500">settlement_cycle</code> + matching batch minutes. Default for Owner: T+1.</p>
            </div>
            <div>
                <label class="text-sm text-gray-400">Default Settlement Mode</label>
                <select name="default_settlement_mode" class="input-field mt-1">
                    <?php foreach ($modes as $k => $label): ?>
                    <option value="<?= $k ?>" <?= $defaults['mode'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400">Default Rail (B / C)</label>
                <select name="default_settlement_rail" class="input-field mt-1">
                    <?php foreach ($rails as $k => $r): ?>
                    <option value="<?= $k ?>" <?= $defaults['rail'] === $k ? 'selected' : '' ?>><?= e($r['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="default_batch_interval_minutes" value="<?= (int)$defaults['interval_minutes'] ?>">
            <label class="flex items-center gap-2 text-sm text-gray-400">
                <input type="checkbox" name="settlement_batch_enabled" value="1" <?= $defaults['batch_enabled'] ? 'checked' : '' ?> class="rounded border-gray-600">
                Enable scheduled batching platform-wide
            </label>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Defaults</button>
        </form>
    </div>

    <div class="glass rounded-xl p-6 border border-gray-800">
        <h2 class="font-semibold text-lg mb-1">Batch Cron</h2>
        <p class="text-xs text-gray-500 mb-4">Run scheduled batches (1h / 1.5h / 2h intervals). Set server cron to hit the URL below every 15 minutes.</p>
        <div class="grid sm:grid-cols-2 gap-3 mb-4 text-xs">
            <div class="rounded-lg border border-gray-800 p-3 bg-dark-900/40">
                <p class="text-gray-500">Last run</p>
                <p class="font-medium mt-1"><?= $cronStatus['last_run'] ? e($cronStatus['last_run']) : 'Never' ?></p>
            </div>
            <div class="rounded-lg border border-gray-800 p-3 bg-dark-900/40">
                <p class="text-gray-500">Last result</p>
                <p class="font-medium mt-1"><?= $cronStatus['last_total'] ?> batch(es) · <?= $cronStatus['last_ok'] ?> OK</p>
            </div>
            <div class="rounded-lg border border-gray-800 p-3 bg-dark-900/40">
                <p class="text-gray-500">Due now</p>
                <p class="font-medium mt-1 <?= $cronStatus['due_now'] ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $cronStatus['due_now'] ?> merchant(s)</p>
            </div>
            <div class="rounded-lg border border-gray-800 p-3 bg-dark-900/40">
                <p class="text-gray-500">Scheduler</p>
                <p class="font-medium mt-1"><?= $cronStatus['enabled'] ? 'Enabled' : 'Disabled' ?></p>
            </div>
        </div>
        <code class="block bg-dark-900 rounded-lg p-3 text-xs text-sky-400 font-mono break-all mb-4" id="cron-url"><?= e(function_exists('maskCronUrl') ? maskCronUrl($cronStatus['cron_url']) : $cronStatus['cron_url']) ?></code>
        <div class="flex flex-wrap gap-2 mb-4">
            <a href="?action=run_batches&token=<?= csrfToken() ?>" class="inline-block btn-primary text-sm px-5 py-2.5" onclick="return confirm('Run scheduled batches now?')">▶ Run Batches Now</a>
        </div>
        <p class="text-[11px] text-gray-600 mb-4">Full cron URL is masked for security. To get the real URL, check the <code class="text-gray-500">settlement_cron_key</code> value in Gateway Settings → Platform Settings, or run <code class="text-gray-500">php cron_settlements.php</code> from CLI.</p>
        <form method="POST" class="space-y-3 border-t border-gray-800 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="form" value="cron_key">
            <label class="text-sm text-gray-400">Cron secret key</label>
            <div class="flex flex-wrap gap-2">
                <input type="text" name="settlement_cron_key" value="<?= e(function_exists('maskSecretKey') ? maskSecretKey($cronStatus['key']) : $cronStatus['key']) ?>" class="input-field flex-1 min-w-[200px] font-mono text-xs" autocomplete="off">
                <button type="submit" class="px-4 py-2 rounded-lg text-sm bg-violet-600/20 text-violet-300 hover:bg-violet-600/30">Update Key</button>
            </div>
            <p class="text-[11px] text-gray-600">Hostinger cron example: every 15 min → <code class="text-gray-500">curl -s "<?= e(function_exists('maskCronUrl') ? maskCronUrl($cronStatus['cron_url']) : $cronStatus['cron_url']) ?>"</code></p>
        </form>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden border border-gray-800">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent Batches</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Batch</th><th class="px-4 py-3 text-left">Merchant</th>
                <th class="px-4 py-3 text-left">Rail</th><th class="px-4 py-3 text-right">Txns</th>
                <th class="px-4 py-3 text-right">Net</th><th class="px-4 py-3 text-left">Status</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($recentBatches)): ?>
                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500 text-xs">No batches yet.</td></tr>
                <?php else: foreach ($recentBatches as $b): ?>
                <tr class="hover:bg-white/[0.02]">
                    <td class="px-4 py-3 font-mono text-xs"><a href="admin_settlement_batches.php?batch=<?= (int)$b['id'] ?>" class="text-sky-400"><?= e($b['batch_code']) ?></a></td>
                    <td class="px-4 py-3"><?= e($b['business_name']) ?></td>
                    <td class="px-4 py-3"><?= settlementRailBadge($b['settlement_rail']) ?></td>
                    <td class="px-4 py-3 text-right"><?= (int)$b['txn_count'] ?></td>
                    <td class="px-4 py-3 text-right font-semibold text-emerald-400"><?= walletMoney((float)$b['net_amount']) ?></td>
                    <td class="px-4 py-3"><?= settlementBatchStatusBadge($b['status']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
