<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);

$id = (int)($_GET['id'] ?? 0);
requireMerchantAccess($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'send_outreach') {
        $result = sendMerchantOutreach($id, trim($_POST['message'] ?? ''), $_POST['channel'] ?? 'email', [
            'subject' => trim($_POST['subject'] ?? 'Update from ' . APP_NAME),
            'link_url' => trim($_POST['link_url'] ?? ''),
            'reference_type' => $_POST['reference_type'] ?? null,
            'reference_id' => $_POST['reference_id'] ?? null,
            'sent_by_admin_id' => (int)($_SESSION['admin_id'] ?? 0),
        ]);
        if ($result['ok'] && ($result['channel'] ?? '') === 'whatsapp' && !empty($result['whatsapp_url'])) {
            $_SESSION['open_wa_url'] = $result['whatsapp_url'];
            flash('success', $result['status'] === 'sent' ? 'WhatsApp message sent via API.' : 'WhatsApp opened — confirm send in WhatsApp.');
        } elseif ($result['ok']) {
            flash('success', 'Email sent to merchant.');
        } else {
            flash('error', $result['error'] ?? 'Could not send message.');
        }
        redirect('admin_view_merchant.php?id=' . $id);
    }
    if ($action === 'assign_staff' && staffCanAssignMerchants()) {
        $staffId = (int)($_POST['staff_id'] ?? 0);
        if ($staffId && assignMerchantToStaff($staffId, $id, trim($_POST['assign_note'] ?? ''))) {
            flash('success', 'Staff assigned to this merchant.');
        } else {
            flash('error', 'Could not assign staff.');
        }
        redirect('admin_view_merchant.php?id=' . $id);
    }
    if ($action === 'retry_webhook' && (isSuperAdmin() || adminRole() === 'ceo' || adminRole() === 'finance')) {
        $logId = (int)($_POST['log_id'] ?? 0);
        $result = retryMerchantWebhookLog($logId, $id);
        logStaffActivity('webhook_retry', $result['message'], $id);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('admin_view_merchant.php?id=' . $id);
    }
    if ($action === 'test_webhook' && (isSuperAdmin() || adminRole() === 'ceo')) {
        $result = sendMerchantWebhookTest($id);
        logStaffActivity('webhook_test', $result['message'], $id);
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        redirect('admin_view_merchant.php?id=' . $id);
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'auto_provision' && verifyCsrf($_GET['token'] ?? '')) {
    if (!isSuperAdmin() && adminRole() !== 'ceo') {
        flash('error', 'Only admin can re-run auto setup.');
        redirect('admin_view_merchant.php?id=' . $id);
    }
    $result = autoProvisionMerchant($id, (int)($_SESSION['admin_id'] ?? 0));
    logStaffActivity('auto_provision', $result['message'] ?? '', $id);
    flash($result['ok'] ? 'success' : 'error', $result['message']);
    redirect('admin_view_merchant.php?id=' . $id);
}

if (isset($_GET['action']) && $_GET['action'] === 'evidence_pack' && verifyCsrf($_GET['token'] ?? '')) {
    if (!function_exists('downloadEvidencePack') && is_file(__DIR__ . '/includes/evidence_pack.php')) {
        require_once __DIR__ . '/includes/evidence_pack.php';
    }
    if (function_exists('downloadEvidencePack')) {
        downloadEvidencePack($id);
    }
    flash('error', 'Evidence Pack not available.');
    redirect('admin_view_merchant.php?id=' . $id);
}

$waOpen = $_SESSION['open_wa_url'] ?? null;
unset($_SESSION['open_wa_url']);

$view = buildMerchantAdminView($id);
if (!$view) {
    flash('error', 'Merchant not found.');
    redirect('manage_merchant.php');
}

$m = $view['merchant'];
$preview = $view['preview'];
$assignedStaff = getMerchantAssignedStaff($id);
$outreachLogs = getMerchantOutreachLogs($id, 10);
$webhookSummary = $view['webhook_summary'];
$webhookLogs = $view['webhook_logs'];
$assignableStaff = [];
if (staffCanAssignMerchants()) {
    $assignableStaff = getDB()->query("SELECT id, username, name, role FROM admins WHERE is_active=1 AND role NOT IN ('super','ceo') ORDER BY name")->fetchAll();
}

$pageTitle = 'Merchant View — ' . ($m['merchant_code'] ?? '');
require_once __DIR__ . '/header.php';
?>
<?php if ($waOpen): ?>
<script>window.open(<?= json_encode($waOpen) ?>, '_blank');</script>
<?php endif; ?>

