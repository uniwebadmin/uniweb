<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

/**
 * Partner Forward Queue (PDF section D)
 *
 * D3: After KYC verify, enqueue package to partner_forward_queue with schedule_at.
 * D4: Cron worker pushes package to enabled partners; status matrix on admin + merchant.
 * D5: Manual fallback only after repeated failures.
 *
 * Status vocabulary (Admin DB `status` column — fail-closed):
 *   queued / processing / retry / paused — worker pipeline (not at partner yet)
 *   staged — UniWeb saved package only (`local_record` adapter); partner API NOT success
 *   success — partner API acknowledged (live adapter only; never from local_record alone)
 *   failed — partner reject, timeout, or max retries exhausted
 *   cancelled / paused — manual ops stop
 *
 * Adapter sub-modes (stored in partner_response JSON, not a separate DB status):
 *   local_record — gateway_submissions + API log on UniWeb; row stays `staged`
 *   live_api     — real partner KYC/onboarding HTTP; may set `success` when ACK received
 *
 * Retry policy (no infinite silent retry):
 *   max_attempts default 3 per row · backoff = attempt# × 30 minutes (cap 2h) · then `failed`
 */
function forwardQueueDefaultMaxAttempts(): int
{
    return 3;
}

/** Seconds before next worker pass after a retryable failure (attempt 1 → 30m, 2 → 60m, 3 → 90m). */
function forwardQueueRetryDelaySeconds(int $attemptNumber): int
{
    $attemptNumber = max(1, $attemptNumber);
    return min(7200, max(900, $attemptNumber * 1800));
}

function forwardQueueRetryScheduleAt(int $attemptNumber): string
{
    return date('Y-m-d H:i:s', time() + forwardQueueRetryDelaySeconds($attemptNumber));
}

/** @return array<string, string> status key => Admin English label */
function forwardQueueStatusVocabulary(): array
{
    return [
        'queued' => 'Queued — waiting for schedule',
        'processing' => 'Processing — worker running',
        'staged' => 'Staged — UniWeb saved only (not sent to partner)',
        'success' => 'Accepted — partner API acknowledged',
        'retry' => 'Retry scheduled — transient error',
        'failed' => 'Failed — reject/timeout/max retries',
        'paused' => 'Paused — admin hold',
        'cancelled' => 'Cancelled',
    ];
}

function forwardQueueRetryPolicyHint(): string
{
    $max = forwardQueueDefaultMaxAttempts();
    $mins = (int)(forwardQueueRetryDelaySeconds(1) / 60);
    return "Retry policy: up to {$max} attempts per row · backoff {$mins}m × attempt number (max 2h) · then Failed. Staged/local_record never counts as money sent.";
}

/** Mask secrets in queue error text for Admin list/detail. */
function maskForwardQueueErrorMessage(?string $message): string
{
    $message = trim((string)$message);
    if ($message === '') {
        return '—';
    }
    if (function_exists('maskAuditSecrets')) {
        $message = maskAuditSecrets($message);
    }
    $message = preg_replace('/\b(sk_[a-zA-Z0-9]{8,}|key_[a-zA-Z0-9]{8,}|rzp_[a-zA-Z0-9]{8,}|api_[a-zA-Z0-9]{8,})\b/', '[redacted]', $message) ?? $message;
    return mb_substr($message, 0, 240);
}

/** Adapter mode from partner_response JSON — local_record vs live_api. */
function forwardQueueRowAdapterMode(array $row): string
{
    $raw = (string)($row['partner_response'] ?? '');
    if ($raw === '') {
        return '';
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return '';
    }
    return (string)($decoded['adapter'] ?? $decoded['mode'] ?? '');
}

if (!function_exists('ensurePartnerForwardQueueTable')) {
function ensurePartnerForwardQueueTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS partner_forward_queue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            partner_key VARCHAR(40) NOT NULL,
            package_payload LONGTEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            schedule_at DATETIME NOT NULL,
            attempts INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            last_attempt_at DATETIME DEFAULT NULL,
            partner_reference VARCHAR(100) DEFAULT NULL,
            partner_response LONGTEXT DEFAULT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_pfq_status (status, schedule_at),
            INDEX idx_pfq_merchant (merchant_id, status),
            INDEX idx_pfq_partner (partner_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ok */ }

    partnerForwardQueueUpgradeLegacySchema($db);
}
}

/**
 * One-time shape fix: older builds created merchant-level columns (scheduled_at, gateways)
 * without partner_key. Add per-partner columns instead of a second conflicting table.
 */
