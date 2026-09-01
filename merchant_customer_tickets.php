<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireLogin();
requireMerchantTeamCapability('support');
ensureCustomerPortalSchema();

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['ticket_db_id'] ?? 0);
    $reply = trim((string)($_POST['merchant_reply'] ?? ''));
    $status = (string)($_POST['status'] ?? 'in_progress');
    $t = getMerchantCustomerTicket($merchantId, $id);
    if (!$t) {
        flash('error', 'Complaint not found for your account.');
        redirect('merchant_customer_tickets.php');
    }
    $actor = (string)($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant');
    $res = replyToCustomerTicket($id, 'merchant', $reply, $status, $actor);
    flash($res['ok'] ? 'success' : 'error', $res['message']);
    redirect('merchant_customer_tickets.php?id=' . (int)$id);
}

$viewId = (int)($_GET['id'] ?? 0);
$view = $viewId ? getMerchantCustomerTicket($merchantId, $viewId) : null;
$statusFilter = (string)($_GET['status'] ?? '');
$ticketQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
if (!function_exists('wiringMerchantComplaintQueryState') && is_file(__DIR__ . '/includes/wiring_deep_link_workflow.php')) {
    require_once __DIR__ . '/includes/wiring_deep_link_workflow.php';
}
$complaintQueryState = function_exists('wiringMerchantComplaintQueryState')
    ? wiringMerchantComplaintQueryState($merchantId, $ticketQ, $view !== null)
    : ['redirect' => '', 'focusTicketId' => ''];
if (($complaintQueryState['redirect'] ?? '') !== '') {
    $extra = '';
    if ($statusFilter !== '') {
        $extra .= '&status=' . rawurlencode($statusFilter);
    }
    redirect($complaintQueryState['redirect'] . $extra);
}
$focusTicketId = (string)($complaintQueryState['focusTicketId'] ?? '');
$listParams = listPageParams(20);
$allTickets = getMerchantCustomerTickets($merchantId, $statusFilter ?: null);
if ($ticketQ !== '') {
    $allTickets = array_values(array_filter($allTickets, static function ($tk) use ($ticketQ) {
        $hay = strtolower(($tk['ticket_id'] ?? '') . ' ' . ($tk['subject'] ?? '') . ' ' . ($tk['customer_phone'] ?? '') . ' ' . ($tk['txn_reference'] ?? ''));
        return str_contains($hay, strtolower($ticketQ));
    }));
}
$ticketTotal = count($allTickets);
$tickets = array_slice($allTickets, $listParams['offset'], $listParams['perPage']);
$openCount = getPendingMerchantCustomerTicketCount($merchantId);

