<?php
declare(strict_types=1);

/**
 * Sub-Merchant Hierarchy (canonical parent ↔ child links).
 *
 * Agents (merchant portal) and Admin Sub-Merchants share one model:
 * - merchant_hierarchy row (admin reports / roll-up)
 * - merchants.parent_merchant_id (agent list / commission)
 *
 * Always use addSubMerchant() / removeSubMerchant() — never update one side only.
 */

function ensureSubMerchantTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
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
    backfillMerchantHierarchySync();
}

/**
 * One-time idempotent sync for rows created before dual-write (local boot + migration 069).
 */
function backfillMerchantHierarchySync(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    ensureMerchantAgentColumns();

    try {
        $db = getDB();
        $db->exec(
            "INSERT INTO merchant_hierarchy (parent_merchant_id, child_merchant_id, relationship, status)
             SELECT m.parent_merchant_id, m.id, 'franchise', 'active'
             FROM merchants m
             WHERE m.parent_merchant_id IS NOT NULL
               AND m.parent_merchant_id > 0
               AND m.status != 'deleted'
             ON DUPLICATE KEY UPDATE
               status = 'active',
               relationship = IF(merchant_hierarchy.relationship = 'branch', VALUES(relationship), merchant_hierarchy.relationship)"
        );
        $db->exec(
            "UPDATE merchants m
             INNER JOIN merchant_hierarchy h
               ON h.child_merchant_id = m.id AND h.status = 'active'
             SET m.parent_merchant_id = h.parent_merchant_id
             WHERE m.parent_merchant_id IS NULL OR m.parent_merchant_id = 0"
        );
    } catch (Throwable $e) { /* ok */ }
}

function merchantParentLinkConflict(int $parentMerchantId, int $childMerchantId): ?string
{
    if ($parentMerchantId === $childMerchantId) {
        return 'Cannot link merchant to itself.';
    }
    if ($parentMerchantId < 1 || $childMerchantId < 1) {
        return 'Invalid merchant id.';
    }

    ensureSubMerchantTable();
    ensureMerchantAgentColumns();

    try {
        $db = getDB();

        $st = $db->prepare('SELECT parent_merchant_id, status FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$childMerchantId]);
        $child = $st->fetch();
        if (!$child) {
            return 'Child merchant not found.';
        }
        if (($child['status'] ?? '') === 'deleted') {
            return 'Child merchant is deleted.';
        }

        $existingParent = (int)($child['parent_merchant_id'] ?? 0);
        if ($existingParent > 0 && $existingParent !== $parentMerchantId) {
            return 'Child merchant already belongs to another parent.';
        }

        $st = $db->prepare(
            "SELECT parent_merchant_id FROM merchant_hierarchy
             WHERE child_merchant_id=? AND status='active' LIMIT 1"
        );
        $st->execute([$childMerchantId]);
        $hRow = $st->fetch();
        if ($hRow) {
            $hParent = (int)($hRow['parent_merchant_id'] ?? 0);
            if ($hParent > 0 && $hParent !== $parentMerchantId) {
                return 'Child merchant already linked under another parent in hierarchy.';
            }
        }

        if (merchantIsDescendantOf($parentMerchantId, $childMerchantId)) {
            return 'This link would create a circular hierarchy.';
        }
    } catch (Throwable $e) {
        return 'Could not validate hierarchy link.';
    }

    return null;
}

function merchantIsDescendantOf(int $ancestorId, int $candidateAncestorId): bool
{
    if ($ancestorId < 1 || $candidateAncestorId < 1 || $ancestorId === $candidateAncestorId) {
        return $ancestorId === $candidateAncestorId;
    }

    ensureSubMerchantTable();
    ensureMerchantAgentColumns();

    $current = $ancestorId;
    $seen = [];
    $maxHops = 32;

    try {
        $db = getDB();
        for ($i = 0; $i < $maxHops && $current > 0; $i++) {
            if ($current === $candidateAncestorId) {
                return true;
            }
            if (isset($seen[$current])) {
                break;
            }
            $seen[$current] = true;

            $parentId = 0;
            $st = $db->prepare('SELECT parent_merchant_id FROM merchants WHERE id=? LIMIT 1');
            $st->execute([$current]);
            $row = $st->fetch();
            if ($row && !empty($row['parent_merchant_id'])) {
                $parentId = (int)$row['parent_merchant_id'];
            }
            if ($parentId < 1) {
                $st = $db->prepare(
                    "SELECT parent_merchant_id FROM merchant_hierarchy
                     WHERE child_merchant_id=? AND status='active' LIMIT 1"
                );
                $st->execute([$current]);
                $h = $st->fetch();
                if ($h && !empty($h['parent_merchant_id'])) {
                    $parentId = (int)$h['parent_merchant_id'];
                }
            }
            $current = $parentId;
        }
    } catch (Throwable $e) {
        return false;
    }

    return false;
}

function syncMerchantParentColumn(int $parentMerchantId, int $childMerchantId): void
{
    ensureMerchantAgentColumns();
    getDB()->prepare(
        'UPDATE merchants SET parent_merchant_id=? WHERE id=? AND (parent_merchant_id IS NULL OR parent_merchant_id=? OR parent_merchant_id=0)'
    )->execute([$parentMerchantId, $childMerchantId, $parentMerchantId]);
}

function clearMerchantParentColumn(int $parentMerchantId, int $childMerchantId): void
{
    ensureMerchantAgentColumns();
    getDB()->prepare(
        'UPDATE merchants SET parent_merchant_id=NULL WHERE id=? AND parent_merchant_id=?'
    )->execute([$childMerchantId, $parentMerchantId]);
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

/**
 * Child merchants under a parent (agent portal + synced hierarchy).
 */
function getChildMerchants(int $parentMerchantId): array
{
    ensureSubMerchantTable();
    ensureMerchantAgentColumns();
    try {
        $st = getDB()->prepare(
            "SELECT m.* FROM merchants m
             WHERE m.parent_merchant_id=? AND m.status != 'deleted'
             ORDER BY m.created_at DESC"
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
    ensureMerchantAgentColumns();
    try {
        $db = getDB();
        $st = $db->prepare(
            "SELECT m.* FROM merchant_hierarchy h
             JOIN merchants m ON m.id = h.parent_merchant_id
             WHERE h.child_merchant_id=? AND h.status='active' LIMIT 1"
        );
        $st->execute([$childMerchantId]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }

        $st = $db->prepare(
            'SELECT p.* FROM merchants c
             JOIN merchants p ON p.id = c.parent_merchant_id
             WHERE c.id=? AND c.parent_merchant_id IS NOT NULL AND c.parent_merchant_id > 0
             LIMIT 1'
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

    $relationship = trim($relationship) !== '' ? trim($relationship) : 'branch';
    $conflict = merchantParentLinkConflict($parentMerchantId, $childMerchantId);
    if ($conflict !== null) {
        return ['ok' => false, 'error' => $conflict];
    }

    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO merchant_hierarchy (parent_merchant_id, child_merchant_id, relationship)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE status='active', relationship=VALUES(relationship)"
        )->execute([$parentMerchantId, $childMerchantId, $relationship]);
        syncMerchantParentColumn($parentMerchantId, $childMerchantId);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function removeSubMerchant(int $parentMerchantId, int $childMerchantId): bool
{
    ensureSubMerchantTable();
    try {
        getDB()->prepare(
            "UPDATE merchant_hierarchy SET status='inactive'
             WHERE parent_merchant_id=? AND child_merchant_id=?"
        )->execute([$parentMerchantId, $childMerchantId]);
        clearMerchantParentColumn($parentMerchantId, $childMerchantId);
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
