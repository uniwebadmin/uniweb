<?php
require_once __DIR__ . '/config.php';
if (is_file(__DIR__ . '/includes/release_helpers.php')) {
    require_once __DIR__ . '/includes/release_helpers.php';
}
requireStaffAccess(['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']);
ensureKycSchema();
require_once __DIR__ . '/includes/auto_kyc.php';
require_once __DIR__ . '/includes/onboarding_state_machine.php';
if (!function_exists('kycRejectReasonPresets') && is_file(__DIR__ . '/includes/kyc_entity.php')) {
    require_once __DIR__ . '/includes/kyc_entity.php';
}
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
    $docId = (int)($_POST['doc_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'Compliance review'));
    $rejectActions = ['reject_doc', 'reject_video', 'force_reject', 'reject_request'];
    if (in_array($action, $rejectActions, true)) {
        $norm = function_exists('kycNormalizeRejectReason')
            ? kycNormalizeRejectReason($reason)
            : ['ok' => strlen($reason) >= 10, 'reason' => $reason, 'error' => 'Rejection reason must be at least 10 characters. Please provide a clear explanation for the merchant.'];
        if (empty($norm['ok'])) {
            flash('error', $norm['error'] ?? 'Rejection reason must be at least 10 characters. Please provide a clear explanation for the merchant.');
            redirect('admin_kyc.php');
        }
        $reason = (string)$norm['reason'];
    }
    try {
        if (in_array($action, ['approve_doc', 'verify_merchant', 'verify_merchant_now', 'live_enable', 'verify_video', 'reject_video', 'reject_doc', 'force_hold', 'force_reject', 'force_resubmit'], true)) {
            requireStaffKycMutation();
        }
        if (in_array($action, ['approve_request', 'reject_request', 'live_enable', 'verify_merchant_now'], true) && !$canChecker) {
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
        } elseif ($action === 'verify_merchant_now') {
            requireStepUpAuth();
            if (!isSuperAdmin()) {
                throw new RuntimeException('One-step Verify is limited to super admin (solo ops).');
            }
            requireMerchantAccess($id);
            verifyMerchantKycNow($id, $reason);
            flash('success', 'Merchant KYC verified. Live money still needs the separate Live activation gate.');
        } elseif ($action === 'live_enable') {
            requireStepUpAuth();
            requireMerchantAccess($id);
            submitApprovalRequest('merchant_live_enable', $id, 'merchant', (string)$id, $reason);
            flash('success', 'Live activation sent to an independent checker.');
        } elseif ($action === 'verify_video') {
            if ($docId < 1) {
                throw new RuntimeException('Select a Video KYC recording first. Refresh the queue and try again.');
            }
            $videoDoc = $db->prepare("SELECT id, merchant_id, status FROM kyc_documents WHERE id=? AND doc_type='video_kyc' LIMIT 1");
            $videoDoc->execute([$docId]);
            $videoRow = $videoDoc->fetch();
            if (!$videoRow) {
                throw new RuntimeException('That Video KYC recording was not found. Refresh the queue and try again.');
            }
            $merchantId = (int)$videoRow['merchant_id'];
            requireMerchantAccess($merchantId);
            try {
                $db->prepare("UPDATE kyc_documents SET status='approved', rejection_reason=NULL, reviewed_at=NOW() WHERE id=? AND merchant_id=? AND doc_type='video_kyc'")
                    ->execute([$docId, $merchantId]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE kyc_documents SET status='approved', reviewed_at=NOW() WHERE id=? AND merchant_id=? AND doc_type='video_kyc'")
                    ->execute([$docId, $merchantId]);
            }
            $check = $db->prepare("SELECT status FROM kyc_documents WHERE id=? AND merchant_id=?");
            $check->execute([$docId, $merchantId]);
            if (strtolower((string)$check->fetchColumn()) !== 'approved') {
                throw new RuntimeException('Could not verify that Video KYC recording. Refresh and try again.');
            }
            $db->prepare("UPDATE merchants SET video_kyc_status='verified' WHERE id=?")->execute([$merchantId]);
            $id = $merchantId;
            recordImmutableAudit('video_kyc_verified', $merchantId, 'merchant', (string)$merchantId, $reason);
            logStaffActivity('video_kyc_verified', $reason, $merchantId, 'kyc_document', (string)$docId);
            createNotification($merchantId, 'Video KYC Verified', 'Your Video KYC was approved. Continue with remaining onboarding steps.');
            if (function_exists('sendTemplatedEmail')) {
                sendTemplatedEmail($merchantId, 'kyc_approved', []);
            }
            flash('success', 'Video KYC verified for this recording.');
        } elseif ($action === 'reject_video') {
            if ($docId < 1) {
                throw new RuntimeException('Select a Video KYC recording first. Refresh the queue and try again.');
            }
            $videoDoc = $db->prepare("SELECT id, merchant_id, status FROM kyc_documents WHERE id=? AND doc_type='video_kyc' LIMIT 1");
            $videoDoc->execute([$docId]);
            $videoRow = $videoDoc->fetch();
            if (!$videoRow) {
                throw new RuntimeException('That Video KYC recording was not found. Refresh the queue and try again.');
            }
            $merchantId = (int)$videoRow['merchant_id'];
            requireMerchantAccess($merchantId);
            try {
                $db->prepare("UPDATE kyc_documents SET status='rejected', rejection_reason=?, reviewed_at=NOW() WHERE id=? AND merchant_id=? AND doc_type='video_kyc'")
                    ->execute([$reason, $docId, $merchantId]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE kyc_documents SET status='rejected', reviewed_at=NOW() WHERE id=? AND merchant_id=? AND doc_type='video_kyc'")
                    ->execute([$docId, $merchantId]);
                if (function_exists('logPlatformError')) {
                    logPlatformError('warning', 'Video KYC reject needed fallback (rejection_reason)', ['merchant_id' => $merchantId, 'doc_id' => $docId, 'error' => $e->getMessage()]);
                }
            }
            $check = $db->prepare("SELECT status FROM kyc_documents WHERE id=? AND merchant_id=?");
            $check->execute([$docId, $merchantId]);
            if (strtolower((string)$check->fetchColumn()) !== 'rejected') {
                throw new RuntimeException('Could not reject that Video KYC recording. Refresh and try again.');
            }
            $db->prepare("UPDATE merchants SET video_kyc_status='rejected' WHERE id=?")->execute([$merchantId]);
            $id = $merchantId;
            logStaffActivity('video_kyc_rejected', $reason, $merchantId, 'kyc_document', (string)$docId);
            createNotification($merchantId, 'Video KYC Needs Re-upload', 'Reason: ' . $reason);
            if (function_exists('sendTemplatedEmail')) {
                sendTemplatedEmail($merchantId, 'kyc_rejected', ['reason' => $reason]);
            }
            flash('success', 'Video KYC rejected. Merchant sees: ' . $reason);
        } elseif ($action === 'reject_doc') {
            $doc = $db->prepare('SELECT merchant_id,doc_type FROM kyc_documents WHERE id=?');
            $doc->execute([$id]);
            $d = $doc->fetch();
            if (!$d) throw new RuntimeException('Document not found.');
            requireMerchantAccess((int)$d['merchant_id']);
            try {
                $db->prepare("UPDATE kyc_documents SET status='rejected', rejection_reason=?, reviewed_at=NOW() WHERE id=?")->execute([$reason, $id]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE kyc_documents SET status='rejected', reviewed_at=NOW() WHERE id=?")->execute([$id]);
            }
            $db->prepare("UPDATE merchants SET kyc_status='submitted',onboarding_state='clarification',account_mode='test' WHERE id=?")->execute([(int)$d['merchant_id']]);
            logStaffActivity('kyc_clarification_requested', $d['doc_type'] . ': ' . $reason, (int)$d['merchant_id'], 'kyc_document', (string)$id);
            $docLabel = str_replace('_', ' ', (string)$d['doc_type']);
            createNotification((int)$d['merchant_id'], 'KYC Document Rejected', ucfirst($docLabel) . ' — ' . $reason . ' Please re-upload a clearer copy.');
            if (function_exists('sendTemplatedEmail')) {
                sendTemplatedEmail((int)$d['merchant_id'], 'kyc_rejected', ['reason' => ucfirst($docLabel) . ' — ' . $reason]);
            }
            flash('success', 'Document rejected. Merchant sees: ' . $reason);
        } elseif ($action === 'approve_request') {
            requireStepUpAuth();
            approveApprovalRequest($id, $reason);
            flash('success', 'Independent checker approval completed.');
        } elseif ($action === 'reject_request') {
            rejectApprovalRequest($id, $reason);
            flash('success', 'Approval request rejected.');
        } elseif ($action === 'force_hold' && $canMutateKyc) {
            // D1: Admin force hold with reason
            requireMerchantAccess($id);
            $tr = merchant_transition($id, 'hold', $reason);
            if (!$tr['ok']) throw new RuntimeException($tr['error']);
            logStaffActivity('kyc_force_hold', $reason, $id, 'merchant', (string)$id);
            flash('success', 'Merchant put on hold. Reason shown to merchant.');
        } elseif ($action === 'force_reject' && $canMutateKyc) {
            // D1: Admin force reject with reason
            requireMerchantAccess($id);
            $tr = merchant_transition($id, 'rejected', $reason);
            if (!$tr['ok']) throw new RuntimeException($tr['error']);
            logStaffActivity('kyc_force_reject', $reason, $id, 'merchant', (string)$id);
            flash('success', 'Merchant rejected. Reason shown to merchant.');
        } elseif ($action === 'force_resubmit' && $canMutateKyc) {
            // D1: Admin allow merchant to resubmit
            requireMerchantAccess($id);
            $tr = merchant_transition($id, 'kyc_submitted', $reason ?: 'Admin requested resubmission');
            if (!$tr['ok']) throw new RuntimeException($tr['error']);
            logStaffActivity('kyc_force_resubmit', $reason, $id, 'merchant', (string)$id);
            flash('success', 'Merchant can now resubmit KYC.');
        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        if (function_exists('logPlatformError')) {
            logPlatformError('error', 'admin_kyc action failed: ' . $action, ['merchant_id' => $id, 'error' => $e->getMessage()]);
        }
    }
    redirect('admin_kyc.php');
}

$pendingDocs = [];
try {
    $pendingDocs = $db->query(
        "SELECT k.*, m.business_name, m.merchant_code, m.business_entity_type
         FROM kyc_documents k
         JOIN merchants m ON k.merchant_id = m.id
         WHERE k.status COLLATE utf8mb4_unicode_ci = 'pending'
         ORDER BY k.created_at ASC"
    )->fetchAll();
} catch (Throwable $e) {
    try {
        $pendingDocs = $db->query(
            "SELECT k.*, m.business_name, m.merchant_code, m.business_entity_type
             FROM kyc_documents k
             JOIN merchants m ON k.merchant_id = m.id
             WHERE k.status = 'pending'
             ORDER BY k.created_at ASC"
        )->fetchAll();
    } catch (Throwable $e2) {
        $pendingDocs = [];
    }
}
$pendingMerchants = [];
$recentSignups = [];
try {
    $pendingMerchants = function_exists('getPendingKycQueue') ? getPendingKycQueue(50) : [];
} catch (Throwable $e) {
    $pendingMerchants = [];
}
try {
    $recentSignups = function_exists('getRecentSignupQueue') ? getRecentSignupQueue(12) : [];
} catch (Throwable $e) {
    $recentSignups = [];
}
$approvalQueue = [];
try {
    $approvalQueue = $db->query(
        "SELECT r.*, m.business_name
         FROM approval_requests r
         LEFT JOIN merchants m ON m.id = r.merchant_id
         WHERE r.status = 'pending' AND r.action_type IN ('kyc_document_approve','kyc_merchant_verify','merchant_live_enable')
         ORDER BY r.requested_at ASC LIMIT 50"
    )->fetchAll();
} catch (Throwable $e) {
    $approvalQueue = [];
}
$liveCandidates = [];
try {
    $liveCandidates = $db->query(
        "SELECT id, business_name, merchant_code FROM merchants
         WHERE status = 'active' AND kyc_status = 'verified' AND account_mode = 'test'
           AND email <> CONVERT('demo@uniweb.co.in' USING utf8mb4)
         ORDER BY id ASC LIMIT 50"
    )->fetchAll();
} catch (Throwable $e) {
    try {
        $liveCandidates = $db->query(
            "SELECT id, business_name, merchant_code FROM merchants
             WHERE status = 'active' AND kyc_status = 'verified' AND account_mode = 'test'
               AND email <> 'demo@uniweb.co.in'
             ORDER BY id ASC LIMIT 50"
        )->fetchAll();
    } catch (Throwable $e2) {
        $liveCandidates = [];
    }
}
$videoQueue = [];
try {
    $videoQueue = $db->query(
        "SELECT m.id, m.business_name, m.merchant_code, m.video_kyc_status, m.kyc_status,
                k.id AS doc_id, k.created_at AS video_uploaded_at, k.ip_address AS video_ip, k.recorded_at AS video_recorded_at
         FROM merchants m
         INNER JOIN kyc_documents k ON k.id = (
             SELECT k2.id FROM kyc_documents k2
             WHERE k2.merchant_id = m.id AND k2.doc_type COLLATE utf8mb4_unicode_ci = 'video_kyc'
             ORDER BY k2.created_at DESC LIMIT 1
         )
         WHERE m.status = 'active'
           AND m.email <> CONVERT('demo@uniweb.co.in' USING utf8mb4)
           AND COALESCE(m.video_kyc_status, 'pending') IN ('submitted', 'pending')
         ORDER BY k.created_at ASC
         LIMIT 50"
    )->fetchAll();
} catch (Throwable $e) {
    try {
        $videoQueue = $db->query(
            "SELECT m.id, m.business_name, m.merchant_code, m.video_kyc_status, m.kyc_status,
                    k.id AS doc_id, k.created_at AS video_uploaded_at, k.ip_address AS video_ip, k.recorded_at AS video_recorded_at
             FROM merchants m
             INNER JOIN kyc_documents k ON k.id = (
                 SELECT k2.id FROM kyc_documents k2
                 WHERE k2.merchant_id = m.id AND k2.doc_type = 'video_kyc'
                 ORDER BY k2.created_at DESC LIMIT 1
             )
             WHERE m.status = 'active'
               AND m.email <> 'demo@uniweb.co.in'
               AND COALESCE(m.video_kyc_status, 'pending') IN ('submitted', 'pending')
             ORDER BY k.created_at ASC
             LIMIT 50"
        )->fetchAll();
    } catch (Throwable $e2) {
        $videoQueue = [];
    }
}
if (!isSuperAdmin()) {
    $pendingDocs = array_values(array_filter($pendingDocs, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
    $pendingMerchants = array_values(array_filter($pendingMerchants, static fn(array $row): bool => staffHasMerchantAccess((int)$row['id'])));
    $recentSignups = array_values(array_filter($recentSignups, static fn(array $row): bool => staffHasMerchantAccess((int)$row['id'])));
    $approvalQueue = array_values(array_filter($approvalQueue, static fn(array $row): bool => empty($row['merchant_id']) || staffHasMerchantAccess((int)$row['merchant_id'])));
    $liveCandidates = array_values(array_filter($liveCandidates, static fn(array $row): bool => staffHasMerchantAccess((int)$row['id'])));
    $videoQueue = array_values(array_filter($videoQueue, static fn(array $row): bool => staffHasMerchantAccess((int)$row['id'])));
}

// D2: Manual assist lane — merchants that failed auto-KYC N times
$manualAssistQueue = [];
try {
    if (function_exists('getKycMaxFailures')) {
        $maxFail = getKycMaxFailures();
        $manualAssistQueue = $db->prepare(
            "SELECT m.id, m.business_name, m.merchant_code, m.onboarding_state, m.kyc_status,
                    (SELECT COUNT(*) FROM auto_kyc_runs WHERE merchant_id=m.id AND action=?) AS fail_count
             FROM merchants m
             WHERE m.onboarding_state = 'hold'
               AND m.status NOT IN ('blocked','suspended','deleted')
               AND m.email <> 'demo@uniweb.co.in'
             ORDER BY fail_count DESC LIMIT 20"
        );
        $manualAssistQueue->execute([getKycFailAction()]);
        $manualAssistQueue = $manualAssistQueue->fetchAll();
    }
} catch (Throwable $e) {
    $manualAssistQueue = [];
}

$pageTitle = 'KYC Review';
require_once __DIR__ . '/header.php';
?>

<div class="glass rounded-xl p-5 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Go-live path: Signup → Docs → Verify → Live</p>
    <p class="text-xs text-gray-500">Work this page top-down: Video queue → Pending documents → <a href="#verify-queue" class="text-sky-400 hover:underline">Verify queue</a> → Live activation gate. Reject reasons must be clear sentences (merchant sees the same text). Partner auto-forward runs on the existing queue when keys + commercial are set — no separate KYC product.</p>
</div>

<div class="mb-6 flex flex-col sm:flex-row flex-wrap gap-3">
    <?php if (isSuperAdmin()): ?>
    <a href="admin_watchdog.php" class="glass px-4 py-2 rounded-xl text-sm text-amber-400 text-center w-full sm:w-auto">Link Watchdog</a>
    <?php endif; ?>
    <a href="admin_auto_kyc.php" class="glass px-4 py-2 rounded-xl text-sm text-violet-300 text-center w-full sm:w-auto">Auto KYC + Partner forward</a>
    <a href="manage_merchant.php" class="glass px-4 py-2 rounded-xl text-sm text-gray-300 text-center w-full sm:w-auto">All Merchants</a>
</div>

<?php if (!$canMutateKyc): ?>
<div class="glass rounded-xl p-4 mb-6 border border-amber-500/20 text-sm text-amber-300">View-only: your role can inspect KYC but cannot approve, reject, or enable Live.</div>
<?php endif; ?>

<?php if (!empty($approvalQueue) && $canChecker): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-amber-500/30 min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Independent checker queue</h2><p class="text-xs text-gray-500 mt-1">Maker and checker should be different users. Super admin may complete their own request after step-up (solo launch ops). CSRF + step-up on approve.</p></div>
    <?php foreach ($approvalQueue as $request): ?>
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-between gap-3">
        <div class="min-w-0"><p class="text-sm font-medium break-words"><?= e(str_replace('_', ' ', $request['action_type'])) ?> · <?= e($request['business_name'] ?? 'Platform') ?></p><p class="text-xs text-gray-500 break-words"><?= e($request['request_ref']) ?> · <?= e($request['request_reason']) ?></p></div>
        <div class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto">
            <form method="post" class="flex flex-col sm:flex-row gap-2 min-w-0 flex-1"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>"><input name="reason" required maxlength="500" placeholder="Checker reason" aria-label="Checker reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 w-full"><button class="text-xs bg-emerald-600 text-white px-3 py-2 rounded-lg w-full sm:w-auto">Approve</button></form>
            <form method="post" class="flex flex-col sm:flex-row gap-2 min-w-0 flex-1"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="id" value="<?= (int)$request['id'] ?>"><input name="reason" required maxlength="500" placeholder="Rejection reason" aria-label="Rejection reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 w-full"><button class="text-xs bg-red-600/20 text-red-400 px-3 py-2 rounded-lg w-full sm:w-auto">Reject</button></form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif (!empty($approvalQueue) && !$canChecker): ?>
<div class="glass rounded-xl p-4 mb-6 text-sm text-gray-400"><?= count($approvalQueue) ?> approval request(s) waiting for a KYC/ops checker.</div>
<?php endif; ?>

<?php if (!empty($videoQueue) && $canMutateKyc): ?>
<div id="video-kyc-queue" class="glass rounded-xl overflow-hidden mb-8 border border-violet-500/30 min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Video KYC queue</h2>
        <p class="text-xs text-gray-500 mt-1">Review the live camera recording (IP + date/time). Verify or reject this row. Status must be <span class="text-violet-300">verified</span> for the Live gate.</p>
    </div>
    <?php foreach ($videoQueue as $videoRow): ?>
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-medium break-words"><?= adminMerchantLink((int)$videoRow['id'], (string)($videoRow['business_name'] ?: 'Merchant'), 'font-medium text-white hover:text-sky-300') ?> · <?= adminMerchantLink((int)$videoRow['id'], (string)$videoRow['merchant_code'], 'font-mono text-sky-400 text-xs') ?></p>
            <p class="text-xs text-gray-500">Video status: <?= e((string)($videoRow['video_kyc_status'] ?? 'pending')) ?> · KYC: <?= e((string)($videoRow['kyc_status'] ?? '')) ?><?php if (!empty($videoRow['video_uploaded_at'])): ?> · Uploaded <?= e(formatDate($videoRow['video_uploaded_at'])) ?><?php endif; ?><?php if (!empty($videoRow['video_ip'])): ?> · IP <?= e($videoRow['video_ip']) ?><?php endif; ?><?php if (!empty($videoRow['video_recorded_at'])): ?> · Recorded <?= e(formatDate($videoRow['video_recorded_at'])) ?><?php endif; ?></p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2 flex-wrap w-full sm:w-auto">
            <?php if (!empty($videoRow['doc_id'])): ?>
            <a href="admin_kyc_doc.php?id=<?= (int)$videoRow['doc_id'] ?>&token=<?= csrfToken() ?>" target="_blank" rel="noopener" class="text-xs bg-sky-600/20 text-sky-400 px-3 py-2 rounded-lg text-center">Play / view video</a>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_video"><input type="hidden" name="id" value="<?= (int)$videoRow['id'] ?>"><input type="hidden" name="doc_id" value="<?= (int)$videoRow['doc_id'] ?>"><input type="hidden" name="reason" value="Video KYC reviewed"><button class="text-xs bg-violet-600 text-white px-3 py-2 rounded-lg w-full sm:w-auto">Mark video verified</button></form>
            <form method="post" class="flex flex-col sm:flex-row gap-2 items-stretch sm:items-center min-w-0 flex-1"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_video"><input type="hidden" name="id" value="<?= (int)$videoRow['id'] ?>"><input type="hidden" name="doc_id" value="<?= (int)$videoRow['doc_id'] ?>"><div class="flex flex-col gap-1 w-full min-w-0"><div class="flex flex-wrap gap-1 mb-1"><?php foreach (kycRejectReasonPresets('video') as $preset): ?><button type="button" onclick="this.closest('form').querySelector('textarea[name=reason]').value=this.textContent" class="text-[10px] px-2 py-1 rounded border border-gray-700 text-gray-400 hover:text-sky-400 hover:border-sky-500/30"><?= e($preset) ?></button><?php endforeach; ?></div><textarea name="reason" required minlength="10" maxlength="500" rows="2" placeholder="Rejection reason (min 10 chars — shown to merchant)" aria-label="Video rejection reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 w-full min-w-0"></textarea></div><button class="text-xs bg-red-600/20 text-red-400 px-3 py-2 rounded-lg w-full sm:w-auto shrink-0">Reject video</button></form>
            <?php else: ?>
            <p class="text-xs text-amber-400">No video row to verify. Ask the merchant to record again with live camera.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php elseif (empty($videoQueue) && $canMutateKyc): ?>
<div class="glass rounded-xl p-4 mb-6 text-sm text-gray-500 border border-gray-800">Video KYC queue is clear — no submitted videos waiting for review.</div>
<?php endif; ?>

<?php if (!empty($manualAssistQueue) && $canMutateKyc): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-red-500/30 min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold text-red-400">Manual assist lane</h2>
        <p class="text-xs text-gray-500 mt-1">Merchants that failed auto-KYC verification <?= function_exists('getKycMaxFailures') ? getKycMaxFailures() : 3 ?>+ times. Staff can review, correct, or upload on their behalf — same queue, no parallel product.</p>
    </div>
    <?php foreach ($manualAssistQueue as $ma): ?>
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-medium break-words"><?= adminMerchantLink((int)$ma['id'], (string)($ma['business_name'] ?: 'Merchant'), 'font-medium text-white hover:text-sky-300') ?> · <?= adminMerchantLink((int)$ma['id'], (string)$ma['merchant_code'], 'font-mono text-sky-400 text-xs') ?></p>
            <p class="text-xs text-red-400">Failed <?= (int)$ma['fail_count'] ?> time(s) · State: <?= e($ma['onboarding_state']) ?></p>
        </div>
        <div class="flex gap-2">
            <a href="admin_edit_merchant.php?id=<?= (int)$ma['id'] ?>" class="text-xs bg-sky-600/20 text-sky-300 px-3 py-2 rounded-lg">Review & Fix</a>
            <a href="admin_kyc_doc.php?merchant_id=<?= (int)$ma['id'] ?>" class="text-xs bg-violet-600/20 text-violet-300 px-3 py-2 rounded-lg">View Documents</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($liveCandidates) && $canMutateKyc): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-emerald-500/20 min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Live activation gate</h2><p class="text-xs text-gray-500 mt-1">KYC verification alone does not enable real money. Every server-side gate must pass. Step-up required to send Live activation.</p></div>
    <?php foreach ($liveCandidates as $candidate):
        $gate = merchantLiveGateReport((int)$candidate['id']);
        $missingLabels = merchantLiveGateMissingLabels($gate);
        $opsLinks = merchantLiveGateOpsLinks((int)$candidate['id'], $gate);
        $liveVidId = 0;
        try {
            $vidSt = $db->prepare("SELECT id FROM kyc_documents WHERE merchant_id=? AND doc_type='video_kyc' ORDER BY created_at DESC LIMIT 1");
            $vidSt->execute([(int)$candidate['id']]);
            $liveVidId = (int)$vidSt->fetchColumn();
        } catch (Throwable $e) {
            $liveVidId = 0;
        }
    ?>
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-col sm:flex-row flex-wrap items-stretch sm:items-center justify-between gap-3">
        <div class="min-w-0"><p class="text-sm font-medium break-words"><?= adminMerchantLink((int)$candidate['id'], (string)($candidate['business_name'] ?: 'Merchant'), 'font-medium text-white hover:text-sky-300') ?> · <?= adminMerchantLink((int)$candidate['id'], (string)$candidate['merchant_code'], 'font-mono text-sky-400 text-xs') ?></p><p class="text-xs <?= $gate['ok'] ? 'text-emerald-400' : 'text-amber-400' ?>"><?= $gate['ok'] ? 'All gates complete' : 'Missing: ' . e(implode(', ', $missingLabels)) ?></p></div>
        <div class="flex flex-col sm:flex-row gap-2 flex-wrap w-full sm:w-auto">
            <?php foreach ($opsLinks as $opsLink): ?>
            <a href="<?= e($opsLink['href']) ?>" class="text-xs bg-gray-700/40 text-gray-300 px-3 py-2 rounded-lg text-center"><?= e($opsLink['label']) ?></a>
            <?php endforeach; ?>
            <?php if ($liveVidId > 0 && in_array('video_verified', $gate['missing'] ?? [], true)): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_video"><input type="hidden" name="id" value="<?= (int)$candidate['id'] ?>"><input type="hidden" name="doc_id" value="<?= $liveVidId ?>"><input type="hidden" name="reason" value="Video reviewed"><button class="text-xs bg-violet-600/20 text-violet-300 px-3 py-2 rounded-lg w-full sm:w-auto">Mark video verified</button></form>
            <?php endif; ?>
            <?php if ($gate['ok'] && $canChecker): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="live_enable"><input type="hidden" name="id" value="<?= (int)$candidate['id'] ?>"><input type="hidden" name="reason" value="All production onboarding gates verified"><button class="text-xs bg-emerald-600 text-white px-3 py-2 rounded-lg w-full sm:w-auto">Send Live activation for approval</button></form><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($recentSignups)): ?>
<div class="glass rounded-xl overflow-hidden mb-8 border border-sky-500/20 min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Recent signups — KYC review (Individual / Freelancer first)</h2>
        <p class="text-xs text-gray-500 mt-1">Maker can send KYC to the checker queue, or super admin can <strong class="font-medium text-gray-400">Verify KYC now</strong> (step-up). Live money still needs the separate Live activation gate.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-[720px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                <tr>
                    <th class="px-4 sm:px-5 py-3 text-left">Code</th>
                    <th class="px-4 sm:px-5 py-3 text-left">Business</th>
                    <th class="px-4 sm:px-5 py-3 text-left">Entity</th>
                    <th class="px-4 sm:px-5 py-3 text-left">Contact</th>
                    <th class="px-4 sm:px-5 py-3 text-left">KYC</th>
                    <th class="px-4 sm:px-5 py-3 text-left">Joined</th>
                    <th class="px-4 sm:px-5 py-3 text-left">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($recentSignups as $m):
                    $mid = (int)$m['id'];
                    $waUrl = merchantWhatsAppUrl($m['phone'] ?? null);
                    $canVerify = in_array(($m['kyc_status'] ?? ''), ['pending', 'submitted'], true);
                ?>
                <tr>
                    <td class="px-4 sm:px-5 py-3 font-mono text-xs"><?= adminMerchantLink($mid, $m['merchant_code']) ?></td>
                    <td class="px-4 sm:px-5 py-3"><?= adminMerchantLink($mid, $m['business_name'], 'font-medium hover:text-sky-300') ?></td>
                    <td class="px-4 sm:px-5 py-3 text-xs"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></td>
                    <td class="px-4 sm:px-5 py-3 text-xs">
                        <?= merchantMailtoLink((string)$m['email'], $m['email'], 'text-gray-400 hover:text-sky-300') ?>
                        <?php if ($waUrl): ?><br><a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="text-emerald-400">WhatsApp</a><?php endif; ?>
                    </td>
                    <td class="px-4 sm:px-5 py-3"><?= statusBadge($m['kyc_status']) ?></td>
                    <td class="px-4 sm:px-5 py-3 text-xs text-gray-500"><?= formatDate($m['created_at']) ?></td>
                    <td class="px-4 sm:px-5 py-3 text-xs whitespace-nowrap">
                        <a href="<?= e(adminMerchantUrl($mid)) ?>" class="text-gray-400 hover:text-white mr-2">View</a>
                        <?php if ($canVerify && $canMutateKyc): ?>
                        <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant"><input type="hidden" name="id" value="<?= $mid ?>"><input type="hidden" name="reason" value="Entity documents reviewed"><button class="text-brand-400 hover:text-brand-300 mr-2">Send for KYC approval</button></form>
                        <?php if (isSuperAdmin()): ?>
                        <form method="post" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant_now"><input type="hidden" name="id" value="<?= $mid ?>"><input type="hidden" name="reason" value="Entity documents reviewed — super solo verify"><button class="text-emerald-400 hover:text-emerald-300">Verify KYC now</button></form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
    <div class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Pending documents</h2>
            <p class="text-xs text-gray-500 mt-1">Approve docs (maker) before the independent checker verifies the merchant</p>
        </div>
        <?php if (empty($pendingDocs)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No pending documents</p>
        <?php else: foreach ($pendingDocs as $doc): ?>
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
            <div class="flex justify-between items-start mb-2 gap-2">
                <div class="min-w-0">
                    <p class="font-medium text-sm"><?= adminMerchantLink((int)$doc['merchant_id'], $doc['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                    <p class="text-xs text-gray-500 capitalize break-words"><?= e(entityTypeLabel($doc['business_entity_type'] ?? '')) ?> — <?= str_replace('_', ' ', $doc['doc_type']) ?><?php if (!empty($doc['is_masked'])): ?> <span class="inline-block px-1.5 py-0.5 bg-emerald-600/20 text-emerald-400 rounded text-[10px] font-medium">Aadhaar Masked</span><?php endif; ?><?php if ((int)($doc['version_number'] ?? 1) > 1): ?> <span class="inline-block px-1.5 py-0.5 bg-sky-600/20 text-sky-400 rounded text-[10px] font-medium">v<?= (int)$doc['version_number'] ?></span><?php endif; ?></p>
                </div>
                <?= statusBadge($doc['status']) ?>
            </div>
            <div class="flex gap-2 mt-2 flex-wrap">
                <a href="<?= e(adminMerchantUrl((int)$doc['merchant_id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1.5 rounded-lg">View Merchant</a>
                <a href="admin_kyc_doc.php?id=<?= $doc['id'] ?>&token=<?= csrfToken() ?>" target="_blank" class="text-xs bg-sky-600/20 text-sky-400 px-3 py-1.5 rounded-lg">View Doc</a>
                <?php if ($canMutateKyc): ?>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="approve_doc"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><input type="hidden" name="reason" value="Document content reviewed"><button class="text-xs bg-brand-600/20 text-brand-400 px-3 py-1.5 rounded-lg">Send for approval</button></form>
                <form method="post" class="flex flex-col sm:flex-row gap-2 w-full sm:w-auto min-w-0"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="reject_doc"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><div class="flex flex-col gap-1 w-full min-w-0"><div class="flex flex-wrap gap-1 mb-1"><?php foreach (kycRejectReasonPresets('document') as $preset): ?><button type="button" onclick="this.closest('form').querySelector('textarea[name=reason]').value=this.textContent" class="text-[10px] px-2 py-1 rounded border border-gray-700 text-gray-400 hover:text-sky-400 hover:border-sky-500/30"><?= e($preset) ?></button><?php endforeach; ?></div><textarea name="reason" required minlength="10" maxlength="500" rows="2" placeholder="Clarification reason (min 10 chars — shown to merchant)" aria-label="Document rejection reason" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5 w-full"></textarea></div><button class="text-xs bg-red-600/20 text-red-400 px-3 py-1.5 rounded-lg w-full sm:w-auto shrink-0">Reject</button></form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div id="verify-queue" class="glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800">
            <h2 class="font-semibold">Verify queue — KYC approve</h2>
            <p class="text-xs text-gray-500 mt-1">Use <strong class="text-emerald-400">Verify KYC now</strong> (super) or Send for KYC approval (checker). Then Live gate. Partner forward: keys + contract first — same Auto KYC queue, not a new product.</p>
        </div>
        <?php if (empty($pendingMerchants)): ?>
        <p class="text-gray-500 text-sm text-center py-8">Verify queue clear — no merchants waiting.</p>
        <?php else: foreach ($pendingMerchants as $m): ?>
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-col sm:flex-row justify-between items-stretch sm:items-center gap-3">
            <div class="min-w-0">
                <p class="font-medium text-sm"><?= adminMerchantLink((int)$m['id'], $m['business_name'], 'font-medium text-sm text-white hover:text-sky-300') ?></p>
                <p class="text-xs text-gray-500 break-all"><?= merchantMailtoLink((string)$m['email']) ?> · <?= adminMerchantLink((int)$m['id'], $m['merchant_code'], 'font-mono text-sky-400') ?></p>
                <p class="text-xs text-gray-600"><?= e(entityTypeLabel($m['business_entity_type'] ?? '')) ?></p>
            </div>
            <div class="flex gap-2 flex-wrap w-full sm:w-auto">
                <a href="<?= e(adminMerchantUrl((int)$m['id'])) ?>" class="text-xs bg-gray-700/50 text-gray-300 px-3 py-1.5 rounded-lg text-center flex-1 sm:flex-none">View</a>
                <?php if ($canMutateKyc): ?>
                <form method="post" class="flex-1 sm:flex-none"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="reason" value="KYC package reviewed"><button class="text-xs bg-brand-600 text-white px-3 py-1.5 rounded-lg w-full">Send for KYC approval</button></form>
                <?php if (isSuperAdmin()): ?>
                <form method="post" class="flex-1 sm:flex-none"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify_merchant_now"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><input type="hidden" name="reason" value="KYC package reviewed — super solo verify"><button class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded-lg w-full">Verify KYC now</button></form>
                <?php endif; ?>
                <a href="admin_gateway_submit.php?merchant_id=<?= (int)$m['id'] ?>" class="text-xs bg-violet-600/20 text-violet-400 px-3 py-1.5 rounded-lg text-center flex-1 sm:flex-none">1-Click Partner Forward</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
