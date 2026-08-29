<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$filters = [
    'from' => mb_substr(trim($_GET['from'] ?? ''), 0, 10),
    'to' => mb_substr(trim($_GET['to'] ?? ''), 0, 10),
    'status' => strtolower(trim($_GET['status'] ?? 'all')),
    'type' => strtolower(trim($_GET['type'] ?? 'all')),
    'amount_min' => trim($_GET['amount_min'] ?? ''),
    'amount_max' => trim($_GET['amount_max'] ?? ''),
];
$txns = getCustomerTransactions($phone, 100, $filters);
$refundsByTxn = getCustomerRefundsByTxn($phone);
$tickets = getCustomerTickets($phone);
$openTickets = count(array_filter($tickets, static fn($t) => in_array(($t['status'] ?? ''), ['open', 'in_progress'], true)));

$pageTitle = 'My Payments';
$hideNav = true;
$hideFooter = true;
$customerPortalUi = true;
$cpNavActive = 'dashboard';
$bodyClass = trim(($bodyClass ?? '') . ' customer-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="cp-main py-8 space-y-6 flex-1 w-full">
        <div class="cp-hero">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Customer portal</p>
                <h1 class="cp-display text-3xl sm:text-4xl font-bold mt-2 text-slate-900">My payments</h1>
                <p class="cp-muted mt-2 max-w-xl">Every payment made with +91 <?= e($phone) ?> across UniWeb merchants — filter, review status, and raise a complaint when needed.</p>
                <p class="text-xs text-slate-500 mt-2 max-w-xl"><?= e(customerPortalScopeCopy()) ?></p>
            </div>
            <div class="cp-stat">
                <p>Payments found</p>
                <strong><?= count($txns) ?></strong>
                <p class="mt-2"><?= $openTickets ?> open complaint<?= $openTickets === 1 ? '' : 's' ?></p>
                <a href="customer_ticket.php?new=1" class="inline-flex mt-4 text-sm font-bold underline underline-offset-4 decoration-white/50 hover:decoration-white">Raise a complaint →</a>
            </div>
        </div>

        <section id="txns" class="cp-panel scroll-mt-24">
            <div class="cp-panel-head">
                <h2 class="font-bold text-slate-900">Transaction history</h2>
                <span class="cp-muted"><?= count($txns) ?> result<?= count($txns) === 1 ? '' : 's' ?></span>
            </div>
            <form method="GET" class="cp-filters px-5 py-4 border-b border-slate-100 grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3 items-end">
                <div>
                    <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-from">From date</label>
                    <input id="cp-from" type="date" name="from" value="<?= e($filters['from']) ?>" class="cp-input mt-1 !py-2">
                </div>
                <div>
                    <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-to">To date</label>
                    <input id="cp-to" type="date" name="to" value="<?= e($filters['to']) ?>" class="cp-input mt-1 !py-2">
                </div>
                <div>
                    <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-status">Status</label>
                    <select id="cp-status" name="status" class="cp-input mt-1 !py-2">
                        <?php foreach (['all' => 'All statuses', 'success' => 'Success', 'pending' => 'Pending', 'failed' => 'Failed', 'refunded' => 'Refunded'] as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-type">Type</label>
                    <select id="cp-type" name="type" class="cp-input mt-1 !py-2">
                        <?php foreach (['all' => 'All types', 'upi' => 'UPI', 'card' => 'Card', 'netbanking' => 'Netbanking', 'wallet' => 'Wallet apps'] as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $filters['type'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-amin">Min amount</label>
                    <input id="cp-amin" type="number" step="0.01" min="0" name="amount_min" value="<?= e((string)$filters['amount_min']) ?>" class="cp-input mt-1 !py-2" placeholder="₹">
                </div>
                <div class="flex gap-2">
                    <div class="flex-1">
                        <label class="text-[10px] uppercase text-slate-500 font-semibold" for="cp-amax">Max amount</label>
                        <input id="cp-amax" type="number" step="0.01" min="0" name="amount_max" value="<?= e((string)$filters['amount_max']) ?>" class="cp-input mt-1 !py-2" placeholder="₹">
                    </div>
                    <button type="submit" class="cp-btn cp-btn-primary !py-2 !px-3 text-xs self-end">Filter</button>
                </div>
            </form>
            <?php if (empty($txns)): ?>
            <div class="cp-empty">
                <p class="font-semibold text-slate-700 mb-1">No payments match these filters</p>
                <p>Clear filters or try another date / status. If you paid with a different number, log out and sign in with that number.</p>
            </div>
            <?php else: ?>
            <div class="cp-txn-list">
                <?php foreach ($txns as $t):
                    $reason = customerTransactionReason($t);
                ?>
                <article class="cp-txn-row">
                    <div>
                        <p class="cp-mono"><a href="receipt.php?txn=<?= rawurlencode((string)$t['txn_id']) ?>" target="_blank" class="hover:underline"><?= e($t['txn_id']) ?></a></p>
                        <p class="text-sm font-semibold text-slate-800 mt-1 sm:hidden"><?= formatMoney((float)$t['amount']) ?></p>
                        <p class="cp-muted uppercase mt-0.5"><?= e(function_exists('customerPaymentMethodLabel') ? customerPaymentMethodLabel($t['payment_method'] ?? '') : ($t['payment_method'] ?? '')) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-800"><?= e($t['business_name'] ?: 'Merchant') ?></p>
                        <p class="cp-muted mt-0.5"><?= formatDate($t['created_at']) ?></p>
                    </div>
                    <div class="hidden sm:block text-sm font-bold text-slate-900"><?= formatMoney((float)$t['amount']) ?></div>
                    <div>
                        <?= statusBadge((string)$t['status']) ?>
                        <?php
                        $txnRefunds = $refundsByTxn[(string)$t['txn_id']] ?? [];
                        foreach ($txnRefunds as $rf):
                            $rfLabel = customerRefundPortalLabel($rf);
                        ?>
                        <p class="text-[10px] text-violet-700 mt-1">Refund <?= e($rf['refund_id']) ?> — <?= e($rfLabel) ?><?= !empty($rf['failure_reason']) ? ' — ' . e(mb_substr((string)$rf['failure_reason'], 0, 80)) : '' ?></p>
                        <?php endforeach; ?>
                        <?php if ($reason): ?><p class="cp-reason"><?= e($reason) ?></p><?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="receipt.php?txn=<?= rawurlencode((string)$t['txn_id']) ?>" target="_blank" class="cp-btn cp-btn-ghost !py-2 !px-3 text-xs whitespace-nowrap">Receipt</a>
                        <a href="customer_ticket.php?new=1&txn=<?= rawurlencode((string)$t['txn_id']) ?>" class="cp-btn cp-btn-ghost !py-2 !px-3 text-xs whitespace-nowrap">Report issue</a>
                    </div>
                </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>

        <section id="complaints" class="cp-panel scroll-mt-24">
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

        <?php
        $customerChargebacks = [];
        try {
            $csSt = getDB()->prepare("SELECT * FROM chargebacks WHERE customer_phone=? ORDER BY created_at DESC LIMIT 10");
            $csSt->execute([$phone]);
            $customerChargebacks = $csSt->fetchAll();
        } catch (Throwable $e) {}
        ?>
        <?php if (!empty($customerChargebacks)): ?>
        <section id="chargebacks" class="cp-panel scroll-mt-24">
            <div class="cp-panel-head">
                <h2 class="font-bold text-slate-900">My disputes / chargebacks</h2>
            </div>
            <?php foreach ($customerChargebacks as $cb): ?>
            <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-slate-100 last:border-0">
                <div class="min-w-0">
                    <p class="cp-mono"><?= e($cb['chargeback_ref'] ?? '') ?></p>
                    <p class="text-sm font-semibold text-slate-800 mt-0.5"><?= formatMoney((float)($cb['amount'] ?? 0)) ?></p>
                    <p class="cp-muted mt-0.5"><?= e($cb['reason'] ?? '') ?></p>
                    <p class="text-[10px] text-amber-700 mt-1">Bank dispute — not the same as a refund.</p>
                </div>
                <div class="text-right shrink-0">
                    <?= statusBadge((string)($cb['status'] ?? '')) ?>
                    <p class="cp-muted mt-1"><?= formatDate($cb['created_at'] ?? '') ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

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
