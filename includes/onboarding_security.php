<?php
declare(strict_types=1);

function currentControlActor(): array
{
    $admin = function_exists('getAdmin') ? getAdmin() : null;
    $role = strtolower((string)($admin['role'] ?? ''));
    return [
        'type' => in_array($role, ['super', 'ceo'], true) ? 'admin' : 'staff',
        'id' => (int)($admin['id'] ?? ($_SESSION['admin_id'] ?? 0)),
        'role' => $role,
    ];
}

function merchantLiveGateReport(int $merchantId): array
{
    try {
        $st = getDB()->prepare('SELECT * FROM merchants WHERE id=?');
        $st->execute([$merchantId]);
        $merchant = $st->fetch();
        if (!$merchant) {
            return ['ok' => false, 'checks' => ['merchant' => false], 'missing' => ['merchant']];
        }
        $required = getKycRequirements((string)($merchant['business_entity_type'] ?? 'sole_proprietorship'));
        $doc = getDB()->prepare(
            "SELECT doc_type,MAX(status='approved' AND scan_status='clean') AS ready
             FROM kyc_documents WHERE merchant_id=? GROUP BY doc_type"
        );
        $doc->execute([$merchantId]);
        $readyDocs = [];
        foreach ($doc->fetchAll() as $row) {
            if ((int)$row['ready'] === 1) {
                $readyDocs[] = (string)$row['doc_type'];
            }
        }
        $bank = getDB()->prepare(
            "SELECT COUNT(*) FROM kyc_verifications
             WHERE merchant_id=? AND doc_type='bank' AND status='verified'"
        );
        $bank->execute([$merchantId]);
        $agreement = getDB()->prepare(
            'SELECT COUNT(*) AS cnt, MAX(requires_resign) AS needs_resign FROM merchant_agreement_acceptances WHERE merchant_id=? AND agreement_version=?'
        );
        $agreement->execute([$merchantId, ACTIVE_MERCHANT_AGREEMENT_VERSION]);
        $agRow = $agreement->fetch();
        $agreementAccepted = (int)($agRow['cnt'] ?? 0) > 0;
        $agreementNeedsResign = (int)($agRow['needs_resign'] ?? 0) > 0;

        $videoStatus = strtolower((string)($merchant['video_kyc_status'] ?? 'pending'));
        $checks = [
            'not_demo' => strtolower((string)$merchant['email']) !== 'demo@uniweb.co.in',
            'kyc_verified' => ($merchant['kyc_status'] ?? '') === 'verified',
            'entity_documents' => kycDocsSatisfyRequirements($required, $readyDocs),
            'bank_verified' => (int)$bank->fetchColumn() > 0 && ($merchant['bank_verification_status'] ?? 'pending') === 'verified',
            'website_verified' => ($merchant['website_status'] ?? '') === 'verified'
                && ($merchant['website_review_status'] ?? 'pending') === 'verified',
            'video_verified' => in_array($videoStatus, ['verified', 'approved'], true),
            'agreement_accepted' => $agreementAccepted && !$agreementNeedsResign,
            'merchant_active' => ($merchant['status'] ?? '') === 'active',
        ];
        return [
            'ok' => !in_array(false, $checks, true),
            'checks' => $checks,
            'missing' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'checks' => ['schema_ready' => false], 'missing' => ['schema_ready']];
    }
}

function merchantLiveGateSatisfied(int $merchantId): bool
{
    return !empty(merchantLiveGateReport($merchantId)['ok']);
}

function merchantLiveGateCheckLabels(): array
{
    return [
        'merchant' => 'Merchant record',
        'schema_ready' => 'Database ready',
        'not_demo' => 'Not a demo account',
        'kyc_verified' => 'KYC verification',
        'entity_documents' => 'Required KYC documents',
        'bank_verified' => 'Bank account verified',
        'website_verified' => 'Website reviewed',
        'video_verified' => 'Video KYC verified',
        'agreement_accepted' => 'Merchant agreement signed',
        'merchant_active' => 'Merchant account active',
    ];
}

function merchantLiveGateMissingLabels(array $report): array
{
    $labels = merchantLiveGateCheckLabels();
    $out = [];
    foreach ($report['missing'] ?? [] as $key) {
        $out[] = $labels[(string)$key] ?? str_replace('_', ' ', (string)$key);
    }
    return $out;
}

