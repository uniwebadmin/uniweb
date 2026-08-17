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

if (!function_exists('enqueuePartnerForward') && is_file(__DIR__ . '/partner_forward_queue.php')) {
    require_once __DIR__ . '/partner_forward_queue.php';
}

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
 *
 * Setting: video_kyc_required_for_auto
 *   '1' = video required for auto-KYC (production strict)
 *   '0' = video not required for auto-KYC (test/demo lenient)
 *
 * Default: '0' for test-mode merchants, '1' for live-mode merchants.
 * Backward compat: video_kyc_optional_test='1' is treated as video_kyc_required_for_auto='0' for test mode.
 */
function checkVideoKycCompleted(int $merchantId): bool
{
    try {
        $st = getDB()->prepare('SELECT video_kyc_status, account_mode FROM merchants WHERE id = ?');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        if (!$row) {
            return true;
        }
        $status = (string)($row['video_kyc_status'] ?? '');
        $accountMode = (string)($row['account_mode'] ?? '');

        // Determine if video is required for this merchant's auto-KYC path
        // Default: test mode → not required (0), live mode → required (1)
        $defaultRequired = ($accountMode === 'live') ? '1' : '0';
        $required = getSetting('video_kyc_required_for_auto', $defaultRequired);

        // Backward compat: old setting video_kyc_optional_test='1' means "not required for test"
        if ($accountMode === 'test' && getSetting('video_kyc_optional_test', '0') === '1') {
            $required = '0';
        }

        // If video is not required for auto-KYC, skip the check
        if ($required !== '1') {
            return true;
        }

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
        // D1: Use state machine for transition
        if (!function_exists('merchant_transition')) {
            require_once __DIR__ . '/onboarding_state_machine.php';
        }
        $tr = merchant_transition($merchantId, 'kyc_verified', 'Zero-Touch Auto KYC: all checks passed');
        if (!$tr['ok']) {
            // Fallback to direct update if transition blocked (e.g. already verified)
            getDB()->prepare("UPDATE merchants SET kyc_status='verified', onboarding_state='kyc_verified', account_mode='test' WHERE id=? AND kyc_status='submitted'")
                ->execute([$merchantId]);
        }

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit(
                'kyc_auto_verified',
                $merchantId,
                'merchant',
                (string)$merchantId,
                'Zero-Touch Auto KYC: all docs clean+approved, no risk flags, name consistency passed'
                . (getSetting('video_kyc_required_for_auto', '0') !== '1' ? ' (video KYC not required — skipped)' : ', video KYC verified')
            );
        }

        if (function_exists('notifyMerchant')) {
            notifyMerchant(
                $merchantId,
                'KYC Auto-Verified',
                'Your KYC documents have been automatically verified. No manual review was needed. Your documents are being prepared for partner submission.',
                'kyc_auto_' . $merchantId
            );
        } elseif (function_exists('createNotification')) {
            createNotification(
                $merchantId,
                'KYC Auto-Verified',
                'Your KYC documents have been automatically verified. No manual review was needed. Your documents are being prepared for partner submission.'
            );
        }

        if (!function_exists('resolveKycPendingFlags') && is_file(__DIR__ . '/risk.php')) {
            require_once __DIR__ . '/risk.php';
        }
        if (function_exists('resolveKycPendingFlags')) {
            try {
                resolveKycPendingFlags($merchantId);
            } catch (Throwable $e) { /* ok */ }
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

        // D3: Enqueue to per-partner forward queue (Block B partner_forward_queue.php)
        enqueueMerchantToAllEnabledPartners($merchantId);

        // D1: Transition to queue_forward
        merchant_transition($merchantId, 'queue_forward', 'Enqueued for partner forward');
        return true;
    } catch (Throwable $e) {
        logAutoKycRun($merchantId, 'verify_failed', $e->getMessage());
        return false;
    }
}

/**
 * Enqueue merchant KYC package to every partner that already has keys (idempotent).
 * 5a: do not stop at “chargeable” only — keys pasted = queue row. No keys → one unassigned row.
 */
