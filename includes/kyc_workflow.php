<?php
declare(strict_types=1);

/**
 * Canonical KYC workflow — single verify path for manual, auto, and checker (diagrams 1–6).
 *
 * Master truth: merchants.kyc_status + onboarding_state (via merchant_transition).
 * Helpers: kyc_documents, kyc_verifications, approval_requests, partner_forward_queue.
 */

function merchantKycVerifyCheckLabels(): array
{
    return [
        'merchant' => 'Merchant record',
        'not_deleted' => 'Account active',
        'kyc_submittable' => 'KYC status allows verify',
        'entity_documents' => 'Required documents approved & clean',
        'no_pending_required_docs' => 'No pending required documents',
        'no_rejected_docs' => 'No rejected required documents',
        'video_verified' => 'Video KYC verified (when required)',
        'no_risk_block' => 'No high-risk block',
    ];
}

/**
 * Readiness report for merchant KYC verify (not Live enable — that uses merchantLiveGateReport).
 *
 * @return array{ok:bool,checks:array<string,bool>,missing:list<string>,merchant_id:int}
 */
function merchantKycReadinessReport(int $merchantId): array
{
    if ($merchantId < 1) {
        return ['ok' => false, 'checks' => ['merchant' => false], 'missing' => ['merchant'], 'merchant_id' => 0];
    }
    if (!function_exists('getKycRequirements') && is_file(__DIR__ . '/kyc_entity.php')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    try {
        $st = getDB()->prepare('SELECT id, status, kyc_status, onboarding_state, business_entity_type, video_kyc_status, account_mode FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $merchant = $st->fetch();
        if (!$merchant) {
            return ['ok' => false, 'checks' => ['merchant' => false], 'missing' => ['merchant'], 'merchant_id' => $merchantId];
        }

        $kycStatus = strtolower(trim((string)($merchant['kyc_status'] ?? '')));
        $required = getKycRequirements((string)($merchant['business_entity_type'] ?? 'sole_proprietorship'));

        $docSt = getDB()->prepare(
            "SELECT doc_type, status, scan_status
             FROM kyc_documents WHERE merchant_id=? ORDER BY created_at DESC"
        );
        $docSt->execute([$merchantId]);
        $latestByType = [];
        foreach ($docSt->fetchAll() as $row) {
            $t = (string)($row['doc_type'] ?? '');
            if ($t !== '' && !isset($latestByType[$t])) {
                $latestByType[$t] = $row;
            }
        }

        $readyDocTypes = [];
        $hasPendingRequired = false;
        $hasRejectedRequired = false;
        foreach ($required as $reqType) {
            if (!isset($latestByType[$reqType])) {
                continue;
            }
            $doc = $latestByType[$reqType];
            $status = (string)($doc['status'] ?? '');
            $scan = (string)($doc['scan_status'] ?? '');
            if ($status === 'rejected') {
                $hasRejectedRequired = true;
            }
            if ($status === 'pending' || $scan !== 'clean') {
                $hasPendingRequired = true;
            }
            if ($status === 'approved' && $scan === 'clean') {
                $readyDocTypes[] = $reqType;
            }
        }

        $entityDocsOk = kycDocsSatisfyRequirements($required, $readyDocTypes);

        $videoOk = true;
        if (function_exists('checkVideoKycCompleted')) {
            $videoOk = checkVideoKycCompleted($merchantId);
        }

        $riskBlock = false;
        if (function_exists('merchantHasRiskFlags')) {
            $riskBlock = merchantHasRiskFlags($merchantId);
        }

        $checks = [
            'merchant' => true,
            'not_deleted' => ($merchant['status'] ?? '') !== 'deleted',
            'kyc_submittable' => in_array($kycStatus, ['pending', 'submitted', 'clarification'], true),
            'entity_documents' => $entityDocsOk,
            'no_pending_required_docs' => !$hasPendingRequired,
            'no_rejected_docs' => !$hasRejectedRequired,
            'video_verified' => $videoOk,
            'no_risk_block' => !$riskBlock,
        ];

        if ($kycStatus === 'verified') {
            return ['ok' => true, 'checks' => $checks, 'missing' => [], 'merchant_id' => $merchantId, 'already_verified' => true];
        }

        return [
            'ok' => !in_array(false, $checks, true),
            'checks' => $checks,
            'missing' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
            'merchant_id' => $merchantId,
            'already_verified' => false,
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'checks' => ['schema_ready' => false], 'missing' => ['schema_ready'], 'merchant_id' => $merchantId];
    }
}

function merchantKycReadinessMissingLabels(array $report): array
{
    $labels = merchantKycVerifyCheckLabels();
    $out = [];
    foreach ($report['missing'] ?? [] as $key) {
        $out[] = $labels[(string)$key] ?? str_replace('_', ' ', (string)$key);
    }
    return $out;
}

/**
 * Mark merchant as submitted (diagram 3 — after docs uploaded).
 */
function markMerchantKycSubmitted(int $merchantId): void
{
    if ($merchantId < 1) {
        return;
    }
    if (!function_exists('merchant_transition')) {
        require_once __DIR__ . '/onboarding_state_machine.php';
    }
    $tr = merchant_transition($merchantId, 'kyc_submitted', 'KYC documents submitted');
    if (!$tr['ok']) {
        try {
            getDB()->prepare(
                "UPDATE merchants SET kyc_status='submitted', onboarding_state='kyc_submitted',
                 onboarding_submitted_at=COALESCE(onboarding_submitted_at, NOW()), account_mode='test' WHERE id=?"
            )->execute([$merchantId]);
        } catch (Throwable $e) {
            getDB()->prepare("UPDATE merchants SET kyc_status='submitted', account_mode='test' WHERE id=?")
                ->execute([$merchantId]);
        }
    }
}

/**
 * Single canonical KYC verify + forward enqueue (manual, auto, checker).
 *
 * @return array{ok:bool,error?:string,already?:bool}
 */
function completeMerchantKycVerification(int $merchantId, string $source, string $reason = ''): array
{
    $source = trim($source) !== '' ? trim($source) : 'system';
    $report = merchantKycReadinessReport($merchantId);
    if (!empty($report['already_verified'])) {
        return ['ok' => true, 'already' => true];
    }
    if (empty($report['ok'])) {
        $missing = merchantKycReadinessMissingLabels($report);
        return ['ok' => false, 'error' => 'KYC verify blocked: ' . implode(', ', $missing)];
    }

    if (!function_exists('merchant_transition')) {
        require_once __DIR__ . '/onboarding_state_machine.php';
    }

    $tr = merchant_transition($merchantId, 'kyc_verified', $reason !== '' ? $reason : ('KYC verified (' . $source . ')'));
    if (!$tr['ok']) {
        try {
            getDB()->prepare(
                "UPDATE merchants SET kyc_status='verified', onboarding_state='kyc_verified', account_mode='test' WHERE id=? AND kyc_status IN ('pending','submitted','clarification')"
            )->execute([$merchantId]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not set KYC verified: ' . $e->getMessage()];
        }
    }

    if (!function_exists('resolveKycPendingFlags') && is_file(__DIR__ . '/risk.php')) {
        require_once __DIR__ . '/risk.php';
    }
    if (function_exists('resolveKycPendingFlags')) {
        try {
            resolveKycPendingFlags($merchantId);
        } catch (Throwable $e) { /* ok */ }
    }

    if (!function_exists('afterKycVerifiedAutoSendMethods') && is_file(__DIR__ . '/method_requests.php')) {
        require_once __DIR__ . '/method_requests.php';
    }
    if (function_exists('afterKycVerifiedAutoSendMethods')) {
        try {
            afterKycVerifiedAutoSendMethods($merchantId, $source);
        } catch (Throwable $e) {
            error_log('afterKycVerifiedAutoSendMethods (' . $source . '): ' . $e->getMessage());
        }
    }

    if (!function_exists('enqueueMerchantToAllEnabledPartners') && is_file(__DIR__ . '/partner_forward_queue.php')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (function_exists('enqueueMerchantToAllEnabledPartners')) {
        try {
            enqueueMerchantToAllEnabledPartners($merchantId);
        } catch (Throwable $e) {
            error_log('enqueueMerchantToAllEnabledPartners (' . $source . '): ' . $e->getMessage());
        }
    }

    merchant_transition($merchantId, 'queue_forward', 'Enqueued for partner forward');

    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'kyc_verified',
            $merchantId,
            'merchant',
            (string)$merchantId,
            ($reason !== '' ? $reason . ' — ' : '') . 'source=' . $source
        );
    }

    $eventKey = 'kyc_verified_' . $merchantId;
    if (function_exists('notifyMerchant')) {
        notifyMerchant(
            $merchantId,
            'KYC Verified',
            'Your KYC is verified. Documents are queued for partner submission. Live money needs a separate activation step.',
            $eventKey
        );
    } elseif (function_exists('createNotification')) {
        createNotification(
            $merchantId,
            'KYC Verified',
            'Your KYC is verified. Documents are queued for partner submission. Live money needs a separate activation step.',
            $eventKey
        );
    }

    return ['ok' => true];
}
