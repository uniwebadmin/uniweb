<?php
/**
 * Background auto-audit cron — run every 10 minutes on Hostinger.
 * URL: cron_auto_audit.php?key=YOUR_WATCHDOG_KEY
 *
 * Does: demo fix, verified→live, link scan, error check, watchdog, saves history.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/kyc_upload.php';

$auth = validateCronRequest();
if (empty($auth['ok'])) {
    rejectCronRequest($auth['error'] ?? 'Invalid key');
}

$http = ($_GET['http'] ?? '0') === '1';
$verbose = ($_GET['verbose'] ?? '0') === '1';
$report = runBackgroundAutoAudit($http, 'cron');
$health = getCronHealthStatus();
$webhookQueue = processMerchantWebhookQueue(25);
$kycScans = processPendingKycScans(10);

$settleStep = $report['steps']['settlement'] ?? null;
$payload = [
    'ok' => !empty($report['ok']),
    'skipped' => !empty($report['skipped']),
    'ran_at' => $report['ran_at'] ?? date('c'),
    'failed' => $report['failed'] ?? 0,
    'broken_links' => $report['broken_links'] ?? 0,
    'errors' => $report['error_count'] ?? 0,
    'errors_cleared' => $report['errors_cleared'] ?? 0,
    'merchants_fixed' => $report['merchants_fixed'] ?? 0,
    'settlement_batches' => is_array($settleStep) ? (int)($settleStep['batches'] ?? 0) : 0,
    'settlement_ok' => is_array($settleStep) ? !empty($settleStep['ok']) : false,
    'next_run_in_sec' => autoAuditIntervalSeconds(),
    'cron_24_7' => !empty($health['live']),
    'cron_runs_24h' => (int)($health['runs_24h'] ?? 0),
    'merchant_webhooks' => $webhookQueue,
    'kyc_scans' => $kycScans,
    'help_en' => !empty($report['ok'])
        ? 'All clear — cron runs audit every 10 min. Pending KYC: verify in admin_kyc.php.'
        : 'Cron ran but some checks failed — open Admin → Link Watchdog.',
];

if ($verbose && function_exists('runFullLinkWatchdog')) {
    $payload['steps'] = $report['steps'] ?? [];
    $scan = runFullLinkWatchdog(false);
    $payload['broken_link_details'] = array_slice($scan['broken_links'] ?? [], 0, 20);
    $payload['page_issues'] = array_values(array_map(
        static fn(array $page): array => [
            'file' => $page['file'] ?? '',
            'portal' => $page['portal'] ?? '',
            'issues' => $page['issues'] ?? [],
        ],
        array_filter($scan['pages'] ?? [], static fn(array $page): bool => empty($page['ok']))
    ));
    if (function_exists('getErrorLogSummary')) {
        $payload['error_summary'] = getErrorLogSummary();
    }
}

sendCronJsonResponse($payload);
