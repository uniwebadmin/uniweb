<?php
declare(strict_types=1);

/** Background auto-audit — runs on cron + admin panel (every N minutes) */

function ensureAutoAuditEngine(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    getDB()->exec("CREATE TABLE IF NOT EXISTS platform_audit_runs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        run_type VARCHAR(24) NOT NULL DEFAULT 'auto',
        ok TINYINT(1) NOT NULL DEFAULT 0,
        failed_checks INT UNSIGNED NOT NULL DEFAULT 0,
        broken_links INT UNSIGNED NOT NULL DEFAULT 0,
        error_count INT UNSIGNED NOT NULL DEFAULT 0,
        merchants_fixed INT UNSIGNED NOT NULL DEFAULT 0,
        summary_json JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created (created_at),
        INDEX idx_ok (ok, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ready = true;
}

function autoAuditIntervalSeconds(): int
{
    $mins = (int)getSetting('auto_audit_interval_minutes', '10');
    return max(5, min(120, $mins)) * 60;
}

function autoAuditWatchdogKey(): string
{
    if (function_exists('ensureCronWatchdogKeyPersisted')) {
        return ensureCronWatchdogKeyPersisted();
    }
    return getSetting('platform_watchdog_key', defaultCronWatchdogKey());
}

function saveAutoAuditMeta(string $key, string $value): void
{
    getDB()->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute([$key, $value, $value]);
    clearSettingCache($key);
}

function getLastAutoAuditTimestamp(): int
{
    $ts = (int)getSetting('auto_audit_last_run_ts', '0');
    return $ts;
}

function shouldRunAutoAudit(): bool
{
    return (time() - getLastAutoAuditTimestamp()) >= autoAuditIntervalSeconds();
}

function isAutoAuditLocked(): bool
{
    $lockUntil = (int)getSetting('auto_audit_lock_until', '0');
    return $lockUntil > time();
}

function lockAutoAudit(int $seconds = 120): void
{
    saveAutoAuditMeta('auto_audit_lock_until', (string)(time() + $seconds));
}

function unlockAutoAudit(): void
{
    saveAutoAuditMeta('auto_audit_lock_until', '0');
}

/** Full background audit + auto-fixes */
function runBackgroundAutoAudit(bool $httpProbe = false, string $runType = 'auto'): array
{
    if (isAutoAuditLocked()) {
        return getLastAutoAuditRun() ?? ['ok' => true, 'skipped' => true, 'detail' => 'Audit already running'];
    }

    lockAutoAudit(180);
    $merchantsFixed = 0;
    $errorsCleared = function_exists('autoResolveAuditNoise') ? autoResolveAuditNoise() : 0;
    $report = [
        'run_type' => $runType,
        'ran_at' => date('Y-m-d H:i:s'),
        'http_probe' => $httpProbe,
        'errors_cleared' => $errorsCleared,
        'steps' => [],
    ];

    try {
        try {
            $merchantsFixed = fixVerifiedMerchantsNotLive();
            $report['steps']['verified_live'] = ['ok' => true, 'fixed' => $merchantsFixed];
        } catch (Throwable $e) {
            $report['steps']['verified_live'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (function_exists('runPlatformWatchdog')) {
            $watch = runPlatformWatchdog();
            $report['steps']['watchdog'] = $watch;
        }

        try {
            if (function_exists('runScheduledSettlementBatches')) {
                $settleResults = runScheduledSettlementBatches();
                if (function_exists('logSettlementCronRun')) {
                    logSettlementCronRun($settleResults);
                }
                $report['steps']['settlement'] = ['ok' => true, 'batches' => count($settleResults)];
            }
        } catch (Throwable $e) {
            $report['steps']['settlement'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        if (function_exists('runMorningPlatformOps')) {
            $morning = runMorningPlatformOps();
            $report['steps']['morning_ops'] = $morning;
            $_SESSION['morning_ops_report'] = $morning;
            $_SESSION['morning_ops_ran'] = date('Y-m-d');
        }

        try {
            // Defense in depth: live config.php is gitignored and may not include 'qr_events'.
            if (!function_exists('runQrHealthAlerts')) {
                require_once __DIR__ . '/qr_events.php';
            }
            $qrAlerts = runQrHealthAlerts();
            $report['steps']['qr_alerts'] = ['ok' => true, 'expiry_notified' => $qrAlerts['expiry'], 'low_scan_notified' => $qrAlerts['low_scan']];
        } catch (Throwable $e) {
            $report['steps']['qr_alerts'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            // Defense in depth: live config.php is gitignored and may not include 'va_manager'.
            if (!function_exists('resetVirtualAccountDailyCountersIfNeeded')) {
                require_once __DIR__ . '/va_manager.php';
            }
            $vaReset = resetVirtualAccountDailyCountersIfNeeded();
            $report['steps']['va_counters'] = ['ok' => true, 'reset' => $vaReset];
        } catch (Throwable $e) {
            $report['steps']['va_counters'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $brokenLinks = 0;
        $linkOk = true;
        if (function_exists('runFullLinkWatchdog')) {
            $linkScan = runFullLinkWatchdog($httpProbe);
            $brokenLinks = count($linkScan['broken_links']);
            $linkOk = (bool)$linkScan['ok'];
            $report['steps']['link_scan'] = [
                'ok' => $linkOk,
                'summary' => $linkScan['summary'],
                'broken_links' => $brokenLinks,
            ];
            $_SESSION['watchdog_quick_scan'] = $linkScan;
            if (!$httpProbe) {
                $_SESSION['watchdog_scan'] = null;
            } else {
                $_SESSION['watchdog_scan'] = $linkScan;
            }
        }

        $errorCount = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;
        $failed = 0;
        if (!empty($report['steps']['watchdog']['failed'])) {
            $failed = (int)$report['steps']['watchdog']['failed'];
        }
        if (!empty($report['steps']['morning_ops']['failed'])) {
            $failed = max($failed, (int)$report['steps']['morning_ops']['failed']);
        }
        if ($brokenLinks > 0) {
            $failed = max($failed, 1);
        }
        if ($errorCount > 0) {
            $failed = max($failed, 1);
        }

        $report['ok'] = $failed === 0;
        $report['failed'] = $failed;
        $report['broken_links'] = $brokenLinks;
        $report['error_count'] = $errorCount;
        $report['merchants_fixed'] = $merchantsFixed;
        $report['errors_cleared'] = $errorsCleared;

        ensureAutoAuditEngine();
        getDB()->prepare('INSERT INTO platform_audit_runs
            (run_type, ok, failed_checks, broken_links, error_count, merchants_fixed, summary_json)
            VALUES (?,?,?,?,?,?,?)')->execute([
            $runType,
            $report['ok'] ? 1 : 0,
            $failed,
            $brokenLinks,
            $errorCount,
            $merchantsFixed,
            json_encode($report, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);

        saveAutoAuditMeta('auto_audit_last_run_ts', (string)time());
        saveAutoAuditMeta('auto_audit_last_ok', $report['ok'] ? '1' : '0');
        saveAutoAuditMeta('auto_audit_last_summary', json_encode([
            'ran_at' => $report['ran_at'],
            'ok' => $report['ok'],
            'failed' => $failed,
            'broken_links' => $brokenLinks,
            'errors' => $errorCount,
            'merchants_fixed' => $merchantsFixed,
        ]));

        pruneAutoAuditRuns(200);
    } finally {
        unlockAutoAudit();
    }

    return $report;
}

function pruneAutoAuditRuns(int $keep = 200): void
{
    try {
        $count = (int)getDB()->query('SELECT COUNT(*) FROM platform_audit_runs')->fetchColumn();
        if ($count > $keep + 20) {
            getDB()->exec('DELETE FROM platform_audit_runs WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM platform_audit_runs ORDER BY id DESC LIMIT ' . (int)$keep . ') t
            )');
        }
    } catch (Throwable $e) {
        /* ok */
    }
}

function getLastAutoAuditRun(): ?array
{
    try {
        ensureAutoAuditEngine();
        $row = getDB()->query('SELECT * FROM platform_audit_runs ORDER BY id DESC LIMIT 1')->fetch();
        if (!$row) {
            $raw = getSetting('auto_audit_last_summary', '');
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                return is_array($decoded) ? $decoded : null;
            }
            return null;
        }
        $summary = json_decode((string)($row['summary_json'] ?? ''), true);
        return [
            'id' => (int)$row['id'],
            'ran_at' => $row['created_at'],
            'ok' => (bool)$row['ok'],
            'failed' => (int)$row['failed_checks'],
            'broken_links' => (int)$row['broken_links'],
            'error_count' => (int)$row['error_count'],
            'merchants_fixed' => (int)$row['merchants_fixed'],
            'run_type' => $row['run_type'],
            'summary' => is_array($summary) ? $summary : [],
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function getAutoAuditHistory(int $limit = 20): array
{
    try {
        ensureAutoAuditEngine();
        $stmt = getDB()->prepare('SELECT id, run_type, ok, failed_checks, broken_links, error_count, merchants_fixed, created_at
            FROM platform_audit_runs ORDER BY id DESC LIMIT ?');
        $stmt->execute([max(1, min(100, $limit))]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** Run if interval elapsed — returns report or null if skipped */
function maybeRunBackgroundAutoAudit(bool $force = false): ?array
{
    if (!$force && !shouldRunAutoAudit()) {
        return null;
    }
    $httpProbe = $force || ((int)date('G') % 6 === 0 && (int)date('i') < 15);
    return runBackgroundAutoAudit($httpProbe, $force ? 'manual' : 'background');
}

function bootstrapAutoAuditOnShutdown(): void
{
    if (defined('UNIWEB_AUTO_AUDIT_BOOTSTRAPPED')) {
        return;
    }
    define('UNIWEB_AUTO_AUDIT_BOOTSTRAPPED', true);

    register_shutdown_function(static function (): void {
        try {
            if (!function_exists('maybeRunBackgroundAutoAudit')) {
                return;
            }
            $force = !empty($_SESSION['force_auto_audit']);
            if ($force) {
                unset($_SESSION['force_auto_audit']);
            }
            $run = false;
            if (php_sapi_name() === 'cli') {
                $run = true;
            } elseif (function_exists('isAdminLoggedIn') && isAdminLoggedIn() && function_exists('isSuperAdmin') && isSuperAdmin()) {
                $run = true;
            }
            if ($run) {
                maybeRunBackgroundAutoAudit($force);
            }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'Auto audit shutdown: ' . $e->getMessage());
            }
        }
    });
}

function getAutoAuditStatusForHeader(): array
{
    $last = getLastAutoAuditRun();
    $errors = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;
    $mins = (int)(autoAuditIntervalSeconds() / 60);
    return [
        'audit_ok' => $last && !empty($last['ok']),
        'errors' => $errors,
        'last_at' => $last['ran_at'] ?? null,
        'interval_min' => $mins,
    ];
}

function getGatewaySetupGaps(): array
{
    if (!function_exists('platformHealthSummary')) {
        return [];
    }
    $health = platformHealthSummary();
    $optional = ['axis', 'decentro', 'whatsapp', 'otp', 'settlement_cron', 'merchant_webhooks', 'pg_webhooks'];
    $gaps = [];
    foreach ($health['services'] as $svc) {
        if (!empty($svc['ok'])) {
            continue;
        }
        if (in_array($svc['id'] ?? '', $optional, true)) {
            continue;
        }
        $gaps[] = $svc;
    }
    return $gaps;
}

bootstrapAutoAuditOnShutdown();