<div class="mb-6 flex flex-wrap gap-3 items-center justify-between">
    <div class="flex flex-wrap gap-3 items-center">
        <a href="manage_merchant.php" class="text-sm text-gray-400 hover:text-white">← All Merchants</a>
        <?php if (isSuperAdmin() || adminRole() === 'ceo'): ?>
        <a href="admin_edit_merchant.php?id=<?= $id ?>" class="text-sm bg-brand-600 hover:bg-brand-500 text-white px-4 py-2 rounded-lg font-semibold">Edit Merchant</a>
        <a href="?id=<?= $id ?>&action=auto_provision&token=<?= csrfToken() ?>" class="text-sm text-emerald-400 border border-emerald-500/40 px-4 py-2 rounded-lg" onclick="return confirm('Auto setup payment pack?')">Re-Auto Setup</a>
        <?php endif; ?>
        <a href="?id=<?= $id ?>&action=evidence_pack&token=<?= csrfToken() ?>" class="text-sm text-amber-400 border border-amber-500/40 px-4 py-2 rounded-lg" onclick="return confirm('Download Evidence Pack? This may take a few seconds.')">Evidence Pack</a>
    </div>
    <?= accountModeBadge($m) ?>
</div>

<div class="mb-6 flex flex-wrap gap-2 text-xs">
    <a href="mailto:<?= e($m['email']) ?>" class="glass px-3 py-2 rounded-lg text-sky-400 hover:text-sky-300">Email</a>
    <?php if (!empty($m['phone'])):
        $waPhone = preg_replace('/\D+/', '', $m['phone']);
        if (strlen($waPhone) === 10) {
            $waPhone = '91' . $waPhone;
        }
    ?>
    <a href="tel:<?= e(preg_replace('/\D+/', '', $m['phone'])) ?>" class="glass px-3 py-2 rounded-lg text-gray-300 hover:text-white">Phone</a>
    <?php if ($waPhone): ?>
    <a href="https://wa.me/<?= e($waPhone) ?>" target="_blank" rel="noopener" class="glass px-3 py-2 rounded-lg text-emerald-400 hover:text-emerald-300">WhatsApp</a>
    <?php endif; endif; ?>
    <a href="<?= e(adminMerchantRefundsUrl($id)) ?>" class="glass px-3 py-2 rounded-lg text-amber-400 hover:text-amber-300">Refunds</a>
    <a href="<?= e(adminMerchantTransactionsUrl($id)) ?>" class="glass px-3 py-2 rounded-lg text-gray-300 hover:text-white">Transactions</a>
    <?php if (isSuperAdmin() || in_array(adminRole(), ['ceo','regional_manager','finance','ops'], true)): ?><a href="admin_merchant_banks.php?id=<?= $id ?>" class="glass px-3 py-2 rounded-lg text-sky-500 hover:text-sky-400">Bank Accounts</a><?php endif; ?>
    <a href="<?= e(adminMerchantApiUrl($id)) ?>" class="glass px-3 py-2 rounded-lg text-brand-400 hover:text-brand-300">Merchant API</a>
    <a href="<?= e(adminMerchantWebsiteUrl($id)) ?>" class="glass px-3 py-2 rounded-lg text-violet-400 hover:text-violet-300">Website</a>
    <a href="admin_disputes.php?merchant_id=<?= (int)$id ?>" class="glass px-3 py-2 rounded-lg text-red-400 hover:text-red-300">Disputes</a>
    <a href="admin_customer_tickets.php?merchant_id=<?= (int)$id ?>" class="glass px-3 py-2 rounded-lg text-amber-400 hover:text-amber-300">Customer tickets</a>
    <a href="admin_support.php?merchant_id=<?= (int)$id ?>" class="glass px-3 py-2 rounded-lg text-sky-400 hover:text-sky-300">Merchant support</a>
    <a href="admin_kyc.php?merchant_id=<?= (int)$id ?>" class="glass px-3 py-2 rounded-lg text-gray-500 hover:text-white">KYC</a>
</div>

