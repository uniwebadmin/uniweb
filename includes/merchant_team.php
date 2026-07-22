<?php
declare(strict_types=1);

function ensureMerchantTeamSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!function_exists('schemaExecQuiet')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS merchant_team_members (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        email VARCHAR(190) NOT NULL,
        name VARCHAR(120) NOT NULL,
        role VARCHAR(32) NOT NULL DEFAULT 'viewer',
        status VARCHAR(20) NOT NULL DEFAULT 'invited',
        invite_token CHAR(64) DEFAULT NULL,
        password_hash VARCHAR(255) DEFAULT NULL,
        invited_by INT DEFAULT NULL,
        accepted_at DATETIME DEFAULT NULL,
        last_login_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_merchant_team_email (merchant_id, email),
        UNIQUE KEY uniq_team_invite_token (invite_token),
        INDEX idx_team_merchant (merchant_id),
        INDEX idx_team_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function merchantTeamRoles(): array
{
    return [
        'admin' => ['label' => 'Admin', 'hint' => 'Manage team, settings, payments (not ownership transfer)'],
        'finance' => ['label' => 'Finance', 'hint' => 'Settlements, refunds, reports, wallet'],
        'developer' => ['label' => 'Developer', 'hint' => 'API keys, webhooks, payment links'],
        'viewer' => ['label' => 'Viewer', 'hint' => 'Read-only dashboard and transactions'],
    ];
}

function merchantTeamRoleLabel(string $role): string
{
    return merchantTeamRoles()[$role]['label'] ?? ucfirst($role);
}

function currentMerchantTeamMember(): ?array
{
    $id = (int)($_SESSION['merchant_team_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    ensureMerchantTeamSchema();
    $st = getDB()->prepare("SELECT * FROM merchant_team_members WHERE id=? AND status='active'");
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function currentMerchantTeamRole(): string
{
    $member = currentMerchantTeamMember();
    if ($member) {
        return (string)$member['role'];
    }
    return 'owner';
}

function merchantTeamCan(string $capability): bool
{
    $role = currentMerchantTeamRole();
    if ($role === 'owner') {
        return true;
    }
    $map = [
        'manage_team' => ['admin'],
        'settings' => ['admin'],
        'settle' => ['admin', 'finance'],
        'refund' => ['admin', 'finance'],
        'support' => ['admin', 'finance'], // customer complaints reply
        'api' => ['admin', 'developer'],
        'create_links' => ['admin', 'developer', 'finance'],
        'view' => ['admin', 'finance', 'developer', 'viewer'],
    ];
    return in_array($role, $map[$capability] ?? [], true);
}

function requireMerchantTeamCapability(string $capability): void
{
    if (!merchantTeamCan($capability)) {
        flash('error', 'Your team role cannot perform this action. Ask the account owner.');
        redirect('dashboard.php');
    }
}

function listMerchantTeamMembers(int $merchantId): array
{
    ensureMerchantTeamSchema();
    $st = getDB()->prepare('SELECT * FROM merchant_team_members WHERE merchant_id=? ORDER BY FIELD(status,"active","invited","revoked"), created_at DESC');
    $st->execute([$merchantId]);
    return $st->fetchAll();
}

function inviteMerchantTeamMember(int $merchantId, string $email, string $name, string $role, int $invitedBy): array
{
    ensureMerchantTeamSchema();
    $email = strtolower(trim($email));
    $name = mb_substr(trim($name), 0, 120);
    $roles = merchantTeamRoles();
    if (!isset($roles[$role])) {
        return ['ok' => false, 'error' => 'Invalid role.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    if ($name === '') {
        return ['ok' => false, 'error' => 'Name is required.'];
    }
    $owner = getDB()->prepare('SELECT email FROM merchants WHERE id=?');
    $owner->execute([$merchantId]);
    $ownerEmail = strtolower((string)$owner->fetchColumn());
    if ($email === $ownerEmail) {
        return ['ok' => false, 'error' => 'That email is already the account owner.'];
    }
    $dup = getDB()->prepare('SELECT id, status FROM merchant_team_members WHERE merchant_id=? AND email=?');
    $dup->execute([$merchantId, $email]);
    $existing = $dup->fetch();
    if ($existing && $existing['status'] !== 'revoked') {
        return ['ok' => false, 'error' => 'This email is already on the team.'];
    }
    $token = bin2hex(random_bytes(24));
    if ($existing) {
        getDB()->prepare("UPDATE merchant_team_members SET name=?, role=?, status='invited', invite_token=?, password_hash=NULL, invited_by=?, accepted_at=NULL WHERE id=?")
            ->execute([$name, $role, $token, $invitedBy, (int)$existing['id']]);
        $id = (int)$existing['id'];
    } else {
        getDB()->prepare("INSERT INTO merchant_team_members (merchant_id, email, name, role, status, invite_token, invited_by) VALUES (?,?,?,?,?,?,?)")
            ->execute([$merchantId, $email, $name, $role, 'invited', $token, $invitedBy]);
        $id = (int)getDB()->lastInsertId();
    }
    $inviteUrl = APP_URL . '/merchant_team_accept.php?token=' . urlencode($token);
    require_once __DIR__ . '/mailer.php';
    $body = "You have been invited to the UniWeb merchant team as " . merchantTeamRoleLabel($role) . ".\n\n"
        . "Accept invite and set your password:\n{$inviteUrl}\n\n"
        . "If you did not expect this, ignore this email.";
    sendPlatformEmail($email, APP_NAME . ' — Team invite', $body);
    return ['ok' => true, 'id' => $id, 'invite_url' => $inviteUrl];
}

function findMerchantTeamInvite(string $token): ?array
{
    ensureMerchantTeamSchema();
    $token = trim($token);
    if ($token === '' || strlen($token) < 32) {
        return null;
    }
    $st = getDB()->prepare("SELECT t.*, m.business_name, m.merchant_code FROM merchant_team_members t JOIN merchants m ON m.id=t.merchant_id WHERE t.invite_token=? AND t.status='invited'");
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

function acceptMerchantTeamInvite(string $token, string $password): array
{
    $invite = findMerchantTeamInvite($token);
    if (!$invite) {
        return ['ok' => false, 'error' => 'Invite is invalid or expired.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }
    getDB()->prepare("UPDATE merchant_team_members SET password_hash=?, status='active', invite_token=NULL, accepted_at=NOW() WHERE id=?")
        ->execute([password_hash($password, PASSWORD_BCRYPT), (int)$invite['id']]);
    return ['ok' => true, 'member' => $invite];
}

function authenticateMerchantTeamLogin(string $email, string $password): ?array
{
    ensureMerchantTeamSchema();
    $email = strtolower(trim($email));
    $st = getDB()->prepare("SELECT * FROM merchant_team_members WHERE email=? AND status='active' LIMIT 1");
    $st->execute([$email]);
    $member = $st->fetch();
    if (!$member || empty($member['password_hash']) || !password_verify($password, (string)$member['password_hash'])) {
        return null;
    }
    getDB()->prepare('UPDATE merchant_team_members SET last_login_at=NOW() WHERE id=?')->execute([(int)$member['id']]);
    return $member;
}
