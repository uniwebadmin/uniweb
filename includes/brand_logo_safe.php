<?php
declare(strict_types=1);

/**
 * Safe wrapper — public/merchant nav logo without fatal if brand_logo.php missing on partial deploy.
 * Expects optional vars in caller scope: $logoHref, $logoSize, $showWordmark, $merchantPanel, $merchantInitial.
 */
$__brandLogoPath = __DIR__ . '/brand_logo.php';
if (is_file($__brandLogoPath)) {
    require $__brandLogoPath;
    return;
}

$logoHref = $logoHref ?? 'index.php';
$logoSize = $logoSize ?? 'md';
$showWordmark = $showWordmark ?? true;
$merchantPanel = $merchantPanel ?? false;
$merchantInitial = $merchantInitial ?? 'M';
$sizes = ['sm' => 'h-8 w-8', 'md' => 'h-9 w-9', 'lg' => 'h-11 w-11'];
$iconCls = $sizes[$logoSize] ?? $sizes['md'];
?>
<a href="<?= e($logoHref) ?>" class="inline-flex items-center gap-2.5 group shrink-0">
    <?php if ($merchantPanel): ?>
    <span class="<?= e($iconCls) ?> rounded-xl bg-gradient-to-br from-brand-600 to-cyan-600 flex items-center justify-center text-white font-bold shadow-lg shadow-brand-500/20">
        <?= e($merchantInitial) ?>
    </span>
    <?php else: ?>
    <span class="<?= e($iconCls) ?> rounded-xl bg-gradient-to-br from-brand-600 to-cyan-600 flex items-center justify-center text-white font-bold shadow-lg shadow-brand-500/20" aria-hidden="true">U</span>
    <?php if ($showWordmark): ?>
    <span class="text-xl font-extrabold tracking-tight whitespace-nowrap leading-none">
        <span class="text-white">Uni</span><span class="gradient-text">Web</span>
    </span>
    <?php endif; ?>
    <?php endif; ?>
</a>
