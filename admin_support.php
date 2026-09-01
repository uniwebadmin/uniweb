<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
if (!function_exists('listPublicContactInquiries') && is_file(__DIR__ . '/includes/schema_ensure.php')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
if (!function_exists('ensureSupportTicketTable')) {
    require_once __DIR__ . '/includes/demo_tour.php';
}
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
ensureSupportTicketTable();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (($_POST['action'] ?? '') === 'close_inquiry') {
        $inquiryId = trim((string)($_POST['inquiry_id'] ?? ''));
        if (function_exists('closePublicContactInquiry') && closePublicContactInquiry($inquiryId)) {
            if (function_exists('logStaffActivity')) {
                logStaffActivity('contact_inquiry_closed', $inquiryId, null, 'contact_inquiry', $inquiryId);
            }
            flash('success', 'Website inquiry ' . $inquiryId . ' marked closed.');
        } else {
            flash('error', 'Could not close that website inquiry.');
        }
        redirect('admin_support.php');
    }
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
    $returnQ = mb_substr(trim((string)($_POST['_q'] ?? $_GET['q'] ?? '')), 0, 100);
    redirect('admin_support.php' . ($returnQ !== '' ? ('?q=' . rawurlencode($returnQ)) : ''));
}

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$focusTicketId = '';
if (!function_exists('wiringAdminSupportQueryState') && is_file(__DIR__ . '/includes/wiring_deep_link_workflow.php')) {
    require_once __DIR__ . '/includes/wiring_deep_link_workflow.php';
}
$supportQueryState = function_exists('wiringAdminSupportQueryState')
    ? wiringAdminSupportQueryState($_GET)
    : ['q' => $q, 'focusTicketId' => ''];
$q = (string)$supportQueryState['q'];
$focusTicketId = (string)$supportQueryState['focusTicketId'];
$statusFilter = trim($_GET['status'] ?? 'all');
$filterMerchantId = (int)($_GET['merchant_id'] ?? 0);
$sql = 'SELECT t.*, m.business_name, m.email FROM support_tickets t JOIN merchants m ON t.merchant_id=m.id';
$params = [];
$where = [];
if ($filterMerchantId > 0) {
    $where[] = 't.merchant_id = ?';
    $params[] = $filterMerchantId;
}
if ($statusFilter !== 'all' && in_array($statusFilter, ['open', 'in_progress', 'resolved', 'closed'], true)) {
    $where[] = 't.status = ?';
    $params[] = $statusFilter;
}
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    if ($focusTicketId !== '') {
        $where[] = 'LOWER(TRIM(COALESCE(t.ticket_id,\'\'))) = ?';
        $params[] = strtolower($focusTicketId);
    } else {
        $where[] = '(LOWER(TRIM(COALESCE(t.ticket_id,\'\'))) LIKE ? OR LOWER(TRIM(COALESCE(t.subject,\'\'))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,\'\'))) LIKE ?)';
        array_push($params, $like, $like, $like);
    }
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
if ($focusTicketId !== '' && function_exists('wiringAdminSupportEnsureFocusedTicket')) {
    $tickets = wiringAdminSupportEnsureFocusedTicket($db, $tickets, $focusTicketId);
}
$websiteInquiries = function_exists('listPublicContactInquiries') ? listPublicContactInquiries(30) : [];
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csvRows = [];
    foreach ($tickets as $t) {
        $csvRows[] = [$t['ticket_id'] ?? '', $t['business_name'] ?? '', $t['subject'] ?? '', $t['status'] ?? '', $t['priority'] ?? '', $t['created_at'] ?? ''];
    }
    sendCsvDownload(['Ticket', 'Merchant', 'Subject', 'Status', 'Priority', 'Created'], $csvRows, 'support-tickets-' . date('Y-m-d') . '.csv');
}
$pageTitle = 'Support Tickets';
require_once __DIR__ . '/header.php';
if (!function_exists('renderComplianceSupportPathPanel')) {
    require_once __DIR__ . '/includes/compliance_workflow.php';
}
echo renderComplianceSupportPathPanel('tkt');
?>

<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Admin first — support queue</p>
    <p class="text-xs text-gray-500">Merchant tickets land here for Admin/staff reply. Paste <strong class="text-gray-300">TKT…</strong> in search to jump to that ticket. Payment chargeback disputes: use <a href="admin_disputes.php" class="text-sky-400 hover:underline">Disputes</a> (resolve or single partner forward). Bulk routing later — no new app.</p>
</div>
<?php
$supportEdu = function_exists('wiringAdminSupportEducation') ? wiringAdminSupportEducation() : null;
if (is_array($supportEdu)):
?>
<div class="glass rounded-xl p-3 mb-4 border border-sky-500/20 text-xs text-gray-400">
    <p class="font-semibold text-sky-300 mb-1"><?= e((string)$supportEdu['title']) ?></p>
    <p><?= e((string)$supportEdu['rule']) ?></p>
</div>
<?php endif; ?>
<?php if ($filterMerchantId > 0): ?>
<div class="glass rounded-xl p-3 mb-4 border border-sky-500/30 text-xs text-sky-200 flex flex-wrap items-center justify-between gap-2">
    <span>Filtered to merchant #<?= (int)$filterMerchantId ?></span>
    <a href="admin_support.php" class="text-sky-400 hover:underline">Clear filter</a>
</div>
<?php endif; ?>

