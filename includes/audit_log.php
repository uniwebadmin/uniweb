<?php
declare(strict_types=1);

/**
 * A7: Immutable Audit Log — records all money actions to immutable_audit_log table.
 * The table has DB triggers preventing UPDATE and DELETE.
 * This file provides the write function + query helpers.
 */

function ensureAuditLogTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS immutable_audit_log (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_id VARCHAR(40) NOT NULL,
            actor_type VARCHAR(32) NOT NULL,
            actor_id INT DEFAULT NULL,
            action VARCHAR(80) NOT NULL,
            merchant_id INT DEFAULT NULL,
            resource_type VARCHAR(64) DEFAULT NULL,
            resource_id VARCHAR(100) DEFAULT NULL,
            reason VARCHAR(500) DEFAULT NULL,
            before_hash CHAR(64) DEFAULT NULL,
            after_hash CHAR(64) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_audit_event (event_id),
            INDEX idx_audit_action (action, created_at),
            INDEX idx_audit_merchant (merchant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Record an immutable audit log entry for a money action.
 * Once written, cannot be updated or deleted (DB triggers enforce).
 *
 * @param string $action   e.g. 'payment_capture', 'settlement', 'payout', 'refund', 'balance_rebuild'
 * @param array  $params   merchant_id, actor_type, actor_id, resource_type, resource_id, reason, before_state, after_state
 */
function recordAuditEvent(string $action, array $params = []): ?int
{
    ensureAuditLogTable();
    try {
        $db = getDB();
        $eventId = generateId('AUD');

        $beforeHash = null;
        $afterHash = null;
        if (isset($params['before_state'])) {
            $beforeHash = hash('sha256', json_encode($params['before_state'], JSON_UNESCAPED_SLASHES));
        }
        if (isset($params['after_state'])) {
            $afterHash = hash('sha256', json_encode($params['after_state'], JSON_UNESCAPED_SLASHES));
        }

        $st = $db->prepare(
            'INSERT INTO immutable_audit_log
             (event_id, actor_type, actor_id, action, merchant_id, resource_type, resource_id, reason, before_hash, after_hash, ip_address, user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $eventId,
            mb_substr((string)($params['actor_type'] ?? 'system'), 0, 32),
            $params['actor_id'] ?? null,
            mb_substr($action, 0, 80),
            $params['merchant_id'] ?? null,
            mb_substr((string)($params['resource_type'] ?? ''), 0, 64) ?: null,
            mb_substr((string)($params['resource_id'] ?? ''), 0, 100) ?: null,
            mb_substr((string)($params['reason'] ?? ''), 0, 500) ?: null,
            $beforeHash,
            $afterHash,
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500) ?: null,
        ]);
        return (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // Non-fatal — audit log should never break the main operation
        error_log('recordAuditEvent failed: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get audit log entries for a merchant.
 */
function getMerchantAuditLog(int $merchantId, int $limit = 100): array
{
    ensureAuditLogTable();
    try {
        $st = getDB()->prepare(
            "SELECT * FROM immutable_audit_log WHERE merchant_id=? ORDER BY created_at DESC LIMIT ?"
        );
        $st->bindValue(1, $merchantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get audit log entries by action type.
 */
function getAuditLogByAction(string $action, int $limit = 100): array
{
    ensureAuditLogTable();
    try {
        $st = getDB()->prepare(
            "SELECT * FROM immutable_audit_log WHERE action=? ORDER BY created_at DESC LIMIT ?"
        );
        $st->bindValue(1, $action);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function auditLogFilterWhere(?string $actionFilter = null, ?int $merchantFilter = null, string $from = '', string $to = ''): array
{
    $where = '1=1';
    $params = [];
    if ($actionFilter) {
        $where .= ' AND action=?';
        $params[] = $actionFilter;
    }
    if ($merchantFilter) {
        $where .= ' AND merchant_id=?';
        $params[] = $merchantFilter;
    }
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $where .= ' AND DATE(created_at) >= ?';
        $params[] = $from;
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $where .= ' AND DATE(created_at) <= ?';
        $params[] = $to;
    }
    return [$where, $params];
}

/**
 * Get all audit log entries (admin view).
 */
function getAllAuditLog(int $limit = 200, int $offset = 0, ?string $actionFilter = null, ?int $merchantFilter = null, string $from = '', string $to = ''): array
{
    ensureAuditLogTable();
    try {
        [$where, $params] = auditLogFilterWhere($actionFilter, $merchantFilter, $from, $to);
        $limit = max(1, min(5000, $limit));
        $offset = max(0, $offset);
        $sql = "SELECT * FROM immutable_audit_log WHERE {$where} ORDER BY created_at DESC LIMIT {$limit} OFFSET {$offset}";
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Count total audit log entries (for pagination).
 */
function countAuditLog(?string $actionFilter = null, ?int $merchantFilter = null, string $from = '', string $to = ''): int
{
    ensureAuditLogTable();
    try {
        [$where, $params] = auditLogFilterWhere($actionFilter, $merchantFilter, $from, $to);
        $st = getDB()->prepare("SELECT COUNT(*) FROM immutable_audit_log WHERE {$where}");
        $st->execute($params);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