function partnerForwardQueueUpgradeLegacySchema(PDO $db): void
{
    try {
        $cols = $db->query('SHOW COLUMNS FROM partner_forward_queue')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    } catch (Throwable $e) {
        return;
    }
    if ($cols === []) {
        return;
    }
    $hasPartnerKey = in_array('partner_key', $cols, true);
    $hasScheduleAt = in_array('schedule_at', $cols, true);
    if (!$hasPartnerKey) {
        try {
            $db->exec("ALTER TABLE partner_forward_queue ADD COLUMN partner_key VARCHAR(40) NOT NULL DEFAULT 'legacy' AFTER merchant_id");
        } catch (Throwable $e) { /* ok */ }
    }
    if (!$hasScheduleAt) {
        try {
            $db->exec('ALTER TABLE partner_forward_queue ADD COLUMN schedule_at DATETIME NULL AFTER status');
            if (in_array('scheduled_at', $cols, true)) {
                $db->exec('UPDATE partner_forward_queue SET schedule_at = scheduled_at WHERE schedule_at IS NULL AND scheduled_at IS NOT NULL');
            }
            $db->exec('UPDATE partner_forward_queue SET schedule_at = NOW() WHERE schedule_at IS NULL');
            $db->exec('ALTER TABLE partner_forward_queue MODIFY schedule_at DATETIME NOT NULL');
        } catch (Throwable $e) { /* ok */ }
    }
    foreach ([
        'package_payload LONGTEXT DEFAULT NULL',
        'attempts INT NOT NULL DEFAULT 0',
        'max_attempts INT NOT NULL DEFAULT 3',
        'last_attempt_at DATETIME DEFAULT NULL',
        'partner_reference VARCHAR(100) DEFAULT NULL',
        'partner_response LONGTEXT DEFAULT NULL',
        'error_message VARCHAR(500) DEFAULT NULL',
        'updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP',
    ] as $def) {
        $name = trim(explode(' ', $def)[0]);
        if (!in_array($name, $cols, true)) {
            try {
                $db->exec('ALTER TABLE partner_forward_queue ADD COLUMN ' . $def);
            } catch (Throwable $e) { /* ok */ }
        }
    }
}

/**
 * Shared IST schedule for KYC forward (hold window + after 18:00 → next day 09:00).
 */
function forwardQueueNextScheduleAt(): DateTime
{
    if (!function_exists('holdWindowComputeSchedule') && is_file(__DIR__ . '/hold_window_workflow.php')) {
        require_once __DIR__ . '/hold_window_workflow.php';
    }
    if (function_exists('holdWindowComputeSchedule')) {
        return holdWindowComputeSchedule();
    }

    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $hour = (int)$now->format('H');
    if ($hour >= 18) {
        return new DateTime('tomorrow 09:00', new DateTimeZone('Asia/Kolkata'));
    }
    $hold = 75;
    if (function_exists('getHoldWindowMinutes')) {
        $hold = max(60, min(90, getHoldWindowMinutes()));
    } else {
        $hold = random_int(60, 90);
    }
    $schedule = clone $now;
    $schedule->modify('+' . $hold . ' minutes');
    return $schedule;
}

/**
 * D3: Enqueue a KYC package to the forward queue.
 * schedule_at = now + 60-90 min. If after 18:00, schedule next day 09:00.
 */
