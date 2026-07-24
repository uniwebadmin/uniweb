<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
ensurePartnerEngine();

$counts = partnerConfiguredCount();
$registry = getPartnerRegistry();
$pageTitle = 'Banking & Gateway Partners';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6">
    <p class="text-sm text-gray-400">Partner structure is ready — paste API keys when received from each partner.</p>
    <p class="text-xs text-gray-500 mt-1"><?= $counts['ready'] ?>/<?= $counts['total'] ?> partners have credentials saved</p>
</div>

<div class="grid md:grid-cols-2 xl:grid-cols-3 gap-4">
    <?php if (empty($registry)): ?>
    <div class="glass rounded-xl p-8 col-span-full text-center text-gray-500">No partners configured in registry yet.</div>
    <?php else: foreach ($registry as $key => $p):
        $configured = partnerIsConfigured($key);
        $test = partnerTestConnection($key);
    ?>
    <div class="glass rounded-xl p-5 card-hover">
        <div class="flex items-start justify-between gap-3 mb-3">
            <div>
                <p class="text-2xl"><?= e($p['icon']) ?></p>
                <h3 class="font-semibold text-lg mt-1"><?= e($p['name']) ?></h3>
                <span class="text-[10px] uppercase tracking-wide <?= $p['type'] === 'banking' ? 'text-rose-400' : 'text-sky-400' ?>"><?= e($p['type']) ?></span>
            </div>
            <span class="text-xs px-2 py-1 rounded-full <?= $configured ? 'bg-emerald-500/20 text-emerald-400' : 'bg-amber-500/20 text-amber-400' ?>">
                <?= $configured ? 'Keys Saved' : 'Awaiting Keys' ?>
            </span>
        </div>
        <p class="text-xs text-gray-500 mb-4 min-h-[40px]"><?= e($p['use']) ?></p>
        <div class="flex flex-wrap gap-2">
            <a href="<?= e($p['admin_page']) ?>" class="btn-primary text-xs px-4 py-2">Configure →</a>
            <?php if ($p['signup']): ?>
            <a href="<?= e($p['signup']) ?>" target="_blank" rel="noopener" class="text-xs text-gray-400 hover:text-white px-3 py-2 border border-gray-700 rounded-lg">Signup</a>
            <?php endif; ?>
            <a href="admin_partner_requests.php?partner=<?= e($key) ?>" class="text-xs text-sky-400 px-3 py-2">Email Template</a>
        </div>
        <p class="text-[11px] text-gray-600 mt-3 truncate" title="<?= e($test['message']) ?>"><?= e(mb_substr($test['message'], 0, 80)) ?></p>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