<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <div class="lg:col-span-2 glass rounded-xl p-6">
        <h2 class="font-semibold text-lg mb-2"><?= e($m['business_name']) ?></h2>
        <p class="text-sm text-gray-400"><?= e($m['name']) ?> · <a href="admin_edit_merchant.php?id=<?= $id ?>" class="font-mono text-sky-400 hover:underline"><?= e($m['merchant_code']) ?></a></p>
        <p class="text-xs text-gray-500 mt-1"><?= e($m['email']) ?> · <?= e($m['phone']) ?></p>

        <div class="grid sm:grid-cols-4 gap-3 mt-4 text-sm">
            <div class="bg-dark-900/50 rounded-lg p-3 border border-gray-800"><p class="text-xs text-gray-500">Wallet</p><p class="font-bold text-emerald-400"><?= formatMoney(safeDisplayBalance($view['wallet']['balance'])) ?></p></div>
            <div class="bg-dark-900/50 rounded-lg p-3 border border-gray-800"><p class="text-xs text-gray-500">Txns</p><p class="font-bold"><?= $view['txn_success'] ?>/<?= $view['txn_total'] ?></p></div>
            <div class="bg-dark-900/50 rounded-lg p-3 border border-gray-800"><p class="text-xs text-gray-500">KYC</p><p><?= statusBadge($m['kyc_status'] ?? 'pending') ?></p></div>
            <div class="bg-dark-900/50 rounded-lg p-3 border border-gray-800"><p class="text-xs text-gray-500">Mode</p><p class="font-semibold"><?= isMerchantLive($m) ? 'LIVE' : 'TEST' ?></p></div>
        </div>

        <div class="mt-6 p-4 rounded-xl border border-sky-500/30 bg-sky-500/5">
            <h3 class="font-semibold text-sm text-sky-300 mb-3">Contact Merchant (Email / WhatsApp only)</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="send_outreach">
                <input type="text" name="subject" value="Update from <?= e(APP_NAME) ?>" class="input-field text-sm" placeholder="Email subject">
                <textarea name="message" rows="3" required class="input-field text-sm" placeholder="Write your message to the merchant about any issue..."></textarea>
                <input type="hidden" name="link_url" value="">
                <div class="flex flex-wrap gap-2">
                    <button type="submit" name="channel" value="email" class="text-sm bg-brand-600 hover:bg-brand-500 text-white px-4 py-2 rounded-lg">Send Email</button>
                    <button type="submit" name="channel" value="whatsapp" class="text-sm bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2 rounded-lg">Send WhatsApp</button>
                </div>
                <p class="text-[11px] text-gray-500">SMS disabled for now. WhatsApp opens with your message pre-filled, or sends via API if configured.</p>
            </form>
        </div>

        <?php if (!empty($view['pack_links'])): ?>
        <h3 class="font-semibold text-sm mt-6 mb-3">Payment Pack Links — report issue directly</h3>
        <div class="space-y-3">
            <?php foreach ($view['pack_links'] as $link):
                $cat = getPaymentMethodCatalog()[$link['payment_method'] ?? ''] ?? null;
                $payUrl = buildPaymentLinkUrl($link['link_id'], $cat['pay_key'] ?? null);
                $issueMsg = packLinkIssueTemplate($link, $payUrl);
            ?>
            <div class="bg-dark-900/40 rounded-lg px-3 py-3 border border-gray-800 text-xs">
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="font-medium"><?= e($link['link_label'] ?? $link['payment_method'] ?? 'Link') ?></span>
                    <a href="<?= e($payUrl) ?>" target="_blank" class="font-mono text-sky-400 hover:underline"><?= e($link['link_id']) ?></a>
                    <span><?= formatMoney((float)$link['amount']) ?></span>
                    <?= statusBadge($link['status'] ?? 'active') ?>
                    <a href="<?= e($payUrl) ?>" target="_blank" class="text-emerald-400">Test link →</a>
                </div>
                <form method="POST" class="space-y-2">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="send_outreach">
                    <input type="hidden" name="subject" value="Payment link issue — <?= e($link['link_id']) ?>">
                    <input type="hidden" name="link_url" value="<?= e($payUrl) ?>">
                    <input type="hidden" name="reference_type" value="payment_link">
                    <input type="hidden" name="reference_id" value="<?= e($link['link_id']) ?>">
                    <textarea name="message" rows="2" class="input-field text-xs"><?= e($issueMsg) ?></textarea>
                    <div class="flex gap-2">
                        <button type="submit" name="channel" value="email" class="px-3 py-1.5 rounded-lg bg-brand-600/80 text-white text-xs">Email about this</button>
                        <button type="submit" name="channel" value="whatsapp" class="px-3 py-1.5 rounded-lg bg-emerald-600/80 text-white text-xs">WhatsApp about this</button>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="space-y-4">
        <?php if (staffCanAssignMerchants()): ?>
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Assign Staff to Merchant</h3>
            <?php if (empty($assignedStaff)): ?><p class="text-xs text-amber-400 mb-3">No staff assigned yet.</p><?php else: ?>
            <ul class="text-xs space-y-1 mb-3">
                <?php foreach ($assignedStaff as $as): ?>
                <li class="text-gray-300"><?= e($as['name']) ?> <span class="text-gray-500">(<?= e(staffRoleLabel($as['role'])) ?>)</span></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <form method="POST" class="space-y-2">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="assign_staff">
                <select name="staff_id" class="input-field text-xs" required>
                    <option value="">Select staff member</option>
                    <?php foreach ($assignableStaff as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?> — <?= e(staffRoleLabel($s['role'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <input name="assign_note" class="input-field text-xs" placeholder="Note (optional)">
                <button type="submit" class="w-full text-xs bg-sky-600 text-white py-2 rounded-lg">Assign Staff</button>
            </form>
        </div>
        <?php else: ?>
        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-2">Assigned Staff</h3>
            <?php if (empty($assignedStaff)): ?><p class="text-xs text-gray-500">Not assigned.</p>
            <?php else: foreach ($assignedStaff as $as): ?>
            <p class="text-xs text-gray-300"><?= e($as['name']) ?> (<?= e(staffRoleLabel($as['role'])) ?>)</p>
            <?php endforeach; endif; ?>
        </div>
        <?php endif; ?>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Recent Outreach</h3>
            <?php if (empty($outreachLogs)): ?><p class="text-xs text-gray-500">No messages yet.</p>
            <?php else: foreach ($outreachLogs as $log): ?>
            <div class="border-b border-gray-800 py-2 last:border-0 text-xs">
                <span class="text-gray-500"><?= formatDate($log['created_at']) ?></span> ·
                <span class="capitalize"><?= e($log['channel']) ?></span> · <?= e($log['status']) ?>
                <p class="text-gray-400 truncate mt-1"><?= e($log['message_body']) ?></p>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-3">Outbound Webhooks</h3>
            <?php if (!$webhookSummary['configured']): ?>
            <p class="text-xs text-amber-400 mb-2">Webhook URL not set by merchant.</p>
            <?php else: ?>
            <p class="text-[11px] text-gray-500 break-all mb-2"><?= e($webhookSummary['url']) ?></p>
            <div class="grid grid-cols-3 gap-2 text-xs mb-3">
                <div class="bg-dark-900/50 rounded p-2 border border-gray-800"><span class="text-gray-500 block">Total</span><span class="font-bold"><?= $webhookSummary['total'] ?></span></div>
                <div class="bg-dark-900/50 rounded p-2 border border-gray-800"><span class="text-gray-500 block">Failed</span><span class="font-bold <?= $webhookSummary['failed'] ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $webhookSummary['failed'] ?></span></div>
                <div class="bg-dark-900/50 rounded p-2 border border-gray-800"><span class="text-gray-500 block">Last</span><span class="text-[10px]"><?= $webhookSummary['last_at'] ? e(formatDate($webhookSummary['last_at'])) : '—' ?></span></div>
            </div>
            <?php if (isSuperAdmin() || adminRole() === 'ceo'): ?>
            <form method="POST" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="test_webhook">
                <button type="submit" class="text-xs px-3 py-1.5 rounded-lg bg-sky-600/20 text-sky-400 hover:bg-sky-600/30">Send Test Webhook</button>
            </form>
            <?php endif; ?>
            <?php if (!empty($webhookLogs)): ?>
            <div class="space-y-2 max-h-48 overflow-y-auto">
                <?php foreach ($webhookLogs as $wl):
                    $code = (int)($wl['response_code'] ?? 0);
                    $ok = merchantWebhookDeliveryOk($code ?: null);
                ?>
                <div class="text-[11px] border-b border-gray-800 pb-2">
                    <div class="flex justify-between gap-2">
                        <span class="font-mono text-brand-400"><?= e($wl['event_type'] ?? '') ?></span>
                        <span class="<?= $ok ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $code ?: '—' ?></span>
                    </div>
                    <p class="text-gray-500 truncate"><?= e(formatDate($wl['created_at'] ?? '')) ?></p>
                    <?php if (!$ok && (isSuperAdmin() || adminRole() === 'ceo' || adminRole() === 'finance')): ?>
                    <form method="POST" class="mt-1">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="retry_webhook">
                        <input type="hidden" name="log_id" value="<?= (int)$wl['id'] ?>">
                        <button type="submit" class="text-sky-400 hover:underline">Retry</button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <div class="glass rounded-xl p-5 text-sm">
            <h3 class="font-semibold mb-2">Gateway IDs</h3>
            <p class="text-xs text-gray-500">PayU</p><p class="font-mono text-xs break-all"><?= e($m['payu_child_key'] ?: '—') ?></p>
            <p class="text-xs text-gray-500 mt-2">Razorpay</p><p class="font-mono text-xs break-all"><?= e($m['razorpay_linked_account_id'] ?: '—') ?></p>
            <a href="admin_kyc.php?merchant_id=<?= (int)$id ?>" class="inline-block mt-3 text-xs text-brand-400">Review KYC →</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