function enqueuePartnerForward(int $merchantId, string $partnerKey, ?array $payload = null): int
{
    ensurePartnerForwardQueueTable();
    $db = getDB();

    // Idempotent: skip if a non-terminal row already exists for this merchant + partner
    try {
        $check = $db->prepare(
            "SELECT id FROM partner_forward_queue
             WHERE merchant_id=? AND partner_key=? AND status IN ('queued','retry','processing','staged','success')
             LIMIT 1"
        );
        $check->execute([$merchantId, $partnerKey]);
        $existingId = (int)$check->fetchColumn();
        if ($existingId > 0) {
            return $existingId; // already queued — do not flood duplicates
        }
    } catch (Throwable $e) { /* table may not exist yet — continue to insert */ }

    $schedule = forwardQueueNextScheduleAt();
    try {
        $st = $db->prepare(
            'INSERT INTO partner_forward_queue (merchant_id, partner_key, package_payload, status, schedule_at, max_attempts)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $st->execute([
            $merchantId,
            $partnerKey,
            $payload ? json_encode($payload) : null,
            'queued',
            $schedule->format('Y-m-d H:i:s'),
            forwardQueueDefaultMaxAttempts(),
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        logPlatformError('error', 'enqueuePartnerForward failed: ' . $e->getMessage(), ['merchant_id' => $merchantId]);
        return 0;
    }
}

/**
 * Enqueue merchant KYC package to every partner that already has keys (idempotent).
 * Single entry point after Admin Verify / Auto KYC / Live enable.
 */
function enqueueMerchantToAllEnabledPartners(int $merchantId): void
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }

    $targets = function_exists('getKycForwardPartnerKeys') ? getKycForwardPartnerKeys() : [];
    if ($targets === []) {
        foreach (array_keys(getPartnerRegistry()) as $partnerKey) {
            if (partnerIsConfigured((string)$partnerKey)) {
                $targets[] = (string)$partnerKey;
            }
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
            if ($partnerKey !== 'unassigned') {
                if (!function_exists('build_partner_onboarding_payload') && is_file(__DIR__ . '/partner_payload.php')) {
                    require_once __DIR__ . '/partner_payload.php';
                }
                if (function_exists('build_partner_onboarding_payload')) {
                    $payload = build_partner_onboarding_payload($merchantId);
                    if (function_exists('redactPartnerPayload')) {
                        $payload = redactPartnerPayload($payload);
                    }
                }
            } else {
                $payload = ['reason' => 'No partner keys yet — row kept so KYC Forward Queue is not empty'];
            }
            $queueId = enqueuePartnerForward($merchantId, $partnerKey, $payload);
            if ($queueId > 0) {
                $enqueued++;
                if ($partnerKey !== 'unassigned' && !partnerIsConfigured($partnerKey)) {
                    getDB()->prepare(
                        "UPDATE partner_forward_queue SET status='staged', error_message=?, updated_at=NOW()
                         WHERE id=? AND status='queued'"
                    )->execute([
                        'Partner keys not configured — paste in Partner Registry, then re-queue.',
                        $queueId,
                    ]);
                }
                if (function_exists('logAutoKycRun')) {
                    logAutoKycRun($merchantId, 'partner_enqueued', "Enqueued to {$partnerKey} (queue_id={$queueId})");
                }
            }
        } catch (Throwable $e) {
            if (function_exists('logAutoKycRun')) {
                logAutoKycRun($merchantId, 'partner_enqueue_failed', "{$partnerKey}: " . $e->getMessage());
            }
        }
    }

    if ($enqueued === 0 && function_exists('logAutoKycRun')) {
        logAutoKycRun($merchantId, 'partner_enqueue_skip', 'Already queued or insert skipped (idempotent)');
    }

    if ($enqueued > 0 && function_exists('uwRecordAuditEvent')) {
        uwRecordAuditEvent('kyc_forward_enqueued', [
            'merchant_id' => $merchantId,
            'actor_type' => 'system',
            'resource_type' => 'merchant',
            'resource_id' => (string)$merchantId,
            'reason' => 'KYC forward queue rows enqueued after verify',
            'after_state' => ['partners_enqueued' => $enqueued],
        ]);
    }
}

/**
 * Keep gateway_submissions (manual record) and partner_forward_queue in sync.
 */
