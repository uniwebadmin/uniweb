<?php
declare(strict_types=1);

function validateStrongPassword(string $password, int $minLength = 12): ?string
{
    if (strlen($password) < $minLength) {
        return "Password must be at least {$minLength} characters.";
    }
    if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password must include upper, lower, number and special character.';
    }
    return null;
}

function recordImmutableAudit(
    string $action,
    ?int $merchantId = null,
    ?string $resourceType = null,
    ?string $resourceId = null,
    ?string $reason = null,
    ?string $beforeHash = null,
    ?string $afterHash = null
): string {
    $eventId = 'AUD-' . strtoupper(bin2hex(random_bytes(10)));
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $merchantActor = (int)($_SESSION['merchant_id'] ?? 0);
    $actorType = $adminId > 0 ? 'admin' : ($merchantActor > 0 ? 'merchant' : 'system');
    $actorId = $adminId > 0 ? $adminId : ($merchantActor > 0 ? $merchantActor : null);
    try {
        getDB()->prepare(
            'INSERT INTO immutable_audit_log
             (event_id,actor_type,actor_id,action,merchant_id,resource_type,resource_id,reason,before_hash,after_hash,ip_address,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $eventId,
            $actorType,
            $actorId,
            $action,
            $merchantId,
            $resourceType,
            $resourceId,
            $reason,
            $beforeHash,
            $afterHash,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    } catch (Throwable $e) {
        logPlatformError('warning', 'Immutable audit write failed: ' . $e->getMessage(), ['action' => $action]);
    }
    return $eventId;
}

function openIncident(string $title, string $details, string $severity = 'medium'): string
{
    $ref = 'INC-' . strtoupper(bin2hex(random_bytes(6)));
    $severity = in_array($severity, ['low', 'medium', 'high', 'critical'], true) ? $severity : 'medium';
    getDB()->prepare(
        'INSERT INTO incident_log (incident_ref,severity,title,details,opened_by) VALUES (?,?,?,?,?)'
    )->execute([$ref, $severity, $title, $details, (int)($_SESSION['admin_id'] ?? 0) ?: null]);
    recordImmutableAudit('incident_opened', null, 'incident', $ref, $title);
    return $ref;
}

function adminHasMfaEnabled(?array $admin): bool
{
    return !empty($admin['totp_enabled']) && !empty($admin['totp_secret']);
}

function beginAdminPasswordChallenge(array $admin, string $portal = 'admin'): void
{
    $_SESSION['pending_admin_id'] = (int)$admin['id'];
    $_SESSION['pending_admin_portal'] = $portal;
    $_SESSION['pending_admin_name'] = (string)$admin['name'];
    $_SESSION['pending_admin_auth_version'] = (int)($admin['auth_version'] ?? 1);
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_auth_version'], $_SESSION['admin_authenticated_at']);
}

function completeAdminLoginSession(array $admin): void
{
    unset(
        $_SESSION['pending_admin_id'],
        $_SESSION['pending_admin_portal'],
        $_SESSION['pending_admin_name'],
        $_SESSION['pending_admin_auth_version'],
        $_SESSION['pending_admin_mfa_setup'],
        $_SESSION['merchant_id'],
        $_SESSION['merchant_code']
    );
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_name'] = (string)$admin['name'];
    $_SESSION['admin_auth_version'] = (int)($admin['auth_version'] ?? 1);
    $_SESSION['admin_authenticated_at'] = time();
    $_SESSION['admin_stepup_at'] = time();
    initializePortalSession();
    recordImmutableAudit('admin_login', null, 'admin', (string)$admin['id'], 'Authenticated with MFA');
}

function requireStepUpAuth(int $maxAgeSeconds = 300): void
{
    $at = (int)($_SESSION['admin_stepup_at'] ?? 0);
    if ($at > 0 && (time() - $at) <= $maxAgeSeconds) {
        return;
    }
    flash('error', 'Re-authentication required for this sensitive action.');
    $_SESSION['stepup_return'] = basename((string)($_SERVER['PHP_SELF'] ?? 'admin_dashboard.php'));
    redirect('admin_stepup.php');
}

function markStepUpAuthenticated(): void
{
    $_SESSION['admin_stepup_at'] = time();
}

function ensureAdminMfaColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        getDB()->exec("ALTER TABLE admins ADD COLUMN totp_secret VARCHAR(64) DEFAULT NULL");
    } catch (Throwable $e) {
    }
    try {
        getDB()->exec("ALTER TABLE admins ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) {
    }
    try {
        getDB()->exec("ALTER TABLE admins ADD COLUMN mfa_enforced_at DATETIME DEFAULT NULL");
    } catch (Throwable $e) {
    }
}
