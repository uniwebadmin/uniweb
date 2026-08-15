<?php
declare(strict_types=1);

/** Cron / watchdog URL security + 24/7 health checks */

function defaultCronWatchdogKey(): string
{
    return 'uniweb_watch_' . substr(hash('sha256', DB_NAME . APP_URL), 0, 16);
}

function ensureCronWatchdogKeyPersisted(): string
{
    $stored = trim(getSetting('platform_watchdog_key', ''));
    if ($stored !== '') {
        return $stored;
    }
    $key = defaultCronWatchdogKey();
    saveAutoAuditMeta('platform_watchdog_key', $key);
    return $key;
}

function rotateCronWatchdogKey(): string
{
    $key = 'uniweb_watch_' . bin2hex(random_bytes(12));
    saveAutoAuditMeta('platform_watchdog_key', $key);
    return $key;
}

function cronRequestKey(): string
{
    return trim($_GET['key'] ?? $_POST['key'] ?? $_SERVER['HTTP_X_WATCHDOG_KEY'] ?? '');
}

function logCronAuthFailure(string $reason): void
{
    if (!function_exists('logPlatformError')) {
        return;
    }
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (str_contains($ua, 'UniWeb-Watchdog')) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    logPlatformError('warning', 'Cron auth failed (' . $reason . ') from ' . $ip, [
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
    ]);
}

/** @return array{ok:bool,error?:string} */
function validateCronRequest(): array
{
    ensureCronWatchdogKeyPersisted();
    $key = cronRequestKey();
    $expected = autoAuditWatchdogKey();

    if ($key === '') {
        logCronAuthFailure('missing key');
        return ['ok' => false, 'error' => 'Invalid key'];
    }
    if (!hash_equals($expected, $key)) {
        logCronAuthFailure('wrong key');
        return ['ok' => false, 'error' => 'Invalid key'];
    }
    return ['ok' => true];
}

/**
 * Accept the main watchdog key, or an optional dedicated cron key.
 * One Hostinger job can therefore use a single UniWeb-made key.
 */
function cronAuthOk(?string $dedicatedSettingKey = null): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
        return true;
    }
    $provided = cronRequestKey();
    if ($provided === '') {
        return false;
    }
    $watch = autoAuditWatchdogKey();
    if (hash_equals($watch, $provided)) {
        return true;
    }
    if ($dedicatedSettingKey) {
        $dedicated = trim((string)getSetting($dedicatedSettingKey, ''));
        if ($dedicated !== '' && hash_equals($dedicated, $provided)) {
            return true;
        }
    }
    return false;
}

function sendCronJsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

function rejectCronRequest(string $error = 'Invalid key'): void
{
    sendCronJsonResponse(['ok' => false, 'error' => $error], 403);
}

