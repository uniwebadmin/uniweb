<?php
require_once __DIR__ . '/config.php';
if (!function_exists('uxFormLabel')) {
    require_once __DIR__ . '/includes/page_ux.php';
}
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

$supportChannels = [
    ['key' => 'whatsapp', 'label' => 'WhatsApp', 'color' => 'emerald', 'url' => 'https://wa.me/' . preg_replace('/[^0-9]/', '', (string)(defined('SUPPORT_WHATSAPP') ? SUPPORT_WHATSAPP : '919900000000')), 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>'],
    ['key' => 'email', 'label' => 'Email', 'color' => 'sky', 'url' => 'mailto:' . (defined('SUPPORT_EMAIL') ? SUPPORT_EMAIL : COMPANY_SUPPORT_EMAIL), 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>'],
    ['key' => 'phone', 'label' => 'Call', 'color' => 'amber', 'url' => 'tel:' . (defined('COMPANY_PHONE') ? COMPANY_PHONE : '+911140000000'), 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>'],
    ['key' => 'instagram', 'label' => 'Instagram', 'color' => 'pink', 'url' => defined('SUPPORT_INSTAGRAM') ? SUPPORT_INSTAGRAM : 'https://instagram.com/uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'],
    ['key' => 'telegram', 'label' => 'Telegram', 'color' => 'cyan', 'url' => defined('SUPPORT_TELEGRAM') ? SUPPORT_TELEGRAM : 'https://t.me/uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>'],
    ['key' => 'facebook', 'label' => 'Facebook', 'color' => 'blue', 'url' => defined('SUPPORT_FACEBOOK') ? SUPPORT_FACEBOOK : 'https://facebook.com/uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg>'],
    ['key' => 'twitter', 'label' => 'Twitter / X', 'color' => 'gray', 'url' => defined('SUPPORT_TWITTER') ? SUPPORT_TWITTER : 'https://x.com/uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M23 3a10.9 10.9 0 01-3.14 1.53 4.48 4.48 0 00-7.86 3v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5a4.5 4.5 0 00-.08-.83A7.72 7.72 0 0023 3z"/></svg>'],
    ['key' => 'linkedin', 'label' => 'LinkedIn', 'color' => 'indigo', 'url' => defined('SUPPORT_LINKEDIN') ? SUPPORT_LINKEDIN : 'https://linkedin.com/company/uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2zM4 6a2 2 0 11-4 0 2 2 0 014 0z"/></svg>'],
    ['key' => 'youtube', 'label' => 'YouTube', 'color' => 'red', 'url' => defined('SUPPORT_YOUTUBE') ? SUPPORT_YOUTUBE : 'https://youtube.com/@uniweb', 'icon' => '<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>'],
];

$pageTitle = 'Support & Compliance';
require_once __DIR__ . '/header.php';
?>

<div class="glass rounded-xl p-6 mb-6">
    <h2 class="font-semibold mb-1">Connect with Admin</h2>
    <p class="text-xs text-gray-500 mb-4">Reach us on any channel you prefer. Replace placeholder links in your <code>config.php</code> with real accounts.</p>
    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
        <?php foreach ($supportChannels as $ch): ?>
        <a href="<?= e($ch['url']) ?>" target="_blank" rel="noopener noreferrer" class="flex items-center gap-3 p-3 rounded-xl border border-gray-800 hover:bg-white/5 hover:border-<?= e($ch['color']) ?>-500/40 transition group">
            <span class="text-<?= e($ch['color']) ?>-400 group-hover:scale-110 transition"><?= $ch['icon'] ?></span>
            <span class="text-sm font-medium text-gray-300 group-hover:text-white"><?= e($ch['label']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

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
