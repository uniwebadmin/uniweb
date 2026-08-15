<?php
declare(strict_types=1);

/**
 * D8: Transparently rehash a verified password to Argon2id if needed.
 * Call ONLY after password_verify() returns true.
 * Returns true if rehash was performed, false if already Argon2id.
 */
function maybeRehashToArgon2id(string $plainPassword, string $currentHash): ?string
{
    if (!defined('PASSWORD_ARGON2ID')) {
        return null;
    }
    $needs = password_needs_rehash($currentHash, PASSWORD_ARGON2ID);
    $isLegacy = !str_starts_with($currentHash, '$argon2id$');
    if (!$needs && !$isLegacy) {
        return null;
    }
    return password_hash($plainPassword, PASSWORD_ARGON2ID);
}

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
    if ($adminId > 0 && function_exists('logStaffActivity')) {
        logStaffActivity($action, (string)($reason ?? ''), $merchantId, $resourceType, $resourceId);
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

function updateIncidentStatus(string $ref, string $status): bool
{
    if (!in_array($status, ['open', 'mitigating', 'resolved'], true)) {
        return false;
    }
    $db = getDB();
    if ($status === 'resolved') {
        $stmt = $db->prepare(
            "UPDATE incident_log SET status = 'resolved', resolved_at = NOW(), resolved_by = ? WHERE incident_ref = ? AND status != 'resolved'"
        );
        $stmt->execute([(int)($_SESSION['admin_id'] ?? 0) ?: null, $ref]);
    } else {
        $stmt = $db->prepare('UPDATE incident_log SET status = ? WHERE incident_ref = ?');
        $stmt->execute([$status, $ref]);
    }
    if ($stmt->rowCount() > 0) {
        recordImmutableAudit('incident_' . $status, null, 'incident', $ref);
        return true;
    }
    return false;
}

function listIncidents(int $limit = 50, bool $publicOnly = false): array
{
    try {
        $rows = getDB()->query(
            'SELECT incident_ref, severity, title, details, status, opened_at, resolved_at FROM incident_log ORDER BY opened_at DESC LIMIT ' . max(1, min(200, $limit))
        )->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    if (!$publicOnly) {
        return $rows;
    }
    // Public view: drop internal free-text details, keep only the status timeline.
    return array_map(static fn($r) => [
        'incident_ref' => $r['incident_ref'],
        'severity' => $r['severity'],
        'title' => $r['title'],
        'status' => $r['status'],
        'opened_at' => $r['opened_at'],
        'resolved_at' => $r['resolved_at'],
    ], $rows);
}

/**
 * Real, honest uptime % over a trailing window: 100% minus the share of time
 * spent inside logged incident windows (open->resolved, or open->now if still open).
 * If no incidents exist yet, returns 100% with a note that tracking just started.
 */
function computeUptimeStats(int $days = 90): array
{
    $windowStart = new DateTimeImmutable("-{$days} days");
    $now = new DateTimeImmutable();
    $windowSeconds = max(1, $now->getTimestamp() - $windowStart->getTimestamp());

    try {
        $rows = getDB()->prepare(
            'SELECT opened_at, resolved_at FROM incident_log WHERE opened_at >= ? OR resolved_at >= ? OR resolved_at IS NULL'
        );
        $rows->execute([$windowStart->format('Y-m-d H:i:s'), $windowStart->format('Y-m-d H:i:s')]);
        $incidents = $rows->fetchAll();
    } catch (Throwable $e) {
        $incidents = [];
    }

    $downtimeSeconds = 0;
    foreach ($incidents as $inc) {
        $opened = new DateTimeImmutable($inc['opened_at']);
        $resolved = $inc['resolved_at'] ? new DateTimeImmutable($inc['resolved_at']) : $now;
        $start = max($opened, $windowStart);
        $end = min($resolved, $now);
        if ($end > $start) {
            $downtimeSeconds += $end->getTimestamp() - $start->getTimestamp();
        }
    }

    $uptimePct = round((1 - ($downtimeSeconds / $windowSeconds)) * 100, 2);
    return [
        'days' => $days,
        'uptime_pct' => max(0, min(100, $uptimePct)),
        'downtime_minutes' => (int)round($downtimeSeconds / 60),
        'incident_count' => count($incidents),
        'tracking_since' => $windowStart->format('Y-m-d'),
    ];
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
        getDB()->exec("ALTER TABLE admins ADD COLUMN totp_secret VARCHAR(256) DEFAULT NULL");
    } catch (Throwable $e) {
    }
    try {
        getDB()->exec("ALTER TABLE admins MODIFY totp_secret VARCHAR(256) DEFAULT NULL");
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

/**
 * D6: Block access to out-of-scope product pages (NBFC, customer PPI wallet).
 * Call at top of page after session bootstrap.
 * Super-admin can still view for audit/ops purposes.
 */
function abortFeatureDisabled(string $feature): void
{
    // Super-admin can view for audit — but product is not usable
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return;
    }
    http_response_code(403);
    if (function_exists('flash')) {
        flash('error', ucfirst($feature) . ' feature is not available on this platform.');
    }
    // Redirect to safe dashboard
    if (function_exists('redirect')) {
        if (function_exists('isAdminLoggedIn') && function_exists('isMerchantLoggedIn')) {
            if (isAdminLoggedIn()) {
                redirect('admin_dashboard.php');
            }
            redirect('dashboard.php');
        }
        redirect('index.php');
    }
    exit('403 — Feature not available');
}
