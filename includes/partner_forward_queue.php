<?php
declare(strict_types=1);

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
             WHERE merchant_id=? AND partner_key=? AND status IN ('queued','retry','processing')
             LIMIT 1"
        );
        $check->execute([$merchantId, $partnerKey]);
        if ($check->fetchColumn()) {
            return 0; // already queued — do not flood duplicates
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
                if (function_exists('createNotification')) {
                    createNotification($merchantId, 'KYC Forwarded', 'Your KYC package has been submitted to ' . ucfirst($partnerKey) . '.');
                }
            } else {
                if ($attempts >= (int)$item['max_attempts']) {
                    $db->prepare("UPDATE partner_forward_queue SET status='failed', error_message=? WHERE id=?")
                        ->execute([$result['error'] ?? 'Unknown error', $itemId]);
                    $results['failed']++;
                    if (function_exists('createNotification')) {
                        createNotification($merchantId, 'KYC Forward Failed', 'KYC submission to ' . ucfirst($partnerKey) . ' failed after ' . $attempts . ' attempts. Staff will assist manually.');
                    }
                } else {
                    $nextRetry = date('Y-m-d H:i:s', time() + ($attempts * 1800));
                    $db->prepare("UPDATE partner_forward_queue SET status='retry', error_message=?, schedule_at=? WHERE id=?")
                        ->execute([$result['error'] ?? 'Unknown error', $nextRetry, $itemId]);
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
 * Push KYC package to a partner API.
 * Stub for now — real adapter will be built when partner keys are configured.
 */
function pushPackageToPartner(string $partnerKey, int $merchantId, array $payload): array
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $registry = getPartnerRegistry();
    if (!isset($registry[$partnerKey])) {
        return ['success' => false, 'error' => 'Unknown partner: ' . $partnerKey];
    }

    $partner = $registry[$partnerKey];
    if (empty($partner['keys_configured'])) {
        return ['success' => false, 'error' => 'Partner keys not configured yet'];
    }

    // Rebuild full payload at push time — the stored queue payload is redacted
    if (!function_exists('build_partner_onboarding_payload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $fullPayload = build_partner_onboarding_payload($merchantId);

    return ['success' => false, 'error' => 'Partner adapter not yet implemented for ' . $partnerKey, 'payload_ready' => !empty($fullPayload['merchant'])];
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
