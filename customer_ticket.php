<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireCustomer();

$phone = currentCustomerPhone();
$isNew = isset($_GET['new']);
$ticketId = trim((string)($_GET['id'] ?? ''));
$prefillTxn = trim((string)($_GET['txn'] ?? ''));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Session expired. Please try again.';
    } elseif (($_POST['action'] ?? '') === 'create') {
        $res = createCustomerTicket(
            $phone,
            (string)($_POST['subject'] ?? ''),
            (string)($_POST['message'] ?? ''),
            trim((string)($_POST['txn'] ?? '')) ?: null
        );
        if ($res['ok']) {
            flash('success', $res['message']);
            redirect('customer_ticket.php?id=' . rawurlencode($res['ticket_id']));
        }
        $error = $res['message'];
        $isNew = true;
    } elseif (($_POST['action'] ?? '') === 'reply') {
        $t = getCustomerTicket(trim((string)($_POST['ticket_id'] ?? '')), $phone);
        if (!$t) {
            flash('error', 'Complaint not found.');
            redirect('customer_portal.php');
        }
        if (addCustomerTicketMessage((int)$t['id'], 'customer', (string)($_POST['reply'] ?? ''))) {
            flash('success', 'Reply sent. Our support team will respond.');
        } else {
            flash('error', 'Please type a reply (max 5000 characters).');
        }
        redirect('customer_ticket.php?id=' . rawurlencode((string)$t['ticket_id']));
    }
}

$ticket = null;
$messages = [];
if (!$isNew && $ticketId !== '') {
    $ticket = getCustomerTicket($ticketId, $phone);
    if (!$ticket) {
        flash('error', 'Complaint not found.');
        redirect('customer_portal.php');
    }
    $messages = getCustomerTicketMessages((int)$ticket['id']);
}

$pageTitle = $ticket ? 'Complaint ' . $ticket['ticket_id'] : 'Raise a complaint';
$hideNav = true;
$hideFooter = true;
require_once __DIR__ . '/header.php';
?>
<div class="min-h-screen flex flex-col">
    <header class="border-b border-gray-800 bg-dark-950/80">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-3">
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <a href="customer_logout.php" class="text-sm text-gray-400 hover:text-white border border-gray-700 rounded-lg px-3 py-1.5">Logout</a>
        </div>
    </header>

    <main class="flex-1 w-full max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <a href="customer_portal.php" class="text-sm text-brand-400 hover:text-brand-300">← Back to my payments</a>
        <?php if ($error): ?><div class="bg-red-500/10 border border-red-500/30 text-red-400 text-sm px-4 py-3 rounded-lg"><?= e($error) ?></div><?php endif; ?>

        <?php if ($ticket): ?>
        <div class="glass rounded-xl p-6">
            <div class="flex flex-wrap justify-between items-start gap-3 mb-4">
                <div>
                    <p class="font-mono text-sm text-sky-400"><?= e($ticket['ticket_id']) ?></p>
                    <h1 class="text-lg font-semibold mt-1"><?= e($ticket['subject']) ?></h1>
                </div>
                <?= statusBadge((string)$ticket['status']) ?>
            </div>
            <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($ticket['message']) ?></p>
            <?php if (!empty($ticket['txn_reference'])): ?>
            <p class="text-xs text-sky-400 mt-3 font-mono">Transaction: <?= e($ticket['txn_reference']) ?></p>
            <?php endif; ?>
            <p class="text-xs text-gray-600 mt-3"><?= formatDate($ticket['created_at']) ?></p>

            <?php foreach ($messages as $msg): ?>
            <div class="mt-4 rounded-lg p-4 border <?= $msg['sender_type'] === 'admin' ? 'bg-brand-500/5 border-brand-500/20' : 'bg-sky-500/5 border-sky-500/20' ?>">
                <p class="<?= $msg['sender_type'] === 'admin' ? 'text-brand-400' : 'text-sky-400' ?> text-xs mb-2 font-semibold"><?= $msg['sender_type'] === 'admin' ? 'Support Team' : 'You' ?></p>
                <p class="text-sm text-gray-300 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
                <p class="text-xs text-gray-600 mt-2"><?= formatDate($msg['created_at']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="glass rounded-xl p-6 border border-sky-500/20">
            <h2 class="font-semibold mb-3">Add a reply</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="ticket_id" value="<?= e($ticket['ticket_id']) ?>">
                <textarea name="reply" rows="4" maxlength="5000" required class="input-field" placeholder="Type your reply…"></textarea>
                <button type="submit" class="btn-primary px-5 py-2.5 text-sm">Send Reply</button>
            </form>
        </div>

        <?php else: ?>
        <div class="glass rounded-xl p-6">
            <h1 class="text-xl font-bold mb-1">Raise a complaint</h1>
            <p class="text-sm text-gray-500 mb-5">Tell us what went wrong with a payment and we'll look into it.</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5">Transaction ID (optional)</label>
                    <input type="text" name="txn" class="input-field font-mono" placeholder="e.g. TXN..." value="<?= e($prefillTxn) ?>">
                    <p class="text-xs text-gray-600 mt-1">Leave blank for a general complaint. Must be a payment from your mobile number.</p>
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5">Subject</label>
                    <input type="text" name="subject" required maxlength="200" class="input-field" placeholder="Short summary of the issue" value="<?= e($_POST['subject'] ?? '') ?>">
                </div>
                <div>
                    <label class="block text-sm text-gray-400 mb-1.5">Describe your issue</label>
                    <textarea name="message" rows="5" maxlength="5000" required class="input-field" placeholder="What happened? Include amount, date, and any reference."><?= e($_POST['message'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn-primary px-5 py-3 text-sm w-full sm:w-auto">Submit complaint</button>
            </form>
        </div>
        <?php endif; ?>
    </main>

    <footer class="border-t border-gray-800/70 bg-dark-950">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 py-4 text-xs text-gray-600">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
        </div>
    </footer>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
