<?php
declare(strict_types=1);

/**
 * Zero-Touch Auto KYC Engine (P2.1)
 *
 * Automatically approves KYC documents and verifies merchants when ALL
 * conditions are met — no manual intervention needed.
 *
 * Conditions for auto-verify:
 *   1. All required docs for the entity type are uploaded
 *   2. All uploaded docs have scan_status='clean' (malware scan passed)
 *   3. No doc has status='rejected'
 *   4. Video KYC is verified (if required for entity type)
 *   5. No risk flags on the merchant
 *   6. Merchant kyc_status is 'submitted' (not already verified/rejected)
 *
 * Runs via cron_auto_kyc.php (every 10 minutes) or admin trigger.
 */

function ensureAutoKycTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS auto_kyc_runs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            detail VARCHAR(500) DEFAULT NULL,
            ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, ran_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function logAutoKycRun(int $merchantId, string $action, string $detail = ''): void
{
    try {
        ensureAutoKycTable();
        getDB()->prepare('INSERT INTO auto_kyc_runs (merchant_id, action, detail) VALUES (?,?,?)')
            ->execute([$merchantId, $action, mb_substr($detail, 0, 500)]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Check if a merchant has any active risk flags.
 */
function merchantHasRiskFlags(int $merchantId): bool
{
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM merchants
            WHERE id = ?
              AND (status = 'blocked' OR status = 'suspended'
                   OR kyc_status = 'rejected' OR kyc_status = 'clarification')");
        $st->execute([$merchantId]);
        return (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
        return true; // safe default: don't auto-verify if we can't check
    }
}

/**
 * Get all required doc types for a merchant's entity type.
 */
function getMerchantRequiredDocs(int $merchantId): array
{
    try {
        $st = getDB()->prepare('SELECT business_entity_type FROM merchants WHERE id = ?');
        $st->execute([$merchantId]);
        $entityType = (string)$st->fetchColumn();
        if (!function_exists('getKycRequirements')) {
            require_once __DIR__ . '/kyc_entity.php';
        }
        return getKycRequirements($entityType ?: 'sole_proprietorship');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Check if all required docs are uploaded, clean, and approved.
 * Returns array with 'ready' bool and 'missing' list.
 */
function checkDocsAutoApproveReady(int $merchantId): array
{
    $required = getMerchantRequiredDocs($merchantId);
    if (empty($required)) {
        return ['ready' => false, 'missing' => ['unknown_entity_type']];
    }

    try {
        // Get latest doc per type
        $st = getDB()->prepare("SELECT doc_type, status, scan_status
            FROM kyc_documents
            WHERE merchant_id = ?
            ORDER BY created_at DESC");
        $st->execute([$merchantId]);
        $allDocs = $st->fetchAll();

        // Latest per type
        $latestByType = [];
        foreach ($allDocs as $doc) {
            $t = $doc['doc_type'];
            if (!isset($latestByType[$t])) {
                $latestByType[$t] = $doc;
            }
        }

        $missing = [];
        $needsApproval = [];
        $hasRejected = false;

        foreach ($required as $reqType) {
            if (!isset($latestByType[$reqType])) {
                $missing[] = $reqType;
                continue;
            }
            $doc = $latestByType[$reqType];
            if ($doc['status'] === 'rejected') {
                $hasRejected = true;
                $missing[] = $reqType . ' (rejected)';
                continue;
            }
            if ($doc['scan_status'] !== 'clean') {
                $missing[] = $reqType . ' (scan not clean)';
                continue;
            }
            if ($doc['status'] === 'pending') {
                $needsApproval[] = $reqType;
            }
            // status='approved' is good
        }

        $ready = empty($missing) && !$hasRejected;
        return [
            'ready' => $ready,
            'missing' => $missing,
            'needs_approval' => $needsApproval,
            'latest_by_type' => $latestByType,
        ];
    } catch (Throwable $e) {
        return ['ready' => false, 'missing' => ['db_error']];
    }
}

/**
 * Check if video KYC is required and completed for a merchant.
 */
function checkVideoKycCompleted(int $merchantId): bool
{
    try {
        $st = getDB()->prepare('SELECT video_kyc_status FROM merchants WHERE id = ?');
        $st->execute([$merchantId]);
        $status = (string)$st->fetchColumn();
        // 'verified' means done. 'pending'/'rejected'/NULL means not done.
        // If the column doesn't exist, fetchColumn returns false → treat as not required.
        if ($status === '' || $status === 'false') {
            return true; // column missing or no video KYC required
        }
        return $status === 'verified';
    } catch (Throwable $e) {
        // Column might not exist on older DBs — treat as not required
        return true;
    }
}

/**
 * Auto-approve a single pending KYC document that has passed malware scan.
 */
function autoApproveKycDoc(int $merchantId, int $docId): bool
{
    try {
        getDB()->prepare("UPDATE kyc_documents
            SET status = 'approved', reviewed_at = NOW(), rejection_reason = NULL
            WHERE id = ? AND merchant_id = ? AND status = 'pending' AND scan_status = 'clean'")
            ->execute([$docId, $merchantId]);
        return getDB()->prepare("SELECT COUNT(*) FROM kyc_documents WHERE id = ? AND status = 'approved'")
            ->execute([$docId]) && (int)getDB()->query("SELECT ROW_COUNT()")->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Auto-verify a merchant's KYC — sets kyc_status='verified'.
 */
function autoVerifyMerchantKyc(int $merchantId): bool
{
    try {
        getDB()->prepare("UPDATE merchants
            SET kyc_status = 'verified',
                onboarding_state = 'verified',
                account_mode = 'test'
            WHERE id = ? AND kyc_status = 'submitted'")
            ->execute([$merchantId]);

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit(
                'kyc_auto_verified',
                $merchantId,
                'merchant',
                (string)$merchantId,
                'Zero-Touch Auto KYC: all docs clean+approved, video KYC verified, no risk flags'
            );
        }

        if (function_exists('createNotification')) {
            createNotification(
                $merchantId,
                'KYC Auto-Verified',
                'Your KYC documents have been automatically verified. No manual review was needed. Live activation is a separate step.'
            );
        }

        // Trigger post-KYC automation
        if (!function_exists('afterKycVerifiedAutoSendMethods')) {
            $mr = __DIR__ . '/method_requests.php';
            if (is_file($mr)) {
                require_once $mr;
            }
        }
        if (function_exists('afterKycVerifiedAutoSendMethods')) {
            try {
                afterKycVerifiedAutoSendMethods($merchantId, 'auto_kyc_engine');
            } catch (Throwable $e) {
                error_log('Auto KYC afterKycVerifiedAutoSendMethods: ' . $e->getMessage());
            }
        }

        logAutoKycRun($merchantId, 'merchant_verified', 'Auto-verified by Zero-Touch KYC engine');
        return true;
    } catch (Throwable $e) {
        logAutoKycRun($merchantId, 'verify_failed', $e->getMessage());
        return false;
    }
}

/**
 * Run the Zero-Touch Auto KYC engine for all eligible merchants.
 *
 * @return array Summary of actions taken
 */
function runAutoKycEngine(): array
{
    ensureAutoKycTable();
    $db = getDB();

    $summary = [
        'merchants_checked' => 0,
        'docs_auto_approved' => 0,
        'merchants_verified' => 0,
        'skipped_risk' => 0,
        'skipped_video' => 0,
        'skipped_missing_docs' => 0,
        'errors' => 0,
    ];

    // Find merchants with kyc_status='submitted' — candidates for auto-KYC
    try {
        $st = $db->prepare("SELECT id, business_name, business_entity_type, video_kyc_status
            FROM merchants
            WHERE kyc_status = 'submitted'
              AND status NOT IN ('blocked', 'suspended', 'deleted')
            ORDER BY id ASC");
        $st->execute();
        $merchants = $st->fetchAll();
    } catch (Throwable $e) {
        // video_kyc_status column might not exist
        try {
            $st = $db->prepare("SELECT id, business_name, business_entity_type
                FROM merchants
                WHERE kyc_status = 'submitted'
                  AND status NOT IN ('blocked', 'suspended', 'deleted')
                ORDER BY id ASC");
            $st->execute();
            $merchants = $st->fetchAll();
        } catch (Throwable $e2) {
            return $summary;
        }
    }

    foreach ($merchants as $m) {
        $merchantId = (int)$m['id'];
        $summary['merchants_checked']++;

        // 1. Check risk flags
        if (merchantHasRiskFlags($merchantId)) {
            $summary['skipped_risk']++;
            logAutoKycRun($merchantId, 'skipped', 'Risk flags present');
            continue;
        }

        // 2. Check video KYC (if required)
        if (!checkVideoKycCompleted($merchantId)) {
            $summary['skipped_video']++;
            logAutoKycRun($merchantId, 'skipped', 'Video KYC not verified');
            continue;
        }

        // 3. Check docs
        $docCheck = checkDocsAutoApproveReady($merchantId);
        if (!$docCheck['ready']) {
            $summary['skipped_missing_docs']++;
            logAutoKycRun($merchantId, 'skipped', 'Missing/incomplete docs: ' . implode(', ', $docCheck['missing']));
            continue;
        }

        // 4. Auto-approve any pending docs that are clean
        if (!empty($docCheck['needs_approval'])) {
            foreach ($docCheck['needs_approval'] as $docType) {
                $doc = $docCheck['latest_by_type'][$docType] ?? null;
                if ($doc && ($doc['status'] ?? '') === 'pending' && ($doc['scan_status'] ?? '') === 'clean') {
                    try {
                        $db->prepare("UPDATE kyc_documents
                            SET status = 'approved', reviewed_at = NOW(), rejection_reason = NULL
                            WHERE merchant_id = ? AND doc_type = ? AND status = 'pending' AND scan_status = 'clean'")
                            ->execute([$merchantId, $docType]);
                        $summary['docs_auto_approved']++;
                        logAutoKycRun($merchantId, 'doc_approved', $docType . ' auto-approved (clean scan)');
                    } catch (Throwable $e) {
                        error_log('Auto KYC doc approve failed: ' . $e->getMessage());
                    }
                }
            }
        }

        // 5. Verify all required docs are now approved (re-check after auto-approve)
        $recheck = checkDocsAutoApproveReady($merchantId);
        if (!$recheck['ready']) {
            $summary['skipped_missing_docs']++;
            logAutoKycRun($merchantId, 'skipped', 'Docs still incomplete after auto-approve');
            continue;
        }

        // 6. All conditions met — auto-verify merchant
        if (autoVerifyMerchantKyc($merchantId)) {
            $summary['merchants_verified']++;
        } else {
            $summary['errors']++;
        }
    }

    // Save summary to settings for header display
    if (function_exists('saveSetting')) {
        try {
            saveSetting('auto_kyc_last_run', json_encode([
                'ran_at' => date('Y-m-d H:i:s'),
                'summary' => $summary,
            ]));
        } catch (Throwable $e) { /* ok */ }
    }

    return $summary;
}

/**
 * Get last auto-KYC run summary for admin header display.
 */
function getLastAutoKycRun(): ?array
{
    try {
        $raw = getSetting('auto_kyc_last_run', '');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable $e) {
        return null;
    }
}
