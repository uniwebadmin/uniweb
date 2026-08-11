<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops', 'finance']);
require_once __DIR__ . '/includes/customer_portal.php';
ensureCustomerPortalSchema();

$phoneRaw = trim((string)($_GET['phone'] ?? ''));
$phone = customerNormalizePhone($phoneRaw) ?: preg_replace('/\D/', '', $phoneRaw);
$highlightTxn = trim((string)($_GET['txn'] ?? ''));

$pageTitle = 'Customer Lookup';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div>
        <h1 class="text-xl font-semibold">Customer Lookup</h1>
        <p class="text-sm text-gray-400 mt-1">Search customer by mobile number — view payments & complaints across all merchants.</p>
    </div>

    <div class="glass rounded-xl p-5">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-gray-500 mb-1">Customer Mobile Number</label>
                <input type="text" name="phone" value="<?= e($phoneRaw) ?>" placeholder="e.g. 9876543210 or +919876543210" class="w-full bg-dark-900 border border-gray-700 rounded-lg px-4 py-2.5 text-sm focus:border-brand-500 focus:outline-none" />
            </div>
            <button type="submit" class="px-5 py-2.5 bg-brand-600 hover:bg-brand-500 text-white text-sm font-medium rounded-lg transition">Search</button>
        </form>
    </div>

    <?php if ($phone !== '' && strlen((string)$phone) >= 10):
        $txns = getCustomerTransactions((string)$phone, 100);
        $tickets = getCustomerTickets((string)$phone);
    ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-semibold">+91 <?= e((string)$phone) ?></h2>
            <p class="text-sm text-gray-400 mt-1"><?= count($txns) ?> payment<?= count($txns) === 1 ? '' : 's' ?> · <?= count($tickets) ?> complaint<?= count($tickets) === 1 ? '' : 's' ?></p>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800 flex flex-wrap justify-between gap-2">
            <h2 class="font-semibold">Transactions</h2>
            <span class="text-xs text-gray-500"><?= count($txns) ?> payment<?= count($txns) === 1 ? '' : 's' ?></span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[720px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Merchant</th>
                    <th class="px-5 py-3 text-left">Amount</th>
                    <th class="px-5 py-3 text-left">Type</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Date</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (!$txns): ?>
                    <tr><td colspan="6" class="px-5 py-10 text-center text-gray-500">No transactions for this mobile.</td></tr>
                    <?php else: foreach ($txns as $t):
                        $isHit = $highlightTxn !== '' && strcasecmp((string)$t['txn_id'], $highlightTxn) === 0;
                    ?>
                    <tr class="<?= $isHit ? 'bg-amber-500/10 ring-1 ring-inset ring-amber-500/40' : 'hover:bg-white/5' ?>">
                        <td class="px-5 py-3 font-mono text-xs"><?= txnDetailLink((string)$t['txn_id']) ?><?= $isHit ? ' <span class="text-amber-300 text-[10px] uppercase">Disputed</span>' : '' ?></td>
                        <td class="px-5 py-3 text-xs"><?= !empty($t['merchant_id']) ? adminMerchantLink((int)$t['merchant_id'], (string)($t['business_name'] ?: 'Merchant')) : e($t['business_name'] ?: '—') ?></td>
                        <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$t['amount']) ?></td>
                        <td class="px-5 py-3 text-xs uppercase"><?= e(paymentMethodLabel($t['payment_method'] ?? '')) ?></td>
                        <td class="px-5 py-3"><?= statusBadge((string)$t['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($t['created_at']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-800"><h2 class="font-semibold">Complaints</h2></div>
        <div class="divide-y divide-gray-800">
            <?php if (!$tickets): ?>
            <p class="px-5 py-8 text-center text-gray-500 text-sm">No complaints from this customer.</p>
            <?php else: foreach ($tickets as $tk): ?>
            <a href="admin_customer_tickets.php?id=<?= (int)$tk['id'] ?>" class="flex flex-wrap justify-between gap-3 px-5 py-4 hover:bg-white/5">
                <div>
                    <p class="font-mono text-xs text-sky-400"><?= e($tk['ticket_id']) ?></p>
                    <p class="text-sm font-medium mt-1"><?= e($tk['subject']) ?></p>
                    <?php if (!empty($tk['txn_reference'])): ?>
                    <p class="text-xs text-gray-500 mt-1 font-mono">Txn <?= e($tk['txn_reference']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="text-right"><?= statusBadge((string)$tk['status']) ?><p class="text-xs text-gray-500 mt-1"><?= formatDate($tk['updated_at'] ?? $tk['created_at']) ?></p></div>
            </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
