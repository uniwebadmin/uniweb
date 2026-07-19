<?php
/**
 * Unified cron — delegates to background auto-audit.
 */
require_once __DIR__ . '/config.php';

$auth = validateCronRequest();
if (empty($auth['ok'])) {
    rejectCronRequest($auth['error'] ?? 'Invalid watchdog key');
}

$report = runBackgroundAutoAudit(($_GET['http'] ?? '0') === '1', 'cron_watchdog');
$health = getCronHealthStatus();

sendCronJsonResponse([
    'ok' => !empty($report['ok']),
    'ran_at' => $report['ran_at'] ?? date('c'),
    'failed' => $report['failed'] ?? 0,
    'broken_links' => $report['broken_links'] ?? 0,
    'errors' => $report['error_count'] ?? 0,
    'merchants_fixed' => $report['merchants_fixed'] ?? 0,
    'cron_24_7' => !empty($health['live']),
]);