/** Is Hostinger (or any client) hitting cron on schedule? */
function getCronHealthStatus(): array
{
    $intervalSec = autoAuditIntervalSeconds();
    $graceSec = max(900, (int)round($intervalSec * 1.5));
    $lastCronAt = null;
    $lastCronType = null;
    $runs24h = 0;

    try {
        ensureAutoAuditEngine();
        $row = getDB()->query("SELECT run_type, created_at FROM platform_audit_runs
            WHERE run_type IN ('cron','cron_watchdog')
            ORDER BY id DESC LIMIT 1")->fetch();
        if ($row) {
            $lastCronAt = (string)$row['created_at'];
            $lastCronType = (string)$row['run_type'];
        }
        $runs24h = (int)getDB()->query("SELECT COUNT(*) FROM platform_audit_runs
            WHERE run_type IN ('cron','cron_watchdog') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Throwable $e) {
        /* ok */
    }

    $lastTs = $lastCronAt ? strtotime($lastCronAt) : 0;
    $settingsTs = (int)getSetting('auto_audit_last_run_ts', '0');
    $effectiveTs = max($lastTs, $settingsTs);
    $ageSec = $effectiveTs > 0 ? max(0, time() - $effectiveTs) : null;
    $expected24h = (int)floor(86400 / max(1, $intervalSec));
    $minRunsForLive = max(12, (int)floor($expected24h * 0.08));
    $live = ($ageSec !== null && $ageSec <= $graceSec)
        || ($runs24h >= $minRunsForLive);

    return [
        'live' => $live,
        'interval_min' => (int)round($intervalSec / 60),
        'grace_min' => (int)round($graceSec / 60),
        'last_cron_at' => $lastCronAt,
        'last_cron_type' => $lastCronType,
        'age_sec' => $ageSec,
        'runs_24h' => $runs24h,
        'expected_runs_24h' => $expected24h,
        'cron_url' => rtrim(APP_URL, '/') . '/cron_auto_audit.php?key=' . rawurlencode(autoAuditWatchdogKey()),
    ];
}

function cronHealthLabel(array $health): string
{
    if (!empty($health['live'])) {
        return '24/7 ON — last hit ' . (int)($health['age_sec'] ?? 0) . 's ago';
    }
    if (!empty($health['last_cron_at'])) {
        return 'Stale — last cron ' . (int)floor(((int)($health['age_sec'] ?? 0)) / 60) . ' min ago. Check Hostinger Cron Jobs.';
    }
    return 'Not started — add Hostinger cron (every ' . (int)($health['interval_min'] ?? 10) . ' min).';
}

/**
 * Mask the key= parameter in a cron URL so the full secret is never shown in HTML.
 * Returns e.g. https://uniweb.co.in/cron_auto_audit.php?key=****abdd
 */
function maskCronUrl(string $url): string
{
    return preg_replace('/(key=)[^&]+/', '$1****' . substr((string)preg_replace('/.*key=/', '', $url), -4), $url);
}

/**
 * Mask a bare secret key string — show first 6 + last 4 only.
 */
function maskSecretKey(string $key): string
{
    if (strlen($key) <= 12) return str_repeat('*', strlen($key));
    return substr($key, 0, 6) . '****' . substr($key, -4);
}

/**
 * D9: Record a cron heartbeat — call at end of each cron job.
 * Stores timestamp in gateway_settings as cron_heartbeat_{job}.
 */
function recordCronHeartbeat(string $job, string $status = 'ok'): void
{
    $key = 'cron_heartbeat_' . $job;
    $value = json_encode(['ts' => date('Y-m-d H:i:s'), 'status' => $status]);
    try {
        getDB()->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$key, $value, $value]);
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * D9: Get heartbeat status for all inventory cron jobs.
 * Returns array of [job, label, schedule, last_run, age_human, status_code, stale_after_hours]
 */
function getCronHeartbeatStatus(): array
{
    $jobs = [
        ['job' => 'auto_audit',          'label' => 'Auto Audit (includes forward queue + webhook retry)', 'schedule' => 'Every 10 min',  'stale_hours' => 1],
        ['job' => 'auto_kyc',             'label' => 'Auto KYC Engine',                                      'schedule' => 'Every 10 min',  'stale_hours' => 1],
        ['job' => 'settlements',          'label' => 'Settlements + Payout Dispatch',                        'schedule' => 'Every 15 min',  'stale_hours' => 1],
        ['job' => 'mandates',             'label' => 'Mandate Debits (recurring)',                           'schedule' => 'Daily 09:00',   'stale_hours' => 36],
        ['job' => 'db_backup',            'label' => 'Database Backup',                                      'schedule' => 'Daily 02:00',   'stale_hours' => 36],
        ['job' => 'reconciliation',       'label' => 'Reconciliation Summary',                               'schedule' => 'Daily 03:00',   'stale_hours' => 36],
        ['job' => 'bank_reconciliation',  'label' => 'Bank Reconciliation',                                  'schedule' => 'Daily 04:00',   'stale_hours' => 36],
    ];

    $result = [];
    foreach ($jobs as $j) {
        $key = 'cron_heartbeat_' . $j['job'];
        $raw = null;
        try {
            $stmt = getDB()->prepare('SELECT setting_value FROM gateway_settings WHERE setting_key=? LIMIT 1');
            $stmt->execute([$key]);
            $raw = $stmt->fetchColumn() ?: null;
        } catch (Throwable $e) { /* ok */ }

        if ($raw) {
            $data = json_decode((string)$raw, true);
            $ts = $data['ts'] ?? null;
            $status = $data['status'] ?? 'ok';
            if ($ts) {
                $ageSec = max(0, time() - strtotime($ts));
                $ageHours = $ageSec / 3600;
                $staleHours = $j['stale_hours'];
                if ($ageHours > $staleHours) {
                    $code = 'STALE';
                } else {
                    $code = 'OK';
                }
                // Human-readable age
                if ($ageSec < 60) {
                    $ageHuman = $ageSec . 's ago';
                } elseif ($ageSec < 3600) {
                    $ageHuman = floor($ageSec / 60) . ' min ago';
                } else {
                    $ageHuman = floor($ageSec / 3600) . 'h ' . floor(($ageSec % 3600) / 60) . 'm ago';
                }
                $result[] = [
                    'job' => $j['job'],
                    'label' => $j['label'],
                    'schedule' => $j['schedule'],
                    'last_run' => $ts,
                    'age_human' => $ageHuman,
                    'status' => $code,
                    'heartbeat_status' => $status,
                ];
            } else {
                $result[] = array_merge($j, ['last_run' => null, 'age_human' => 'Never', 'status' => 'NEVER', 'heartbeat_status' => '']);
            }
        } else {
            $result[] = array_merge($j, ['last_run' => null, 'age_human' => 'Never', 'status' => 'NEVER', 'heartbeat_status' => '']);
        }
    }
    return $result;
}
