<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/page_ux.php';
require_once __DIR__ . '/includes/cases_ops.php';
ensureCasesSpineSchema();
if (!function_exists('listPublicContactInquiries') && is_file(__DIR__ . '/includes/schema_ensure.php')) {
    require_once __DIR__ . '/includes/schema_ensure.php';
}
if (!function_exists('ensureSupportTicketTable')) {
    require_once __DIR__ . '/includes/demo_tour.php';
}
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
ensureSupportTicketTable();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['cases_action'])) {
        $casesRedirect = casesOpsHandlePost('admin_support.php' . (isset($_GET['tab']) ? ('?tab=' . urlencode((string)$_GET['tab'])) : ''));
        if ($casesRedirect !== null) {
            redirect($casesRedirect);
        }
    }
}
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
$pageTitle = 'Cases';
$casesTab = casesOpsCurrentTab();
require_once __DIR__ . '/header.php';
if (!function_exists('renderComplianceSupportPathPanel')) {
    require_once __DIR__ . '/includes/compliance_workflow.php';
}
echo renderCasesOpsTabs($casesTab === 'support' ? 'support' : ($casesTab === 'all' ? 'all' : 'support'));
?>

<?php if ($casesTab === 'all'): ?>
<?php
$allTypeFilter = trim((string)($_GET['case_type'] ?? ''));
$allStatus = trim((string)($_GET['status'] ?? 'open'));
$allQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$allMerchantId = (int)($_GET['merchant_id'] ?? 0);
$unifiedCases = casesUnifiedInbox(
    $allTypeFilter !== '' ? $allTypeFilter : null,
    $allStatus !== '' ? $allStatus : 'open',
    $allQ,
    $allMerchantId
);
$partnerChoices = casesOpsPartnerChoices();
?>
<div class="glass rounded-xl p-5 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Cases hub — one inbox</p>
    <p class="text-xs text-gray-500">Support tickets, customer complaints, and disputes in one place. Close here or forward to Registry partner (honest if API not wired).</p>
</div>
<form method="GET" class="glass rounded-xl p-4 mb-4 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[160px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input name="q" value="<?= e($allQ) ?>" class="input-field mt-1 text-sm" placeholder="Ref / subject / merchant"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Type</label><select name="case_type" class="input-field mt-1 text-sm"><option value="">All types</option><?php foreach (casesValidTypes() as $ct): ?><option value="<?= e($ct) ?>" <?= $allTypeFilter === $ct ? 'selected' : '' ?>><?= e(casesTypeLabel($ct)) ?></option><?php endforeach; ?></select></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><option value="open" <?= $allStatus === 'open' ? 'selected' : '' ?>>Open / active</option><option value="all" <?= $allStatus === 'all' ? 'selected' : '' ?>>All statuses</option><option value="closed" <?= $allStatus === 'closed' ? 'selected' : '' ?>>Closed</option></select></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>
<form method="POST" class="glass rounded-xl overflow-hidden mb-8">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <div class="px-4 py-3 border-b border-gray-800 flex flex-wrap gap-2 items-center text-xs">
        <span class="text-gray-500">Bulk:</span>
        <select name="partner_key" class="input-field text-sm"><option value="">Partner (forward)</option><?php foreach ($partnerChoices as $pk => $pl): ?><option value="<?= e($pk) ?>"><?= e($pl) ?></option><?php endforeach; ?></select>
        <input type="text" name="note" placeholder="Note / resolution" class="input-field text-sm flex-1 min-w-[140px]">
        <button type="submit" name="cases_action" value="bulk_close" class="px-3 py-1.5 rounded-lg border border-gray-700 text-gray-300">Close selected</button>
        <button type="submit" name="cases_action" value="bulk_forward" class="px-3 py-1.5 rounded-lg bg-violet-600/20 text-violet-300">Forward selected</button>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="bg-dark-900/50 text-gray-400 text-xs uppercase"><tr>
                <th class="px-3 py-2 text-left w-8"></th><th class="px-3 py-2 text-left">Type</th><th class="px-3 py-2 text-left">Ref</th><th class="px-3 py-2 text-left">Merchant</th><th class="px-3 py-2 text-left">Subject</th><th class="px-3 py-2 text-left">Status</th><th class="px-3 py-2 text-left">Partner</th><th class="px-3 py-2 text-left">Open</th>
            </tr></thead>
            <tbody>
            <?php if ($unifiedCases === []): ?>
            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No open cases — customer complaints and disputes appear here when raised.</td></tr>
            <?php else: foreach ($unifiedCases as $uc): ?>
            <tr class="border-t border-gray-800/50">
                <td class="px-3 py-3"><input type="checkbox" name="selected[]" value="<?= e($uc['case_type'] . ':' . $uc['case_db_id']) ?>"></td>
                <td class="px-3 py-3 text-xs text-gray-400"><?= e(casesTypeLabel((string)$uc['case_type'])) ?></td>
                <td class="px-3 py-3 font-mono text-xs"><a href="<?= e((string)$uc['detail_url']) ?>" class="text-sky-400 hover:underline"><?= e((string)$uc['case_ref']) ?></a></td>
                <td class="px-3 py-3 text-xs"><?= !empty($uc['merchant_id']) ? adminMerchantLink((int)$uc['merchant_id'], (string)$uc['business_name']) : '—' ?></td>
                <td class="px-3 py-3 text-xs text-gray-300 max-w-xs truncate"><?= e((string)$uc['subject']) ?></td>
                <td class="px-3 py-3"><?= statusBadge((string)$uc['status']) ?></td>
                <td class="px-3 py-3 text-xs text-gray-500"><?= e((string)($uc['partner_status'] ?? '—')) ?></td>
                <td class="px-3 py-3 text-xs text-gray-500"><?= e((string)($uc['sort_at'] ?? '')) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</form>
<?php else: ?>

<?php
echo renderComplianceSupportPathPanel('tkt');
?>

<div class="glass rounded-xl p-4 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Admin first — support queue</p>
    <p class="text-xs text-gray-500">Merchant tickets (UniWeb-only unless you forward). Use <a href="<?= e(casesOpsUrl('complaints')) ?>" class="text-sky-400 hover:underline">Customer complaints</a> or <a href="<?= e(casesOpsUrl('disputes')) ?>" class="text-sky-400 hover:underline">Disputes</a> tabs for payer cases.</p>
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
    <input type="hidden" name="tab" value="support">
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
        <?= renderCasesPartnerEventsPanel('support_ticket', (int)$t['id']) ?>
        <div class="flex flex-wrap gap-2 mt-3 text-xs">
            <form method="POST" class="inline-flex flex-wrap gap-2 items-end" onsubmit="return confirm('Close this ticket?')">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="cases_action" value="close_case">
                <input type="hidden" name="case_type" value="support_ticket">
                <input type="hidden" name="case_id" value="<?= (int)$t['id'] ?>">
                <input type="text" name="note" placeholder="Close note" class="input-field text-sm w-40">
                <button type="submit" class="px-3 py-1.5 rounded-lg border border-gray-700 text-gray-300">Close here</button>
            </form>
        </div>
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

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
