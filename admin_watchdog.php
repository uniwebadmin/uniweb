<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireSuperAdmin();

$tab = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'scan'));
if (!in_array($tab, ['scan', 'auto', 'rules'], true)) {
    $tab = 'scan';
}

if (isset($_GET['scan']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $http = ($_GET['http'] ?? '1') !== '0';
    $scan = runFullLinkWatchdog($http);
    $_SESSION['watchdog_scan'] = $scan;
    $_SESSION['watchdog_quick_scan'] = $scan;
    $broken = (int)($scan['summary']['broken_links'] ?? 0);
    $missing = (int)($scan['summary']['missing_files'] ?? 0);
    $httpFail = (int)($scan['summary']['http_fail'] ?? 0);
    if (!empty($scan['ok'])) {
        flash('success', 'Watchdog scan clean — no broken links, missing files, or HTTP failures.');
    } else {
        flash('error', "Watchdog found issues: {$broken} broken link(s), {$missing} missing file(s), {$httpFail} HTTP fail(s).");
    }
    redirect('admin_watchdog.php?tab=scan');
}

if (isset($_GET['run_auto']) && verifyCsrf($_GET['csrf'] ?? '')) {
    $report = runBackgroundAutoAudit(false, 'manual');
    if (!empty($report['skipped'])) {
        flash('warning', 'Auto-audit already running — try again in a moment.');
    } elseif (!empty($report['ok'])) {
        flash('success', 'Manual auto-audit finished — all clear.');
    } else {
        flash('error', 'Auto-audit finished with ' . (int)($report['failed'] ?? 0) . ' issue(s).');
    }
    redirect('admin_watchdog.php?tab=auto');
}

$scan = $_SESSION['watchdog_scan'] ?? $_SESSION['watchdog_quick_scan'] ?? null;
$lastAuto = getLastAutoAuditRun();
$autoHistory = getAutoAuditHistory(25);
$registry = getWatchdogPageRegistry();
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tab === 'rules') {
    $csvRows = [];
    foreach ($registry as $row) {
        $csvRows[] = [
            $row['portal'] ?? '',
            $row['file'] ?? '',
            $row['label'] ?? '',
            $row['auth'] ?? '',
            is_file(__DIR__ . '/' . ($row['file'] ?? '')) ? 'yes' : 'no',
        ];
    }
    sendCsvDownload(['Portal', 'File', 'Label', 'Auth', 'On disk'], $csvRows, 'watchdog-registry-' . date('Y-m-d') . '.csv');
}
$intervalMin = (int)(autoAuditIntervalSeconds() / 60);
$unresolvedErrors = countUnresolvedPlatformErrors();

$summary = is_array($scan) ? ($scan['summary'] ?? []) : [];
$brokenLinks = is_array($scan) ? ($scan['broken_links'] ?? []) : [];
$pages = is_array($scan) ? ($scan['pages'] ?? []) : [];
$issuePages = array_values(array_filter($pages, static fn(array $p): bool => empty($p['ok'])));

$pageTitle = 'Link Watchdog';
require_once __DIR__ . '/header.php';

$tabClass = static function (string $id, string $active): string {
    return $id === $active
        ? 'btn-primary text-xs px-4 py-2'
        : 'glass text-xs px-4 py-2 rounded-xl text-gray-300 hover:text-white';
};
?>

<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">
    <div>
        <p class="text-sm text-gray-400">Scan every portal page and internal link · Auto-audit every <?= $intervalMin ?> min</p>
        <?php if (is_array($scan) && !empty($scan['scanned_at'])): ?>
        <p class="text-xs text-gray-600 mt-1">Last scan: <?= e(formatDate((string)$scan['scanned_at'])) ?></p>
        <?php endif; ?>
    </div>
    <div class="flex flex-wrap gap-2">
        <a href="?scan=1&csrf=<?= e(csrfToken()) ?>" class="btn-primary text-sm px-4 py-2">Run Full Scan</a>
        <a href="?scan=1&http=0&csrf=<?= e(csrfToken()) ?>" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Static Scan Only</a>
        <a href="?run_auto=1&csrf=<?= e(csrfToken()) ?>" class="glass px-4 py-2 rounded-xl text-sm text-violet-400">Run Auto-Audit Now</a>
        <a href="admin_error_log.php" class="glass px-4 py-2 rounded-xl text-sm <?= $unresolvedErrors > 0 ? 'text-red-400' : 'text-gray-300' ?>">Error Log<?= $unresolvedErrors > 0 ? ' (' . $unresolvedErrors . ')' : '' ?></a>
        <a href="admin_platform_status.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Platform Status</a>
    </div>
</div>

