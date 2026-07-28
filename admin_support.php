<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
ensureSupportTicketTable();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $reply = trim($_POST['admin_reply'] ?? '');
    $status = $_POST['status'] ?? 'in_progress';
    if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) $status = 'in_progress';
    if ($ticketId && $reply) {
        $t = $db->prepare('SELECT merchant_id, ticket_id FROM support_tickets WHERE id = ?');
        $t->execute([$ticketId]);
        $row = $t->fetch();
        if (!$row) {
            flash('error', 'Ticket not found.');
            redirect('admin_support.php');
        }
        requireMerchantAccess((int)$row['merchant_id']);
        $db->prepare('UPDATE support_tickets SET admin_reply = ?, status = ? WHERE id = ?')
            ->execute([$reply, $status, $ticketId]);
        $db->prepare("INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'admin', ?, ?)")
            ->execute([$ticketId, (int)($_SESSION['admin_id'] ?? 0), $reply]);
        createNotification((int)$row['merchant_id'], 'Support Reply: ' . $row['ticket_id'], $reply);
        logStaffActivity('support_reply', $row['ticket_id'] . ' — ' . mb_substr($reply, 0, 120), (int)$row['merchant_id'], 'support_ticket', $row['ticket_id']);
        flash('success', 'Reply sent to merchant.');
    }
    redirect('admin_support.php');
}

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = trim($_GET['status'] ?? 'all');
$sql = 'SELECT t.*, m.business_name, m.email FROM support_tickets t JOIN merchants m ON t.merchant_id=m.id';
$params = [];
$where = [];
if ($statusFilter !== 'all' && in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where[] = '(LOWER(TRIM(COALESCE(t.ticket_id,\'\'))) LIKE ? OR LOWER(TRIM(COALESCE(t.subject,\'\'))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,\'\'))) LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY FIELD(t.status,"open","in_progress","resolved","closed"), t.created_at DESC LIMIT 80';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();
if (!isSuperAdmin()) {
    $tickets = array_values(array_filter($tickets, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
}
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($tickets as $t) {
        $csvRows[] = [$t['ticket_id'] ?? '', $t['business_name'] ?? '', $t['subject'] ?? '', $t['status'] ?? '', $t['priority'] ?? '', $t['created_at'] ?? ''];
    }
    sendCsvDownload(['Ticket', 'Merchant', 'Subject', 'Status', 'Priority', 'Created'], $csvRows, 'support-tickets-' . date('Y-m-d') . '.csv');
}
$pageTitle = 'Support Tickets';
require_once __DIR__ . '/header.php';
?>

<?= uxListToolbar(uxExportCsvLink(array_filter(['q' => $q ?: null, 'status' => $statusFilter !== 'all' ? $statusFilter : null]))) ?>
<form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end no-print" aria-label="Filter support tickets">
    <div class="flex-1 min-w-[180px]"><?= uxLabel('support-q', 'Search') ?><input id="support-q" name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Ticket ID / subject / merchant"></div>
    <div><?= uxLabel('support-status', 'Status') ?><select id="support-status" name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $statusFilter===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>

<div class="space-y-4">
    <?php if (empty($tickets)): ?>
    <?= uxEmptyState('No support tickets yet', 'Merchant support requests from the portal appear here for your team to reply.') ?>
    <?php else: foreach ($tickets as $t):
        $ticketAgeSeconds = max(0, time() - (strtotime((string)($t['created_at'] ?? '')) ?: time()));
        $ticketAgeHours = (int)floor($ticketAgeSeconds / 3600);
        $ticketOverdue = in_array($t['status'] ?? '', ['open', 'in_progress'], true) && $ticketAgeSeconds >= 86400;
        $threadStmt = $db->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC');
        $threadStmt->execute([(int)$t['id']]);
        $thread = $threadStmt->fetchAll();
        $hasAdminThreadReply = (bool)array_filter($thread, static fn(array $row): bool => $row['sender_type'] === 'admin');
    ?>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap justify-between gap-2 mb-3">
            <div>
                <span class="font-mono text-xs text-gray-500"><?= e($t['ticket_id']) ?></span>
                <h3 class="font-semibold mt-1"><?= e($t['subject']) ?></h3>
                <p class="text-xs text-gray-500"><?= adminMerchantLink((int)$t['merchant_id'], $t['business_name']) ?> — <?= e($t['email']) ?></p>
            </div>
            <div class="flex gap-2 items-start flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$t['merchant_id'])) ?>" class="text-xs text-emerald-400 border border-emerald-500/30 px-3 py-1 rounded-lg">View Merchant</a>
                <?= statusBadge($t['priority']) ?>
                <?= statusBadge($t['status']) ?>
                <span class="text-xs <?= $ticketOverdue ? 'text-red-400 font-semibold' : 'text-gray-500' ?>"><?= $ticketAgeHours < 1 ? '< 1h' : $ticketAgeHours . 'h' ?><?= $ticketOverdue ? ' · SLA overdue' : '' ?></span>
            </div>
        </div>
        <p class="text-sm text-gray-400 mb-4"><?= e($t['message']) ?></p>
        <?php if ($t['admin_reply'] && !$hasAdminThreadReply): ?>
        <div class="bg-brand-500/5 border border-brand-500/20 rounded-lg p-3 mb-4 text-sm">
            <p class="text-brand-400 text-xs mb-1">Your Reply:</p><?= e($t['admin_reply']) ?>
        </div>
        <?php endif; ?>
        <?php foreach ($thread as $msg): ?>
        <div class="rounded-lg p-3 mb-3 text-sm border <?= $msg['sender_type'] === 'admin' ? 'bg-brand-500/5 border-brand-500/20' : 'bg-sky-500/5 border-sky-500/20' ?>">
            <p class="<?= $msg['sender_type'] === 'admin' ? 'text-brand-500' : 'text-sky-500' ?> text-xs font-semibold mb-1"><?= $msg['sender_type'] === 'admin' ? 'Support Team' : 'Merchant' ?> · <?= formatDate($msg['created_at']) ?></p>
            <p class="text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
        </div>
        <?php endforeach; ?>
        <form method="POST" class="space-y-3 border-t border-gray-800 pt-4" aria-label="Reply to ticket <?= e($t['ticket_id']) ?>">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
            <?= uxLabel('reply-' . (int)$t['id'], 'Reply') ?>
            <textarea id="reply-<?= (int)$t['id'] ?>" name="admin_reply" rows="2" class="input-field mt-1" placeholder="Type your reply..." required></textarea>
            <div class="flex gap-3 items-center">
                <?= uxLabel('status-' . (int)$t['id'], 'Status') ?>
                <select id="status-<?= (int)$t['id'] ?>" name="status" class="input-field w-auto mt-1">
                    <?php foreach (['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $value=>$label): ?>
                    <option value="<?= $value ?>" <?= ($t['status'] ?? '')===$value?'selected':'' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-primary px-4 py-2 text-sm">Send Reply</button>
            </div>
        </form>
        <p class="text-xs text-gray-600 mt-2"><?= formatDate($t['created_at']) ?></p>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
