<?php
declare(strict_types=1);

/**
 * Merchant → Admin "Request to Enable" payment method workflow.
 *
 * A merchant can freely toggle only the methods their provision profile
 * entitles them to. Any other catalogue method is *locked* and shows a
 * "Request to Enable" button. Requests land in an admin queue; on approval
 * the method is unlocked for that merchant.
 */

function ensureMethodRequestSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec(
            'CREATE TABLE IF NOT EXISTS merchant_method_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                method_key VARCHAR(40) NOT NULL,
                status ENUM("pending","approved","rejected") NOT NULL DEFAULT "pending",
                merchant_note VARCHAR(500) DEFAULT NULL,
                admin_note VARCHAR(500) DEFAULT NULL,
                decided_by VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME DEFAULT NULL,
                INDEX idx_mmr_merchant (merchant_id),
                INDEX idx_mmr_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('ensureMethodRequestSchema: ' . $e->getMessage());
    }
}

/** Methods the merchant is entitled to toggle themselves (profile + approved requests). */
function merchantEntitledMethods(array $merchant): array
{
    $profile = getMerchantProvisionProfile($merchant);
    $methods = $profile['methods'] ?? [];
    // Any already-enabled methods stay entitled so we never strip access.
    $methods = array_merge($methods, getMerchantEnabledMethods($merchant));
    foreach (approvedMethodKeys((int)$merchant['id']) as $k) {
        $methods[] = $k;
    }
    $catalog = array_keys(getPaymentMethodCatalog());
    return array_values(array_unique(array_intersect($catalog, $methods)));
}

/** Catalogue methods not yet entitled — these are the "request to enable" candidates. */
function merchantLockedMethods(array $merchant): array
{
    $catalog = array_keys(getPaymentMethodCatalog());
    return array_values(array_diff($catalog, merchantEntitledMethods($merchant)));
}

function approvedMethodKeys(int $merchantId): array
{
    ensureMethodRequestSchema();
    try {
        $stmt = getDB()->prepare('SELECT DISTINCT method_key FROM merchant_method_requests WHERE merchant_id=? AND status="approved"');
        $stmt->execute([$merchantId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
        return [];
    }
}

/** Latest request status per method for this merchant: [method_key => status]. */
function merchantMethodRequestMap(int $merchantId): array
{
    ensureMethodRequestSchema();
    $map = [];
    try {
        $stmt = getDB()->prepare('SELECT method_key, status FROM merchant_method_requests WHERE merchant_id=? ORDER BY id ASC');
        $stmt->execute([$merchantId]);
        foreach ($stmt->fetchAll() as $row) {
            $map[(string)$row['method_key']] = (string)$row['status'];
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $map;
}

/** Merchant raises a request to enable a locked method. */
function requestMethodEnable(int $merchantId, string $methodKey, string $note = ''): array
{
    ensureMethodRequestSchema();
    $catalog = getPaymentMethodCatalog();
    if (!isset($catalog[$methodKey])) {
        return ['ok' => false, 'error' => 'Unknown payment method.'];
    }
    try {
        $db = getDB();
        $chk = $db->prepare('SELECT id FROM merchant_method_requests WHERE merchant_id=? AND method_key=? AND status="pending" LIMIT 1');
        $chk->execute([$merchantId, $methodKey]);
        if ($chk->fetch()) {
            return ['ok' => false, 'error' => 'A request for this method is already pending.'];
        }
        $db->prepare('INSERT INTO merchant_method_requests (merchant_id, method_key, merchant_note) VALUES (?,?,?)')
            ->execute([$merchantId, $methodKey, mb_substr(trim($note), 0, 500) ?: null]);
        return ['ok' => true, 'message' => $catalog[$methodKey]['label'] . ' requested. Admin will review shortly.'];
    } catch (Throwable $e) {
        error_log('requestMethodEnable: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not submit request. Please try again.'];
    }
}

function getPendingMethodRequestCount(): int
{
    ensureMethodRequestSchema();
    try {
        return (int)getDB()->query('SELECT COUNT(*) FROM merchant_method_requests WHERE status="pending"')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Admin list with merchant context. $status = pending|approved|rejected|all */
function getMethodRequests(string $status = 'pending', int $limit = 200): array
{
    ensureMethodRequestSchema();
    $sql = 'SELECT r.*, m.business_name, m.merchant_code
            FROM merchant_method_requests r
            JOIN merchants m ON r.merchant_id = m.id';
    $params = [];
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(1, min(500, $limit));
    try {
        $stmt = getDB()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Admin approves/rejects a request. On approve, unlock the method for the merchant. */
function decideMethodRequest(int $requestId, bool $approve, string $decidedBy, string $adminNote = ''): array
{
    ensureMethodRequestSchema();
    $db = getDB();
    try {
        $stmt = $db->prepare('SELECT * FROM merchant_method_requests WHERE id=? LIMIT 1');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) return ['ok' => false, 'error' => 'Request not found.'];
        if ($req['status'] !== 'pending') return ['ok' => false, 'error' => 'Request already decided.'];

        $newStatus = $approve ? 'approved' : 'rejected';
        $db->prepare('UPDATE merchant_method_requests SET status=?, admin_note=?, decided_by=?, decided_at=NOW() WHERE id=?')
            ->execute([$newStatus, mb_substr(trim($adminNote), 0, 500) ?: null, mb_substr($decidedBy, 0, 120), $requestId]);

        if ($approve) {
            unlockMerchantMethod((int)$req['merchant_id'], (string)$req['method_key']);
        }
        return ['ok' => true, 'message' => 'Request ' . $newStatus . '.'];
    } catch (Throwable $e) {
        error_log('decideMethodRequest: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not update request.'];
    }
}

/** Add a method to a merchant's persisted enabled_methods (best-effort; column may be absent). */
function unlockMerchantMethod(int $merchantId, string $methodKey): void
{
    try {
        $db = getDB();
        $m = $db->prepare('SELECT enabled_methods FROM merchants WHERE id=?');
        $m->execute([$merchantId]);
        $raw = (string)($m->fetchColumn() ?: '');
        $current = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $current = $decoded;
        }
        if (!in_array($methodKey, $current, true)) {
            $current[] = $methodKey;
        }
        $db->prepare('UPDATE merchants SET enabled_methods=? WHERE id=?')
            ->execute([json_encode(array_values($current)), $merchantId]);
    } catch (Throwable $e) {
        // enabled_methods column may not exist on older schemas; approval status still recorded.
        error_log('unlockMerchantMethod: ' . $e->getMessage());
    }
}
