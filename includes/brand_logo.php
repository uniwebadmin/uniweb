<?php
/**
 * Reusable UniWeb brand logo — public site vs merchant panel
 */
$logoSize = $logoSize ?? 'md';
$showWordmark = $showWordmark ?? true;
$logoHref = $logoHref ?? 'index.php';
$merchantPanel = $merchantPanel ?? false;
$merchantInitial = $merchantInitial ?? 'M';
$sizes = ['sm' => 'h-8 w-8', 'md' => 'h-9 w-9', 'lg' => 'h-11 w-11'];
$textSizes = ['sm' => 'text-lg', 'md' => 'text-xl', 'lg' => 'text-2xl'];
$iconCls = $sizes[$logoSize] ?? $sizes['md'];
$textCls = $textSizes[$logoSize] ?? $textSizes['md'];
?>
<a href="<?= e($logoHref) ?>" class="inline-flex items-center gap-2.5 group shrink-0">
    <?php if ($merchantPanel): ?>
    <span class="<?= $iconCls ?> rounded-xl bg-gradient-to-br from-brand-600 to-cyan-600 flex items-center justify-center text-white font-bold shadow-lg shadow-brand-500/20 group-hover:scale-105 transition-transform">
        <?= e($merchantInitial) ?>
    </span>
    <?php else: ?>
    <img src="assets/img/uniweb-logo.svg" alt="<?= e(APP_NAME) ?>" class="<?= $iconCls ?> rounded-xl shadow-md shadow-sky-500/20 group-hover:scale-105 transition-transform" width="44" height="44">
    <?php if ($showWordmark): ?>
    <span class="<?= $textCls ?> font-extrabold tracking-tight whitespace-nowrap leading-none">
        <span class="text-white">Uni</span><span class="gradient-text">Web</span>
    </span>
    <?php endif; ?>
    <?php endif; ?>
</a>
