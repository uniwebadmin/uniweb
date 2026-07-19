<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureSupportTicketTable();
$merchant = getMerchant();
$id = trim($_GET['id'] ?? '');
$db = getDB();
$stmt = $db->prepare('SELECT * FROM support_tickets WHERE ticket_id = ? AND merchant_id = ?');
$stmt->execute([$id, $merchant['id']]);
$ticket = $stmt->fetch();
if (!$ticket) {
    flash('error', 'Ticket not found.');
    redirect('support.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $reply = trim((string)($_POST['reply'] ?? ''));
    if ($reply === '') {
        flash('error', 'Please type your reply.');
    } elseif (mb_strlen($reply) > 5000) {
        flash('error', 'Reply is too long.');
    } else {
        $db->prepare("INSERT INTO support_ticket_messages (ticket_id, sender_type, sender_id, message) VALUES (?, 'merchant', ?, ?)")
            ->execute([(int)$ticket['id'], (int)$merchant['id'], $reply]);
        $db->prepare("UPDATE support_tickets SET status='open' WHERE id=?")->execute([(int)$ticket['id']]);
        flash('success', 'Reply sent. Support can now respond.');
    }
    redirect('support_ticket.php?id=' . rawurlencode($id));
}
$messageStmt = $db->prepare('SELECT * FROM support_ticket_messages WHERE ticket_id=? ORDER BY created_at ASC, id ASC');
$messageStmt->execute([(int)$ticket['id']]);
$messages = $messageStmt->fetchAll();
$hasAdminThreadReply = (bool)array_filter($messages, static fn(array $row): bool => $row['sender_type'] === 'admin');
$categories = getSupportTicketCategories();
$catLabel = $categories[$ticket['category'] ?? 'general'] ?? 'General';
$pageTitle = __('ticket_detail');
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl mx-auto space-y-6">
    <a href="support.php" class="text-sm text-brand-400 hover:text-brand-300"><?= __('back_to_support') ?></a>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
            <div>
                <p class="font-mono text-sm text-sky-400"><?= e($ticket['ticket_id']) ?></p>
                <span class="text-xs bg-gray-800 text-gray-400 px-2 py-0.5 rounded"><?= e($catLabel) ?></span>
                <h1 class="text-lg font-semibold mt-2"><?= e($ticket['subject']) ?></h1>
            </div>
            <?= statusBadge($ticket['status']) ?>
        </div>
        <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($ticket['message']) ?></p>
        <?php if (!empty($ticket['txn_reference'])): ?>
        <p class="text-xs text-sky-400 mt-3">Txn: <a href="<?= e(transactionDetailUrl($ticket['txn_reference'])) ?>" class="underline"><?= e($ticket['txn_reference']) ?></a></p>
        <?php endif; ?>
        <?php if ($ticket['admin_reply'] && !$hasAdminThreadReply): ?>
        <div class="mt-4 bg-brand-500/5 border border-brand-500/20 rounded-lg p-4">
            <p class="text-brand-400 text-xs mb-2 font-semibold">Admin Reply</p>
            <p class="text-sm text-gray-300"><?= e($ticket['admin_reply']) ?></p>
        </div>
        <?php endif; ?>
        <?php foreach ($messages as $msg): ?>
        <div class="mt-4 rounded-lg p-4 border <?= $msg['sender_type'] === 'admin' ? 'bg-brand-500/5 border-brand-500/20' : 'bg-sky-500/5 border-sky-500/20' ?>">
            <p class="<?= $msg['sender_type'] === 'admin' ? 'text-brand-500' : 'text-sky-500' ?> text-xs mb-2 font-semibold"><?= $msg['sender_type'] === 'admin' ? 'Support Team' : 'You' ?></p>
            <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
            <p class="text-xs text-gray-600 mt-2"><?= formatDate($msg['created_at']) ?></p>
        </div>
        <?php endforeach; ?>
        <p class="text-xs text-gray-600 mt-4"><?= formatDate($ticket['created_at']) ?> · Priority: <?= e(ucfirst($ticket['priority'] ?? 'medium')) ?></p>
    </div>
    <div class="glass rounded-xl p-6 border border-sky-500/20">
        <h2 class="font-semibold mb-2">Reply to Support</h2>
        <p class="text-xs text-gray-500 mb-4">Reply is always available. A resolved or closed ticket will reopen automatically.</p>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <textarea name="reply" rows="4" maxlength="5000" required class="input-field" placeholder="Type your reply…"></textarea>
            <button type="submit" class="btn-primary px-5 py-2.5 text-sm">Send Reply</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
