<?php
declare(strict_types=1);

/**
 * Sub-Merchant Hierarchy.
 *
 * Allows a parent merchant to have sub-merchants (branches, franchises, outlets).
 * Settlements and reports can be rolled up to the parent.
 */

function ensureSubMerchantTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_hierarchy (
            id INT AUTO_INCREMENT PRIMARY KEY,
            parent_merchant_id INT NOT NULL,
            child_merchant_id INT NOT NULL,
            relationship VARCHAR(30) NOT NULL DEFAULT 'branch',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_parent_child (parent_merchant_id, child_merchant_id),
            INDEX idx_parent (parent_merchant_id, status),
            INDEX idx_child (child_merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getSubMerchants(int $parentMerchantId): array
{
    ensureSubMerchantTable();
    try {
        $st = getDB()->prepare(
            "SELECT h.*, m.business_name, m.merchant_code, m.kyc_status, m.status
             FROM merchant_hierarchy h
             JOIN merchants m ON m.id = h.child_merchant_id
             WHERE h.parent_merchant_id=? AND h.status='active'
             ORDER BY m.business_name"
        );
        $st->execute([$parentMerchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getParentMerchant(int $childMerchantId): ?array
{
    ensureSubMerchantTable();
    try {
        $st = getDB()->prepare(
            "SELECT m.* FROM merchant_hierarchy h
             JOIN merchants m ON m.id = h.parent_merchant_id
             WHERE h.child_merchant_id=? AND h.status='active' LIMIT 1"
        );
        $st->execute([$childMerchantId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function addSubMerchant(int $parentMerchantId, int $childMerchantId, string $relationship = 'branch'): array
{
    ensureSubMerchantTable();
    if ($parentMerchantId === $childMerchantId) {
        return ['ok' => false, 'error' => 'Cannot add self as sub-merchant.'];
    }
    try {
        getDB()->prepare(
            "INSERT INTO merchant_hierarchy (parent_merchant_id, child_merchant_id, relationship)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE status='active', relationship=VALUES(relationship)"
        )->execute([$parentMerchantId, $childMerchantId, $relationship]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function removeSubMerchant(int $parentMerchantId, int $childMerchantId): bool
{
    ensureSubMerchantTable();
    try {
        getDB()->prepare("UPDATE merchant_hierarchy SET status='inactive' WHERE parent_merchant_id=? AND child_merchant_id=?")
            ->execute([$parentMerchantId, $childMerchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get rolled-up transaction summary for a parent merchant (includes all sub-merchants).
 */
function getHierarchyTransactionSummary(int $parentMerchantId, int $days = 30): array
{
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));
    $childIds = array_column(getSubMerchants($parentMerchantId), 'child_merchant_id');
    $allIds = array_merge([$parentMerchantId], $childIds);

    if (empty($allIds)) {
        return ['total' => 0, 'volume' => 0, 'success' => 0];
    }

    try {
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $st = getDB()->prepare(
            "SELECT COUNT(*) as total,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success,
                COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as volume
             FROM transactions WHERE merchant_id IN ($placeholders) AND created_at >= ?"
        );
        $params = array_merge($allIds, [$since]);
        $st->execute($params);
        $row = $st->fetch();
        return [
            'total' => (int)($row['total'] ?? 0),
            'success' => (int)($row['success'] ?? 0),
            'volume' => (float)($row['volume'] ?? 0),
            'merchant_count' => count($allIds),
        ];
    } catch (Throwable $e) {
        return ['total' => 0, 'volume' => 0, 'success' => 0, 'merchant_count' => 1];
    }
}
