<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

if (isset($_GET['resolve']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $rid = (int)$_GET['resolve'];
    if ($rid > 0) {
        resolvePlatformError($rid);
        flash('success', 'Error marked resolved.');
    }
    redirect('admin_error_log.php');
}

if (isset($_GET['resolve_all']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $n = resolveAllPlatformErrors();
    flash('success', $n . ' error(s) marked resolved.');
    redirect('admin_error_log.php');
}

if (isset($_GET['run_watchdog']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $watch = runPlatformWatchdog();
    flash($watch['ok'] ? 'success' : 'error', $watch['ok']
        ? 'Watchdog: all checks passed.'
        : 'Watchdog found ' . $watch['failed'] . ' problem(s) — logged below.');
    redirect('admin_error_log.php');
}

$showResolved = isset($_GET['show']) && $_GET['show'] === 'all';
$errors = getRecentPlatformErrors(80, !$showResolved);
$unresolved = countUnresolvedPlatformErrors();
$watchdogKey = getSetting('platform_watchdog_key', 'uniweb_watch_' . substr(hash('sha256', DB_NAME . APP_URL), 0, 16));
$watchdogUrl = APP_URL . '/platform_watchdog.php?key=' . rawurlencode($watchdogKey);

$pageTitle = 'Error Log';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">
    <div>
        <p class="text-sm text-gray-400">Every PHP error, exception & fatal is caught automatically</p>
        <?php if ($unresolved > 0): ?>
        <p class="text-xs text-amber-400 mt-2">● <?= (int)$unresolved ?> entries in log — usually old repeat alerts, not <?= (int)$unresolved ?> broken pages. Click <strong>Resolve all</strong> to clear after review.</p>
        <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="?run_watchdog=1&csrf=<?= e(csrfToken()) ?>" class="btn-primary text-sm px-4 py-2">Run Watchdog Now</a>
        <?php if ($unresolved > 0): ?>
        <a href="?resolve_all=1&csrf=<?= e(csrfToken()) ?>" class="glass px-4 py-2 rounded-xl text-sm text-amber-400" onclick="return confirm('Mark all <?= (int)$unresolved ?> errors resolved?')">Resolve all</a>
        <?php endif; ?>
        <a href="<?= $showResolved ? 'admin_error_log.php' : '?show=all' ?>" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">
            <?= $showResolved ? 'Unresolved only' : 'Show all' ?>
        </a>
        <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400">Link Watchdog</a>
    </div>
</div>

<div class="grid sm:grid-cols-3 gap-4 mb-8">
    <div class="glass rounded-xl p-5 border <?= $unresolved > 0 ? 'border-amber-500/40' : 'border-emerald-500/30' ?>">
        <p class="text-xs text-gray-500">Unresolved errors</p>
        <p class="text-3xl font-bold <?= $unresolved > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $unresolved ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Error catcher</p>
        <p class="text-lg font-semibold text-emerald-400 mt-1">● Active</p>
        <p class="text-xs text-gray-600 mt-1">includes/error_catcher.php</p>
        <p class="text-[10px] text-gray-600 mt-2">Auto-audit cron (every 10 min): <code class="text-sky-400"><?= e(APP_URL) ?>/cron_auto_audit.php?key=…</code></p>
        <p class="text-[10px] text-gray-600">Morning ops: <code class="text-sky-400"><?= e(APP_URL) ?>/morning_ops.php?key=…</code></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Cron watchdog URL</p>
        <p class="text-xs font-mono text-sky-400 mt-1 break-all"><?= e($watchdogUrl) ?></p>
        <p class="text-[10px] text-gray-600 mt-1">Set platform_watchdog_key in Gateway Settings</p>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold"><?= $showResolved ? 'All logged errors' : 'Unresolved errors' ?></h2>
    </div>
    <?php if (empty($errors)): ?>
    <div class="px-6 py-16 text-center text-gray-500">
        <p class="text-emerald-400 text-lg mb-2">✓ No errors logged</p>
        <p class="text-sm">System is clean. Run Watchdog to double-check key pages.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">When</th>
                    <th class="px-5 py-3 text-left">Level</th>
                    <th class="px-5 py-3 text-left">Message</th>
                    <th class="px-5 py-3 text-left">Where</th>
                    <th class="px-5 py-3 text-left">User</th>
                    <th class="px-5 py-3 text-left">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($errors as $err):
                    $lvl = $err['level'] ?? 'error';
                    $lvlClass = match ($lvl) {
                        'fatal', 'exception', 'error' => 'text-red-400',
                        'watchdog' => 'text-amber-400',
                        default => 'text-gray-400',
                    };
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($err['created_at']) ?></td>
                    <td class="px-5 py-3 text-xs font-semibold uppercase <?= $lvlClass ?>"><?= e($lvl) ?></td>
                    <td class="px-5 py-3 text-xs max-w-md">
                        <p class="text-gray-200"><?= e(mb_substr($err['message'] ?? '', 0, 200)) ?></p>
                        <?php if (!empty($err['url'])): ?>
                        <p class="text-gray-600 font-mono mt-1"><?= e($err['url']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500 font-mono">
                        <?= e(basename($err['file'] ?? '')) ?><?= !empty($err['line']) ? ':' . (int)$err['line'] : '' ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500">
                        <?= e($err['actor_type'] ?? 'guest') ?><?= !empty($err['actor_id']) ? ' #' . (int)$err['actor_id'] : '' ?>
                    </td>
                    <td class="px-5 py-3 whitespace-nowrap">
                        <?php if (empty($err['is_resolved'])): ?>
                        <a href="?resolve=<?= (int)$err['id'] ?>&csrf=<?= e(csrfToken()) ?>" class="text-xs text-emerald-400">Resolve</a>
                        <?php else: ?>
                        <span class="text-xs text-gray-600">Resolved</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
