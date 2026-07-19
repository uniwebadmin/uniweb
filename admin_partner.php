<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
ensurePartnerEngine();

$p = trim($_GET['p'] ?? '');
$registry = getPartnerRegistry();
if (!isset($registry[$p])) {
    flash('error', 'Partner not found.');
    redirect('admin_partners.php');
}
$partner = $registry[$p];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_keys') {
        partnerSaveConfig($p, $_POST['keys'] ?? []);
        flash('success', $partner['name'] . ' settings saved.');
        redirect('admin_partner.php?p=' . rawurlencode($p));
    }
}

if (isset($_GET['action']) && verifyCsrf($_GET['token'] ?? '')) {
    if ($_GET['action'] === 'test') {
        $test = partnerTestConnection($p);
        flash($test['ok'] ? 'success' : 'error', $test['message']);
    }
    if ($_GET['action'] === 'clear_logs') {
        getDB()->prepare('DELETE FROM partner_api_logs WHERE partner_key = ?')->execute([$p]);
        flash('success', 'Logs cleared.');
    }
    redirect('admin_partner.php?p=' . rawurlencode($p));
}

$test = partnerTestConnection($p);
$logs = partnerGetRecentLogs($p, 40);
$pageTitle = $partner['name'] . ' — Partner Setup';
require_once __DIR__ . '/header.php';
?>

<div class="mb-4 flex flex-wrap gap-3 items-center">
    <a href="admin_partners.php" class="text-sm text-gray-400 hover:text-white">← All Partners</a>
    <a href="gateway_settings.php" class="text-sm text-sky-400">Gateway Settings →</a>
    <a href="<?= e(partnerMailto($p)) ?>" class="text-sm text-emerald-400">📧 Production Email</a>
</div>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-5 mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-3xl mb-1"><?= e($partner['icon']) ?></p>
            <h2 class="font-semibold text-sky-300 text-lg"><?= e($partner['name']) ?></h2>
            <p class="text-sm text-gray-400 mt-1"><?= e($partner['use']) ?></p>
        </div>
        <span class="text-xs px-3 py-1.5 rounded-full <?= $test['ok'] ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400' ?>">
            <?= $test['ok'] ? 'Configured' : 'Awaiting API Keys' ?>
        </span>
    </div>
    <?php if (!empty($partner['checklist'])): ?>
    <ol class="text-sm text-gray-400 space-y-1.5 list-decimal list-inside mt-4">
        <?php foreach ($partner['checklist'] as $step): ?>
        <li><?= e($step) ?></li>
        <?php endforeach; ?>
    </ol>
    <?php endif; ?>
</div>

<div class="grid lg:grid-cols-3 gap-4 mb-8">
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Environment</p>
        <p class="text-lg font-bold mt-1"><?= e(strtoupper(getSetting($partner['env_key'] ?? '', 'sandbox'))) ?></p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Webhook URL</p>
        <p class="text-xs font-mono text-sky-400 mt-1 break-all"><?= e($partner['webhook'] ?? '—') ?></p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Connection Test</p>
        <p class="text-sm mt-1 <?= $test['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= e(mb_substr($test['message'], 0, 60)) ?></p>
    </div>
</div>

<div class="flex flex-wrap gap-3 mb-8">
    <a href="?p=<?= e($p) ?>&action=test&token=<?= csrfToken() ?>" class="btn-primary px-5 py-2.5">Test Connection</a>
    <?php if ($partner['docs']): ?>
    <a href="<?= e($partner['docs']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">API Docs ↗</a>
    <?php endif; ?>
    <?php if ($partner['dashboard']): ?>
    <a href="<?= e($partner['dashboard']) ?>" target="_blank" rel="noopener" class="glass px-5 py-2.5 rounded-xl text-sm">Dashboard ↗</a>
    <?php endif; ?>
    <a href="?p=<?= e($p) ?>&action=clear_logs&token=<?= csrfToken() ?>" class="text-sm text-red-400 px-3 py-2" onclick="return confirm('Clear logs?')">Clear Logs</a>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-8">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">API Keys (paste when partner gives)</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save_keys">
            <?php foreach ($partner['config_keys'] as $key => $meta): ?>
            <div>
                <label class="text-sm text-gray-400"><?= e($meta['label']) ?></label>
                <?php if (($meta['type'] ?? 'text') === 'select'): ?>
                <select name="keys[<?= e($key) ?>]" class="input-field mt-1">
                    <?php foreach ($meta['options'] as $val => $label): ?>
                    <option value="<?= e($val) ?>" <?= getSetting($key, '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="<?= e($meta['type'] ?? 'text') ?>" name="keys[<?= e($key) ?>]" value="<?= e(getSetting($key, '')) ?>" class="input-field mt-1 font-mono text-xs" autocomplete="off">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <button type="submit" class="btn-primary px-6 py-2.5">Save Keys</button>
        </form>
    </div>
    <div class="glass rounded-xl p-6 text-sm text-gray-400">
        <h2 class="font-semibold text-white mb-3">Quick Links</h2>
        <ul class="space-y-2 text-xs">
            <?php if ($partner['signup']): ?><li>Signup: <a href="<?= e($partner['signup']) ?>" class="text-sky-400" target="_blank" rel="noopener"><?= e($partner['signup']) ?></a></li><?php endif; ?>
            <?php if ($partner['email']): ?><li>Email: <a href="mailto:<?= e($partner['email']) ?>" class="text-sky-400"><?= e($partner['email']) ?></a></li><?php endif; ?>
            <li>Type: <strong class="text-gray-300"><?= e($partner['type']) ?></strong></li>
            <li>Status: After adding keys, click Test Connection</li>
        </ul>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex justify-between">
        <h2 class="font-semibold">API Logs</h2>
        <span class="text-xs text-gray-500"><?= count($logs) ?> entries</span>
    </div>
    <div class="overflow-x-auto max-h-[400px]">
        <table class="w-full text-xs">
            <thead class="text-gray-500 uppercase bg-dark-900/50 sticky top-0">
                <tr>
                    <th class="px-4 py-2 text-left">Time</th>
                    <th class="px-4 py-2 text-left">Endpoint</th>
                    <th class="px-4 py-2 text-left">HTTP</th>
                    <th class="px-4 py-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800 font-mono">
                <?php if (empty($logs)): ?>
                <tr><td colspan="4" class="px-4 py-10 text-center text-gray-500">No API calls yet. Save keys and run Test Connection.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                    <td class="px-4 py-2 text-sky-400"><?= e($log['endpoint']) ?></td>
                    <td class="px-4 py-2"><?= (int)$log['http_code'] ?></td>
                    <td class="px-4 py-2"><?= e($log['status']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
