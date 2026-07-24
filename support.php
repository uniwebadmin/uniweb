<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureSupportTicketTable();
$merchant = getMerchant();
$db = getDB();
$categories = getSupportTicketCategories();

$recentTxns = $db->prepare("SELECT txn_id, amount, status, created_at FROM transactions WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 30");
$recentTxns->execute([$merchant['id']]);
$txnList = $recentTxns->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $priority = $_POST['priority'] ?? 'medium';
    $category = $_POST['category'] ?? 'general';
    $txnRef = trim($_POST['txn_reference'] ?? '') ?: null;
    if (!isset($categories[$category])) $category = 'general';
    if ($subject && $message) {
        try {
            $ticketId = generateId('TKT');
            $db->prepare('INSERT INTO support_tickets (ticket_id, merchant_id, category, subject, message, txn_reference, priority) VALUES (?,?,?,?,?,?,?)')
                ->execute([$ticketId, $merchant['id'], $category, $subject, $message, $txnRef, $priority]);
            flash('success', 'Support ticket created: ' . $ticketId);
            redirect('support.php');
        } catch (Throwable $e) {
            flash('error', 'Could not create ticket. Please email ' . COMPANY_SUPPORT_EMAIL);
            redirect('support.php');
        }
    } else {
        flash('error', 'Subject and message are required.');
    }
}

$tickets = $db->prepare('SELECT * FROM support_tickets WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 20');
$tickets->execute([$merchant['id']]);
$ticketList = $tickets->fetchAll();
$pageTitle = 'Support & Compliance';
require_once __DIR__ . '/header.php';
?>

<div class="grid lg:grid-cols-3 gap-6">
    <div class="space-y-6">
        <div class="glass rounded-xl p-6">
            <h2 class="font-semibold mb-4">Raise a Ticket</h2>
            <form method="POST" class="space-y-4" aria-label="Raise support ticket">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <div>
                    <?= uxFormLabel('ticket-category', 'Category', true) ?>
                    <select name="category" class="input-field mt-1" id="ticket-category" required>
                        <?php foreach ($categories as $k => $label): ?>
                        <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="txn-field" class="hidden">
                    <?= uxFormLabel('txn_reference', 'Transaction ID (optional)') ?>
                    <select name="txn_reference" id="txn_reference" class="input-field mt-1">
                        <option value="">— Select transaction —</option>
                        <?php foreach ($txnList as $t): ?>
                        <option value="<?= e($t['txn_id']) ?>"><?= e($t['txn_id']) ?> · <?= formatMoney((float)$t['amount']) ?> · <?= e($t['status']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><?= uxFormLabel(uxFieldId('subject'), 'Subject', true) ?><input type="text" name="subject" id="<?= e(uxFieldId('subject')) ?>" required class="input-field mt-1" placeholder="Brief issue title"></div>
                <div><?= uxFormLabel(uxFieldId('priority'), 'Priority') ?>
                    <select name="priority" id="<?= e(uxFieldId('priority')) ?>" class="input-field mt-1"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select>
                </div>
                <div><?= uxFormLabel(uxFieldId('message'), 'Message', true) ?><textarea name="message" id="<?= e(uxFieldId('message')) ?>" required rows="4" class="input-field mt-1" placeholder="Describe your issue in detail"></textarea></div>
                <button type="submit" class="w-full btn-primary py-3">Submit Ticket</button>
            </form>
        </div>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold text-brand-400 mb-3">Compliance & KYC</h3>
            <ul class="space-y-2 text-gray-400 text-xs">
                <li><a href="kyc.php" class="text-sky-400 hover:underline">Upload KYC Documents →</a></li>
                <li><a href="merchant_video_verification.php" class="text-sky-400 hover:underline">Video KYC →</a></li>
                <li><a href="terms.php" class="hover:text-white">Terms & Conditions</a></li>
                <li><a href="privacy.php" class="hover:text-white">Privacy Policy</a></li>
                <li><a href="refund_policy.php" class="hover:text-white">Refund Policy</a></li>
            </ul>
            <p class="text-xs text-gray-600 mt-4">KYC status: <strong class="text-amber-400"><?= ucfirst(e($merchant['kyc_status'] ?? 'pending')) ?></strong></p>
        </div>

        <div class="glass rounded-xl p-5 text-xs text-gray-500">
            <p>Email: <a href="mailto:<?= e(COMPANY_SUPPORT_EMAIL) ?>" class="text-brand-400"><?= e(COMPANY_SUPPORT_EMAIL) ?></a></p>
            <p class="mt-1">Phone: <?= e(COMPANY_PHONE) ?></p>
        </div>
    </div>

    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Your Tickets</h2></div>
        <?php if (empty($ticketList)): ?>
        <div class="p-6"><?= renderMerchantEmptyState('No support tickets yet', 'Raise a ticket for payment, settlement or KYC questions. We usually respond within one business day.', null, null) ?></div>
        <?php else: ?>
        <div class="divide-y divide-gray-800">
            <?php foreach ($ticketList as $t):
                $catLabel = $categories[$t['category'] ?? 'general'] ?? 'General';
            ?>
            <div class="px-6 py-4 hover:bg-white/5 cursor-pointer" onclick="location.href='support_ticket.php?id=<?= e(urlencode($t['ticket_id'])) ?>'">
                <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
                    <div>
                        <span class="font-mono text-xs"><a href="support_ticket.php?id=<?= e(urlencode($t['ticket_id'])) ?>" class="text-sky-400 hover:underline"><?= e($t['ticket_id']) ?></a></span>
                        <span class="text-xs bg-gray-800 text-gray-400 px-2 py-0.5 rounded ml-2"><?= e($catLabel) ?></span>
                        <p class="font-medium text-sm mt-1"><?= e($t['subject']) ?></p>
                    </div>
                    <?= statusBadge($t['status']) ?>
                </div>
                <p class="text-sm text-gray-400"><?= e($t['message']) ?></p>
                <?php if (!empty($t['txn_reference'])): ?><p class="text-xs text-sky-400/80 mt-1">Txn: <?= e($t['txn_reference']) ?></p><?php endif; ?>
                <?php if ($t['admin_reply']): ?><div class="mt-3 bg-brand-500/5 border border-brand-500/20 rounded-lg p-3 text-sm"><p class="text-brand-400 text-xs mb-1">Admin Reply:</p><?= e($t['admin_reply']) ?></div><?php endif; ?>
                <p class="text-xs text-gray-600 mt-2"><?= formatDate($t['created_at']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('ticket-category')?.addEventListener('change', function() {
    const show = ['transaction','settlement','dispute'].includes(this.value);
    document.getElementById('txn-field')?.classList.toggle('hidden', !show);
});
</script>
<?php require_once __DIR__ . '/footer.php'; ?>
