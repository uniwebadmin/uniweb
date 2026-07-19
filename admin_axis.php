<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$db = getDB();

if (isset($_GET['action']) && verifyCsrf($_GET['token'] ?? '')) {
    $action = $_GET['action'];
    if ($action === 'test_token') {
        $test = axisTestConnection();
        flash($test['token_ok'] ? 'success' : 'error', $test['message']);
    } elseif ($action === 'create_test_va') {
        $demo = $db->query("SELECT * FROM merchants WHERE email='demo@uniweb.co.in' LIMIT 1")->fetch();
        if (!$demo) {
            $demo = $db->query('SELECT * FROM merchants ORDER BY id ASC LIMIT 1')->fetch();
        }
        if ($demo) {
            $va = ensureAxisVirtualAccount((int)$demo['id']);
            flash($va ? 'success' : 'error', $va
                ? 'VA provisioned: ' . ($va['va_number'] ?? '') . ' [' . ($va['_source'] ?? 'axis') . ']'
                : 'VA creation failed — check API logs. Subscribe Virtual Account API on Axis portal.');
        }
    } elseif ($action === 'clear_logs') {
        try { $db->exec('TRUNCATE TABLE axis_api_logs'); flash('success', 'Logs cleared.'); } catch (Throwable $e) { flash('error', 'Run update_v10.php first.'); }
    }
    redirect('admin_axis.php');
}

$test = axisTestConnection();
$logs = axisGetRecentLogs(40);
$c = axisCredentials();
$pageTitle = 'Axis Bank UAT';
require_once __DIR__ . '/header.php';
?>

<div class="mb-4">
    <a href="gateway_settings.php" class="text-sm text-gray-400 hover:text-white">← Gateway Settings</a>
</div>

<div class="bg-sky-500/10 border border-sky-500/30 rounded-xl p-5 mb-6">
    <h2 class="font-semibold text-sky-300 mb-2">Axis Developer Portal — UAT Checklist</h2>
    <ol class="text-sm text-gray-400 space-y-2 list-decimal list-inside">
        <li>Login: <a href="https://apiportal.axis.bank.in/portal/" class="text-sky-400" target="_blank" rel="noopener">apiportal.axis.bank.in</a></li>
        <li>App <strong class="text-white"><?= e($c['app_name']) ?></strong> → Subscribe <strong>Virtual Account</strong> + <strong>Collections</strong> APIs</li>
        <li>Add Webhook URL in Axis portal: <code class="text-xs bg-dark-900 px-2 py-1 rounded break-all"><?= e(axisWebhookUrl()) ?></code></li>
        <li>Run <strong>Test Token</strong> + <strong>Create Test VA</strong> below — calls appear on portal</li>
        <li>After Axis approval → receive live keys → set <code>axis_environment=production</code> in Gateway Settings</li>
    </ol>
</div>

<div class="grid lg:grid-cols-5 gap-4 mb-8">
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Environment</p>
        <p class="text-xl font-bold text-sky-400"><?= strtoupper(e(getSetting('axis_environment', 'uat'))) ?></p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">API Base</p>
        <p class="text-xs font-mono text-gray-300 mt-1 break-all"><?= e(axisApiBase()) ?></p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Server IP (whitelist)</p>
        <p class="text-sm font-mono text-amber-300 mt-1"><?= e($test['server_ip'] ?? axisServerPublicIp()) ?></p>
        <p class="text-xs text-gray-600 mt-1">Whitelist this IP on Axis portal</p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Token Status</p>
        <p class="text-xl font-bold <?= $test['token_ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $test['token_ok'] ? 'OK' : 'Pending' ?></p>
    </div>
    <div class="glass rounded-xl p-5">
        <p class="text-xs text-gray-500">Mock VA</p>
        <p class="text-xl font-bold <?= axisAllowMock() ? 'text-amber-400' : 'text-emerald-400' ?>"><?= axisAllowMock() ? 'ON' : 'OFF' ?></p>
        <p class="text-xs text-gray-600 mt-1">OFF = real Axis API only</p>
    </div>
</div>

<div class="flex flex-wrap gap-3 mb-8">
    <a href="?action=test_token&token=<?= csrfToken() ?>" class="btn-primary px-5 py-2.5">Test Token → Axis</a>
    <a href="?action=create_test_va&token=<?= csrfToken() ?>" class="bg-sky-600 hover:bg-sky-500 text-white px-5 py-2.5 rounded-xl font-semibold">Create Test Virtual Account</a>
    <a href="gateway_settings.php" class="glass px-5 py-2.5 rounded-xl text-sm">Edit Keys</a>
    <a href="?action=clear_logs&token=<?= csrfToken() ?>" class="text-sm text-red-400 px-3 py-2" onclick="return confirm('Clear logs?')">Clear Logs</a>
</div>

