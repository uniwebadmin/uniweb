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
    return trim($_GET['key'] ?? $_SERVER['HTTP_X_WATCHDOG_KEY'] ?? '');
}

function logCronAuthFailure(string $reason): void
{
    if (!function_exists('logPlatformError')) {
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
