<?php
declare(strict_types=1);

/** Sidebar nav for logged-in customer portal (pay + complaints only; no PPI wallet). */
$cpNavActive = $cpNavActive ?? 'dashboard';
$cpNavItems = [
    'dashboard' => [
        'href' => 'customer_portal.php',
        'label' => 'Dashboard',
        'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
    ],
    'transactions' => [
        'href' => 'customer_portal.php#txns',
        'label' => 'Transactions',
        'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
    ],
    'complaints' => [
        'href' => 'customer_portal.php#complaints',
        'label' => 'Complaints',
        'icon' => 'M8 10h.01M12 10h.01M16 10h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
    ],
    'profile' => [
        'href' => 'customer_profile.php',
        'label' => 'Profile',
        'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
    ],
];
?>
<nav class="cp-sidebar-nav sidebar-nav p-3 space-y-0.5 text-sm flex-1 overflow-y-auto" aria-label="Customer portal">
    <?php foreach ($cpNavItems as $key => $item): ?>
    <a href="<?= e($item['href']) ?>" class="sidebar-link cp-sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm transition <?= $cpNavActive === $key ? 'is-active active' : '' ?>">
        <svg class="w-5 h-5 flex-shrink-0 overflow-visible" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="<?= e($item['icon']) ?>"/></svg>
        <?= e($item['label']) ?>
    </a>
    <?php endforeach; ?>
</nav>
