<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
$merchant = requireMerchantAccount();

$launch = getMerchantLaunchCenter($merchant);
$next = $launch['next'];
$pageTitle = 'Launch Center';
require_once __DIR__ . '/header.php';
?>

<div class="max-w-5xl mx-auto space-y-6 sm:space-y-8">
    <section class="glass rounded-2xl p-5 sm:p-7 border border-brand-500/25 overflow-hidden relative">
        <div class="absolute -right-24 -top-24 w-64 h-64 rounded-full bg-brand-500/10 blur-3xl pointer-events-none"></div>
        <div class="relative flex flex-col lg:flex-row lg:items-center gap-6 justify-between">
            <div class="max-w-2xl">
                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-400">Merchant Launch Center</p>
                <h2 class="text-2xl sm:text-3xl font-bold text-white mt-2">One path from setup to first collection.</h2>
                <p class="text-sm text-gray-400 mt-3 leading-relaxed">Finish each item once. Your progress stays saved, and repeated actions never create duplicate payment setup or launch records.</p>
            </div>
            <div class="shrink-0 rounded-2xl border border-brand-500/30 bg-brand-500/10 px-6 py-4 min-w-[10rem]">
                <p class="text-[10px] uppercase tracking-wider text-brand-300">Launch readiness</p>
                <p class="text-4xl font-bold text-white mt-1"><?= (int)$launch['score'] ?>%</p>
                <p class="text-xs text-gray-400 mt-1"><?= (int)$launch['completed'] ?>/<?= (int)$launch['total'] ?> completed</p>
            </div>
        </div>
        <div class="relative mt-6 h-2 rounded-full bg-dark-950/70 overflow-hidden">
            <div class="h-full rounded-full bg-gradient-to-r from-brand-500 via-emerald-400 to-sky-400 transition-all" style="width:<?= (int)$launch['score'] ?>%"></div>
        </div>
    </section>

    <?php if ($next): ?>
    <section class="rounded-2xl p-5 sm:p-6 border border-sky-500/30 bg-sky-500/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wider text-sky-400 font-semibold">Do this next</p>
            <h3 class="font-semibold text-white mt-1"><?= e($next['label']) ?></h3>
            <p class="text-sm text-gray-400 mt-1"><?= e($next['hint']) ?></p>
        </div>
        <a href="<?= e($next['url']) ?>" class="btn-primary px-5 py-3 text-center whitespace-nowrap">Continue →</a>
    </section>
    <?php else: ?>
    <section class="rounded-2xl p-5 sm:p-6 border border-emerald-500/30 bg-emerald-500/5 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wider text-emerald-400 font-semibold">Launch checklist complete</p>
            <h3 class="font-semibold text-white mt-1">Your account is ready for the next approval stage.</h3>
            <p class="text-sm text-gray-400 mt-1"><?= !empty($launch['live_ready']) ? 'Live mode is available in your dashboard.' : 'Partner and compliance approvals still control live money activation.' ?></p>
        </div>
        <?php if (!empty($launch['live_ready'])): ?>
        <a href="<?= e(merchantModeToggleUrl('live')) ?>" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-3 rounded-xl font-semibold text-center whitespace-nowrap">Switch to Live →</a>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <section class="glass rounded-2xl overflow-hidden border border-gray-800">
        <div class="px-5 sm:px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-semibold text-white">Your launch steps</h3>
                <p class="text-xs text-gray-500 mt-1">Every step has an exact result and a saved final state.</p>
            </div>
            <span class="text-xs px-3 py-1.5 rounded-full <?= $launch['score'] === 100 ? 'bg-emerald-500/15 text-emerald-300' : 'bg-sky-500/15 text-sky-300' ?>"><?= $launch['score'] === 100 ? 'Ready' : 'In progress' ?></span>
        </div>
        <div class="divide-y divide-gray-800">
            <?php foreach ($launch['steps'] as $index => $step): ?>
            <a href="<?= e($step['url']) ?>" class="group flex items-center gap-3 sm:gap-4 px-5 sm:px-6 py-4 hover:bg-white/[0.025] transition">
                <span class="w-8 h-8 rounded-full shrink-0 flex items-center justify-center text-sm font-bold <?= $step['done'] ? 'bg-emerald-500/15 text-emerald-300 border border-emerald-500/30' : 'bg-dark-900 text-gray-500 border border-gray-700 group-hover:border-sky-500/50 group-hover:text-sky-300' ?>">
                    <?= $step['done'] ? '✓' : (int)$index + 1 ?>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium <?= $step['done'] ? 'text-gray-300' : 'text-white' ?>"><?= e($step['label']) ?></span>
                    <span class="block text-xs text-gray-500 mt-0.5 truncate"><?= e($step['hint']) ?></span>
                </span>
                <span class="text-xs shrink-0 <?= $step['done'] ? 'text-emerald-400' : 'text-sky-400' ?>"><?= $step['done'] ? 'Completed' : 'Open →' ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="grid sm:grid-cols-2 gap-4">
        <div class="glass rounded-xl p-5 border border-gray-800">
            <p class="text-xs uppercase tracking-wider text-violet-400 font-semibold">Test safely first</p>
            <p class="text-sm text-gray-400 mt-2">Stay in Test Mode. Open Payment Pack or a payment link, then use <strong class="text-amber-300">UniWeb Test Pay</strong> on checkout — no real money.</p>
            <a href="merchant_payment_pack.php" class="inline-block mt-4 text-sm text-sky-400 hover:text-sky-300">Open Payment Pack → UniWeb Test Pay</a>
        </div>
        <div class="glass rounded-xl p-5 border border-gray-800">
            <p class="text-xs uppercase tracking-wider text-amber-400 font-semibold">Need help?</p>
            <p class="text-sm text-gray-400 mt-2">Every missing item has one clear action. Support can see your launch progress without asking you to repeat details.</p>
            <a href="support.php" class="inline-block mt-4 text-sm text-sky-400 hover:text-sky-300">Get support →</a>
        </div>
    </section>
</div>

<?php require_once __DIR__ . '/footer.php';
