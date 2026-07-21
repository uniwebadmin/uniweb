<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$txns = getCustomerTransactions($phone);
$tickets = getCustomerTickets($phone);

$pageTitle = 'My Payments';
$hideNav = true;
$hideFooter = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex flex-col">
    <header class="border-b border-gray-800 bg-dark-950/80">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-3">
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-gray-400 hidden sm:inline">+91 <?= e($phone) ?></span>
                <a href="customer_logout.php" class="text-gray-400 hover:text-white border border-gray-700 rounded-lg px-3 py-1.5">Logout</a>
            </div>
        </div>
    </header>

    <main class="flex-1 w-full max-w-5xl mx-auto px-4 sm:px-6 py-8 space-y-8">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold">My Payments</h1>
                <p class="text-sm text-gray-500 mt-1">All payments made from your mobile number across UniWeb merchants.</p>
            </div>
            <a href="customer_ticket.php?new=1" class="btn-primary px-4 py-2.5 text-sm">Raise a complaint</a>
        </div>

        <section class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Transaction History</h2></div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                        <th class="px-5 py-3 text-left">Transaction</th>
                        <th class="px-5 py-3 text-left">Merchant</th>
                        <th class="px-5 py-3 text-right">Amount</th>
                        <th class="px-5 py-3 text-left">Status</th>
                        <th class="px-5 py-3 text-left">Date</th>
                        <th class="px-5 py-3 text-left">Action</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php if (empty($txns)): ?>
                        <tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No payments found for +91 <?= e($phone) ?>.</td></tr>
                        <?php else: foreach ($txns as $t): $reason = customerTransactionReason($t); ?>
                        <tr class="hover:bg-white/5 align-top">
                            <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($t['txn_id']) ?></td>
                            <td class="px-5 py-3"><?= e($t['business_name'] ?: '—') ?></td>
                            <td class="px-5 py-3 text-right font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                            <td class="px-5 py-3">
                                <?= statusBadge((string)$t['status']) ?>
                                <?php if ($reason): ?><p class="text-[11px] text-gray-500 mt-1 max-w-[220px]"><?= e($reason) ?></p><?php endif; ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($t['created_at']) ?></td>
                            <td class="px-5 py-3 text-xs">
                                <a href="customer_ticket.php?new=1&txn=<?= rawurlencode((string)$t['txn_id']) ?>" class="text-amber-400 hover:underline whitespace-nowrap">Report issue</a>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="glass rounded-xl overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-800 flex items-center justify-between">
                <h2 class="font-semibold">My Complaints</h2>
                <a href="customer_ticket.php?new=1" class="text-xs text-brand-400 hover:underline">+ New</a>
            </div>
            <div class="divide-y divide-gray-800">
                <?php if (empty($tickets)): ?>
                <p class="px-6 py-10 text-center text-gray-500 text-sm">No complaints yet. If a payment has an issue, click "Report issue" on it above.</p>
                <?php else: foreach ($tickets as $tk): ?>
                <a href="customer_ticket.php?id=<?= rawurlencode((string)$tk['ticket_id']) ?>" class="flex items-center justify-between gap-3 px-6 py-4 hover:bg-white/5">
                    <div class="min-w-0">
                        <p class="font-mono text-xs text-sky-400"><?= e($tk['ticket_id']) ?></p>
                        <p class="text-sm mt-0.5 truncate"><?= e($tk['subject']) ?></p>
                        <?php if (!empty($tk['txn_reference'])): ?><p class="text-[11px] text-gray-600 mt-0.5 font-mono">Txn: <?= e($tk['txn_reference']) ?></p><?php endif; ?>
                    </div>
                    <div class="text-right shrink-0">
                        <?= statusBadge((string)$tk['status']) ?>
                        <p class="text-[11px] text-gray-600 mt-1"><?= formatDate($tk['created_at']) ?></p>
                    </div>
                </a>
                <?php endforeach; endif; ?>
            </div>
        </section>
    </main>

    <footer class="border-t border-gray-800/70 bg-dark-950">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-4 flex flex-col sm:flex-row items-center justify-between gap-2 text-xs text-gray-600">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
            <span class="flex gap-4">
                <a href="terms.php" class="hover:text-brand-400">Terms</a>
                <a href="privacy.php" class="hover:text-brand-400">Privacy</a>
                <a href="contact.php" class="hover:text-brand-400">Contact</a>
            </span>
        </div>
    </footer>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
