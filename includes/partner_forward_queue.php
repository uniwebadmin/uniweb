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
 */

if (!function_exists('ensurePartnerForwardQueueTable')) {
function ensurePartnerForwardQueueTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS partner_forward_queue (
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
}
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

    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    $hour = (int)$now->format('H');
    if ($hour >= 18) {
        $schedule = new DateTime('tomorrow 09:00', new DateTimeZone('Asia/Kolkata'));
    } else {
        $schedule = clone $now;
        $schedule->modify('+' . random_int(60, 90) . ' minutes');
    }
    try {
        $st = $db->prepare(
            'INSERT INTO partner_forward_queue (merchant_id, partner_key, package_payload, status, schedule_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $st->execute([
            $merchantId,
            $partnerKey,
            $payload ? json_encode($payload) : null,
            'queued',
            $schedule->format('Y-m-d H:i:s'),
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        logPlatformError('error', 'enqueuePartnerForward failed: ' . $e->getMessage(), ['merchant_id' => $merchantId]);
        return 0;
    }
}

/**
 * D4: Cron worker — process queued items whose schedule_at has passed.
 * Returns count of processed items.
 */
if (!function_exists('processPerPartnerForwardQueue')) {
function processPerPartnerForwardQueue(int $limit = 20): array
{
    ensurePartnerForwardQueueTable();
    $db = getDB();
    $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];

    try {
        $st = $db->prepare(
            "SELECT * FROM partner_forward_queue
             WHERE status IN ('queued','retry')
               AND schedule_at <= NOW()
               AND attempts < max_attempts
             ORDER BY schedule_at ASC
             LIMIT ?"
        );
        $st->execute([$limit]);
        $items = $st->fetchAll();
    } catch (Throwable $e) {
        return $results;
    }

    foreach ($items as $item) {
        $results['processed']++;
        $itemId = (int)$item['id'];
        $merchantId = (int)$item['merchant_id'];
        $partnerKey = $item['partner_key'];
        $attempts = (int)$item['attempts'] + 1;

        try {
            $db->prepare("UPDATE partner_forward_queue SET status='processing', attempts=?, last_attempt_at=NOW() WHERE id=?")
                ->execute([$attempts, $itemId]);

            $payload = $item['package_payload'] ? json_decode($item['package_payload'], true) : [];
            $result = pushPackageToPartner($partnerKey, $merchantId, $payload);

            if ($result['success'] ?? false) {
                $db->prepare("UPDATE partner_forward_queue SET status='success', partner_reference=?, partner_response=? WHERE id=?")
                    ->execute([
                        $result['reference'] ?? null,
                        json_encode($result),
                        $itemId,
                    ]);
                $results['success']++;
                if (function_exists('notifyMerchant')) {
                    notifyMerchant($merchantId, 'KYC Forwarded', 'Your KYC package has been submitted to ' . ucfirst($partnerKey) . '.', 'kyc_fwd_' . $merchantId . '_' . $partnerKey);
                } elseif (function_exists('createNotification')) {
                    createNotification($merchantId, 'KYC Forwarded', 'Your KYC package has been submitted to ' . ucfirst($partnerKey) . '.');
                }
            } elseif (!empty($result['staged'])) {
                // 5b: keys OK + package built, but live partner API adapter not live yet — do not fake success or fail-retry spam
                $db->prepare("UPDATE partner_forward_queue SET status='staged', partner_reference=?, partner_response=?, error_message=? WHERE id=?")
                    ->execute([
                        $result['reference'] ?? null,
                        json_encode($result),
                        $result['message'] ?? 'Package ready — partner API adapter pending',
                        $itemId,
                    ]);
                $results['staged'] = ($results['staged'] ?? 0) + 1;
            } else {
                $err = (string)($result['error'] ?? 'Unknown error');
                $terminal = !empty($result['terminal']);
                if ($terminal || $attempts >= (int)$item['max_attempts']) {
                    $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=?")
                        ->execute([$err, $itemId]);
                    $results['failed']++;
                    if (!$terminal && function_exists('notifyMerchant')) {
                        notifyMerchant($merchantId, 'KYC Forward Failed', 'KYC submission to ' . ucfirst($partnerKey) . ' failed after ' . $attempts . ' attempts. Staff will assist manually.', 'kyc_fwd_fail_' . $merchantId . '_' . $partnerKey);
                    } elseif (!$terminal && function_exists('createNotification')) {
                        createNotification($merchantId, 'KYC Forward Failed', 'KYC submission to ' . ucfirst($partnerKey) . ' failed after ' . $attempts . ' attempts. Staff will assist manually.');
                    }
                } else {
                    $nextRetry = date('Y-m-d H:i:s', time() + ($attempts * 1800));
                    $db->prepare("UPDATE partner_forward_queue SET status='retry', error_message=?, schedule_at=? WHERE id=?")
                        ->execute([$err, $nextRetry, $itemId]);
                    $results['retry']++;
                }
            }
        } catch (Throwable $e) {
            $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=?")
                ->execute([$e->getMessage(), $itemId]);
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
    return [
        'processed' => (int)($r['processed'] ?? 0),
        'success' => (int)($r['success'] ?? 0),
        'failed' => (int)($r['failed'] ?? 0),
        'retry' => (int)($r['retry'] ?? 0),
        'forwarded' => (int)($r['success'] ?? 0),
        'errors' => (int)($r['failed'] ?? 0),
    ];
}
}

if (!function_exists('queueMerchantForPartnerForward')) {
function queueMerchantForPartnerForward(int $merchantId, ?string $gateways = null): bool
{
    if (function_exists('enqueueMerchantToAllEnabledPartners')) {
        enqueueMerchantToAllEnabledPartners($merchantId);
        return true;
    }
    $keys = [];
    if ($gateways !== null && $gateways !== '') {
        $keys = array_values(array_filter(array_map('trim', explode(',', $gateways))));
    }
    if ($keys === [] && function_exists('gatewaySubmissionAllowedGateways')) {
        $keys = gatewaySubmissionAllowedGateways();
    }
    foreach ($keys as $key) {
        enqueuePartnerForward($merchantId, (string)$key);
    }
    return $keys !== [];
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
    $now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
    if ((int)$now->format('H') >= 18) {
        $schedule = new DateTime('tomorrow 09:00', new DateTimeZone('Asia/Kolkata'));
    } else {
        $schedule = clone $now;
        $schedule->modify('+' . random_int(60, 90) . ' minutes');
    }
    try {
        getDB()->prepare("UPDATE partner_forward_queue SET status='queued', schedule_at=?, error_message=NULL WHERE merchant_id=? AND status='paused'")
            ->execute([$schedule->format('Y-m-d H:i:s'), $merchantId]);
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
 * Push KYC package to a partner API.
 * 5b: use real Partner Registry key check (partnerIsConfigured). Until adapters exist,
 * mark queue rows as staged (package ready) instead of a fake "keys missing" loop.
 */
function pushPackageToPartner(string $partnerKey, int $merchantId, array $payload): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '' || $partnerKey === 'unassigned') {
        return [
            'success' => false,
            'terminal' => true,
            'error' => 'No partner keys yet — paste keys in Partner Registry, then re-queue.',
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
            'error' => 'Partner keys not configured in Partner Registry → Keys',
        ];
    }

    // Rebuild full payload at push time — the stored queue payload is redacted
    if (!function_exists('build_partner_onboarding_payload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $fullPayload = build_partner_onboarding_payload($merchantId);
    $payloadReady = !empty($fullPayload['merchant']);

    // Honest staging until a live partner adapter is wired (5c)
    return [
        'success' => false,
        'staged' => true,
        'payload_ready' => $payloadReady,
        'reference' => 'STAGED-' . strtoupper($partnerKey) . '-' . $merchantId,
        'message' => $payloadReady
            ? 'Keys OK — KYC package ready. Partner API adapter not live yet (manual / next step).'
            : 'Keys OK — package incomplete (merchant payload empty). Check KYC docs.',
    ];
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
function getAdminForwardMatrix(string $statusFilter = '', string $q = ''): array
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
        getDB()->prepare("UPDATE partner_forward_queue SET status='queued', attempts=0, schedule_at=NOW(), error_message=NULL WHERE id=? AND status='failed'")
            ->execute([$itemId]);
        return getDB()->lastInsertId() > 0 || true;
    } catch (Throwable $e) {
        return false;
    }
}