function merchantLiveGateOpsLinks(int $merchantId, array $report): array
{
    $missing = $report['missing'] ?? [];
    $links = [];
    if (in_array('entity_documents', $missing, true) || in_array('kyc_verified', $missing, true)) {
        $links[] = ['href' => 'admin_kyc.php', 'label' => 'Complete KYC docs'];
    }
    if (in_array('bank_verified', $missing, true)) {
        $links[] = ['href' => 'admin_merchant_banks.php?id=' . $merchantId, 'label' => 'Complete bank'];
    }
    if (in_array('website_verified', $missing, true)) {
        $links[] = ['href' => 'admin_website_reviews.php?q=' . urlencode((string)$merchantId), 'label' => 'Complete website'];
    }
    if (in_array('agreement_accepted', $missing, true)) {
        $links[] = ['href' => 'admin_edit_merchant.php?id=' . $merchantId, 'label' => 'Complete agreement'];
    }
    if (in_array('video_verified', $missing, true)) {
        $links[] = ['href' => 'admin_kyc.php#video-kyc-queue', 'label' => 'Complete Video KYC'];
    }
    $links[] = ['href' => 'admin_edit_merchant.php?id=' . $merchantId, 'label' => 'Merchant profile'];
    return $links;
}

function submitApprovalRequest(
    string $actionType,
    ?int $merchantId,
    ?string $resourceType,
    ?string $resourceId,
    string $reason,
    array $payload = []
): array {
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A review reason is required.');
    }
    $actor = currentControlActor();
    if ($actor['id'] < 1) {
        throw new RuntimeException('Authenticated control actor required.');
    }
    $existing = getDB()->prepare(
        "SELECT request_ref FROM approval_requests
         WHERE action_type=? AND COALESCE(merchant_id,0)=COALESCE(?,0)
           AND COALESCE(resource_type,'')=COALESCE(?,'')
           AND COALESCE(resource_id,'')=COALESCE(?,'') AND status='pending' LIMIT 1"
    );
    $existing->execute([$actionType, $merchantId, $resourceType, $resourceId]);
    if ($ref = $existing->fetchColumn()) {
        return ['created' => false, 'request_ref' => $ref];
    }
    $ref = 'APR-' . strtoupper(bin2hex(random_bytes(8)));
    $before = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES));
    getDB()->prepare(
        'INSERT INTO approval_requests
         (request_ref,action_type,merchant_id,resource_type,resource_id,requested_by_type,requested_by_id,request_reason,payload,before_hash)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $ref, $actionType, $merchantId, $resourceType, $resourceId,
        $actor['type'], $actor['id'], $reason,
        json_encode($payload, JSON_UNESCAPED_SLASHES), $before,
    ]);
    logStaffActivity('approval_requested', $actionType . ' ' . $ref . ': ' . $reason, $merchantId, $resourceType, $resourceId);
    return ['created' => true, 'request_ref' => $ref];
}

function approveApprovalRequest(int $requestId, string $checkerReason): void
{
    $checkerReason = trim($checkerReason);
    if ($checkerReason === '') {
        throw new InvalidArgumentException('Checker reason is required.');
    }
    $actor = currentControlActor();
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare("SELECT * FROM approval_requests WHERE id=? AND status='pending' FOR UPDATE");
        $st->execute([$requestId]);
        $request = $st->fetch();
        if (!$request) {
            throw new RuntimeException('Approval request is no longer pending.');
        }
        if ((int)$request['requested_by_id'] === $actor['id'] && $request['requested_by_type'] === $actor['type']) {
            // Default: independent checker. Solo launch ops: super admin may complete own request after step-up.
            if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
                throw new RuntimeException('Maker and checker must be different users.');
            }
        }
        $merchantId = (int)($request['merchant_id'] ?? 0);
        if ($merchantId > 0 && function_exists('requireMerchantAccess')) {
            requireMerchantAccess($merchantId);
        }
        applyApprovedControlAction($request);
        $after = hash('sha256', json_encode([
            'action' => $request['action_type'],
            'merchant_id' => $merchantId,
            'resource_id' => $request['resource_id'],
            'checked_at' => date('c'),
        ], JSON_UNESCAPED_SLASHES));
        $db->prepare(
            "UPDATE approval_requests SET status='approved',checked_by_type=?,checked_by_id=?,
             checker_reason=?,after_hash=?,checked_at=NOW() WHERE id=?"
        )->execute([$actor['type'], $actor['id'], $checkerReason, $after, $requestId]);
        $db->commit();
        logStaffActivity('approval_completed', $request['action_type'] . ' ' . $request['request_ref'], $merchantId ?: null, $request['resource_type'], $request['resource_id']);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function rejectApprovalRequest(int $requestId, string $checkerReason): void
{
    $checkerReason = trim($checkerReason);
    if ($checkerReason === '') {
        throw new InvalidArgumentException('Rejection reason is required.');
    }
    $actor = currentControlActor();
    getDB()->prepare(
        "UPDATE approval_requests SET status='rejected',checked_by_type=?,checked_by_id=?,
         checker_reason=?,checked_at=NOW() WHERE id=? AND status='pending'"
    )->execute([$actor['type'], $actor['id'], $checkerReason, $requestId]);
    if (function_exists('logStaffActivity')) {
        logStaffActivity('approval_rejected', $checkerReason, null, 'approval_request', (string)$requestId);
    }
}