function syncGatewaySubmissionToForwardQueue(int $merchantId, string $gateway, string $source = 'manual', ?int $submissionId = null): int
{
    ensurePartnerForwardQueueTable();
    $gateway = strtolower(trim($gateway));
    if ($merchantId <= 0 || $gateway === '') {
        return 0;
    }
    if (!function_exists('build_partner_onboarding_payload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $payload = build_partner_onboarding_payload($merchantId);
    if (function_exists('redactPartnerPayload')) {
        $payload = redactPartnerPayload($payload);
    }
    $payload['forward_source'] = $source;
    $payload['gateway'] = $gateway;

    $queueId = enqueuePartnerForward($merchantId, $gateway, $payload);
    if ($queueId <= 0) {
        return 0;
    }

    $ref = $submissionId && $submissionId > 0 ? 'SUB-' . $submissionId : ('STAGED-' . strtoupper($gateway) . '-' . $merchantId);
    $note = $source === 'manual'
        ? 'Synced from Gateway Submit — see gateway_submissions'
        : 'Synced from KYC forward adapter';

    try {
        getDB()->prepare(
            "UPDATE partner_forward_queue SET status='staged', partner_reference=?, error_message=?, updated_at=NOW()
             WHERE id=? AND status IN ('queued','retry','processing','paused')"
        )->execute([$ref, $note, $queueId]);
    } catch (Throwable $e) {
        /* non-fatal */
    }

    return $queueId;
}

/**
 * D4: Cron worker — process queued items whose schedule_at has passed.
 * Returns count of processed items.
 */
/**
 * Make queued rows for one merchant eligible for immediate cron/process (post-verify kick).
 */
function primeMerchantForwardQueue(int $merchantId): int
{
    if ($merchantId < 1) {
        return 0;
    }
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare(
            "UPDATE partner_forward_queue SET schedule_at=NOW()
             WHERE merchant_id=? AND status IN ('queued','retry')"
        );
        $st->execute([$merchantId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * After partner keys saved — re-queue staged/failed rows for that partner and process.
 */
function requeuePartnerForwardAfterKeysSaved(string $partnerKey): int
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '' || $partnerKey === 'unassigned') {
        return 0;
    }
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!partnerIsConfigured($partnerKey)) {
        return 0;
    }
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare(
            "UPDATE partner_forward_queue SET status='queued', attempts=0, schedule_at=NOW(), error_message=NULL
             WHERE partner_key=? AND status IN ('failed','staged')"
        );
        $st->execute([$partnerKey]);
        $count = $st->rowCount();
        if ($count > 0 && function_exists('processPerPartnerForwardQueue')) {
            processPerPartnerForwardQueue(min(50, max(10, $count)), null, $partnerKey);
        }
        return $count;
    } catch (Throwable $e) {
        return 0;
    }
}

if (!function_exists('processPerPartnerForwardQueue')) {
function processPerPartnerForwardQueue(int $limit = 20, ?int $merchantId = null, ?string $partnerKey = null): array
{
    ensurePartnerForwardQueueTable();
    $db = getDB();
    $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0, 'staged' => 0];

    try {
        $sql = "SELECT * FROM partner_forward_queue
             WHERE status IN ('queued','retry')
               AND schedule_at <= NOW()
               AND attempts < max_attempts";
        $params = [];
        if ($merchantId !== null && $merchantId > 0) {
            $sql .= ' AND merchant_id=?';
            $params[] = $merchantId;
        }
        if ($partnerKey !== null && trim($partnerKey) !== '') {
            $sql .= ' AND partner_key=?';
            $params[] = strtolower(trim($partnerKey));
        }
        $sql .= ' ORDER BY schedule_at ASC LIMIT ?';
        $params[] = $limit;
        $st = $db->prepare($sql);
        $st->execute($params);
        $items = $st->fetchAll();
    } catch (Throwable $e) {
        return $results;
    }

    foreach ($items as $item) {
        $results['processed']++;
        $itemId = (int)$item['id'];
        $merchantId = (int)$item['merchant_id'];
        $partnerKey = (string)$item['partner_key'];
        $attempts = (int)$item['attempts'] + 1;

        try {
            $db->prepare("UPDATE partner_forward_queue SET status='processing', attempts=?, last_attempt_at=NOW() WHERE id=? AND partner_key=?")
                ->execute([$attempts, $itemId, $partnerKey]);

            $payload = $item['package_payload'] ? json_decode($item['package_payload'], true) : [];
            if (!is_array($payload)) {
                $payload = [];
            }
            $payloadPartner = strtolower(trim((string)($payload['partner'] ?? $payload['gateway'] ?? '')));
            if ($payloadPartner !== '' && $payloadPartner !== strtolower($partnerKey)) {
                throw new RuntimeException('Partner mismatch — row targets ' . $partnerKey . ', payload targets ' . $payloadPartner);
            }

            $result = pushPackageToPartner($partnerKey, $merchantId, $payload);

            // Fail-closed: local_record / staged adapters must never mark success.
            if (!empty($result['success']) && (!empty($result['staged']) || ($result['adapter'] ?? '') === 'local_record')) {
                $result['success'] = false;
                $result['staged'] = true;
                if (empty($result['message'])) {
                    $result['message'] = 'Local record only — not partner API acceptance.';
                }
            }

            if ($result['success'] ?? false) {
                $dupSuccess = $db->prepare(
                    "SELECT id FROM partner_forward_queue
                     WHERE merchant_id=? AND partner_key=? AND status='success' AND id<>?
                     LIMIT 1"
                );
                $dupSuccess->execute([$merchantId, $partnerKey, $itemId]);
                if ($dupSuccess->fetch()) {
                    $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=?")
                        ->execute(['Duplicate success blocked — partner already accepted for this merchant.', $itemId]);
                    $results['failed']++;
                    continue;
                }
                $db->prepare("UPDATE partner_forward_queue SET status='success', partner_reference=?, partner_response=? WHERE id=? AND partner_key=?")
                    ->execute([
                        $result['reference'] ?? null,
                        json_encode($result),
                        $itemId,
                        $partnerKey,
                    ]);
                $results['success']++;
                if (!function_exists('wiringKycForwardNotifyBody') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
                    require_once __DIR__ . '/wiring_deep_link_workflow.php';
                }
                $fwdBody = 'Your KYC package was accepted by the payment network through UniWeb.';
                if (function_exists('notifyMerchant')) {
                    notifyMerchant($merchantId, 'KYC network accepted', $fwdBody, 'kyc_fwd_' . $merchantId . '_' . $partnerKey);
                } elseif (function_exists('createNotification')) {
                    createNotification($merchantId, 'KYC network accepted', $fwdBody);
                }
            } elseif (!empty($result['staged'])) {
                // 5b: keys OK + package built, but live partner API adapter not live yet — do not fake success or fail-retry spam
                $db->prepare("UPDATE partner_forward_queue SET status='staged', partner_reference=?, partner_response=?, error_message=? WHERE id=? AND partner_key=?")
                    ->execute([
                        $result['reference'] ?? null,
                        json_encode($result),
                        $result['message'] ?? 'Package ready — partner API adapter pending',
                        $itemId,
                        $partnerKey,
                    ]);
                $results['staged'] = ($results['staged'] ?? 0) + 1;
                $stagedBody = 'Your KYC package is saved on UniWeb — not sent to the payment network yet. You will be notified when it is submitted.';
                if (function_exists('notifyMerchant')) {
                    notifyMerchant($merchantId, 'KYC package prepared (queued)', $stagedBody, 'kyc_staged_' . $merchantId . '_' . $partnerKey);
                }
            } else {
                $err = maskForwardQueueErrorMessage((string)($result['error'] ?? 'Unknown error'));
                $terminal = !empty($result['terminal']);
                $maxAttempts = (int)($item['max_attempts'] ?? forwardQueueDefaultMaxAttempts());
                if ($terminal || $attempts >= $maxAttempts) {
                    $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=? AND partner_key=?")
                        ->execute([$err, $itemId, $partnerKey]);
                    $results['failed']++;
                    $failBody = 'KYC submission to the payment network could not be completed. UniWeb staff will assist.';
                    if (!$terminal && function_exists('notifyMerchant')) {
                        notifyMerchant($merchantId, 'KYC forward needs review', $failBody, 'kyc_fwd_fail_' . $merchantId . '_' . $partnerKey);
                    } elseif (!$terminal && function_exists('createNotification')) {
                        createNotification($merchantId, 'KYC forward needs review', $failBody);
                    }
                } else {
                    $nextRetry = forwardQueueRetryScheduleAt($attempts);
                    $db->prepare("UPDATE partner_forward_queue SET status='retry', error_message=?, schedule_at=? WHERE id=? AND partner_key=?")
                        ->execute([$err, $nextRetry, $itemId, $partnerKey]);
                    $results['retry']++;
                }
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=? AND partner_key=?")
                ->execute([maskForwardQueueErrorMessage($e->getMessage()), $itemId, $partnerKey]);
            $results['failed']++;
        }
    }

    return $results;
}
} // end function_exists guard

/**
 * Alias used by cron_auto_kyc / admin_auto_kyc.
 * Returns both new keys (success/failed/retry) and legacy keys (forwarded/errors)
 * so older callers keep working.
 */
if (!function_exists('processPartnerForwardQueue')) {
function processPartnerForwardQueue(int $limit = 20): array
{
    $r = processPerPartnerForwardQueue($limit);
    $summary = [
        'processed' => (int)($r['processed'] ?? 0),
        'success' => (int)($r['success'] ?? 0),
        'failed' => (int)($r['failed'] ?? 0),
        'retry' => (int)($r['retry'] ?? 0),
        'forwarded' => (int)($r['success'] ?? 0),
        'errors' => (int)($r['failed'] ?? 0),
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
    $scheduledAt = forwardQueueNextScheduleAt()->format('Y-m-d H:i:s');
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
            WHERE q.status IN ('queued','paused','retry','processing','staged','success','failed','cancelled')
            ORDER BY
                CASE q.status WHEN 'queued' THEN 0 WHEN 'retry' THEN 1 WHEN 'paused' THEN 2 WHEN 'staged' THEN 3 WHEN 'failed' THEN 4 ELSE 5 END,
                q.schedule_at DESC
            LIMIT ?");
        $st->execute([$limit]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
}

/**
 * 5c: Per-partner KYC forward adapter registry (hooks only — not Phase 11 success routing).
 * mode local_record = write gateway_submissions + log; live API adapters land later.
 *
 * @return array<string, array{mode:string,label:string}>
 */
function getKycForwardAdapterRegistry(): array
{
    if (!function_exists('getKycForwardPartnerKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $registry = getPartnerRegistry();
    $out = [];
    foreach (getKycForwardPartnerKeys() as $partnerKey) {
        $label = (string)($registry[$partnerKey]['name'] ?? ucfirst($partnerKey));
        $out[$partnerKey] = [
            'mode' => 'local_record',
            'label' => $label . ' — local submission record',
        ];
    }
    return $out;
}

/**
 * @return array{mode:string,label:string}|null
 */
function getKycForwardAdapterMeta(string $partnerKey): ?array
{
    $partnerKey = strtolower(trim($partnerKey));
    $reg = getKycForwardAdapterRegistry();
    return $reg[$partnerKey] ?? null;
}

/**
 * Run registered adapter after keys + payload checks. Returns null if no adapter.
 *
 * @param array<string,mixed> $fullPayload
 * @return array<string,mixed>|null
 */
function runKycForwardAdapter(string $partnerKey, int $merchantId, array $fullPayload): ?array
{
    $meta = getKycForwardAdapterMeta($partnerKey);
    if ($meta === null) {
        return null;
    }
    $payloadReady = !empty($fullPayload['merchant']);
    if (!$payloadReady) {
        return [
            'success' => false,
            'staged' => true,
            'payload_ready' => false,
            'adapter' => $meta['mode'],
            'reference' => 'STAGED-' . strtoupper($partnerKey) . '-' . $merchantId,
            'message' => 'Keys OK — package incomplete (merchant payload empty). Check KYC docs.',
        ];
    }

    if ($meta['mode'] === 'live_api' && function_exists('forwardQueuePushLiveApi')) {
        $live = forwardQueuePushLiveApi($partnerKey, $merchantId, $fullPayload);
        if (is_array($live)) {
            $live['adapter'] = 'live_api';
            return $live;
        }
    }

    if ($meta['mode'] === 'local_record') {
        $subRef = null;
        if (function_exists('submitMerchantToGateway') || is_file(__DIR__ . '/gateways.php')) {
            if (!function_exists('submitMerchantToGateway')) {
                require_once __DIR__ . '/gateways.php';
            }
            $allowed = function_exists('getGatewaySubmissionPartnerKeys')
                ? getGatewaySubmissionPartnerKeys()
                : (function_exists('gatewaySubmissionAllowedGateways') ? gatewaySubmissionAllowedGateways() : ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'axis', 'rbl']);
            if (in_array($partnerKey, $allowed, true)) {
                try {
                    submitMerchantToGateway($merchantId, $partnerKey, (int)($_SESSION['admin_id'] ?? 0), 'KYC forward queue (local_record adapter)', 'adapter');
                    $st = getDB()->prepare(
                        "SELECT id FROM gateway_submissions WHERE merchant_id=? AND gateway=? ORDER BY id DESC LIMIT 1"
                    );
                    $st->execute([$merchantId, $partnerKey]);
                    $subId = (int)$st->fetchColumn();
                    if ($subId > 0) {
                        $subRef = 'SUB-' . $subId;
                    }
                } catch (Throwable $e) {
                    /* keep staging even if local record fails */
                }
            }
        }
        if (function_exists('partnerLogApi')) {
            partnerLogApi(
                $partnerKey,
                'kyc_forward_local',
                'POST',
                'queue',
                $subRef ?? 'staged',
                $subRef ? 200 : 0,
                $subRef ? 'ok' : 'pending'
            );
        }
        return [
            'success' => false,
            'staged' => true,
            'payload_ready' => true,
            'adapter' => 'local_record',
            'reference' => $subRef ?? ('STAGED-' . strtoupper($partnerKey) . '-' . $merchantId),
            'message' => 'Keys OK — local onboarding record saved. Live partner KYC API not connected yet (not Phase 11 routing).',
        ];
    }

    return null;
}

/**
 * Queue status counts for Admin Forward Matrix (5c stats).
 *
 * @return array{by_status: array<string,int>, by_partner: list<array{partner_key:string,status:string,cnt:int}>, total:int}
 */
function getForwardQueueStats(): array
{
    ensurePartnerForwardQueueTable();
    $out = ['by_status' => [], 'by_partner' => [], 'total' => 0];
    try {
        $rows = getDB()->query(
            "SELECT status, COUNT(*) AS cnt FROM partner_forward_queue GROUP BY status"
        )->fetchAll() ?: [];
        foreach ($rows as $r) {
            $st = (string)($r['status'] ?? '');
            $c = (int)($r['cnt'] ?? 0);
            $out['by_status'][$st] = $c;
            $out['total'] += $c;
        }
        $out['by_partner'] = getDB()->query(
            "SELECT partner_key, status, COUNT(*) AS cnt
             FROM partner_forward_queue
             GROUP BY partner_key, status
             ORDER BY partner_key ASC, status ASC
             LIMIT 80"
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        /* ok */
    }
    return $out;
}

/**
 * Push KYC package to a partner API.
 * 5b: real Partner Registry key check. 5c: per-partner adapter hook + honest staged hold.
 */
function pushPackageToPartner(string $partnerKey, int $merchantId, array $payload): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '' || $partnerKey === 'unassigned') {
        return [
            'success' => false,
            'staged' => true,
            'reference' => 'UNASSIGNED-' . $merchantId,
            'message' => 'No partner keys yet — paste keys in Partner Registry, then re-queue.',
        ];
    }

    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }

    $registry = getPartnerRegistry();
    if (!isset($registry[$partnerKey])) {
        return ['success' => false, 'terminal' => true, 'error' => 'Unknown partner: ' . $partnerKey];
    }

    if (!partnerIsConfigured($partnerKey)) {
        return [
            'success' => false,
            'staged' => true,
            'reference' => 'STAGED-' . strtoupper($partnerKey) . '-' . $merchantId,
            'message' => 'Partner keys not configured — paste in Partner Registry, then re-queue.',
        ];
    }

    if (!function_exists('build_partner_onboarding_payload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $fullPayload = build_partner_onboarding_payload($merchantId);
    $payloadReady = !empty($fullPayload['merchant']);

    $adapted = runKycForwardAdapter($partnerKey, $merchantId, $fullPayload);
    if (is_array($adapted)) {
        return $adapted;
    }

    return [
        'success' => false,
        'staged' => true,
        'payload_ready' => $payloadReady,
        'adapter' => 'none',
        'reference' => 'STAGED-' . strtoupper($partnerKey) . '-' . $merchantId,
        'message' => $payloadReady
            ? 'Keys OK — KYC package ready. No adapter registered for this partner yet.'
            : 'Keys OK — package incomplete (merchant payload empty). Check KYC docs.',
    ];
}

/**
 * Merchant-safe label for forward-queue status (no partner brand leak).
 */
function merchantForwardQueueStatusLabel(string $status): string
{
    return match ($status) {
        'queued' => 'Queued',
        'processing' => 'Processing',
        'staged' => 'Prepared — not sent to network yet',
        'success' => 'Accepted by payment network',
        'retry' => 'Retry scheduled',
        'failed' => 'Needs UniWeb review',
        'paused' => 'Paused',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

/** Admin matrix — honest labels; success only when partner API confirmed. */
function forwardQueueAdminStatusLabel(string $status): string
{
    $vocab = forwardQueueStatusVocabulary();
    return $vocab[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

/** Admin badge text — includes adapter hint when staged + local_record. */
function forwardQueueAdminStatusBadge(array $row): string
{
    $status = (string)($row['status'] ?? '');
    $label = forwardQueueAdminStatusLabel($status);
    $adapter = forwardQueueRowAdapterMode($row);
    if ($status === 'staged' && $adapter === 'local_record') {
        return $label . ' · local_record';
    }
    if ($status === 'success' && $adapter === 'live_api') {
        return $label . ' · live_api';
    }
    return $label;
}

/**
 * Timeline events for one forward queue row (Admin detail / proof).
 *
 * @return list<array{at:string,event:string,detail:string}>
 */
function getForwardQueueRowTimeline(int $queueId): array
{
    ensurePartnerForwardQueueTable();
    $st = getDB()->prepare('SELECT * FROM partner_forward_queue WHERE id=? LIMIT 1');
    $st->execute([$queueId]);
    $row = $st->fetch();
    if (!$row) {
        return [];
    }
    $timeline = [];
    $timeline[] = [
        'at' => (string)($row['created_at'] ?? ''),
        'event' => 'Row created',
        'detail' => 'Partner: ' . ($row['partner_key'] ?? '—') . ' · initial enqueue',
    ];
    if (!empty($row['schedule_at'])) {
        $timeline[] = [
            'at' => (string)$row['schedule_at'],
            'event' => 'Scheduled',
            'detail' => 'Worker eligible after this time (hold window / IST policy)',
        ];
    }
    if (!empty($row['last_attempt_at'])) {
        $timeline[] = [
            'at' => (string)$row['last_attempt_at'],
            'event' => 'Last attempt #' . (int)($row['attempts'] ?? 0),
            'detail' => forwardQueueAdminStatusLabel((string)($row['status'] ?? '')),
        ];
    }
    if (!empty($row['updated_at']) && ($row['updated_at'] ?? '') !== ($row['created_at'] ?? '')) {
        $timeline[] = [
            'at' => (string)$row['updated_at'],
            'event' => 'Status: ' . forwardQueueAdminStatusLabel((string)($row['status'] ?? '')),
            'detail' => maskForwardQueueErrorMessage(trim((string)($row['error_message'] ?? ''))) ?: (string)($row['partner_reference'] ?? '—'),
        ];
    }
    if (!empty($row['partner_response'])) {
        $resp = json_decode((string)$row['partner_response'], true);
        if (is_array($resp)) {
            if (!empty($resp['message'])) {
                $timeline[] = [
                    'at' => (string)($row['updated_at'] ?? $row['last_attempt_at'] ?? ''),
                    'event' => 'Adapter response',
                    'detail' => maskForwardQueueErrorMessage((string)$resp['message']),
                ];
            }
            $adapter = (string)($resp['adapter'] ?? '');
            if ($adapter !== '') {
                $timeline[] = [
                    'at' => (string)($row['updated_at'] ?? $row['created_at'] ?? ''),
                    'event' => 'Adapter mode',
                    'detail' => $adapter === 'local_record'
                        ? 'local_record — UniWeb saved only, not partner API success'
                        : ($adapter === 'live_api' ? 'live_api — partner HTTP path' : $adapter),
                ];
            }
        }
    }
    return $timeline;
}

/**
 * Get forward queue status for a merchant (D4: status matrix on merchant).
 */
function getMerchantForwardStatus(int $merchantId): array
{
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare(
            "SELECT partner_key, status, attempts, schedule_at, last_attempt_at, error_message, partner_reference
             FROM partner_forward_queue
             WHERE merchant_id=?
             ORDER BY created_at DESC"
        );
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get all forward queue items for admin status matrix (D4).
 */
function getAdminForwardMatrix(string $statusFilter = '', string $q = '', string $partnerFilter = ''): array
{
    ensurePartnerForwardQueueTable();
    try {
        $sql = "SELECT q.*, m.merchant_code, m.business_name
                FROM partner_forward_queue q
                JOIN merchants m ON m.id = q.merchant_id";
        $params = [];
        $conditions = [];
        if ($statusFilter !== '') {
            $conditions[] = "q.status = ?";
            $params[] = $statusFilter;
        }
        $partnerFilter = strtolower(trim($partnerFilter));
        if ($partnerFilter !== '') {
            $conditions[] = 'q.partner_key = ?';
            $params[] = $partnerFilter;
        }
        if ($q !== '') {
            $like = '%' . strtolower($q) . '%';
            $conditions[] = "(LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.merchant_code,''))) LIKE ? OR LOWER(TRIM(COALESCE(q.partner_key,''))) LIKE ? OR LOWER(TRIM(COALESCE(q.status,''))) LIKE ? OR CAST(q.id AS CHAR) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        if ($conditions) {
            $sql .= " WHERE " . implode(' AND ', $conditions);
        }
        $sql .= " ORDER BY q.schedule_at DESC LIMIT 200";
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * D5: Manual fallback — staff can manually mark an item for re-queue.
 */
function manualRequeueForward(int $itemId): bool
{
    ensurePartnerForwardQueueTable();
    try {
        $st = getDB()->prepare("UPDATE partner_forward_queue SET status='queued', attempts=0, schedule_at=NOW(), error_message=NULL, partner_reference=NULL WHERE id=? AND status IN ('failed','staged')");
        $st->execute([$itemId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
