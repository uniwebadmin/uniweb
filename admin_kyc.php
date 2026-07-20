<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);
ensureKycSchema();
$db = getDB();
$canMutateKyc = staffCanMutateKyc();
$canChecker = staffCanCheckerApproveKyc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        flash('error', 'Session expired.');
        redirect('admin_kyc.php');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'Compliance review'));
    try {
        if (in_array($action, ['approve_doc', 'verify_merchant', 'live_enable', 'verify_video', 'reject_doc'], true)) {
            requireStaffKycMutation();
        }
        if (in_array($action, ['approve_request', 'reject_request', 'live_enable'], true) && !$canChecker) {
            throw new RuntimeException('Only KYC/ops/admin roles can complete checker decisions.');
        }
        if ($action === 'approve_doc') {
            $doc = $db->prepare('SELECT merchant_id,doc_type,scan_status FROM kyc_documents WHERE id=?');
            $doc->execute([$id]);
            $d = $doc->fetch();
            if (!$d || $d['scan_status'] !== 'clean') {
                throw new RuntimeException('Document must pass malware scanning first.');
            }
            requireMerchantAccess((int)$d['merchant_id']);
            submitApprovalRequest('kyc_document_approve', (int)$d['merchant_id'], 'kyc_document', (string)$id, $reason, $d);
            flash('success', 'Document approval sent to an independent checker.');
        } elseif ($action === 'verify_merchant') {
            requireMerchantAccess($id);
            submitApprovalRequest('kyc_merchant_verify', $id, 'merchant', (string)$id, $reason);
            $db->prepare("UPDATE merchants SET onboarding_state='under_review',account_mode='test' WHERE id=?")->execute([$id]);
            flash('success', 'KYC verification sent to an independent checker.');
        } elseif ($action === 'live_enable') {
            requireStepUpAuth();
            requireMerchantAccess($id);
            submitApprovalRequest('merchant_live_enable', $id, 'merchant', (string)$id, $reason);
            flash('success', 'Live activation sent to an independent checker.');
        } elseif ($action === 'verify_video') {
            requireMerchantAccess($id);
            $db->prepare("UPDATE merchants SET video_kyc_status='verified' WHERE id=?")->execute([$id]);
            recordImmutableAudit('video_kyc_verified', $id, 'merchant', (string)$id, $reason);
            flash('success', 'Video KYC marked verified.');
        } elseif ($action === 'reject_doc') {
            $doc = $db->prepare('SELECT merchant_id,doc_type FROM kyc_documents WHERE id=?');
            $doc->execute([$id]);
            $d = $doc->fetch();
            if (!$d) throw new RuntimeException('Document not found.');
            requireMerchantAccess((int)$d['merchant_id']);
            $db->prepare("UPDATE kyc_documents SET status='rejected',reviewed_at=NOW() WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE merchants SET kyc_status='submitted',onboarding_state='clarification',account_mode='test' WHERE id=?")->execute([(int)$d['merchant_id']]);
            logStaffActivity('kyc_clarification_requested', $d['doc_type'] . ': ' . $reason, (int)$d['merchant_id'], 'kyc_document', (string)$id);
            flash('success', 'Document rejected and clarification requested.');
        } elseif ($action === 'approve_request') {
            requireStepUpAuth();
            approveApprovalRequest($id, $reason);
            flash('success', 'Independent checker approval completed.');
        } elseif ($action === 'reject_request') {
            rejectApprovalRequest($id, $reason);
            flash('success', 'Approval request rejected.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_kyc.php');
}

$pendingDocs = $db->query("SELECT k.*, m.business_name, m.merchant_code, m.business_entity_type FROM kyc_documents k JOIN merchants m ON k.merchant_id=m.id WHERE k.status='pending' ORDER BY k.created_at ASC")->fetchAll();
$pendingMerchants = getPendingKycQueue(50);
$recentSignups = getRecentSignupQueue(12);
$approvalQueue = $db->query(
    "SELECT r.*,m.business_name FROM approval_requests r
     LEFT JOIN merchants m ON m.id=r.merchant_id
     WHERE r.status='pending' AND r.action_type IN ('kyc_document_approve','kyc_merchant_verify','merchant_live_enable')
     ORDER BY r.requested_at ASC LIMIT 50"
)->fetchAll();
$liveCandidates = $db->query(
    "SELECT id,business_name,merchant_code FROM merchants
     WHERE status='active' AND kyc_status='verified' AND account_mode='test'
       AND email<>'demo@uniweb.co.in' ORDER BY id ASC LIMIT 50"
)->fetchAll();
$pageTitle = 'KYC Review';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap gap-3">
    <?php if (isSuperAdmin()): ?>
    <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400">Link Watchdog</a>
    <?php endif; ?>
    <a href="manage_merchant.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300">All Merchants</a>
</div>

<?php if (!$canMutateKyc): ?>
<div class="glass rounded-xl p-4 mb-6 border border-amber-500/20 text-sm text-amber-300">View-only: your role can inspect KYC but cannot approve, reject, or enable Live.</div>
<?php endif; ?>

<?php if (!empty($approvalQueue) && $canChecker): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-amber-500/30">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Independent checker queue</h2><p class="text-xs text-gray-500 mt-1">The maker who requested an action cannot approve it.</p></div>
    <?php foreach ($approvalQueue as $request): ?>
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-sm font-medium"><?= e(str_replace('_', ' ', $request['action_type'])) ?> · <?= e($request['business_name'] ?? 'Platform') ?></p><p class="text-xs text-gray-500"><?= e($request['request_ref']) ?> · <?= e($request['request_reason']) ?></p></div>
        <div class="flex gap-2">
            <form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>"><input name="reason" required maxlength="500" placeholder="Checker reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1"><button class="text-xs bg-emerald-600 text-white px-3 py-1 rounded-lg">Approve</button></form>
            <form method="post" class="flex gap-2"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>"><input name="reason" required maxlength="500" placeholder="Rejection reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1"><button class="text-xs bg-red-600/20 text-red-400 px-3 py-1 rounded-lg">Reject</button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif (!empty($approvalQueue) && !$canChecker): ?>
<div class="glass rounded-xl p-4 mb-6 text-sm text-gray-400"><?= count($approvalQueue) ?> approval request(s) waiting for a KYC/ops checker.</div>
<?php endif; ?>

<?php if (!empty($liveCandidates) && $canMutateKyc): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-emerald-500/20">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Live activation gate</h2><p class="text-xs text-gray-500 mt-1">KYC verification alone does not enable real money. Every server-side gate must pass.</p></div>
    <?php foreach ($liveCandidates as $candidate): $gate = merchantLiveGateReport((int)$candidate['id']); ?>
    <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-sm font-medium"><?= e($candidate['business_name']) ?> · <?= e($candidate['merchant_code']) ?></p><p class="text-xs <?= $gate['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $gate['ok'] ? 'All gates complete' : 'Missing: ' . e(implode(', ', $gate['missing'])) ?></p></div>
        <div class="flex gap-2 flex-wrap">
            <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_video"><input type="hidden" name="id" value="<?= (int)$candidate['id'] ?>"><input type="hidden" name="reason" value="Video reviewed"><button class="text-xs bg-violet-600/20 text-violet-300 px-3 py-2 rounded-lg">Mark video verified</button></form>
            <?php if ($gate['ok'] && $canChecker): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="live_enable"><input type="hidden" name="id" value="<?= (int)$candidate['id'] ?>"><input type="hidden" name="reason" value="All production onboarding gates verified"><button class="text-xs bg-emerald-600 text-white px-3 py-2 rounded-lg">Send Live activation for approval</button></form><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($recentSignups)): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-sky-500/20">
    <div class="px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Recent signups — KYC review (Individual / Freelancer first)</h2>
        <p class="text-xs text-gray-500 mt-1">Maker sends KYC for approval after documents are OK. An independent checker must approve. Live money still requires the Live activation gate below.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-5 py-3 text-left">Code</th>
                    <th class="px-5 py-3 text-left">Business</th>
                    <th class="px-5 py-3 text-left">Entity</th>
                    <th class="px-5 py-3 text-left">Contact</th>
                    <th class="px-5 py-3 text-left">KYC</th>
                    <th class="px-5 py-3 text-left">Joined</th>
                    <th class="px-5 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($recentSignups as $m):
                    $mid = (int)$m['id'];
                    $waUrl = merchantWhatsAppUrl($m['phone'] ?? null);
                    $canVerify = in_array(($m['kyc_status'] ?? ''), ['pending', 'submitted'], true);
                ?>
                <tr>
                    <td class="px-5 py-3 font-mono text-xs"><?= adminMerchantLink($mid, $m['merchant_code']) ?></td>
                    <td class="px-5 py-3"><?= adminMerchantLink($mid, $m['business_name'], 'font-medium hover:text-sky-300') ?></td>
                    <td class="px-5 py-3 text-xs"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></td>
                    <td class="px-5 py-3 text-xs">
                        <?= merchantMailtoLink((string)$m['email'], $m['email'], 'text-gray-400 hover:text-sky-300') ?>
                        <?php if ($waUrl): ?><br><a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="text-emerald-400">WhatsApp</a><?php endif; ?>
                    </td>
                    <td class="px-5 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($m['created_at']) ?></td>
                    <td class="px-5 py-3 text-xs whitespace-nowrap">
                        <a href="<?= e(adminMerchantUrl($mid)) ?>" class="text-gray-400 hover:text-white mr-2">View</a>
                        <?php if ($canVerify && $canMutateKyc): ?>
                        <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant"><input type="hidden" name="id" value="<?= $mid ?>"><input type="hidden" name="reason" value="Entity documents reviewed"><button class="text-brand-400 hover:text-brand-300 mr-2">Send for KYC approval</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-6">
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Pending documents</h2>
            <p class="text-xs text-gray-500 mt-1">Approve docs (maker) before the independent checker verifies the merchant</p>
        </div>
        <?php if (empty($pendingDocs)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No pending documents</p>
        <?php else: foreach ($pendingDocs as $doc): ?>
        <div class="px-6 py-4 border-b border-gray-800">
            <div class="flex justify-between items-start mb-2">
                <div>
                    <p class="font-medium text-sm"><?= adminMerchantLink((int)$doc['merchant_id'], $doc['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                    <p class="text-xs text-gray-500 capitalize"><?= e(entityTypeLabel($doc['business_entity_type'] ?? '')) ?> — <?= str_replace('_', ' ', $doc['doc_type']) ?></p>
                </div>
                <?= statusBadge($doc['status']) ?>
            </div>
            <div class="flex gap-2 mt-2 flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$doc['merchant_id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1 rounded-lg">View Merchant</a>
                <a href="admin_kyc_doc.php?id=<?= $doc['id'] ?>&token=<?= csrfToken() ?>" target="_blank" class="text-xs bg-sky-600/20 text-sky-400 px-3 py-1 rounded-lg">View Doc</a>
                <?php if ($canMutateKyc): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="approve_doc"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="reason" value="Document content reviewed"><button class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1 rounded-lg">Send for approval</button></form>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_doc"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input name="reason" required maxlength="500" placeholder="Clarification reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1"><button class="text-xs bg-red-600/20 text-red-400 px-3 py-1 rounded-lg">Reject</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Pending KYC — send for checker approval</h2>
            <p class="text-xs text-gray-500 mt-1">Individual / Freelancer first · Maker cannot approve their own request · Live activation is a separate step</p>
        </div>
        <?php if (empty($pendingMerchants)): ?>
        <p class="text-gray-500 text-sm text-center py-8">All caught up!</p>
        <?php else: foreach ($pendingMerchants as $m): ?>
        <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center gap-3 flex-wrap">
            <div>
                <p class="font-medium text-sm"><?= adminMerchantLink((int)$m['id'], $m['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                <p class="text-xs text-gray-500"><?= merchantMailtoLink((string)$m['email']) ?> · <?= adminMerchantLink((int)$m['id'], $m['merchant_code'], 'font-mono text-sky-400') ?></p>
                <p class="text-xs text-gray-600"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></p>
            </div>
            <div class="flex gap-2 flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1 rounded-lg">View</a>
                <?php if ($canMutateKyc): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="reason" value="KYC package reviewed"><button class="text-xs bg-brand-600 text-white px-3 py-1 rounded-lg">Send for KYC approval</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
