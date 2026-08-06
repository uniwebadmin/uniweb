<?php
declare(strict_types=1);

/**
 * Grievance Redressal Engine — escalation, SLA tracking, monthly report.
 * Based on RBI Payment Aggregator guidelines.
 *
 * SLA:
 *   Level 0 (Open): Acknowledge within 24h, resolve within 5 working days
 *   Level 1 (Escalated L1): Grievance Officer — 7 working days
 *   Level 2 (Escalated L2): Nodal Officer — 30 days max before RBI Ombudsman
 */

function ensureGrievanceTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS grievance_complaints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id VARCHAR(32) NOT NULL UNIQUE,
            merchant_id INT DEFAULT NULL,
            customer_name VARCHAR(128) DEFAULT NULL,
            customer_email VARCHAR(255) DEFAULT NULL,
            customer_phone VARCHAR(32) DEFAULT NULL,
            transaction_id INT DEFAULT NULL,
            category ENUM('payment_failure','refund_delay','unauthorized_txn','settlement_delay','kyc_issue','tech_issue','other') NOT NULL DEFAULT 'other',
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            status ENUM('open','acknowledged','in_progress','escalated_l1','escalated_l2','resolved','rejected','closed') NOT NULL DEFAULT 'open',
            priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
            escalation_level TINYINT NOT NULL DEFAULT 0,
            assigned_to INT DEFAULT NULL,
            sla_deadline DATETIME DEFAULT NULL,
            acknowledged_at TIMESTAMP NULL DEFAULT NULL,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            resolution_note TEXT DEFAULT NULL,
            resolution_category ENUM('resolved','partially_resolved','not_resolved','invalid') DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_merchant (merchant_id),
            INDEX idx_escalation (escalation_level),
            INDEX idx_sla (sla_deadline, status),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS grievance_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            complaint_id INT NOT NULL,
            action_type ENUM('created','acknowledged','replied','escalated','resolved','rejected','closed','reopened','note') NOT NULL DEFAULT 'note',
            action_by INT DEFAULT NULL,
            action_by_type ENUM('merchant','customer','staff','system') NOT NULL DEFAULT 'system',
            message TEXT DEFAULT NULL,
            old_status VARCHAR(32) DEFAULT NULL,
            new_status VARCHAR(32) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_complaint (complaint_id),
            INDEX idx_type (action_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Generate unique complaint ID.
 */
function generateComplaintId(): string
{
    return 'GRV' . date('Ymd') . strtoupper(bin2hex(random_bytes(3)));
}

/**
 * SLA deadlines based on escalation level.
 */
function grievanceSlaDeadline(int $escalationLevel): string
{
    $days = match($escalationLevel) {
        0 => 5,   // 5 working days for initial resolution
        1 => 7,   // 7 working days for L1 (Grievance Officer)
        2 => 30,  // 30 days for L2 (Nodal Officer / RBI)
        default => 5,
    };
    return date('Y-m-d H:i:s', strtotime("+{$days} days"));
}

/**
 * Create a new grievance complaint.
 */
function createGrievanceComplaint(array $data): ?array
{
    ensureGrievanceTables();
    $complaintId = generateComplaintId();
    $slaDeadline = grievanceSlaDeadline(0);

    try {
        $db = getDB();
        $db->prepare(
            "INSERT INTO grievance_complaints
             (complaint_id, merchant_id, customer_name, customer_email, customer_phone, transaction_id, category, subject, description, priority, sla_deadline, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?, 'open')"
        )->execute([
            $complaintId,
            $data['merchant_id'] ?? null,
            $data['customer_name'] ?? null,
            $data['customer_email'] ?? null,
            $data['customer_phone'] ?? null,
            $data['transaction_id'] ?? null,
            $data['category'] ?? 'other',
            $data['subject'] ?? '',
            $data['description'] ?? '',
            $data['priority'] ?? 'medium',
            $slaDeadline,
        ]);

        $id = (int)$db->lastInsertId();
        logGrievanceAction($id, 'created', null, 'system', $data['description'] ?? '');

        return ['id' => $id, 'complaint_id' => $complaintId, 'sla_deadline' => $slaDeadline];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Log a grievance action.
 */
function logGrievanceAction(int $complaintId, string $actionType, ?int $actionBy, string $actionByType, string $message = '', ?string $oldStatus = null, ?string $newStatus = null): void
{
    ensureGrievanceTables();
    try {
        getDB()->prepare(
            "INSERT INTO grievance_actions (complaint_id, action_type, action_by, action_by_type, message, old_status, new_status)
             VALUES (?,?,?,?,?,?,?)"
        )->execute([$complaintId, $actionType, $actionBy, $actionByType, $message, $oldStatus, $newStatus]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Acknowledge a complaint.
 */
function acknowledgeComplaint(int $complaintId, ?int $staffId): bool
{
    ensureGrievanceTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT status FROM grievance_complaints WHERE id=?");
        $st->execute([$complaintId]);
        $old = $st->fetchColumn();
        $db->prepare("UPDATE grievance_complaints SET status='acknowledged', acknowledged_at=NOW(), assigned_to=? WHERE id=?")
            ->execute([$staffId, $complaintId]);
        logGrievanceAction($complaintId, 'acknowledged', $staffId, 'staff', 'Complaint acknowledged', $old, 'acknowledged');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Escalate a complaint to next level.
 */
function escalateComplaint(int $complaintId, ?int $staffId, string $note = ''): bool
{
    ensureGrievanceTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT escalation_level, status FROM grievance_complaints WHERE id=?");
        $st->execute([$complaintId]);
        $row = $st->fetch();
        if (!$row) return false;

        $newLevel = min(2, (int)$row['escalation_level'] + 1);
        $newStatus = $newLevel === 1 ? 'escalated_l1' : 'escalated_l2';
        $newSla = grievanceSlaDeadline($newLevel);

        $db->prepare("UPDATE grievance_complaints SET escalation_level=?, status=?, sla_deadline=? WHERE id=?")
            ->execute([$newLevel, $newStatus, $newSla, $complaintId]);
        logGrievanceAction($complaintId, 'escalated', $staffId, 'staff', $note ?: "Escalated to L{$newLevel}", $row['status'], $newStatus);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolve a complaint.
 */
function resolveComplaint(int $complaintId, ?int $staffId, string $note, string $resolutionCategory = 'resolved'): bool
{
    ensureGrievanceTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT status FROM grievance_complaints WHERE id=?");
        $st->execute([$complaintId]);
        $old = $st->fetchColumn();
        $db->prepare("UPDATE grievance_complaints SET status='resolved', resolved_at=NOW(), resolution_note=?, resolution_category=? WHERE id=?")
            ->execute([$note, $resolutionCategory, $complaintId]);
        logGrievanceAction($complaintId, 'resolved', $staffId, 'staff', $note, $old, 'resolved');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Reject a complaint.
 */
function rejectComplaint(int $complaintId, ?int $staffId, string $note): bool
{
    ensureGrievanceTables();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT status FROM grievance_complaints WHERE id=?");
        $st->execute([$complaintId]);
        $old = $st->fetchColumn();
        $db->prepare("UPDATE grievance_complaints SET status='rejected', resolved_at=NOW(), resolution_note=?, resolution_category='invalid' WHERE id=?")
            ->execute([$note, $complaintId]);
        logGrievanceAction($complaintId, 'rejected', $staffId, 'staff', $note, $old, 'rejected');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Add a note/reply to a complaint.
 */
function addComplaintNote(int $complaintId, ?int $userId, string $userType, string $message): bool
{
    ensureGrievanceTables();
    try {
        logGrievanceAction($complaintId, 'replied', $userId, $userType, $message);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get complaints with filters.
 */
function getGrievanceComplaints(int $limit = 50, string $statusFilter = '', ?int $merchantId = null): array
{
    ensureGrievanceTables();
    $sql = "SELECT gc.*, m.business_name, m.merchant_code
            FROM grievance_complaints gc
            LEFT JOIN merchants m ON m.id=gc.merchant_id
            WHERE 1=1";
    $params = [];
    if ($statusFilter !== '') {
        $sql .= " AND gc.status=?";
        $params[] = $statusFilter;
    }
    if ($merchantId !== null) {
        $sql .= " AND gc.merchant_id=?";
        $params[] = $merchantId;
    }
    $sql .= " ORDER BY gc.created_at DESC LIMIT ?";
    $params[] = $limit;
    $st = getDB()->prepare($sql);
    foreach ($params as $i => $v) {
        $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    return $st->fetchAll();
}

/**
 * Get complaint actions/history.
 */
function getComplaintActions(int $complaintId): array
{
    ensureGrievanceTables();
    $st = getDB()->prepare("SELECT * FROM grievance_actions WHERE complaint_id=? ORDER BY created_at ASC");
    $st->execute([$complaintId]);
    return $st->fetchAll();
}

/**
 * Get a single complaint with details.
 */
function getGrievanceComplaint(int $complaintId): ?array
{
    ensureGrievanceTables();
    $st = getDB()->prepare(
        "SELECT gc.*, m.business_name, m.merchant_code
         FROM grievance_complaints gc
         LEFT JOIN merchants m ON m.id=gc.merchant_id
         WHERE gc.id=?"
    );
    $st->execute([$complaintId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Auto-escalate complaints past SLA deadline.
 * Called by cron / auto_audit.
 */
function autoEscalateSlaBreached(): int
{
    ensureGrievanceTables();
    $db = getDB();
    $st = $db->prepare(
        "SELECT id, escalation_level FROM grievance_complaints
         WHERE status IN ('open','acknowledged','in_progress','escalated_l1')
         AND sla_deadline < NOW()"
    );
    $st->execute();
    $escalated = 0;
    foreach ($st->fetchAll() as $row) {
        if ((int)$row['escalation_level'] < 2) {
            escalateComplaint((int)$row['id'], null, 'Auto-escalated: SLA deadline breached');
            $escalated++;
        }
    }
    return $escalated;
}

/**
 * Generate monthly grievance report.
 */
function generateGrievanceMonthlyReport(string $month = ''): array
{
    ensureGrievanceTables();
    if ($month === '') $month = date('Y-m');
    $startDate = $month . '-01';
    $endDate = date('Y-m-t', strtotime($startDate));

    $db = getDB();
    $report = [
        'month' => $month,
        'total' => 0,
        'resolved' => 0,
        'rejected' => 0,
        'pending' => 0,
        'escalated_l1' => 0,
        'escalated_l2' => 0,
        'avg_resolution_hours' => 0,
        'sla_breached' => 0,
        'by_category' => [],
    ];

    try {
        $report['total'] = (int)$db->prepare("SELECT COUNT(*) FROM grievance_complaints WHERE created_at >= ? AND created_at < ? + INTERVAL 1 DAY")->execute([$startDate, $endDate]) ? (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn() : 0;
    } catch (Throwable $e) {}

    try {
        $report['resolved'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status='resolved' AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['rejected'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status='rejected' AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['pending'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status IN ('open','acknowledged','in_progress') AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['escalated_l1'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status='escalated_l1' AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['escalated_l2'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status='escalated_l2' AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['sla_breached'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE sla_deadline < NOW() AND status NOT IN ('resolved','rejected','closed') AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();

        // Avg resolution time
        $avg = $db->query("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) FROM grievance_complaints WHERE status='resolved' AND resolved_at IS NOT NULL AND created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59'")->fetchColumn();
        $report['avg_resolution_hours'] = $avg ? round((float)$avg, 1) : 0;

        // By category
        $catSt = $db->query("SELECT category, COUNT(*) as c FROM grievance_complaints WHERE created_at >= '{$startDate}' AND created_at <= '{$endDate} 23:59:59' GROUP BY category");
        foreach ($catSt->fetchAll() as $row) {
            $report['by_category'][$row['category']] = (int)$row['c'];
        }
    } catch (Throwable $e) {}

    return $report;
}

/**
 * Get grievance stats for dashboard.
 */
function getGrievanceStats(): array
{
    ensureGrievanceTables();
    $db = getDB();
    $stats = [
        'total' => 0,
        'open' => 0,
        'escalated' => 0,
        'resolved' => 0,
        'sla_breached' => 0,
    ];
    try {
        $stats['total'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints")->fetchColumn();
        $stats['open'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status IN ('open','acknowledged','in_progress')")->fetchColumn();
        $stats['escalated'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status IN ('escalated_l1','escalated_l2')")->fetchColumn();
        $stats['resolved'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE status='resolved'")->fetchColumn();
        $stats['sla_breached'] = (int)$db->query("SELECT COUNT(*) FROM grievance_complaints WHERE sla_deadline < NOW() AND status NOT IN ('resolved','rejected','closed')")->fetchColumn();
    } catch (Throwable $e) {}
    return $stats;
}