$pageTitle = 'Customer Complaints';
require_once __DIR__ . '/header.php';
if (!function_exists('renderComplianceSupportPathPanel')) {
    require_once __DIR__ . '/includes/compliance_workflow.php';
}
?>
<div class="space-y-6">
<?= renderComplianceSupportPathPanel('ct') ?>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <p class="text-sm text-gray-400">Complaints from payers on <strong class="text-white">your</strong> transactions only. Replies notify the customer in-app and via WhatsApp/SMS when configured.</p>
            <?php if ($openCount > 0): ?>
            <p class="text-xs text-amber-400 mt-1"><?= (int)$openCount ?> open / in progress</p>
            <?php endif; ?>
        </div>
        <div class="flex gap-2 text-xs flex-wrap">
            <?php foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'] as $k => $lbl): ?>
            <a href="?status=<?= e($k) ?>&q=<?= rawurlencode($ticketQ) ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $k ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>"><?= e($lbl) ?></a>
            <?php endforeach; ?>
            <?= renderExportCsvLink('export_customer_tickets.php?' . http_build_query(['status' => $statusFilter])) ?>
        </div>
    </div>

    <?php if ($view): ?>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
            <div>
                <p class="font-mono text-sm text-sky-400"><?= e($view['ticket_id']) ?></p>
                <h2 class="text-lg font-semibold mt-1"><?= e($view['subject']) ?></h2>
                <p class="text-xs text-gray-500 mt-1">+91 <?= e($view['customer_phone']) ?><?= !empty($view['txn_reference']) ? ' · Txn ' . e($view['txn_reference']) : '' ?></p>
            </div>
            <?= statusBadge((string)$view['status']) ?>
        </div>
        <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($view['message']) ?></p>
        <p class="text-xs text-gray-600 mt-2"><?= formatDate($view['created_at']) ?></p>
        <?php foreach (getCustomerTicketMessages((int)$view['id']) as $msg):
            $stype = (string)($msg['sender_type'] ?? 'customer');
            $label = customerTicketSenderLabel($stype, $msg['sender_label'] ?? null);
            if ($stype === 'customer') {
                $label = 'Customer';
            }
        ?>
        <div class="mt-4 rounded-lg p-4 border <?= $stype === 'customer' ? 'bg-sky-500/5 border-sky-500/20' : 'bg-brand-500/5 border-brand-500/20' ?>">
            <p class="<?= $stype === 'customer' ? 'text-sky-400' : 'text-brand-400' ?> text-xs mb-2 font-semibold"><?= e($label) ?></p>
            <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
            <p class="text-xs text-gray-600 mt-2"><?= formatDate($msg['created_at']) ?></p>
        </div>
        <?php endforeach; ?>
        <form method="POST" class="space-y-3 mt-6 border-t border-gray-800 pt-5">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="ticket_db_id" value="<?= (int)$view['id'] ?>">
            <textarea name="merchant_reply" rows="3" maxlength="5000" class="input-field" placeholder="Reply to the customer…" aria-label="Reply to customer"></textarea>
            <div class="flex flex-wrap items-center gap-3">
                <select name="status" class="input-field w-auto" aria-label="Ticket status">
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
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Complaints</h2>
            <form method="GET" class="flex gap-2 items-center">
                <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
                <label class="sr-only" for="ticket-q">Search complaints</label>
                <input id="ticket-q" type="search" name="q" value="<?= e($ticketQ) ?>" placeholder="Ticket / subject / phone" class="input-field text-sm" aria-label="Search complaints">
                <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[640px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">Ticket</th>
                    <th class="px-5 py-3 text-left">Customer</th>
                    <th class="px-5 py-3 text-left">Subject</th>
                    <th class="px-5 py-3 text-left">Txn</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Updated</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php if (empty($tickets)): ?>
                    <tr><td colspan="6" class="p-0"><?= renderMerchantEmptyState('No customer complaints yet', 'When a payer raises a complaint on your transaction, it will appear here for you to reply.', null, null) ?></td></tr>
                    <?php else: foreach ($tickets as $tk): ?>
                    <tr id="complaint-<?= e($tk['ticket_id']) ?>" class="hover:bg-white/5 cursor-pointer <?= $focusTicketId !== '' && strcasecmp((string)$tk['ticket_id'], $focusTicketId) === 0 ? 'bg-sky-500/10 ring-1 ring-sky-500/30' : '' ?>" onclick="location.href='?id=<?= (int)$tk['id'] ?><?= $statusFilter !== '' ? '&status=' . urlencode($statusFilter) : '' ?><?= $ticketQ !== '' ? '&q=' . urlencode($ticketQ) : '' ?>'">
                        <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($tk['ticket_id']) ?></td>
                        <td class="px-5 py-3 text-xs">+91 <?= e($tk['customer_phone']) ?></td>
                        <td class="px-5 py-3 max-w-[240px] truncate"><?= e($tk['subject']) ?></td>
                        <td class="px-5 py-3 font-mono text-xs text-gray-400"><?= e($tk['txn_reference'] ?: '—') ?></td>
                        <td class="px-5 py-3"><?= statusBadge((string)$tk['status']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-500 whitespace-nowrap"><?= formatDate($tk['updated_at']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= renderListPagination($listParams['page'], $ticketTotal, $listParams['perPage'], ['status' => $statusFilter, 'q' => $ticketQ]) ?>
    </div>
</div>
<?php if ($focusTicketId !== '' && !$view): ?>
<script>document.getElementById('complaint-<?= e($focusTicketId) ?>')?.scrollIntoView({block:'center',behavior:'smooth'});</script>
<?php endif; ?>
<?php require_once __DIR__ . '/footer.php'; ?>
