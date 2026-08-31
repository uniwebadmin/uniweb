<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$pageTitle = 'Soft Launch';
require_once __DIR__ . '/header.php';
$docPath = __DIR__ . '/docs/SOFT_LAUNCH_BLOCKERS.md';
$runbookPath = __DIR__ . '/docs/OWNER_RUNBOOK.md';
$blockers = is_file($docPath) ? (string)file_get_contents($docPath) : '';
$runbook = is_file($runbookPath) ? (string)file_get_contents($runbookPath) : '';
?>

<div class="max-w-3xl space-y-6">
    <div class="glass rounded-xl p-6 border border-emerald-500/30">
        <h1 class="text-xl font-semibold text-white mb-2">Soft Launch Readiness</h1>
        <p class="text-sm text-gray-400">Follow the ordered checklist before first real customer payment. PARKED items are honest — not hidden failures.</p>
        <p class="text-xs text-gray-500 mt-3">Repo docs: <code class="text-gray-400">docs/SOFT_LAUNCH_BLOCKERS.md</code> · <code class="text-gray-400">docs/OWNER_RUNBOOK.md</code></p>
    </div>

    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Blocker checklist</h2>
        <ol class="space-y-4 text-sm">
            <li class="border-l-2 border-amber-500/50 pl-4">
                <p class="font-medium text-white">a) Apply pending migrations</p>
                <p class="text-gray-400 text-xs mt-1">Platform Settings → Apply pending migrations → <strong class="text-emerald-400">ok: true</strong></p>
                <a href="gateway_settings.php" class="text-xs text-sky-400 mt-1 inline-block">Open Platform Settings →</a>
            </li>
            <li class="border-l-2 border-violet-500/50 pl-4">
                <p class="font-medium text-white">b) Partner Registry keys</p>
                <p class="text-gray-400 text-xs mt-1">Razorpay / Cashfree / PayU → Keys → Test Connection green</p>
                <a href="admin_gateway_registry.php" class="text-xs text-sky-400 mt-1 inline-block">Partner Registry →</a>
            </li>
            <li class="border-l-2 border-sky-500/50 pl-4">
                <p class="font-medium text-white">c) Webhook URLs + secrets</p>
                <p class="text-gray-400 text-xs mt-1"><code class="text-gray-500">/razorpay_webhook.php</code> etc. on partner dashboard; signing secret in Registry</p>
                <a href="admin_webhook_reliability.php" class="text-xs text-sky-400 mt-1 inline-block">Webhook Reliability →</a>
            </li>
            <li class="border-l-2 border-fuchsia-500/50 pl-4">
                <p class="font-medium text-white">d) Live Money Switches default OFF</p>
                <p class="text-gray-400 text-xs mt-1">Payout / Recurring / Route routing OFF until Owner chooses</p>
                <a href="gateway_settings.php#live-money-switches" class="text-xs text-sky-400 mt-1 inline-block">Live Money Switches →</a>
            </li>
            <li class="border-l-2 border-gray-500/50 pl-4">
                <p class="font-medium text-white">e) SMTP / pay emails</p>
                <p class="text-gray-400 text-xs mt-1">SMTP configured or Owner accepts no receipt email yet</p>
            </li>
            <li class="border-l-2 border-amber-500/50 pl-4">
                <p class="font-medium text-white">f) Cron on Hostinger (Owner installs)</p>
                <p class="text-gray-400 text-xs mt-1">Copy command from Platform Settings — not pre-installed. See <code class="text-gray-500">docs/CRON_INVENTORY.md</code></p>
                <a href="admin_platform_status.php" class="text-xs text-sky-400 mt-1 inline-block">Platform Status →</a>
            </li>
            <li class="border-l-2 border-emerald-500/50 pl-4">
                <p class="font-medium text-white">g) Smoke green (laptop)</p>
                <p class="text-gray-400 text-xs mt-1"><code class="text-gray-500">php tests/run_smoke_checks.php</code> → passed, failed=0</p>
            </li>
        </ol>
    </div>

    <div class="glass rounded-xl p-6 border border-red-500/25">
        <h2 class="font-semibold text-red-300 mb-2">Emergency stop</h2>
        <ul class="text-sm text-gray-400 space-y-2 list-disc pl-5">
            <li>Platform Settings → Live Money Switches → all <strong class="text-white">OFF</strong></li>
            <li>Rotate keys in Partner Registry if compromised</li>
            <li>Error Log + Watchdog before re-opening traffic</li>
        </ul>
        <div class="flex flex-wrap gap-2 mt-4">
            <a href="admin_error_log.php" class="text-xs px-3 py-2 rounded-lg border border-red-500/40 text-red-300">Error Log</a>
            <a href="admin_watchdog.php" class="text-xs px-3 py-2 rounded-lg border border-amber-500/40 text-amber-300">Watchdog</a>
        </div>
    </div>

    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-2">First live payment (short)</h2>
        <p class="text-sm text-gray-400">Full steps in runbook. Pending ≠ paid. Confirm Success + ledger on Transaction detail.</p>
        <a href="admin_transactions.php" class="text-xs text-sky-400 mt-2 inline-block">Transactions →</a>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
