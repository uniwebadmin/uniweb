<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$txns = getCustomerTransactions($phone);
$tickets = getCustomerTickets($phone);
$openTickets = count(array_filter($tickets, static fn($t) => in_array(($t['status'] ?? ''), ['open', 'in_progress'], true)));

$pageTitle = 'My Payments';
$hideNav = true;
$hideFooter = true;
$customerPortalUi = true;
$bodyClass = trim(($bodyClass ?? '') . ' customer-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="cp-shell">
    <header class="cp-topbar">
        <div class="cp-topbar-inner">
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <div class="flex items-center gap-2 sm:gap-3">
                <span class="cp-phone-chip">+91 <?= e($phone) ?></span>
                <a href="customer_logout.php" class="cp-btn cp-btn-ghost text-xs !py-1.5 !px-3">Logout</a>
            </div>
        </div>
    </header>

    <main class="cp-main py-8 space-y-6 flex-1 w-full">
        <div class="cp-hero">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Customer portal</p>
                <h1 class="cp-display text-3xl sm:text-4xl font-bold mt-2 text-slate-900">My payments</h1>
                <p class="cp-muted mt-2 max-w-xl">Every payment made with +91 <?= e($phone) ?> across UniWeb merchants — read-only history with clear status and reason.</p>
            </div>
            <div class="cp-stat">
                <p>Payments found</p>
                <strong><?= count($txns) ?></strong>
                <p class="mt-2"><?= $openTickets ?> open complaint<?= $openTickets === 1 ? '' : 's' ?></p>
                <a href="customer_ticket.php?new=1" class="inline-flex mt-4 text-sm font-bold underline underline-offset-4 decoration-white/50 hover:decoration-white">Raise a complaint →</a>
            </div>
        </div>

        <section class="cp-panel">
            <div class="cp-panel-head">
                <h2 class="font-bold text-slate-900">Transaction history</h2>
                <span class="cp-muted"><?= count($txns) ?> result<?= count($txns) === 1 ? '' : 's' ?></span>
            </div>
            <?php if (empty($txns)): ?>
            <div class="cp-empty">
                <p class="font-semibold text-slate-700 mb-1">No payments found for this mobile</p>
                <p>If you paid with a different number, log out and sign in with that number.</p>
            </div>
            <?php else: ?>
            <div class="cp-txn-list">
                <?php foreach ($txns as $t):
                    $reason = customerTransactionReason($t);
                ?>
                <article class="cp-txn-row">
                    <div>
                        <p class="cp-mono"><?= e($t['txn_id']) ?></p>
                        <p class="text-sm font-semibold text-slate-800 mt-1 sm:hidden"><?= formatMoney((float)$t['amount']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-800"><?= e($t['business_name'] ?: 'Merchant') ?></p>
                        <p class="cp-muted mt-0.5"><?= formatDate($t['created_at']) ?></p>
                    </div>
                    <div class="hidden sm:block text-sm font-bold text-slate-900"><?= formatMoney((float)$t['amount']) ?></div>
                    <div>
                        <?= statusBadge((string)$t['status']) ?>
                        <?php if ($reason): ?><p class="cp-reason"><?= e($reason) ?></p><?php endif; ?>
                    </div>
                    <div>
                        <a href="customer_ticket.php?new=1&txn=<?= rawurlencode((string)$t['txn_id']) ?>" class="cp-btn cp-btn-ghost !py-2 !px-3 text-xs whitespace-nowrap">Report issue</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section class="cp-panel">
            <div class="cp-panel-head">
                <h2 class="font-bold text-slate-900">My complaints</h2>
                <a href="customer_ticket.php?new=1" class="text-sm font-semibold text-teal-700 hover:underline">+ New</a>
            </div>
            <?php if (empty($tickets)): ?>
            <div class="cp-empty">No complaints yet. Tap <strong>Report issue</strong> on any payment above.</div>
            <?php else: foreach ($tickets as $tk): ?>
            <a href="customer_ticket.php?id=<?= rawurlencode((string)$tk['ticket_id']) ?>" class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100 hover:bg-slate-50/80 last:border-0">
                <div class="min-w-0">
                    <p class="cp-mono"><?= e($tk['ticket_id']) ?></p>
                    <p class="text-sm font-semibold text-slate-800 mt-0.5 truncate"><?= e($tk['subject']) ?></p>
                    <?php if (!empty($tk['txn_reference'])): ?>
                    <p class="cp-muted mt-0.5 font-mono">Txn: <?= e($tk['txn_reference']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right shrink-0">
                    <?= statusBadge((string)$tk['status']) ?>
                    <p class="cp-muted mt-1"><?= formatDate($tk['created_at']) ?></p>
                </div>
            </a>
            <?php endforeach; endif; ?>
        </section>
    </main>

    <footer class="cp-footer">
        <div class="cp-footer-inner">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
            <span class="flex gap-4">
                <a href="terms.php">Terms</a>
                <a href="privacy.php">Privacy</a>
                <a href="contact.php">Contact</a>
            </span>
        </div>
    </footer>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
