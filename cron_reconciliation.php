<?php
declare(strict_types=1);
/**
 * Cron: Daily reconciliation summary + auto-mark reconciled transactions.
 * Run daily via Hostinger cron or includes/auto_audit.php.
 * URL: cron_reconciliation.php?key=<WATCHDOG_KEY>
 */
require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $auth = validateCronRequest();
    if (empty($auth['ok'])) {
        rejectCronRequest($auth['error'] ?? 'Invalid key');
    }
}

$yesterday = date('Y-m-d', strtotime('-1 day'));
$today = date('Y-m-d');

// Generate summary for yesterday and today
$summaries = generateDailyReconciliationSummary($yesterday);
generateDailyReconciliationSummary($today);

// Auto-mark reconciled for last 7 days
$marked = autoMarkReconciledTransactions(7);

recordCronHeartbeat('reconciliation', 'ok');

echo "Reconciliation cron done: " . count($summaries) . " gateway summaries for {$yesterday}, {$marked} txns auto-marked.\n";
