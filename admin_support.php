<?php
require_once __DIR__ . '/config.php';
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

$tickets = $db->query('SELECT t.*, m.business_name, m.email FROM support_tickets t JOIN merchants m ON t.merchant_id=m.id ORDER BY FIELD(t.status,"open","in_progress","resolved","closed"), t.created_at DESC LIMIT 50')->fetchAll();
if (!isSuperAdmin()) {
    $tickets = array_values(array_filter($tickets, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
}
$pageTitle = 'Support Tickets';
require_once __DIR__ . '/header.php';
?>

<div class="space-y-4">
    <?php if (empty($tickets)): ?>
    <div class="glass rounded-xl p-12 text-center text-gray-500">No support tickets yet.</div>
    <?php else: foreach ($tickets as $t):
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
        <form method="POST" class="space-y-3 border-t border-gray-800 pt-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
            <textarea name="admin_reply" rows="2" class="input-field" placeholder="Type your reply..." required></textarea>
            <div class="flex gap-3 items-center">
                <select name="status" class="input-field w-auto">
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