<div class="flex flex-wrap gap-2 mb-8">
    <a href="?tab=scan" class="<?= $tabClass('scan', $tab) ?>">Scan Results</a>
    <a href="?tab=auto" class="<?= $tabClass('auto', $tab) ?>">Auto Audit</a>
    <a href="?tab=rules" class="<?= $tabClass('rules', $tab) ?>">Page Registry</a>
</div>

<?php if ($tab === 'scan'): ?>

<div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
    <?php
    $cards = [
        ['Broken links', (int)($summary['broken_links'] ?? 0), 'amber'],
        ['Missing files', (int)($summary['missing_files'] ?? 0), 'red'],
        ['HTTP fails', (int)($summary['http_fail'] ?? 0), 'red'],
        ['Syntax fails', (int)($summary['syntax_fail'] ?? 0), 'red'],
        ['Files scanned', (int)($summary['total_files'] ?? 0), 'sky'],
    ];
    foreach ($cards as [$label, $val, $color]):
        $ok = $label === 'Files scanned' || $val === 0;
        $border = $ok ? 'border-emerald-500/30' : "border-{$color}-500/40";
        $text = $ok ? 'text-emerald-400' : "text-{$color}-400";
    ?>
    <div class="glass rounded-xl p-5 border <?= $border ?>">
        <p class="text-xs text-gray-500"><?= e($label) ?></p>
        <p class="text-3xl font-bold mt-1 <?= $text ?>"><?= $val ?></p>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($scan === null): ?>
<div class="glass rounded-xl p-10 text-center border border-gray-800 mb-8">
    <p class="text-lg text-gray-300 mb-2">No scan in this session yet</p>
    <p class="text-sm text-gray-500 mb-5">Run a full scan to check admin, merchant, staff, and public pages.</p>
    <a href="?scan=1&csrf=<?= e(csrfToken()) ?>" class="btn-primary text-sm px-5 py-2.5">Run Full Watchdog Scan</a>
</div>
<?php else: ?>

<?php if (!empty($brokenLinks)): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-amber-500/40">
    <div class="px-6 py-4 border-b border-gray-800 flex items-center justify-between gap-3">
        <h2 class="font-semibold text-amber-300">Broken internal links (<?= count($brokenLinks) ?>)</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Source</th>
                    <th class="px-5 py-3 text-left">Href</th>
                    <th class="px-5 py-3 text-left">Target</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach (array_slice($brokenLinks, 0, 100) as $link): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 font-mono text-xs text-gray-400"><?= e((string)($link['source'] ?? '')) ?></td>
                    <td class="px-5 py-3 font-mono text-xs text-amber-300"><?= e((string)($link['href'] ?? '')) ?></td>
                    <td class="px-5 py-3 font-mono text-xs text-red-400"><?= e((string)($link['target'] ?? '')) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="glass rounded-xl overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold">Pages with issues<?= $issuePages ? ' (' . count($issuePages) . ')' : '' ?></h2>
        <?php if (empty($issuePages)): ?>
        <span class="text-xs text-emerald-400">All scanned pages OK</span>
        <?php endif; ?>
    </div>
    <?php if (empty($issuePages)): ?>
    <div class="px-6 py-12 text-center text-gray-500">
        <p class="text-emerald-400 text-lg mb-2">✓ No page issues</p>
        <p class="text-sm">Internal links and registered files look healthy.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Portal</th>
                    <th class="px-5 py-3 text-left">File</th>
                    <th class="px-5 py-3 text-left">Issues</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($issuePages as $page): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-400"><?= e(watchdogPortalLabel((string)($page['portal'] ?? 'other'))) ?></td>
                    <td class="px-5 py-3">
                        <p class="font-mono text-xs text-sky-400"><?= e((string)($page['file'] ?? '')) ?></p>
                        <p class="text-xs text-gray-600"><?= e((string)($page['label'] ?? '')) ?></p>
                    </td>
                    <td class="px-5 py-3 text-xs text-amber-300">
                        <?= e(implode(' · ', $page['issues'] ?? [])) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($summary['by_portal'])): ?>
<div class="glass rounded-xl p-6 mb-8 border border-gray-800">
    <h2 class="font-semibold mb-4">Coverage by portal</h2>
    <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <?php foreach ($summary['by_portal'] as $portal => $count): ?>
        <div class="rounded-lg border border-gray-800 bg-dark-900/40 px-4 py-3">
            <p class="text-xs text-gray-500"><?= e(watchdogPortalLabel((string)$portal)) ?></p>
            <p class="text-xl font-bold text-white mt-1"><?= (int)$count ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php elseif ($tab === 'auto'): ?>

