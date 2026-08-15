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
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS merchant_team_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        actor_role VARCHAR(32) DEFAULT NULL,
        actor_email VARCHAR(190) DEFAULT NULL,
        action VARCHAR(40) NOT NULL,
        member_email VARCHAR(190) DEFAULT NULL,
        details VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_team_events_merchant (merchant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function merchantTeamRoles(): array
{
    return [
        'admin' => ['label' => 'Admin', 'hint' => 'Manage team, settings, payments (not ownership transfer)'],
        'finance' => ['label' => 'Finance', 'hint' => 'Settlements, refunds, reports, settlement balance'],
        'developer' => ['label' => 'Developer', 'hint' => 'API keys, webhooks, payment links'],
        'support' => ['label' => 'Support', 'hint' => 'Customer complaints and read-only payments'],
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
        'support' => ['admin', 'finance', 'support'], // customer complaints reply
        'api' => ['admin', 'developer'],
        'create_links' => ['admin', 'developer', 'finance'],
        'view' => ['admin', 'finance', 'developer', 'support', 'viewer'],
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
    logMerchantTeamEvent($merchantId, 'invited', $email, 'role=' . $role);
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
        ->execute([password_hash($password, PASSWORD_ARGON2ID), (int)$invite['id']]);
    logMerchantTeamEvent(
        (int)$invite['merchant_id'],
        'accepted',
        (string)$invite['email'],
        'Joined as ' . merchantTeamRoleLabel((string)$invite['role']),
        'invitee',
        (string)$invite['email']
    );
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

function merchantTeamCapabilityMatrix(): array
{
    $caps = ['manage_team', 'settle', 'refund', 'support', 'api', 'create_links', 'view'];
    $roles = ['owner' => 'Owner'] + array_map(static fn($m) => $m['label'], merchantTeamRoles());
    $rows = [];
    foreach ($roles as $role => $label) {
        $row = ['role' => $role, 'label' => $label];
        foreach ($caps as $cap) {
            if ($role === 'owner') {
                $row[$cap] = true;
                continue;
            }
            $map = [
                'manage_team' => ['admin'],
                'settle' => ['admin', 'finance'],
                'refund' => ['admin', 'finance'],
                'support' => ['admin', 'finance', 'support'],
                'api' => ['admin', 'developer'],
                'create_links' => ['admin', 'developer', 'finance'],
                'view' => ['admin', 'finance', 'developer', 'support', 'viewer'],
            ];
            $row[$cap] = in_array($role, $map[$cap] ?? [], true);
        }
        $rows[] = $row;
    }
    return $rows;
}

function logMerchantTeamEvent(
    int $merchantId,
    string $action,
    ?string $memberEmail = null,
    ?string $details = null,
    ?string $actorRole = null,
    ?string $actorEmail = null
): void {
    ensureMerchantTeamSchema();
    if ($actorRole === null) {
        $actorRole = currentMerchantTeamRole();
    }
    if ($actorEmail === null) {
        $member = currentMerchantTeamMember();
        $actorEmail = $member ? (string)$member['email'] : '';
        if ($actorEmail === '' && isset($_SESSION['merchant_id']) && function_exists('getMerchant')) {
            try {
                $actorEmail = (string)(getMerchant()['email'] ?? '');
            } catch (Throwable $e) {
                $actorEmail = '';
            }
        }
    }
    try {
        getDB()->prepare(
            'INSERT INTO merchant_team_events (merchant_id, actor_role, actor_email, action, member_email, details)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $merchantId,
            mb_substr((string)$actorRole, 0, 32),
            mb_substr((string)$actorEmail, 0, 190),
            mb_substr($action, 0, 40),
            $memberEmail !== null ? mb_substr($memberEmail, 0, 190) : null,
            $details !== null ? mb_substr($details, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'Team audit write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('team_' . $action, $merchantId, 'merchant_team', $memberEmail, $details);
    }
}

function listMerchantTeamEvents(int $merchantId, int $limit = 30): array
{
    ensureMerchantTeamSchema();
    $limit = max(1, min(100, $limit));
    $st = getDB()->prepare("SELECT * FROM merchant_team_events WHERE merchant_id=? ORDER BY id DESC LIMIT {$limit}");
    $st->execute([$merchantId]);
    return $st->fetchAll() ?: [];
}

function resendMerchantTeamInvite(int $merchantId, int $memberId): array
{
    ensureMerchantTeamSchema();
    $st = getDB()->prepare("SELECT * FROM merchant_team_members WHERE id=? AND merchant_id=? AND status='invited'");
    $st->execute([$memberId, $merchantId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Invite not found or already accepted.'];
    }
    $token = bin2hex(random_bytes(24));
    getDB()->prepare('UPDATE merchant_team_members SET invite_token=? WHERE id=? AND merchant_id=?')
        ->execute([$token, $memberId, $merchantId]);
    $inviteUrl = APP_URL . '/merchant_team_accept.php?token=' . urlencode($token);
    require_once __DIR__ . '/mailer.php';
    $body = "You have been invited to the UniWeb merchant team as " . merchantTeamRoleLabel((string)$row['role']) . ".\n\n"
        . "Accept invite and set your password:\n{$inviteUrl}\n\n"
        . "If you did not expect this, ignore this email.";
    sendPlatformEmail((string)$row['email'], APP_NAME . ' — Team invite', $body);
    logMerchantTeamEvent($merchantId, 'resent', (string)$row['email'], 'role=' . (string)$row['role']);
    return ['ok' => true, 'invite_url' => $inviteUrl];
}

function revokeMerchantTeamMember(int $merchantId, int $memberId): array
{
    ensureMerchantTeamSchema();
    $st = getDB()->prepare('SELECT email, status FROM merchant_team_members WHERE id=? AND merchant_id=?');
    $st->execute([$memberId, $merchantId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Team member not found.'];
    }
    if ((string)$row['status'] === 'revoked') {
        return ['ok' => true];
    }
    getDB()->prepare("UPDATE merchant_team_members SET status='revoked', invite_token=NULL WHERE id=? AND merchant_id=?")
        ->execute([$memberId, $merchantId]);
    logMerchantTeamEvent($merchantId, 'revoked', (string)$row['email'], null);
    return ['ok' => true];
}

function updateMerchantTeamRole(int $merchantId, int $memberId, string $role): array
{
    ensureMerchantTeamSchema();
    if (!isset(merchantTeamRoles()[$role])) {
        return ['ok' => false, 'error' => 'Invalid role.'];
    }
    $st = getDB()->prepare("SELECT email, role FROM merchant_team_members WHERE id=? AND merchant_id=? AND status IN ('active','invited')");
    $st->execute([$memberId, $merchantId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Team member not found.'];
    }
    getDB()->prepare("UPDATE merchant_team_members SET role=? WHERE id=? AND merchant_id=? AND status IN ('active','invited')")
        ->execute([$role, $memberId, $merchantId]);
    logMerchantTeamEvent($merchantId, 'role_changed', (string)$row['email'], (string)$row['role'] . ' → ' . $role);
    return ['ok' => true];
}
