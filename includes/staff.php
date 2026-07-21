<?php
declare(strict_types=1);

function staffRoleDefinitions(): array
{
    return [
        'super' => ['label' => 'Super Admin / CEO', 'level' => 100, 'manage_staff' => true, 'assign_merchants' => true, 'all_merchants' => true],
        'ceo' => ['label' => 'CEO', 'level' => 100, 'manage_staff' => true, 'assign_merchants' => true, 'all_merchants' => true],
        'regional_manager' => ['label' => 'Regional Manager', 'level' => 80, 'manage_staff' => true, 'assign_merchants' => true, 'all_merchants' => true],
        'area_sales_manager' => ['label' => 'Area Sales Manager', 'level' => 60, 'manage_staff' => true, 'assign_merchants' => true, 'all_merchants' => false],
        'team_leader' => ['label' => 'Team Leader', 'level' => 50, 'manage_staff' => false, 'assign_merchants' => true, 'all_merchants' => false],
        'staff_manager' => ['label' => 'Staff Manager', 'level' => 45, 'manage_staff' => false, 'assign_merchants' => true, 'all_merchants' => false],
        'field_staff' => ['label' => 'Field Staff', 'level' => 20, 'manage_staff' => false, 'assign_merchants' => false, 'all_merchants' => false],
        'ops' => ['label' => 'Operations', 'level' => 30, 'manage_staff' => false, 'assign_merchants' => false, 'all_merchants' => false],
        'support' => ['label' => 'Support', 'level' => 25, 'manage_staff' => false, 'assign_merchants' => false, 'all_merchants' => false],
        'kyc' => ['label' => 'KYC Team', 'level' => 25, 'manage_staff' => false, 'assign_merchants' => false, 'all_merchants' => false],
        'finance' => ['label' => 'Finance', 'level' => 30, 'manage_staff' => false, 'assign_merchants' => false, 'all_merchants' => false],
    ];
}

