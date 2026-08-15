<?php
declare(strict_types=1);

/** Shared top nav for logged-in customer portal pages (pay + complaints only; no PPI wallet). */
$cpNavActive = $cpNavActive ?? 'dashboard';
$cpNavItems = [
    'dashboard' => ['href' => 'customer_portal.php', 'label' => 'Dashboard'],
    'transactions' => ['href' => 'customer_portal.php#txns', 'label' => 'Transactions'],
    'complaints' => ['href' => 'customer_ticket.php', 'label' => 'Complaints'],
    'profile' => ['href' => 'customer_profile.php', 'label' => 'Profile'],
];
?>
<nav class="cp-nav" aria-label="Customer portal">
    <?php foreach ($cpNavItems as $key => $item): ?>
    <a href="<?= e($item['href']) ?>" class="cp-nav-link<?= $cpNavActive === $key ? ' is-active' : '' ?>"><?= e($item['label']) ?></a>
    <?php endforeach; ?>
</nav>
