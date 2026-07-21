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
    if ($reply !== '') {
        addCustomerTicketMessage($id, 'admin', $reply);
        if (function_exists('sendWhatsAppTextMessage') && getSetting('whatsapp_enabled', '0') === '1') {
            try { sendWhatsAppTextMessage((string)$t['customer_phone'], 'UniWeb support replied to your complaint ' . $t['ticket_id'] . ': ' . mb_substr($reply, 0, 300)); } catch (Throwable $e) { /* best effort */ }
        }
    }
    setCustomerTicketStatus($id, $status);
    if (function_exists('logStaffActivity')) {
        logStaffActivity('customer_ticket_reply', $t['ticket_id'] . ' — ' . mb_substr($reply, 0, 120), $t['merchant_id'] !== null ? (int)$t['merchant_id'] : null, 'customer_ticket', (string)$t['ticket_id']);
    }
    flash('success', 'Reply saved for complaint ' . $t['ticket_id'] . '.');
    redirect('admin_customer_tickets.php?id=' . (int)$id);
}

$viewId = (int)($_GET['id'] ?? 0);
$view = $viewId ? getCustomerTicketById($viewId) : null;
$statusFilter = (string)($_GET['status'] ?? '');
$tickets = getAllCustomerTickets($statusFilter ?: null);

$pageTitle = 'Customer Complaints';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-400">Grievances raised by payers from the Customer Portal</p>
        <div class="flex gap-2 text-xs">
            <?php foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lbl): ?>
            <a href="?status=<?= e($k) ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $k ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($view): ?>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
            <div>
                <p class="font-mono text-sm text-sky-400"><?= e($view['ticket_id']) ?></p>
                <h2 class="text-lg font-semibold mt-1"><?= e($view['subject']) ?></h2>
                <p class="text-xs text-gray-500 mt-1">+91 <?= e($view['customer_phone']) ?><?= $view['business_name'] ? ' · ' . e($view['business_name']) : '' ?><?= !empty($view['txn_reference']) ? ' · Txn ' . e($view['txn_reference']) : '' ?></p>
            </div>
            <?= statusBadge((string)$view['status']) ?>
        </div>
        <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($view['message']) ?></p>
        <p class="text-xs text-gray-600 mt-2"><?= formatDate($view['created_at']) ?></p>
        <?php foreach (getCustomerTicketMessages((int)$view['id']) as $msg): ?>
        <div class="mt-4 rounded-lg p-4 border <?= $msg['sender_type'] === 'admin' ? 'bg-brand-500/5 border-brand-500/20' : 'bg-sky-500/5 border-sky-500/20' ?>">
            <p class="<?= $msg['sender_type'] === 'admin' ? 'text-brand-400' : 'text-sky-400' ?> text-xs mb-2 font-semibold"><?= $msg['sender_type'] === 'admin' ? 'Support Team' : 'Customer' ?></p>
            <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
            <p class="text-xs text-gray-600 mt-2"><?= formatDate($msg['created_at']) ?></p>
        </div>
        <?php endforeach; ?>
        <form method="POST" class="space-y-3 mt-6 border-t border-gray-800 pt-5">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="ticket_db_id" value="<?= (int)$view['id'] ?>">
            <textarea name="admin_reply" rows="3" maxlength="5000" class="input-field" placeholder="Reply to the customer…"></textarea>
            <div class="flex flex-wrap items-center gap-3">
                <select name="status" class="input-field w-auto">
                    <?php foreach (['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lbl): ?>
                    <option value="<?= e($k) ?>" <?= $view['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary px-5 py-2.5 text-sm">Save reply &amp; status</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Complaints</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Ticket</th>
                    <th class="px-5 py-3 text-left">Customer</th>
                    <th class="px-5 py-3 text-left">Subject</th>
                    <th class="px-5 py-3 text-left">Merchant</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Updated</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($tickets)): ?>
                    <tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No customer complaints<?= $statusFilter ? ' in this status' : '' ?>.</td></tr>
                    <?php else: foreach ($tickets as $tk): ?>
                    <tr class="hover:bg-white/5 cursor-pointer" onclick="location.href='?id=<?= (int)$tk['id'] ?><?= $statusFilter ? '&status=' . e($statusFilter) : '' ?>'">
                        <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($tk['ticket_id']) ?></td>
                        <td class="px-5 py-3 text-xs">+91 <?= e($tk['customer_phone']) ?></td>
                        <td class="px-5 py-3 max-w-[240px] truncate"><?= e($tk['subject']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?= e($tk['business_name'] ?: '—') ?></td>
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