<?= uxListToolbar(uxExportCsvLink(array_filter(['q' => $q ?: null, 'status' => $statusFilter !== 'all' ? $statusFilter : null, 'merchant_id' => $filterMerchantId > 0 ? $filterMerchantId : null]))) ?>

<?php if (!empty($websiteInquiries)): ?>
<div class="glass rounded-xl p-6 mb-6 border border-sky-500/20">
    <h2 class="font-semibold mb-1">Website contact form</h2>
    <p class="text-xs text-gray-500 mb-4">Public Contact page messages. Saved even if email is delayed. Reply from <?= e(COMPANY_SUPPORT_EMAIL) ?> and keep the CTI reference.</p>
    <div class="space-y-3">
        <?php foreach ($websiteInquiries as $inq): ?>
        <div class="border border-gray-800 rounded-lg p-4">
            <div class="flex flex-wrap justify-between gap-2 mb-2">
                <div>
                    <span class="font-mono text-xs text-gray-500"><?= e((string)$inq['inquiry_id']) ?></span>
                    <?= !empty($inq['email_sent']) ? '<span class="text-xs text-emerald-400 ml-2">Email sent</span>' : '<span class="text-xs text-amber-400 ml-2">Email not sent</span>' ?>
                    <?= statusBadge((string)($inq['status'] ?? 'open')) ?>
                </div>
                <span class="text-xs text-gray-500"><?= e(formatDate((string)($inq['created_at'] ?? ''))) ?></span>
            </div>
            <h3 class="font-semibold text-sm"><?= e((string)$inq['subject']) ?></h3>
            <p class="text-xs text-gray-500 mt-1"><?= e((string)$inq['name']) ?> — <a class="text-sky-400" href="mailto:<?= e((string)$inq['email']) ?>"><?= e((string)$inq['email']) ?></a></p>
            <p class="text-sm text-gray-400 mt-2 whitespace-pre-wrap"><?= e((string)$inq['message']) ?></p>
            <?php if (($inq['status'] ?? '') === 'open'): ?>
            <form method="POST" class="mt-3">
                <input type="hidden" name="csrf_token" value="<?= e(csrfToken()) ?>">
                <input type="hidden" name="action" value="close_inquiry">
                <input type="hidden" name="inquiry_id" value="<?= e((string)$inq['inquiry_id']) ?>">
                <button type="submit" class="text-xs border border-gray-700 px-3 py-1.5 rounded-lg text-gray-300 hover:bg-white/5">Mark closed</button>
            </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($focusTicketId !== ''): ?>
<div class="glass rounded-xl p-3 mb-4 border border-sky-500/30 text-xs text-sky-200 flex flex-wrap items-center justify-between gap-2">
    <span>Showing ticket <strong class="font-mono"><?= e($focusTicketId) ?></strong> — other tickets collapsed below.</span>
    <a href="admin_support.php" class="text-sky-400 hover:underline">Show all tickets</a>
</div>
<?php endif; ?>

<form method="GET" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end no-print" aria-label="Filter support tickets">
    <div class="flex-1 min-w-[180px]"><?= uxLabel('support-q', 'Search') ?><input id="support-q" name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Ticket ID / subject / merchant"></div>
    <div><?= uxLabel('support-status', 'Status') ?><select id="support-status" name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $statusFilter===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>

<div class="space-y-4">
    <?php if (empty($tickets)): ?>
    <?= uxEmptyState('No support tickets yet', 'Merchant support requests from the portal appear here for your team to reply.') ?>
    <?php else: foreach ($tickets as $t):
        $isFocused = $focusTicketId !== '' && strcasecmp((string)$t['ticket_id'], $focusTicketId) === 0;
        if ($focusTicketId !== '' && !$isFocused):
    ?>
    <a href="admin_support.php?q=<?= e(rawurlencode((string)$t['ticket_id'])) ?>" class="glass rounded-xl px-4 py-3 flex flex-wrap items-center justify-between gap-2 border border-gray-800 hover:border-sky-500/30 text-sm">
        <span class="font-mono text-xs text-gray-500"><?= e($t['ticket_id']) ?></span>
        <span class="text-gray-300 truncate max-w-md"><?= e($t['subject']) ?></span>
        <?= statusBadge($t['status']) ?>
        <span class="text-xs text-sky-400">Open ticket →</span>
    </a>
    <?php
            continue;
        endif;
        $ticketAgeSeconds = max(0, time() - (strtotime((string)($t['created_at'] ?? '')) ?: time()));
        $ticketAgeHours = (int)floor($ticketAgeSeconds / 3600);
        $ticketOverdue = in_array($t['status'] ?? '', ['open', 'in_progress'], true) && $ticketAgeSeconds >= 86400;
        $threadStmt = $db->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC');
        $threadStmt->execute([(int)$t['id']]);
        $thread = $threadStmt->fetchAll();
        $hasAdminThreadReply = (bool)array_filter($thread, static fn(array $row): bool => $row['sender_type'] === 'admin');
    ?>
    <div class="glass rounded-xl p-6<?= $isFocused ? ' ring-2 ring-sky-500/50 bg-sky-500/10' : '' ?>" id="ticket-<?= e($t['ticket_id']) ?>"<?= $isFocused ? ' style="scroll-margin-top:6rem"' : '' ?>>
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
            <?php if ($q !== ''): ?><input type="hidden" name="_q" value="<?= e($q) ?>"><?php endif; ?>
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
<?php if ($focusTicketId !== ''): ?>
<script>document.getElementById('ticket-<?= e($focusTicketId) ?>')?.scrollIntoView({block:'start',behavior:'smooth'});</script>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