function ensureStaffRoles(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    foreach ([
        "ALTER TABLE admins ADD COLUMN role VARCHAR(32) NOT NULL DEFAULT 'super'",
        "ALTER TABLE admins ADD COLUMN email VARCHAR(150) NULL",
        "ALTER TABLE admins ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE admins ADD COLUMN reports_to INT NULL",
        "ALTER TABLE admins ADD COLUMN phone VARCHAR(20) NULL",
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) { /* ok */ }
    }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS staff_merchant_assignments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            merchant_id INT NOT NULL,
            assigned_by INT NULL,
            note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_staff_merchant (admin_id, merchant_id),
            INDEX (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->exec("CREATE TABLE IF NOT EXISTS staff_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            action VARCHAR(64) NOT NULL,
            details TEXT NULL,
            merchant_id INT NULL,
            reference_type VARCHAR(32) NULL,
            reference_id VARCHAR(64) NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (admin_id),
            INDEX (merchant_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function adminRole(?array $admin = null): string
{
    ensureStaffRoles();
    $admin = $admin ?? getAdmin();
    if (!$admin) {
        return '';
    }
    return strtolower((string)($admin['role'] ?? 'super'));
}

function staffRoleLevel(string $role): int
{
    return staffRoleDefinitions()[$role]['level'] ?? 0;
}

function isSuperAdmin(): bool
{
    $role = adminRole();
    return in_array($role, ['super', 'ceo'], true);
}

function isStaffUser(): bool
{
    return isAdminLoggedIn() && !isSuperAdmin();
}

function staffRoleLabel(string $role): string
{
    return staffRoleDefinitions()[$role]['label'] ?? ucfirst(str_replace('_', ' ', $role));
}

function staffCanManageStaff(): bool
{
    if (!isAdminLoggedIn()) {
        return false;
    }
    $role = adminRole();
    return !empty(staffRoleDefinitions()[$role]['manage_staff']);
}

function staffCanAssignMerchants(): bool
{
    $role = adminRole();
    return !empty(staffRoleDefinitions()[$role]['assign_merchants']) || isSuperAdmin();
}

function logStaffActivity(string $action, string $details = '', ?int $merchantId = null, ?string $refType = null, ?string $refId = null): void
{
    ensureStaffRoles();
    if (!isAdminLoggedIn()) {
        return;
    }
    try {
        getDB()->prepare('INSERT INTO staff_activity_logs (admin_id, action, details, merchant_id, reference_type, reference_id, ip_address) VALUES (?,?,?,?,?,?,?)')
            ->execute([
                (int)$_SESSION['admin_id'],
                $action,
                $details,
                $merchantId,
                $refType,
                $refId,
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
    } catch (Throwable $e) { /* ok */ }
}

function assignMerchantToStaff(int $staffId, int $merchantId, ?string $note = null): bool
{
    ensureStaffRoles();
    if (!staffCanAssignMerchants()) {
        return false;
    }
    try {
        getDB()->prepare('INSERT INTO staff_merchant_assignments (admin_id, merchant_id, assigned_by, note) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by), note=VALUES(note)')
            ->execute([$staffId, $merchantId, (int)($_SESSION['admin_id'] ?? 0), $note]);
        logStaffActivity('merchant_assigned', 'Staff #' . $staffId . ' assigned to merchant #' . $merchantId, $merchantId);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getMerchantAssignedStaff(int $merchantId): array
{
    ensureStaffRoles();
    $st = getDB()->prepare('SELECT a.id, a.username, a.name, a.role, sma.note, sma.created_at FROM staff_merchant_assignments sma JOIN admins a ON a.id=sma.admin_id WHERE sma.merchant_id=? ORDER BY a.name');
    $st->execute([$merchantId]);
    return $st->fetchAll();
}

function getStaffAssignedMerchants(int $adminId): array
{
    ensureStaffRoles();
    $st = getDB()->prepare('SELECT m.id, m.merchant_code, m.business_name, m.kyc_status, m.status FROM staff_merchant_assignments sma JOIN merchants m ON m.id=sma.merchant_id WHERE sma.admin_id=? AND m.status != ? ORDER BY m.business_name');
    $st->execute([$adminId, 'deleted']);
    return $st->fetchAll();
}

function staffHasMerchantAccess(int $merchantId, ?array $admin = null): bool
{
    ensureStaffRoles();
    $admin = $admin ?? getAdmin();
    if (!$admin) {
        return false;
    }
    $role = adminRole($admin);
    $defs = staffRoleDefinitions()[$role] ?? null;
    if (!$defs) {
        return false;
    }
    if (!empty($defs['all_merchants']) || isSuperAdmin()) {
        return true;
    }
    $adminId = (int)$admin['id'];
    $db = getDB();
    $st = $db->prepare('SELECT id FROM staff_merchant_assignments WHERE admin_id=? AND merchant_id=?');
    $st->execute([$adminId, $merchantId]);
    if ($st->fetch()) {
        return true;
    }
    if (in_array($role, ['area_sales_manager', 'team_leader', 'staff_manager', 'regional_manager'], true)) {
        $sub = $db->prepare('SELECT sma.id FROM staff_merchant_assignments sma JOIN admins a ON a.id=sma.admin_id WHERE sma.merchant_id=? AND a.reports_to=?');
        $sub->execute([$merchantId, $adminId]);
        if ($sub->fetch()) {
            return true;
        }
    }
    return false;
}

function requireMerchantAccess(int $merchantId): void
{
    if (!staffHasMerchantAccess($merchantId)) {
        flash('error', 'This merchant is not assigned to you. Ask your manager to assign access.');
        redirect(isSuperAdmin() ? 'manage_merchant.php' : 'staff_dashboard.php');
    }
}

function staffNavForRole(string $role): array
{
    $all = [
        'staff_dashboard.php' => ['Dashboard', ['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'support', 'kyc', 'finance']],
        'manage_merchant.php' => ['Merchants', ['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']],
        'admin_kyc.php' => ['KYC Review', ['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager', 'field_staff', 'ops', 'kyc']],
        'admin_refunds.php' => ['Refunds', ['super', 'ceo', 'regional_manager', 'finance', 'ops']],
        'admin_disputes.php' => ['Disputes', ['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']],
        'admin_support.php' => ['Support Tickets', ['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']],
        'admin_transactions.php' => ['Transactions', ['super', 'ceo', 'regional_manager', 'finance', 'ops']],
        'admin_settlements.php' => ['Settlements', ['super', 'ceo', 'regional_manager', 'finance', 'ops']],
        'admin_settlement_batches.php' => ['Settlement Batches', ['super', 'ceo', 'finance', 'ops']],
        'admin_settlement_settings.php' => ['Settlement Settings', ['super', 'ceo', 'finance']],
        'admin_gateway_submit.php' => ['Gateway Submit', ['super', 'ceo', 'ops']],
        'admin_pg_webhooks.php' => ['PG Webhooks', ['super', 'ceo', 'ops']],
        'admin_reconciliation.php' => ['PG Reconciliation', ['super', 'ceo', 'finance', 'ops']],
    ];
    if (staffCanManageStaff()) {
        $all['admin_manage_staff.php'] = ['Staff Control', ['super', 'ceo', 'regional_manager']];
        $all['admin_staff_activity.php'] = ['Staff Activity Log', ['super', 'ceo', 'regional_manager', 'area_sales_manager', 'team_leader', 'staff_manager']];
    }
    $nav = [];
    foreach ($all as $url => [$label, $roles]) {
        if (in_array($role, $roles, true)) {
            $nav[] = [$url, $label];
        }
    }
    return $nav;
}

function requireStaffAccess(array $allowedRoles): void
{
    if (!isAdminLoggedIn()) {
        redirect(in_array('super', $allowedRoles, true) || in_array('ceo', $allowedRoles, true)
            ? 'admin_login.php'
            : 'staff_login.php');
    }
    ensureStaffRoles();
    $role = adminRole();
    if (!in_array($role, $allowedRoles, true)) {
        flash('error', 'You do not have permission to access this page.');
        redirect(isSuperAdmin() ? 'admin_dashboard.php' : 'staff_dashboard.php');
    }
}

function requireSuperAdmin(): void
{
    requireStaffAccess(['super', 'ceo']);
}

function staffCanAccess(string $page): bool
{
    $role = adminRole();
    foreach (staffNavForRole($role) as [$url]) {
        if ($url === $page) {
            return true;
        }
    }
    return false;
}

function staffCanEditMerchant(): bool
{
    return in_array(adminRole(), ['super', 'ceo', 'regional_manager', 'ops', 'kyc'], true);
}

/** Roles allowed to request/approve KYC and Live activation (not field sales). */
function staffCanMutateKyc(): bool
{
    return in_array(adminRole(), ['super', 'ceo', 'regional_manager', 'ops', 'kyc', 'staff_manager'], true);
}

/** Independent checker for Live / document approvals. */
function staffCanCheckerApproveKyc(): bool
{
    return in_array(adminRole(), ['super', 'ceo', 'regional_manager', 'ops', 'kyc'], true);
}

function requireStaffKycMutation(): void
{
    if (!staffCanMutateKyc()) {
        flash('error', 'Your role can view KYC but cannot approve or reject.');
        redirect('admin_kyc.php');
    }
}

function getStaffActivityLogs(?int $adminId = null, int $limit = 50): array
{
    ensureStaffRoles();
    $sql = 'SELECT l.*, a.name AS staff_name, a.username, m.business_name FROM staff_activity_logs l JOIN admins a ON a.id=l.admin_id LEFT JOIN merchants m ON m.id=l.merchant_id';
    $params = [];
    if ($adminId) {
        $sql .= ' WHERE l.admin_id = ?';
        $params[] = $adminId;
    }
    $sql .= ' ORDER BY l.created_at DESC LIMIT ' . max(1, min(200, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function pgWebhookHealthResponse(string $gateway): void
{
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'gateway' => $gateway,
        'app' => APP_NAME,
        'message' => 'Webhook endpoint is live.',
        'url' => pgWebhookUrl($gateway),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Entity KYC map + merchant team RBAC (git-tracked; live hosts need no config.php function edits).
require_once __DIR__ . '/kyc_entity.php';
require_once __DIR__ . '/merchant_team.php';
