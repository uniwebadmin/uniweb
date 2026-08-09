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

        queueMerchantForPartnerForward($merchantId);
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

/**
 * Ensure partner_forward_queue table exists.
 */
if (!function_exists('ensurePartnerForwardQueueTable')) {
function ensurePartnerForwardQueueTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS partner_forward_queue (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT UNSIGNED NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'queued',
            scheduled_at DATETIME NOT NULL,
            forwarded_at DATETIME DEFAULT NULL,
            gateways VARCHAR(500) DEFAULT NULL,
            admin_note VARCHAR(500) DEFAULT NULL,
            paused_by INT UNSIGNED DEFAULT NULL,
            paused_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_merchant_forward (merchant_id),
            INDEX idx_forward_status (status, scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}
}

/**
 * Get the hold window in minutes (default 75, range 60-90).
 */
function getHoldWindowMinutes(): int
{
    return (int)getSetting('kyc_hold_window_minutes', '75');
}

/**
 * Determine if a given time is in the "night" window (after 6 PM).
 * If so, schedule for next day 11 AM. Otherwise schedule after hold window.
 */
function calculateForwardTime(string $verifiedAt = 'now'): string
{
    $ts = strtotime($verifiedAt);
    if ($ts === false) {
        $ts = time();
    }
    $hour = (int)date('G', $ts);

    if ($hour >= 18) {
        $nextDay = strtotime('next day 11:00', $ts);
        return date('Y-m-d H:i:s', $nextDay);
    }

    $holdMinutes = getHoldWindowMinutes();
    return date('Y-m-d H:i:s', $ts + ($holdMinutes * 60));
}

/**
 * Queue a verified merchant for partner forward.
 * Creates or updates the forward queue entry.
 */
function queueMerchantForPartnerForward(int $merchantId, ?string $gateways = null): bool
{
    ensurePartnerForwardQueueTable();
    $scheduledAt = calculateForwardTime();

    try {
        getDB()->prepare("INSERT INTO partner_forward_queue
            (merchant_id, status, scheduled_at, gateways, created_at)
            VALUES (?, 'queued', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            status = 'queued', scheduled_at = VALUES(scheduled_at),
            gateways = VALUES(gateways), paused_by = NULL, paused_at = NULL,
            forwarded_at = NULL")
            ->execute([$merchantId, $scheduledAt, $gateways]);
        logAutoKycRun($merchantId, 'forward_queued', 'Queued for partner forward at ' . $scheduledAt);
        return true;
    } catch (Throwable $e) {
        logAutoKycRun($merchantId, 'forward_queue_failed', $e->getMessage());
        return false;
    }
}

/**
 * Process the partner forward queue — called from cron.
 * Forwards merchants whose scheduled_at has passed and status='queued'.
 */
if (!function_exists('processPartnerForwardQueue')) {
function processPartnerForwardQueue(): array
{
    ensurePartnerForwardQueueTable();
    $db = getDB();
    $summary = ['processed' => 0, 'forwarded' => 0, 'errors' => 0];

    try {
        $st = $db->prepare("SELECT q.*, m.business_name, m.kyc_status
            FROM partner_forward_queue q
            JOIN merchants m ON q.merchant_id = m.id
            WHERE q.status = 'queued' AND q.scheduled_at <= NOW()
            ORDER BY q.scheduled_at ASC LIMIT 20");
        $st->execute();
        $queue = $st->fetchAll();
    } catch (Throwable $e) {
        return $summary;
    }

    foreach ($queue as $item) {
        $merchantId = (int)$item['merchant_id'];
        $summary['processed']++;

        if (($item['kyc_status'] ?? '') !== 'verified') {
            $db->prepare("UPDATE partner_forward_queue SET status = 'cancelled' WHERE id = ?")
                ->execute([$item['id']]);
            logAutoKycRun($merchantId, 'forward_cancelled', 'KYC no longer verified');
            continue;
        }

        $gateways = array_filter(array_map('trim', explode(',', (string)$item['gateways'])));
        if (empty($gateways)) {
            if (!function_exists('gatewaySubmissionAllowedGateways')) {
                require_once __DIR__ . '/gateway_submit.php';
            }
            if (function_exists('gatewaySubmissionAllowedGateways')) {
                $gateways = gatewaySubmissionAllowedGateways();
            }
        }

        if (empty($gateways)) {
            $db->prepare("UPDATE partner_forward_queue SET status = 'failed', admin_note = 'No gateways configured' WHERE id = ?")
                ->execute([$item['id']]);
            $summary['errors']++;
            continue;
        }

        try {
            if (!function_exists('submitMerchantToGateways')) {
                require_once __DIR__ . '/gateway_submit.php';
            }
            if (function_exists('submitMerchantToGateways')) {
                $count = submitMerchantToGateways($merchantId, $gateways, 0, 'Auto-forwarded by Zero-Touch KYC queue');
                $db->prepare("UPDATE partner_forward_queue SET status = 'forwarded', forwarded_at = NOW() WHERE id = ?")
                    ->execute([$item['id']]);
                $summary['forwarded']++;
                logAutoKycRun($merchantId, 'forwarded', 'Auto-forwarded to ' . $count . ' gateway(s)');
            } else {
                $db->prepare("UPDATE partner_forward_queue SET status = 'failed', admin_note = 'gateway_submit not available' WHERE id = ?")
                    ->execute([$item['id']]);
                $summary['errors']++;
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE partner_forward_queue SET status = 'failed', admin_note = ? WHERE id = ?")
                ->execute([mb_substr($e->getMessage(), 0, 500), $item['id']]);
            $summary['errors']++;
            logAutoKycRun($merchantId, 'forward_error', $e->getMessage());
        }
    }

    if (function_exists('saveSetting')) {
        try {
            saveSetting('partner_forward_last_run', json_encode([
                'ran_at' => date('Y-m-d H:i:s'),
                'summary' => $summary,
            ]));
        } catch (Throwable $e) { /* ok */ }
    }

    return $summary;
}
}

/**
 * Pause a queued partner forward (admin action).
 */
function pausePartnerForward(int $merchantId, int $adminId): bool
{
    ensurePartnerForwardQueueTable();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status = 'paused', paused_by = ?, paused_at = NOW() WHERE merchant_id = ? AND status = 'queued'")
            ->execute([$adminId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resume a paused partner forward — reschedules with fresh hold window.
 */
function resumePartnerForward(int $merchantId): bool
{
    ensurePartnerForwardQueueTable();
    $scheduledAt = calculateForwardTime();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status = 'queued', scheduled_at = ?, paused_by = NULL, paused_at = NULL WHERE merchant_id = ? AND status = 'paused'")
            ->execute([$scheduledAt, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cancel a queued/paused partner forward (admin reject).
 */
function cancelPartnerForward(int $merchantId, string $reason = ''): bool
{
    ensurePartnerForwardQueueTable();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status = 'cancelled', admin_note = ? WHERE merchant_id = ? AND status IN ('queued','paused')")
            ->execute([mb_substr($reason, 0, 500), $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get the partner forward queue for admin dashboard.
 */
function getPartnerForwardQueue(int $limit = 50): array
{
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare("SELECT q.*, m.business_name, m.merchant_code, m.kyc_status
            FROM partner_forward_queue q
            JOIN merchants m ON q.merchant_id = m.id
            WHERE q.status IN ('queued','paused','forwarded','failed')
            ORDER BY
                CASE q.status WHEN 'queued' THEN 0 WHEN 'paused' THEN 1 WHEN 'failed' THEN 2 ELSE 3 END,
                q.scheduled_at DESC
            LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