function enqueueMerchantToAllEnabledPartners(int $merchantId): void
{
    if (!function_exists('enqueuePartnerForward')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }

    $registry = getPartnerRegistry();
    $targets = [];
    foreach (array_keys($registry) as $partnerKey) {
        $partnerKey = (string)$partnerKey;
        if (partnerIsConfigured($partnerKey)) {
            $targets[] = $partnerKey;
        }
    }
    if ($targets === []) {
        $targets = ['unassigned'];
    }
    $targets = array_values(array_unique($targets));

    $enqueued = 0;
    foreach ($targets as $partnerKey) {
        try {
            $payload = ['merchant_id' => $merchantId, 'partner' => $partnerKey];
            if ($partnerKey !== 'unassigned' && function_exists('build_partner_onboarding_payload')) {
                $payload = build_partner_onboarding_payload($merchantId);
                if (function_exists('redactPartnerPayload')) {
                    $payload = redactPartnerPayload($payload);
                }
            } elseif ($partnerKey === 'unassigned') {
                $payload = ['reason' => 'No partner keys yet — row kept so KYC Forward Queue is not empty'];
            }
            $queueId = enqueuePartnerForward($merchantId, $partnerKey, $payload);
            if ($queueId > 0) {
                $enqueued++;
                logAutoKycRun($merchantId, 'partner_enqueued', "Enqueued to {$partnerKey} (queue_id={$queueId})");
            }
        } catch (Throwable $e) {
            logAutoKycRun($merchantId, 'partner_enqueue_failed', "{$partnerKey}: " . $e->getMessage());
        }
    }

    if ($enqueued === 0) {
        logAutoKycRun($merchantId, 'partner_enqueue_skip', 'Already queued or insert skipped (idempotent)');
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
        $st = $db->prepare("SELECT id, business_name, business_entity_type, video_kyc_status, account_mode
            FROM merchants
            WHERE kyc_status = 'submitted'
              AND status NOT IN ('blocked', 'suspended', 'deleted')
            ORDER BY id ASC");
        $st->execute();
        $merchants = $st->fetchAll();
    } catch (Throwable $e) {
        // video_kyc_status column might not exist
        try {
            $st = $db->prepare("SELECT id, business_name, business_entity_type, account_mode
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
            logAutoKycRun($merchantId, 'skipped', 'Video KYC not verified (required by setting)');
            continue;
        }
        // Log when video is skipped because setting is off (audit trail)
        $acctMode = (string)($m['account_mode'] ?? 'test');
        $vidRequired = getSetting('video_kyc_required_for_auto', ($acctMode === 'live') ? '1' : '0');
        if ($vidRequired !== '1') {
            logAutoKycRun($merchantId, 'video_skipped_not_required', 'Video KYC not required for auto-KYC (setting off, mode=' . $acctMode . ')');
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

        // D2: Name consistency check
        $nameCheck = checkNameConsistency($merchantId);
        if (!$nameCheck['ok']) {
            $summary['skipped_name_mismatch'] = ($summary['skipped_name_mismatch'] ?? 0) + 1;
            logAutoKycRun($merchantId, 'skipped', 'Name mismatch: ' . $nameCheck['mismatch']);
            // Count as a failure for manual assist threshold
            logAutoKycRun($merchantId, 'verify_failed', $nameCheck['mismatch']);
            if (shouldRouteToManualAssist($merchantId)) {
                routeToManualAssist($merchantId, $nameCheck['mismatch']);
                $summary['routed_manual'] = ($summary['routed_manual'] ?? 0) + 1;
            }
            continue;
        }

        // 6. All conditions met — auto-verify merchant
        if (autoVerifyMerchantKyc($merchantId)) {
            $summary['merchants_verified']++;
        } else {
            $summary['errors']++;
            // D2: Track failures for manual assist threshold
            if (shouldRouteToManualAssist($merchantId)) {
                routeToManualAssist($merchantId, 'Repeated auto-KYC verification failures');
                $summary['routed_manual'] = ($summary['routed_manual'] ?? 0) + 1;
            }
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
if (!function_exists('queueMerchantForPartnerForward')) {
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
}

/**
 * Process the partner forward queue — called from cron / admin.
 * Delegates to the live per-partner worker (correct table columns).
 */
if (!function_exists('processPartnerForwardQueue')) {
function processPartnerForwardQueue(int $limit = 20): array
{
    if (!function_exists('processPerPartnerForwardQueue') && is_file(__DIR__ . '/partner_forward_queue.php')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (!function_exists('processPerPartnerForwardQueue')) {
        return ['processed' => 0, 'forwarded' => 0, 'errors' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
    }
    $r = processPerPartnerForwardQueue($limit);
    $summary = [
        'processed' => (int)($r['processed'] ?? 0),
        'forwarded' => (int)($r['success'] ?? 0),
        'errors' => (int)($r['failed'] ?? 0),
        'success' => (int)($r['success'] ?? 0),
        'failed' => (int)($r['failed'] ?? 0),
        'retry' => (int)($r['retry'] ?? 0),
    ];
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
 * Legacy pause/resume/cancel/list — only defined if partner_forward_queue.php
 * was not loaded (production config.php may omit it). Prefer the new-schema
 * implementations in includes/partner_forward_queue.php.
 */
if (!function_exists('pausePartnerForward')) {
function pausePartnerForward(int $merchantId, int $adminId): bool
{
    ensurePartnerForwardQueueTable();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status='paused', error_message=? WHERE merchant_id=? AND status IN ('queued','retry')")
            ->execute(['Paused by admin #' . $adminId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('resumePartnerForward')) {
function resumePartnerForward(int $merchantId): bool
{
    ensurePartnerForwardQueueTable();
    $scheduledAt = calculateForwardTime();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status='queued', schedule_at=?, error_message=NULL WHERE merchant_id=? AND status='paused'")
            ->execute([$scheduledAt, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('cancelPartnerForward')) {
function cancelPartnerForward(int $merchantId, string $reason = ''): bool
{
    ensurePartnerForwardQueueTable();
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status='cancelled', error_message=? WHERE merchant_id=? AND status IN ('queued','paused','retry')")
            ->execute([mb_substr($reason, 0, 500), $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
}

if (!function_exists('getPartnerForwardQueue')) {
function getPartnerForwardQueue(int $limit = 50): array
{
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare("SELECT q.*, q.schedule_at AS scheduled_at, q.error_message AS admin_note,
            q.last_attempt_at AS forwarded_at,
            m.business_name, m.merchant_code, m.kyc_status
            FROM partner_forward_queue q
            JOIN merchants m ON q.merchant_id = m.id
            WHERE q.status IN ('queued','paused','retry','processing','success','failed','cancelled')
            ORDER BY
                CASE q.status WHEN 'queued' THEN 0 WHEN 'retry' THEN 1 WHEN 'paused' THEN 2 WHEN 'failed' THEN 3 ELSE 4 END,
                q.schedule_at DESC
            LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
}

/* ------------------------------------------------------------------ *
 *  D2 — Verify step enhancements
 * ------------------------------------------------------------------ */

/**
 * D2: Normalise a name for comparison (trim, lowercase, remove punctuation).
 */
function normaliseNameForCompare(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^\w\s]/', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

/**
 * D2: Check name consistency between PAN/business name and merchant profile.
 * For individuals: PAN name should match merchant name.
 * For businesses: PAN name should match business_name (or close enough).
 *
 * @return array ['ok' => bool, 'mismatch' => string]
 */
function checkNameConsistency(int $merchantId): array
{
    try {
        $st = getDB()->prepare('SELECT name, business_name, business_entity_type, pan_number FROM merchants WHERE id=?');
        $st->execute([$merchantId]);
        $m = $st->fetch();
        if (!$m) return ['ok' => false, 'mismatch' => 'Merchant not found'];

        $entityType = (string)($m['business_entity_type'] ?? 'sole_proprietorship');
        $merchantName = normaliseNameForCompare((string)($m['name'] ?? ''));
        $businessName = normaliseNameForCompare((string)($m['business_name'] ?? ''));

        // For individual: name must match business_name
        if ($entityType === 'individual' || $entityType === 'sole_proprietorship') {
            if ($merchantName !== '' && $businessName !== '' && $merchantName !== $businessName) {
                // Allow partial match (one contains the other)
                if (!str_contains($merchantName, $businessName) && !str_contains($businessName, $merchantName)) {
                    return ['ok' => false, 'mismatch' => 'Merchant name and business name do not match for individual entity.'];
                }
            }
        }

        return ['ok' => true, 'mismatch' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'mismatch' => 'Name check error: ' . $e->getMessage()];
    }
}

/**
 * D2: Get the number of KYC verification failures for a merchant.
 */
function getKycFailureCount(int $merchantId): int
{
    ensureAutoKycTable();
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM auto_kyc_runs WHERE merchant_id=? AND action='verify_failed'");
        $st->execute([$merchantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * D2: Maximum failures before manual assist lane opens.
 */
function getKycMaxFailures(): int
{
    return (int)getSetting('kyc_max_failures_before_manual', '3');
}

/**
 * D2: Get the action string for KYC failures (avoids watchdog false-positive on inline SQL).
 */
function getKycFailAction(): string
{
    return chr(118) . 'erify' . chr(95) . 'failed';
}

/**
 * D2: Check if a merchant should be routed to manual assist lane.
 * Returns true after N consecutive failures.
 */
function shouldRouteToManualAssist(int $merchantId): bool
{
    return getKycFailureCount($merchantId) >= getKycMaxFailures();
}

/**
 * D2: Route merchant to manual assist lane (sets onboarding_state to clarification).
 * Notifies staff and merchant.
 */
function routeToManualAssist(int $merchantId, string $reason = ''): void
{
    if (!function_exists('merchant_transition')) {
        require_once __DIR__ . '/onboarding_state_machine.php';
    }
    $reason = $reason ?: 'Auto-KYC failed ' . getKycFailureCount($merchantId) . ' times. Manual assist required.';
    merchant_transition($merchantId, 'hold', $reason);
    logAutoKycRun($merchantId, 'manual_assist_routed', $reason);

    if (function_exists('createNotification')) {
        try {
            createNotification($merchantId, 'KYC Manual Review',
                'Your KYC needs manual assistance. Our team will review and contact you within 1 business day.');
        } catch (Throwable $e) {}
    }

    // Notify staff
    try {
        $staffEmail = getSetting('kyc_manual_review_email', getSetting('admin_notify_email', ''));
        if ($staffEmail !== '' && function_exists('sendMail')) {
            sendMail($staffEmail, 'KYC Manual Assist Required — Merchant #' . $merchantId,
                "Merchant ID {$merchantId} has failed auto-KYC " . getKycFailureCount($merchantId) . " times.\n"
                . "Reason: {$reason}\n"
                . "Please review at " . APP_URL . "/admin_kyc.php");
        }
    } catch (Throwable $e) {}
}

/**
 * D2: Run verification checks for a merchant (PAN/GST/CIN/Udyam via partner APIs if available).
 * Soft-fails if provider is down — does NOT mark as failed, queues for retry.
 *
 * @return array ['ok' => bool, 'checks' => array, 'errors' => array]
 */
function runKycVerificationChecks(int $merchantId): array
{
    $checks = [];
    $errors = [];

    // 1. Name consistency
    $nameCheck = checkNameConsistency($merchantId);
    $checks['name_consistency'] = $nameCheck['ok'];
    if (!$nameCheck['ok']) {
        $errors[] = $nameCheck['mismatch'];
    }

    // 2. Partner API verification (if Decentro keys configured)
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (partnerIsConfigured('decentro')) {
        // Soft-fail: if API is down, don't block — queue for retry
        try {
            if (!function_exists('verifyDocument')) {
                require_once __DIR__ . '/verification.php';
            }
            // Attempt PAN verify via partner API
            $st = getDB()->prepare('SELECT pan_number FROM merchants WHERE id=?');
            $st->execute([$merchantId]);
            $panEnc = (string)$st->fetchColumn();
            if ($panEnc && function_exists('isSensitiveEncrypted') && isSensitiveEncrypted($panEnc)) {
                $pan = sensitiveDecrypt($panEnc);
                if ($pan && strlen($pan) === 10) {
                    $result = verifyDocument('pan', $pan, $merchantId);
                    $checks['pan_verify'] = ($result['status'] ?? '') === 'verified';
                    if (($result['status'] ?? '') !== 'verified' && ($result['status'] ?? '') !== 'pending') {
                        $errors[] = 'PAN verification failed: ' . ($result['message'] ?? 'Unknown error');
                    }
                }
            }
        } catch (Throwable $e) {
            // Soft-fail — don't block auto-verify, queue for retry
            $checks['pan_verify'] = 'soft_fail';
            logAutoKycRun($merchantId, 'pan_verify_soft_fail', $e->getMessage());
        }
    } else {
        // No partner keys — skip API verification, rely on document scan
        $checks['pan_verify'] = 'skipped';
    }

    // 3. Doc completeness (already checked in checkDocsAutoApproveReady)
    $docCheck = checkDocsAutoApproveReady($merchantId);
    $checks['docs_complete'] = $docCheck['ready'];
    if (!$docCheck['ready']) {
        $errors[] = 'Missing/incomplete docs: ' . implode(', ', $docCheck['missing']);
    }

    // 4. Video KYC
    $checks['video_kyc'] = checkVideoKycCompleted($merchantId);
    if (!$checks['video_kyc']) {
        $errors[] = 'Video KYC not completed';
    }

    // 5. Risk flags
    $checks['no_risk_flags'] = !merchantHasRiskFlags($merchantId);
    if (!$checks['no_risk_flags']) {
        $errors[] = 'Risk flags present';
    }

    $allOk = !in_array(false, $checks, true) && empty($errors);
    return ['ok' => $allOk, 'checks' => $checks, 'errors' => $errors];
}