function applyApprovedControlAction(array $request): void
{
    $db = getDB();
    $merchantId = (int)($request['merchant_id'] ?? 0);
    $resourceId = (int)($request['resource_id'] ?? 0);
    switch ((string)$request['action_type']) {
        case 'kyc_document_approve':
            $st = $db->prepare("SELECT scan_status FROM kyc_documents WHERE id=? AND merchant_id=? FOR UPDATE");
            $st->execute([$resourceId, $merchantId]);
            if ($st->fetchColumn() !== 'clean') {
                throw new RuntimeException('Document must pass malware scanning before approval.');
            }
            $db->prepare("UPDATE kyc_documents SET status='approved',reviewed_at=NOW() WHERE id=? AND merchant_id=?")
                ->execute([$resourceId, $merchantId]);
            if (!function_exists('syncMerchantKycSubmittedIfReady') && is_file(__DIR__ . '/kyc_workflow.php')) {
                require_once __DIR__ . '/kyc_workflow.php';
            }
            if (function_exists('syncMerchantKycSubmittedIfReady')) {
                syncMerchantKycSubmittedIfReady($merchantId);
            }
            break;
        case 'kyc_merchant_verify':
            if (!function_exists('completeMerchantKycVerification') && is_file(__DIR__ . '/kyc_workflow.php')) {
                require_once __DIR__ . '/kyc_workflow.php';
            }
            $result = completeMerchantKycVerification(
                $merchantId,
                'checker_approve',
                trim((string)($request['request_reason'] ?? '')) ?: 'Checker approved KYC verify'
            );
            if (empty($result['ok'])) {
                throw new RuntimeException($result['error'] ?? 'KYC verify failed.');
            }
            break;
        case 'merchant_live_enable':
            $gate = merchantLiveGateReport($merchantId);
            if (empty($gate['ok'])) {
                $missing = function_exists('merchantLiveGateMissingLabels')
                    ? merchantLiveGateMissingLabels($gate)
                    : ($gate['missing'] ?? ['unknown']);
                throw new RuntimeException('Live gates are incomplete: ' . implode(', ', $missing));
            }
            $db->prepare("UPDATE merchants SET account_mode='live',live_enabled_at=NOW(),live_enabled_by=? WHERE id=?")
                ->execute([currentControlActor()['id'], $merchantId]);
            if (function_exists('merchant_transition')) {
                merchant_transition($merchantId, 'live', 'Live activation approved by checker');
            }
            require_once __DIR__ . '/agreement_pdf.php';
            notifyMerchantLiveActivated($merchantId);
            if (!function_exists('advanceMerchantForwardAfterVerify') && is_file(__DIR__ . '/kyc_workflow.php')) {
                require_once __DIR__ . '/kyc_workflow.php';
            }
            if (function_exists('advanceMerchantForwardAfterVerify')) {
                try {
                    advanceMerchantForwardAfterVerify($merchantId);
                } catch (Throwable $e) {
                    error_log('advanceMerchantForwardAfterVerify (live_enable): ' . $e->getMessage());
                }
            }
            break;
        case 'bank_reconciliation_confirm':
            if (!function_exists('confirmBankReconciliationMatch')) {
                require_once __DIR__ . '/bank_reconciliation.php';
            }
            confirmBankReconciliationMatch($resourceId);
            break;
        default:
            throw new RuntimeException('Unsupported approval action.');
    }
}

/**
 * Super-admin one-step KYC verify (live-prep / solo ops).
 * Does NOT enable Live money — that remains a separate maker-checker gate.
 */
function verifyMerchantKycNow(int $merchantId, string $reason): void
{
    if (!function_exists('isSuperAdmin') || !isSuperAdmin()) {
        throw new RuntimeException('Only super admin can verify KYC in one step.');
    }
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A review reason is required.');
    }
    if (!function_exists('completeMerchantKycVerification') && is_file(__DIR__ . '/kyc_workflow.php')) {
        require_once __DIR__ . '/kyc_workflow.php';
    }
    $result = completeMerchantKycVerification($merchantId, 'super_solo_verify', $reason);
    if (empty($result['ok']) && empty($result['already'])) {
        throw new RuntimeException($result['error'] ?? 'KYC verify failed.');
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'kyc_merchant_verify_solo',
            $merchantId,
            'merchant',
            (string)$merchantId,
            $reason . ' [super_solo_ops]'
        );
    }
    logStaffActivity('kyc_verified_solo', $reason, $merchantId, 'merchant', (string)$merchantId);
}