<?php if (!$test['token_ok']): ?>
<div class="bg-amber-500/10 border border-amber-500/30 rounded-xl p-4 mb-6 text-sm text-amber-200">
    <p class="font-semibold text-amber-100 mb-2">Token Failed — Diagnosis</p>
    <p><?= e($test['message']) ?></p>
    <?php if (!empty($test['dns'])): $dns = $test['dns']; ?>
    <div class="mt-4 overflow-x-auto">
        <table class="w-full text-xs font-mono">
            <thead><tr class="text-amber-400/70"><th class="text-left py-1">Host</th><th class="text-left py-1">DNS</th><th class="text-left py-1">IP</th></tr></thead>
            <tbody>
                <?php foreach ($dns['rows'] as $row): ?>
                <tr><td class="py-1 pr-4"><?= e($row['host']) ?></td>
                    <td class="py-1 <?= $row['resolved'] ? 'text-emerald-400' : 'text-red-400' ?>"><?= $row['resolved'] ? 'OK' : 'FAIL' ?></td>
                    <td class="py-1 text-gray-400"><?= e($row['ip'] ?? '—') ?></td></tr>
                <?php endforeach; ?>
                <tr class="border-t border-amber-500/20"><td class="py-1 pr-4 font-semibold"><?= e($dns['base_host']) ?> (configured)</td>
                    <td class="py-1 <?= $dns['base_resolved'] ? 'text-emerald-400' : 'text-red-400' ?>"><?= $dns['base_resolved'] ? 'OK' : 'FAIL' ?></td>
                    <td class="py-1 text-gray-400"><?= e($dns['base_ip'] ?? '—') ?></td></tr>
            </tbody>
        </table>
    </div>
    <?php if (empty($dns['base_resolved'])): ?>
    <p class="mt-3 text-xs text-red-300/90"><strong>Root cause:</strong> Hostinger server cannot resolve Axis UAT host via DNS. Open a Hostinger support ticket with Axis portal IP whitelist — outbound access to <code>sakshamuat.axisbank.co.in</code> is required. You can skip Axis for now and use Decentro VA.</p>
    <?php endif; ?>
    <?php endif; ?>
    <?php if (!empty($test['diagnosis']['last_http'])): ?>
    <p class="mt-2 text-xs font-mono text-amber-400/90">Last HTTP: <?= (int)$test['diagnosis']['last_http'] ?> · <?= e(mb_substr($test['diagnosis']['last_response'] ?? '', 0, 200)) ?></p>
    <?php endif; ?>
    <ul class="mt-3 text-xs text-amber-400/80 space-y-1 list-disc list-inside">
        <li>Portal → App → Subscribe <strong>API Authentication Token Generation</strong> + <a href="https://apiportal.axis.bank.in/portal/product/35601" class="text-sky-400" target="_blank" rel="noopener">Virtual Account</a> (Axis approval may be required)</li>
        <li>UAT Access → Whitelist IP: <code class="bg-dark-900 px-1 rounded"><?= e($test['server_ip'] ?? '') ?></code></li>
        <li>Paste exact <code>axis_token_url</code> from subscribed API docs into Gateway Settings</li>
        <li>UAT requires <a href="https://apiportal.axis.bank.in/portal/index.php/security-features" class="text-sky-400" target="_blank" rel="noopener">JWE encryption + 2-way SSL</a></li>
    </ul>
</div>
<?php endif; ?>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800 flex justify-between">
        <h2 class="font-semibold">Axis API Logs (portal proof)</h2>
        <span class="text-xs text-gray-500"><?= count($logs) ?> entries</span>
    </div>
    <div class="overflow-x-auto max-h-[500px]">
        <table class="w-full text-xs">
            <thead class="text-gray-500 uppercase bg-dark-900/50 sticky top-0">
                <tr>
                    <th class="px-4 py-2 text-left">Time</th>
                    <th class="px-4 py-2 text-left">Endpoint</th>
                    <th class="px-4 py-2 text-left">HTTP</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-left">Response</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800 font-mono">
                <?php if (empty($logs)): ?>
                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">No API calls yet. Run update_v10.php then Test Token.</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-2 text-gray-500 whitespace-nowrap"><?= formatDate($log['created_at']) ?></td>
                    <td class="px-4 py-2 text-sky-400 max-w-[200px] truncate"><?= e($log['endpoint']) ?></td>
                    <td class="px-4 py-2"><?= (int)$log['http_code'] ?></td>
                    <td class="px-4 py-2"><?= e($log['status']) ?></td>
                    <td class="px-4 py-2 text-gray-500 max-w-md break-all text-[11px] leading-relaxed"><?= e($log['response_body'] ?? '') ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6 glass rounded-xl p-5 text-sm text-gray-400">
    <p><strong class="text-gray-300">Webhook Secret:</strong> <code class="text-xs"><?= e(getSetting('axis_webhook_secret', '')) ?></code></p>
    <p class="mt-2"><strong class="text-gray-300">Client ID:</strong> <?= e(substr($c['client_id'], 0, 8)) ?>… · Configure full keys in Gateway Settings</p>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
