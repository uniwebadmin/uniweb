<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
require_once __DIR__ . '/includes/customer_portal.php';
ensureCustomerPortalSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['ticket_db_id'] ?? 0);
    $reply = trim((string)($_POST['admin_reply'] ?? ''));
    $status = (string)($_POST['status'] ?? 'in_progress');
    $t = getCustomerTicketById($id);
    if (!$t) {
        flash('error', 'Complaint not found.');
        redirect('admin_customer_tickets.php');
    }
    $admin = function_exists('getAdmin') ? getAdmin() : null;
    $isStaff = function_exists('isStaffUser') && isStaffUser() && !(function_exists('isSuperAdmin') && isSuperAdmin());
    $senderType = $isStaff ? 'staff' : 'admin';
    $actor = (string)($admin['name'] ?? ($_SESSION['admin_username'] ?? 'Support'));
    $res = replyToCustomerTicket($id, $senderType, $reply, $status, $actor);
    if ($res['ok'] && function_exists('logStaffActivity')) {
        $snip = function_exists('mb_substr') ? mb_substr($reply, 0, 120) : substr($reply, 0, 120);
        logStaffActivity('customer_ticket_reply', $t['ticket_id'] . ' — ' . $snip, $t['merchant_id'] !== null ? (int)$t['merchant_id'] : null, 'customer_ticket', (string)$t['ticket_id']);
    }
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect('admin_customer_tickets.php?id=' . (int)$id);
}

$viewId = (int)($_GET['id'] ?? 0);
$view = $viewId ? getCustomerTicketById($viewId) : null;
$statusFilter = (string)($_GET['status'] ?? '');
$filterMerchantId = (int)($_GET['merchant_id'] ?? 0);
$ticketQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
if (!$view && $ticketQ !== '' && preg_match('/^CT/i', $ticketQ)) {
    try {
        $st = getDB()->prepare('SELECT id FROM customer_tickets WHERE ticket_id = ? LIMIT 1');
        $st->execute([$ticketQ]);
        $found = (int)($st->fetchColumn() ?: 0);
        if ($found > 0) {
            $extra = '';
            if ($statusFilter !== '') {
                $extra .= '&status=' . rawurlencode($statusFilter);
            }
            if ($filterMerchantId > 0) {
                $extra .= '&merchant_id=' . $filterMerchantId;
            }
            redirect('admin_customer_tickets.php?id=' . $found . $extra);
        }
    } catch (Throwable $e) { /* ok */ }
}
$tickets = getAllCustomerTickets($statusFilter ?: null);
if ($filterMerchantId > 0) {
    $tickets = array_values(array_filter($tickets, static function ($tk) use ($filterMerchantId) {
        return (int)($tk['merchant_id'] ?? 0) === $filterMerchantId;
    }));
}
if ($ticketQ !== '') {
    $tickets = array_values(array_filter($tickets, static function ($tk) use ($ticketQ) {
        $hay = strtolower(($tk['ticket_id'] ?? '') . ' ' . ($tk['subject'] ?? '') . ' ' . ($tk['customer_phone'] ?? '') . ' ' . ($tk['txn_reference'] ?? '') . ' ' . ($tk['business_name'] ?? ''));
        return str_contains($hay, strtolower($ticketQ));
    }));
}

$linkedTxn = null;
$customerHistory = [];
if ($view) {
    if (!empty($view['txn_reference'])) {
        $linkedTxn = findCustomerOwnedTransaction((string)$view['customer_phone'], (string)$view['txn_reference']);
        if (!$linkedTxn) {
            try {
                $st = getDB()->prepare('SELECT t.*, m.business_name FROM transactions t LEFT JOIN merchants m ON m.id = t.merchant_id WHERE t.txn_id = ? LIMIT 1');
                $st->execute([(string)$view['txn_reference']]);
                $linkedTxn = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $linkedTxn = null;
            }
        }
    }
    $customerHistory = getCustomerTransactions((string)$view['customer_phone'], 20);
}

