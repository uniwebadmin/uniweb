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
$customerPortalUi = true;
$cpNavActive = 'complaints';
$bodyClass = trim(($bodyClass ?? '') . ' customer-portal-shell');
require_once __DIR__ . '/header.php';
?>
<div class="cp-shell">
    <header class="cp-topbar">
        <div class="cp-topbar-inner">
            <?php $logoHref = 'customer_portal.php'; $logoSize = 'sm'; require __DIR__ . '/includes/brand_logo.php'; ?>
            <?php require __DIR__ . '/includes/customer_portal_nav.php'; ?>
            <div class="flex items-center gap-2">
                <a href="customer_logout.php" class="cp-btn cp-btn-ghost text-xs !py-1.5 !px-3">Logout</a>
            </div>
        </div>
    </header>

    <main class="cp-main py-8 space-y-5 flex-1 w-full" style="max-width:720px">
        <a href="customer_portal.php#complaints" class="text-sm font-semibold text-teal-700 hover:underline">← Back to my payments</a>
        <?php if ($error): ?><div class="cp-alert cp-alert-error"><?= e($error) ?></div><?php endif; ?>

        <?php if ($ticket): ?>
        <section class="cp-panel p-5 sm:p-6">
            <div class="flex flex-wrap justify-between items-start gap-3">
                <div>
                    <p class="cp-mono"><?= e($ticket['ticket_id']) ?></p>
                    <h1 class="cp-display text-2xl font-bold mt-1 text-slate-900"><?= e($ticket['subject']) ?></h1>
                </div>
                <?= statusBadge((string)$ticket['status']) ?>
            </div>
            <p class="text-sm text-slate-700 whitespace-pre-wrap mt-4 leading-relaxed"><?= e($ticket['message']) ?></p>
            <?php if (!empty($ticket['txn_reference'])): ?>
            <p class="cp-mono mt-3">Transaction: <?= e($ticket['txn_reference']) ?></p>
            <?php endif; ?>
            <p class="cp-muted mt-3"><?= formatDate($ticket['created_at']) ?></p>

            <div class="cp-thread">
                <?php foreach ($messages as $msg):
                    $stype = (string)($msg['sender_type'] ?? 'customer');
                    $isYou = $stype === 'customer';
                    $label = customerTicketSenderLabel($stype, $msg['sender_label'] ?? null);
                    if ($isYou && $label === 'You') { /* ok */ }
                    elseif ($isYou) { $label = 'You'; }
                ?>
                <div class="cp-bubble <?= $isYou ? 'cp-bubble-you' : 'cp-bubble-support' ?>">
                    <p class="text-xs font-bold <?= $isYou ? 'text-sky-700' : 'text-teal-700' ?> mb-1.5"><?= e($label) ?></p>
                    <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($msg['message']) ?></p>
                    <p class="cp-muted mt-2"><?= formatDate($msg['created_at']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="cp-panel p-5 sm:p-6">
            <h2 class="font-bold text-slate-900 mb-3">Add a reply</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="ticket_id" value="<?= e($ticket['ticket_id']) ?>">
                <textarea name="reply" rows="4" maxlength="5000" required class="cp-input" placeholder="Type your reply…"></textarea>
                <button type="submit" class="cp-btn cp-btn-primary">Send reply</button>
            </form>
        </section>

        <?php else: ?>
        <section class="cp-panel p-5 sm:p-6">
            <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Support</p>
            <h1 class="cp-display text-2xl sm:text-3xl font-bold mt-2 text-slate-900">Raise a complaint</h1>
            <p class="cp-muted mt-2 mb-5">Tell us what went wrong. Your merchant, UniWeb support, and ops staff can help — replies appear here and by WhatsApp/SMS when configured.</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="create">
                <div class="cp-field">
                    <label for="txn">Transaction ID (optional)</label>
                    <input id="txn" type="text" name="txn" class="cp-input font-mono text-sm" placeholder="e.g. TXN..." value="<?= e($prefillTxn) ?>">
                    <p class="text-xs text-slate-500 mt-1">Must be a payment from your mobile number. Leave blank for a general complaint.</p>
                </div>
                <div class="cp-field">
                    <label for="subject">Subject</label>
                    <input id="subject" type="text" name="subject" required maxlength="200" class="cp-input" placeholder="Short summary of the issue" value="<?= e($_POST['subject'] ?? '') ?>">
                </div>
                <div class="cp-field">
                    <label for="message">Describe your issue</label>
                    <textarea id="message" name="message" rows="5" maxlength="5000" required class="cp-input" placeholder="What happened? Include amount, date, and any reference."><?= e($_POST['message'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="cp-btn cp-btn-primary w-full sm:w-auto">Submit complaint</button>
            </form>
        </section>
        <?php endif; ?>
    </main>

    <footer class="cp-footer">
        <div class="cp-footer-inner" style="max-width:720px">
            <span>&copy; <?= date('Y') ?> <?= COMPANY_LEGAL_NAME ?></span>
        </div>
    </footer>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