<div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <div class="glass rounded-xl p-5 border <?= ($lastAuto && !empty($lastAuto['ok'])) ? 'border-emerald-500/30' : 'border-amber-500/40' ?>">
        <p class="text-xs text-gray-500">Last auto-audit</p>
        <p class="text-lg font-bold mt-1 <?= ($lastAuto && !empty($lastAuto['ok'])) ? 'text-emerald-400' : 'text-amber-400' ?>">
            <?= $lastAuto ? (!empty($lastAuto['ok']) ? '● OK' : '● Issues') : '— Never' ?>
        </p>
        <?php if ($lastAuto): ?>
        <p class="text-xs text-gray-600 mt-1"><?= e(formatDate((string)($lastAuto['ran_at'] ?? ''))) ?></p>
        <?php endif; ?>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Broken links (last)</p>
        <p class="text-3xl font-bold mt-1 <?= (int)($lastAuto['broken_links'] ?? 0) > 0 ? 'text-amber-400' : 'text-emerald-400' ?>"><?= (int)($lastAuto['broken_links'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Errors (last)</p>
        <p class="text-3xl font-bold mt-1 <?= (int)($lastAuto['error_count'] ?? 0) > 0 ? 'text-red-400' : 'text-emerald-400' ?>"><?= (int)($lastAuto['error_count'] ?? 0) ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Interval</p>
        <p class="text-3xl font-bold mt-1 text-violet-400"><?= $intervalMin ?>m</p>
        <p class="text-xs text-gray-600 mt-1">Change in Gateway Settings</p>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden mb-8">
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <h2 class="font-semibold">Auto-audit history</h2>
        <a href="?run_auto=1&csrf=<?= e(csrfToken()) ?>" class="text-xs text-violet-400">Run now →</a>
    </div>
    <?php if (empty($autoHistory)): ?>
    <div class="px-6 py-12 text-center text-gray-500">
        <p class="text-sm">No audit runs stored yet. Cron or a manual run will appear here.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">When</th>
                    <th class="px-5 py-3 text-left">Type</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Broken</th>
                    <th class="px-5 py-3 text-left">Errors</th>
                    <th class="px-5 py-3 text-left">Failed checks</th>
                    <th class="px-5 py-3 text-left">Merchants fixed</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($autoHistory as $row): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= e(formatDate((string)($row['created_at'] ?? ''))) ?></td>
                    <td class="px-5 py-3 text-xs font-mono text-gray-400"><?= e((string)($row['run_type'] ?? '')) ?></td>
                    <td class="px-5 py-3 text-xs font-semibold <?= !empty($row['ok']) ? 'text-emerald-400' : 'text-amber-400' ?>">
                        <?= !empty($row['ok']) ? 'OK' : 'ISSUES' ?>
                    </td>
                    <td class="px-5 py-3 text-xs"><?= (int)($row['broken_links'] ?? 0) ?></td>
                    <td class="px-5 py-3 text-xs"><?= (int)($row['error_count'] ?? 0) ?></td>
                    <td class="px-5 py-3 text-xs"><?= (int)($row['failed_checks'] ?? 0) ?></td>
                    <td class="px-5 py-3 text-xs"><?= (int)($row['merchants_fixed'] ?? 0) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<p class="text-xs text-gray-600 mb-8">Cron URL pattern: <code class="text-sky-400"><?= e(APP_URL) ?>/cron_auto_audit.php?key=…</code> · Key lives in Gateway Settings.</p>

<?php else: /* rules */ ?>

<div class="glass rounded-xl p-5 mb-6 border border-gray-800">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
    <p class="text-sm text-gray-400">Registry of pages the Link Watchdog expects across public, merchant, admin, staff, API, webhook, and system portals. Link Audit redirects here.</p>
    <p class="text-xs text-gray-600 mt-2"><?= count($registry) ?> registered routes</p>
        </div>
        <?= uxExportCsvLink(['tab' => 'rules']) ?>
    </div>
</div>

<div class="glass rounded-xl overflow-hidden mb-8">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Portal</th>
                    <th class="px-5 py-3 text-left">File</th>
                    <th class="px-5 py-3 text-left">Label</th>
                    <th class="px-5 py-3 text-left">Auth</th>
                    <th class="px-5 py-3 text-left">On disk</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($registry as $row):
                    $exists = is_file(__DIR__ . '/' . $row['file']);
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-5 py-3 text-xs text-gray-400"><?= e(watchdogPortalLabel((string)$row['portal'])) ?></td>
                    <td class="px-5 py-3 font-mono text-xs <?= $exists ? 'text-sky-400' : 'text-red-400' ?>"><?= e($row['file']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-300"><?= e($row['label']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= e((string)($row['auth'] ?? 'none')) ?></td>
                    <td class="px-5 py-3 text-xs <?= $exists ? 'text-emerald-400' : 'text-red-400' ?>"><?= $exists ? 'Yes' : 'Missing' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