$pageTitle = 'Customer Complaints';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-400">Grievances raised by payers from the Customer Portal. Visible to admin &amp; ops/support staff. Merchant sees only their own tickets.</p>
        <div class="flex gap-2 text-xs flex-wrap">
            <?php foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lbl):
                $href = '?status=' . rawurlencode($k);
                if ($filterMerchantId > 0) {
                    $href .= '&merchant_id=' . $filterMerchantId;
                }
            ?>
            <a href="<?= e($href) ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $k ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php if ($filterMerchantId > 0): ?>
    <div class="glass rounded-xl p-3 border border-sky-500/30 text-xs text-sky-200 flex flex-wrap items-center justify-between gap-2">
        <span>Filtered to merchant #<?= (int)$filterMerchantId ?> <?= adminMerchantLink($filterMerchantId, 'Open merchant') ?></span>
        <a href="admin_customer_tickets.php<?= $statusFilter !== '' ? '?status=' . rawurlencode($statusFilter) : '' ?>" class="text-sky-400 hover:underline">Clear filter</a>
    </div>
    <?php endif; ?>

    <?php if ($view): ?>
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
            <div class="min-w-0">
                <p class="font-mono text-sm text-sky-400 break-all"><?= e($view['ticket_id']) ?></p>
                <h2 class="text-lg font-semibold mt-1 break-words"><?= e($view['subject']) ?></h2>
                <p class="text-xs text-gray-500 mt-1 break-words">
                    <a href="<?= e(adminCustomerHistoryUrl((string)$view['customer_phone'])) ?>" class="text-sky-400 hover:underline">+91 <?= e($view['customer_phone']) ?></a>
                    <?php if (!empty($view['merchant_id'])): ?>
                    · <?= adminMerchantLink((int)$view['merchant_id'], $view['business_name'] ?: ('Merchant #' . (int)$view['merchant_id'])) ?>
                    <?php elseif ($view['business_name']): ?> · <?= e($view['business_name']) ?><?php endif; ?>
                </p>
            </div>
            <?= statusBadge((string)$view['status']) ?>
        </div>

        <?php if ($linkedTxn || !empty($view['txn_reference'])): ?>
        <div class="mb-5 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
            <p class="text-[10px] uppercase tracking-wide text-amber-300 font-semibold mb-2">Linked payment (complaint)</p>
            <?php if ($linkedTxn): ?>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-mono text-sky-300 text-sm"><?= txnDetailLink((string)$linkedTxn['txn_id']) ?></p>
                    <p class="text-2xl font-bold text-white mt-1"><?= formatMoney((float)$linkedTxn['amount']) ?></p>
                    <p class="text-xs text-gray-400 mt-2"><?= formatDate($linkedTxn['created_at']) ?> · <?= statusBadge((string)$linkedTxn['status']) ?> · <?= e(paymentMethodLabel($linkedTxn['payment_method'] ?? '')) ?></p>
                    <?php if (!empty($linkedTxn['business_name'])): ?>
                    <p class="text-xs text-gray-500 mt-1"><?= e($linkedTxn['business_name']) ?></p>
                    <?php endif; ?>
                </div>
                <div class="flex flex-col gap-2">
                    <a href="<?= e(transactionDetailUrl((string)$linkedTxn['txn_id'])) ?>" class="btn-primary text-sm px-4 py-2 text-center">Open full details</a>
                    <a href="<?= e(adminCustomerHistoryUrl((string)$view['customer_phone']) . '&txn=' . rawurlencode((string)$linkedTxn['txn_id'])) ?>" class="text-sm text-center glass px-4 py-2 rounded-lg text-sky-300 hover:text-white">Customer history →</a>
                    <?php if (strtolower((string)$linkedTxn['status']) === 'success'): ?>
                    <a href="transaction_detail.php?txn=<?= rawurlencode((string)$linkedTxn['txn_id']) ?>#refund" class="text-sm text-center bg-red-500/20 text-red-300 border border-red-500/40 px-4 py-2 rounded-lg font-semibold hover:bg-red-500/30">Refund this payment</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-sm text-amber-100 font-mono">Txn <?= e((string)$view['txn_reference']) ?> <span class="text-gray-500">(not found in DB)</span></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($view['message']) ?></p>
        <p class="text-xs text-gray-600 mt-2"><?= formatDate($view['created_at']) ?></p>
        <?php foreach (getCustomerTicketMessages((int)$view['id']) as $msg):
            $stype = (string)($msg['sender_type'] ?? 'customer');
            $label = customerTicketSenderLabel($stype, $msg['sender_label'] ?? null);
            if ($stype === 'customer') {
                $label = 'Customer';
            }
        ?>
        <div class="mt-4 rounded-lg p-3 sm:p-4 border <?= $stype === 'customer' ? 'bg-sky-500/5 border-sky-500/20' : 'bg-brand-500/5 border-brand-500/20' ?>">
            <p class="<?= $stype === 'customer' ? 'text-sky-400' : 'text-brand-400' ?> text-xs mb-2 font-semibold"><?= e($label) ?></p>
            <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
            <p class="text-xs text-gray-600 mt-2"><?= formatDate($msg['created_at']) ?></p>
        </div>
        <?php endforeach; ?>

        <?php if ($customerHistory): ?>
        <div class="mt-6 border-t border-gray-800 pt-5">
            <div class="flex flex-wrap justify-between gap-2 mb-3">
                <h3 class="font-semibold text-sm">Customer recent payments</h3>
                <a href="<?= e(adminCustomerHistoryUrl((string)$view['customer_phone']) . (!empty($view['txn_reference']) ? '&txn=' . rawurlencode((string)$view['txn_reference']) : '')) ?>" class="text-xs text-sky-400 hover:underline">Full history →</a>
            </div>
            <div class="overflow-x-auto rounded-lg border border-gray-800">
                <table class="w-full text-xs min-w-[560px]">
                    <thead class="text-gray-500 uppercase bg-dark-900/50"><tr>
                        <th class="px-3 py-2 text-left">Txn</th><th class="px-3 py-2 text-left">Amount</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Date</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php foreach ($customerHistory as $ht):
                            $hit = !empty($view['txn_reference']) && strcasecmp((string)$ht['txn_id'], (string)$view['txn_reference']) === 0;
                        ?>
                        <tr class="<?= $hit ? 'bg-amber-500/10' : '' ?>">
                            <td class="px-3 py-2 font-mono"><?= txnDetailLink((string)$ht['txn_id']) ?></td>
                            <td class="px-3 py-2"><?= formatMoney((float)$ht['amount']) ?></td>
                            <td class="px-3 py-2"><?= statusBadge((string)$ht['status']) ?></td>
                            <td class="px-3 py-2 text-gray-500"><?= formatDate($ht['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" class="space-y-3 mt-6 border-t border-gray-800 pt-5">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="ticket_db_id" value="<?= (int)$view['id'] ?>">
            <label class="sr-only" for="admin_reply">Reply to customer</label>
            <textarea id="admin_reply" name="admin_reply" rows="3" maxlength="5000" class="input-field w-full" placeholder="Reply to the customer…" aria-label="Reply to customer"></textarea>
            <div class="flex flex-col sm:flex-row sm:flex-wrap items-stretch sm:items-center gap-3">
                <select name="status" class="input-field w-full sm:w-auto" aria-label="Ticket status">
                    <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $view['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary w-full sm:w-auto px-5 py-2.5 text-sm">Save reply &amp; status</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Complaints</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[820px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Ticket</th>
                    <th class="px-5 py-3 text-left">Customer</th>
                    <th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Subject</th>
                    <th class="px-5 py-3 text-left">Merchant</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Updated</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($tickets)): ?>
                    <tr><td colspan="7" class="px-5 py-12 text-center text-gray-500">No customer complaints<?= $statusFilter ? ' in this status' : '' ?>.</td></tr>
                    <?php else: foreach ($tickets as $tk):
                        $rowHref = '?id=' . (int)$tk['id'];
                        if ($statusFilter !== '') {
                            $rowHref .= '&status=' . rawurlencode($statusFilter);
                        }
                        if ($filterMerchantId > 0) {
                            $rowHref .= '&merchant_id=' . $filterMerchantId;
                        }
                    ?>
                    <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='<?= e($rowHref) ?>'">
                        <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($tk['ticket_id']) ?></td>
                        <td class="px-5 py-3 text-xs" onclick="event.stopPropagation()"><a href="<?= e(adminCustomerHistoryUrl((string)$tk['customer_phone'])) ?>" class="text-sky-400 hover:underline">+91 <?= e($tk['customer_phone']) ?></a></td>
                        <td class="px-5 py-3 font-mono text-xs" onclick="event.stopPropagation()"><?= !empty($tk['txn_reference']) ? txnDetailLink((string)$tk['txn_reference']) : '—' ?></td>
                        <td class="px-5 py-3 max-w-[240px] truncate"><?= e($tk['subject']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400" onclick="event.stopPropagation()">
                            <?php if (!empty($tk['merchant_id'])): ?>
                            <?= adminMerchantLink((int)$tk['merchant_id'], $tk['business_name'] ?: ('#' . (int)$tk['merchant_id'])) ?>
                            <?php else: ?>
                            —
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3"><?= statusBadge((string)$tk['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($tk['updated_at']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
