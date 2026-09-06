<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

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

function autoAuditStepLabel(string $key): string
{
    $map = [
        'verified_live' => 'Verified merchants to Live',
        'watchdog' => 'Platform watchdog',
        'settlement' => 'Settlement batches',
        'morning_ops' => 'Morning ops',
        'qr_alerts' => 'QR health alerts',
        'va_counters' => 'Virtual account counters',
        'auto_kyc' => 'Auto KYC engine',
        'reconciliation' => 'Reconciliation',
        'mandates' => 'Mandate debits',
        'order_expiry' => 'Stale order expiry',
        'test_live_isolation' => 'Test / Live isolation',
        'payout_worker' => 'Payout worker',
        'rolling_reserve' => 'Rolling reserve release',
        'grievance_escalation' => 'Grievance SLA',
        'scheduled_settlements' => 'Scheduled settlements',
        'webhook_retries' => 'Webhook retries',
        'success_rate_alert' => 'Success rate (last 10 min)',
        'recurring_charges' => 'Recurring charges',
        'recurring_mandate_charges' => 'Recurring mandate charges',
        'partner_forward' => 'Partner forward queue',
        'transfer_failure_alert' => 'Transfer failure alerts',
        'link_scan' => 'Link scan',
        'broken_links' => 'Broken links',
        'unresolved_errors' => 'Unresolved Error Log',
    ];
    return $map[$key] ?? ucwords(str_replace('_', ' ', $key));
}

/** Hide cron/API secrets in audit text (JSON, UI, error details). */
function maskAuditSecrets(string $text): string
{
    $text = preg_replace('/(key=)[^&\s\'"]+/i', '$1****', $text) ?? $text;
    $text = preg_replace('/(?i)\b(password|secret|token|api[_-]?key|watchdog_key)\s*[:=]\s*\S+/', '$1=****', $text) ?? $text;
    return $text;
}

function maskAuditReportSecrets(mixed $value): mixed
{
    if (is_string($value)) {
        return maskAuditSecrets($value);
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = maskAuditReportSecrets($v);
        }
        return $out;
    }
    return $value;
}

/**
 * Flatten failed auto-audit / watchdog steps into labeled rows for cron JSON + Watchdog UI.
 *
 * @return list<array{id:string,label:string,detail:string}>
 */
