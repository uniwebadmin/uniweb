<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

$pageTitle = 'Deep Audit Plan';

$phases = [
    ['id' => '0', 'title' => 'PHASE 0 — DB, migrations, schema, fatal capture', 'when' => 'FIRST', 'kind' => 'work'],
    ['id' => '1', 'title' => 'PHASE 1 — Single money / keys plane', 'when' => 'After Phase 0 is green on live', 'kind' => 'work'],
    ['id' => '2', 'title' => 'PHASE 2 — Checkout, QR, methods, links', 'when' => 'After Phase 1 is green on live', 'kind' => 'work'],
    ['id' => '3', 'title' => 'PHASE 3 — KYC & onboarding', 'when' => 'After Phase 2 is green on live', 'kind' => 'work'],
    ['id' => '4', 'title' => 'PHASE 4 — Menus & panels A–Z', 'when' => 'After Phase 3 is green on live', 'kind' => 'work'],
    ['id' => '5', 'title' => 'PHASE 5 — Ops (Watchdog, cron, queue, notifications)', 'when' => 'After Phase 4 is green on live', 'kind' => 'work'],
    ['id' => '6', 'title' => 'PHASE 6 — Global search', 'when' => 'After Phase 5 is green on live', 'kind' => 'work'],
    ['id' => '7', 'title' => 'PHASE 7 — Public website', 'when' => 'After Phase 6 is green on live', 'kind' => 'work'],
    ['id' => '8', 'title' => 'PHASE 8 — Design polish', 'when' => 'After Phase 7 is green on live', 'kind' => 'work'],
    ['id' => '9', 'title' => 'PHASE 9 — Market comparison', 'when' => 'Reference only — does not jump ahead of Phase 0–2', 'kind' => 'reference'],
    ['id' => '10', 'title' => 'PHASE 10 — White-label checklist', 'when' => 'Reference only — only if a real deal needs an item, after 0–2', 'kind' => 'reference'],
    ['id' => '11', 'title' => 'PHASE 11 — Later optional + never NBFC/PPI', 'when' => 'Route/Split only after keys + Owner says start. NBFC and customer PPI wallet stay hidden.', 'kind' => 'later'],
];

require_once __DIR__ . '/header.php';
?>

<div class="mb-6 min-w-0">
    <p class="text-sm text-gray-400">How to use — work top-down by phase. You verify each phase on live before the next. Market and white-label are full reference — they do not override Phase 0–2.</p>
    <p class="text-xs text-amber-400 mt-2">Current focus: Phase 0 first. Do not drop the production database. Never build NBFC product or a customer PPI wallet.</p>
</div>

<div class="flex flex-wrap gap-2 mb-6">
    <a href="gateway_settings.php" class="glass px-4 py-2 rounded-xl text-sm text-sky-400">Gateway Settings</a>
    <a href="admin_error_log.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Error Log</a>
    <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400">Link Watchdog</a>
    <a href="admin_platform_status.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">Platform Status</a>
</div>

<div class="glass rounded-xl overflow-hidden min-w-0 mb-6">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Phase order</h2>
    </div>
    <ol class="divide-y divide-gray-800">
        <?php foreach ($phases as $phase):
            $isFirst = $phase['id'] === '0';
            $badge = match ($phase['kind']) {
                'reference' => 'Reference',
                'later' => 'Later / excluded',
                default => $isFirst ? 'Do first' : 'Work',
            };
            $badgeClass = match ($phase['kind']) {
                'reference' => 'text-gray-500 border-gray-700',
                'later' => 'text-red-400 border-red-500/30',
                default => $isFirst ? 'text-emerald-400 border-emerald-500/40' : 'text-sky-400 border-sky-700/40',
            };
        ?>
        <li class="px-4 sm:px-6 py-4 <?= $isFirst ? 'bg-emerald-500/5' : '' ?>">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-100"><?= e($phase['title']) ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?= e($phase['when']) ?></p>
                </div>
                <span class="text-[11px] px-2 py-1 rounded-lg border <?= $badgeClass ?> w-fit whitespace-nowrap"><?= e($badge) ?></span>
            </div>
        </li>
        <?php endforeach; ?>
    </ol>
</div>

<div class="glass rounded-xl p-4 sm:p-6 border border-gray-800 min-w-0">
    <h2 class="font-semibold mb-3">APPENDIX — Evidence</h2>
    <p class="text-sm text-gray-400 leading-relaxed">Source: Hostinger site copy 14 Aug 2026 · SQL dumps · about 434 PHP files · tickets 2.1–2.16 plus stability. Migrations through 060. Checkout, global search, and merchant menus exist in the repo. Live HTTP and SMTP were not fully executed in the offline PDF. Format stays Problem → Expectation → Solution. Product exclusions only: NBFC and customer PPI wallet.</p>
    <p class="text-xs text-gray-600 mt-3">After you verify a phase on live, send the next point. Do not skip ahead to public-site redesign or white-label before Phase 0–2.</p>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