function collectAutoAuditFailedChecks(array $report): array
{
    $out = [];
    $seen = [];
    $add = static function (string $id, string $label, string $detail) use (&$out, &$seen): void {
        $id = $id !== '' ? $id : $label;
        if (isset($seen[$id])) {
            return;
        }
        $seen[$id] = true;
        $out[] = [
            'id' => $id,
            'label' => $label !== '' ? $label : autoAuditStepLabel($id),
            'detail' => maskAuditSecrets($detail),
        ];
    };

    $steps = is_array($report['steps'] ?? null) ? $report['steps'] : [];
    $watchChecks = $steps['watchdog']['checks'] ?? null;
    if (is_array($watchChecks)) {
        foreach ($watchChecks as $c) {
            if (!is_array($c) || !empty($c['ok'])) {
                continue;
            }
            $add((string)($c['id'] ?? 'watchdog'), (string)($c['label'] ?? 'Watchdog check'), (string)($c['detail'] ?? 'Failed'));
        }
    }

    foreach ($steps as $key => $step) {
        if ($key === 'watchdog' || !is_array($step)) {
            continue;
        }
        if (array_key_exists('ok', $step) && $step['ok'] === false) {
            $detail = (string)($step['error'] ?? $step['alert'] ?? $step['detail'] ?? 'Failed');
            $add((string)$key, autoAuditStepLabel((string)$key), $detail);
        }
    }

    $broken = (int)($report['broken_links'] ?? 0);
    if ($broken > 0) {
        $add('broken_links', autoAuditStepLabel('broken_links'), $broken . ' broken link(s) — open Link Watchdog');
    }
    $errors = (int)($report['error_count'] ?? 0);
    if ($errors > 0) {
        $add('unresolved_errors', autoAuditStepLabel('unresolved_errors'), $errors . ' unresolved — open Error Log');
    }

    return $out;
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

        try {
            if (!function_exists('runAutoKycEngine')) {
                require_once __DIR__ . '/auto_kyc.php';
            }
            $kycResult = runAutoKycEngine();
            $report['steps']['auto_kyc'] = $kycResult;
        } catch (Throwable $e) {
            $report['steps']['auto_kyc'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('generateDailyReconciliationSummary')) {
                require_once __DIR__ . '/reconciliation.php';
            }
            $reconMarked = autoMarkReconciledTransactions(1);
            $report['steps']['reconciliation'] = ['ok' => true, 'auto_marked' => $reconMarked];
        } catch (Throwable $e) {
            $report['steps']['reconciliation'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('processDueMandateDebits')) {
                require_once __DIR__ . '/mandates.php';
            }
            $mandateResult = processDueMandateDebits();
            $report['steps']['mandates'] = $mandateResult;
        } catch (Throwable $e) {
            $report['steps']['mandates'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (function_exists('expireStalePaymentOrders')) {
                $expiryResult = expireStalePaymentOrders();
                $report['steps']['order_expiry'] = ['ok' => true, 'expired' => $expiryResult['expired'], 'errors' => count($expiryResult['errors'])];
            }
        } catch (Throwable $e) {
            $report['steps']['order_expiry'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (function_exists('auditTestLiveIsolation')) {
                $isolationViolations = auditTestLiveIsolation();
                $nIso = count($isolationViolations);
                $report['steps']['test_live_isolation'] = ['ok' => $nIso === 0, 'violations' => $nIso];
                if ($nIso > 0) {
                    logPlatformError('warning', 'Test/Live isolation violations detected', ['violations' => $isolationViolations]);
                } elseif (function_exists('resolvePlatformErrorsByMessageLike')) {
                    resolvePlatformErrorsByMessageLike('Test/Live isolation violations detected%');
                }
            }
        } catch (Throwable $e) {
            $report['steps']['test_live_isolation'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (function_exists('processPayoutJobs')) {
                $payoutResult = processPayoutJobs(20);
                $report['steps']['payout_worker'] = ['ok' => true, 'processed' => $payoutResult['processed'], 'success' => $payoutResult['success'], 'failed' => $payoutResult['failed'], 'retry' => $payoutResult['retry']];
            }
        } catch (Throwable $e) {
            $report['steps']['payout_worker'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('autoReleaseReserveHolds')) {
                require_once __DIR__ . '/rolling_reserve.php';
            }
            $releasedHolds = autoReleaseReserveHolds();
            $report['steps']['rolling_reserve'] = ['ok' => true, 'released' => $releasedHolds];
        } catch (Throwable $e) {
            $report['steps']['rolling_reserve'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('autoEscalateSlaBreached')) {
                require_once __DIR__ . '/grievance_engine.php';
            }
            $escalated = autoEscalateSlaBreached();
            $report['steps']['grievance_escalation'] = ['ok' => true, 'escalated' => $escalated];
        } catch (Throwable $e) {
            $report['steps']['grievance_escalation'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('runScheduledSettlementBatches')) {
                require_once __DIR__ . '/settlement_engine.php';
            }
            $settleResults = runScheduledSettlementBatches();
            $settleOk = count(array_filter($settleResults, fn($r) => !empty($r['ok'])));
            $report['steps']['scheduled_settlements'] = ['ok' => true, 'processed' => count($settleResults), 'succeeded' => $settleOk];
        } catch (Throwable $e) {
            $report['steps']['scheduled_settlements'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('processWebhookRetries')) {
                require_once __DIR__ . '/webhook_reliability.php';
            }
            $retryResults = processWebhookRetries(20);
            $report['steps']['webhook_retries'] = ['ok' => true, 'results' => $retryResults];
        } catch (Throwable $e) {
            $report['steps']['webhook_retries'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Success rate drop alert
        try {
            $db = getDB();
            $st = $db->query("SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success
                FROM transactions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
            $row = $st->fetch();
            $total10 = (int)($row['total'] ?? 0);
            $success10 = (int)($row['success'] ?? 0);
            $rate10 = $total10 > 0 ? ($success10 / $total10 * 100) : 100;

            if ($total10 >= 10 && $rate10 < 80) {
                $report['steps']['success_rate_alert'] = ['ok' => false, 'rate' => round($rate10, 1), 'total' => $total10, 'alert' => 'Success rate below 80% in last 10 minutes'];
                if (function_exists('logPlatformError')) {
                    logPlatformError('warning', "Success rate drop alert: {$rate10}% ({$success10}/{$total10} in 10min)");
                }
            } else {
                $report['steps']['success_rate_alert'] = ['ok' => true, 'rate' => round($rate10, 1), 'total' => $total10];
            }
        } catch (Throwable $e) {
            $report['steps']['success_rate_alert'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Process due subscription charges
        try {
            if (function_exists('processDueSubscriptionCharges')) {
                $subResult = processDueSubscriptionCharges(20);
                $report['steps']['recurring_charges'] = ['ok' => true, 'processed' => $subResult['processed']];
            } elseif (function_exists('getSubscriptionsDueForCharge')) {
                $dueSubs = getSubscriptionsDueForCharge(20);
                $subResults = [];
                foreach ($dueSubs as $sub) {
                    $subResults[] = processSubscriptionCharge((int)$sub['id']);
                }
                $report['steps']['recurring_charges'] = ['ok' => true, 'processed' => count($subResults)];
            }
        } catch (Throwable $e) {
            $report['steps']['recurring_charges'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Process due recurring mandate charges
        try {
            if (function_exists('processDueMandateCharges')) {
                $mandateResult = processDueMandateCharges(20);
                $report['steps']['recurring_mandate_charges'] = ['ok' => true, 'processed' => $mandateResult['processed']];
            }
        } catch (Throwable $e) {
            $report['steps']['recurring_mandate_charges'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // D4: Process partner forward queue
        try {
            if (!function_exists('processPerPartnerForwardQueue')) {
                require_once __DIR__ . '/partner_forward_queue.php';
            }
            if (function_exists('processPerPartnerForwardQueue')) {
                $forwardResult = processPerPartnerForwardQueue(20);
            } elseif (function_exists('processPartnerForwardQueue')) {
                $forwardResult = processPartnerForwardQueue(20);
            } else {
                $forwardResult = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
            }
            $report['steps']['partner_forward'] = ['ok' => true, 'processed' => $forwardResult['processed'] ?? 0, 'success' => $forwardResult['success'] ?? ($forwardResult['forwarded'] ?? 0), 'failed' => $forwardResult['failed'] ?? ($forwardResult['errors'] ?? 0), 'retry' => $forwardResult['retry'] ?? 0];
        } catch (Throwable $e) {
            $report['steps']['partner_forward'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // F7: Alert on repeated partner transfer failures
        try {
            if (!function_exists('alertRepeatedTransferFailures')) {
                require_once __DIR__ . '/split_settlement.php';
            }
            if (function_exists('alertRepeatedTransferFailures')) {
                alertRepeatedTransferFailures();
                $report['steps']['transfer_failure_alert'] = ['ok' => true];
            }
        } catch (Throwable $e) {
            $report['steps']['transfer_failure_alert'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // Payment ledger reconcile — idempotent retry for success txns missing payment_capture journal
        try {
            if (!function_exists('reconcilePendingPaymentLedgers')) {
                require_once __DIR__ . '/financial_integrity.php';
            }
            if (function_exists('reconcilePendingPaymentLedgers')) {
                $ledgerReconcile = reconcilePendingPaymentLedgers(50);
                $report['steps']['payment_ledger_reconcile'] = $ledgerReconcile;
            }
        } catch (Throwable $e) {
            $report['steps']['payment_ledger_reconcile'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            if (!function_exists('reconcilePendingRefunds') && is_file(__DIR__ . '/refund_webhooks.php')) {
                require_once __DIR__ . '/refund_webhooks.php';
            }
            if (function_exists('reconcilePendingRefunds')) {
                $report['steps']['refund_status_reconcile'] = reconcilePendingRefunds(30);
            }
            if (!function_exists('expireOverdueChargebacks') && is_file(__DIR__ . '/chargebacks.php')) {
                require_once __DIR__ . '/chargebacks.php';
            }
            if (function_exists('expireOverdueChargebacks')) {
                $report['steps']['chargeback_expiry'] = expireOverdueChargebacks();
            }
        } catch (Throwable $e) {
            $report['steps']['refund_status_reconcile'] = ['ok' => false, 'error' => $e->getMessage()];
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
        $report['broken_links'] = $brokenLinks;
        $report['error_count'] = $errorCount;
        $report['merchants_fixed'] = $merchantsFixed;
        $report['errors_cleared'] = $errorsCleared;
        $report['failed_list'] = collectAutoAuditFailedChecks($report);
        $failed = count($report['failed_list']);
        $report['ok'] = $failed === 0;
        $report['failed'] = $failed;
        $report = maskAuditReportSecrets($report);

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
            'failed_list' => $report['failed_list'],
            'broken_links' => $brokenLinks,
            'errors' => $errorCount,
            'merchants_fixed' => $merchantsFixed,
        ]));

        pruneAutoAuditRuns(200);

        if (function_exists('recordCronHeartbeat')) {
            $hb = static function (string $job, $step) use ($report): void {
                if (!is_array($step) && $job !== 'auto_audit') {
                    return;
                }
                $status = 'ok';
                if (is_array($step) && ((!empty($step['error'])) || (array_key_exists('ok', $step) && $step['ok'] === false))) {
                    $status = 'error';
                }
                recordCronHeartbeat($job, $status);
            };
            $hb('auto_audit', ['ok' => !empty($report['ok'])]);
            $hb('auto_kyc', $report['steps']['auto_kyc'] ?? null);
            $hb('settlements', $report['steps']['scheduled_settlements'] ?? ($report['steps']['settlement'] ?? null));
            $hb('mandates', $report['steps']['mandates'] ?? null);
            $hb('reconciliation', $report['steps']['reconciliation'] ?? null);
        }
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
            'failed_list' => is_array($summary['failed_list'] ?? null) ? $summary['failed_list'] : [],
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
            $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
            if (in_array($script, ['admin_login.php', 'staff_login.php', 'login.php', 'admin_forgot_password.php'], true)) {
                return;
            }
            if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) === 'POST') {
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

/** Payment collect gateways only — for Platform Settings launch panel (not internal workflow labels). */
function getCriticalCollectGatewayGaps(): array
{
    if (!function_exists('registryCollectPartnerKeyGaps') && is_file(__DIR__ . '/partner_registry_v2.php')) {
        require_once __DIR__ . '/partner_registry_v2.php';
    }
    if (function_exists('registryCollectPartnerKeyGaps')) {
        return registryCollectPartnerKeyGaps();
    }
    $collectIds = ['razorpay', 'cashfree', 'payu'];
    return array_values(array_filter(getGatewaySetupGaps(), static fn(array $svc): bool => in_array($svc['id'] ?? '', $collectIds, true)));
}

bootstrapAutoAuditOnShutdown();
