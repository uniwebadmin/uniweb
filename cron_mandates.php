<?php
/**
 * Cron: Process due mandate debits
 * Runs daily via Hostinger cron.
 * G4: Processes both mandates table (processDueMandateDebits) and recurring_mandates (processDueMandateCharges).
 *
 * Hostinger cron URL: https://yourdomain.com/cron_mandates.php?key=<CRON_MANDATES_KEY>
 * Set cron_mandates_key in Gateway Settings. Schedule: daily at 9:00 AM IST.
 */

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
$cronKey = $_GET['key'] ?? '';
$expectedKey = getSetting('cron_mandates_key', '');
if (!$isCli) {
    if ($expectedKey !== '' && !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'auth']);
        exit;
    }
}

if (!function_exists('processDueMandateDebits') && is_file(__DIR__ . '/includes/mandates.php')) {
    require_once __DIR__ . '/includes/mandates.php';
}
if (!function_exists('processDueMandateCharges') && is_file(__DIR__ . '/includes/recurring.php')) {
    require_once __DIR__ . '/includes/recurring.php';
}

$summary = ['mandates' => null, 'recurring' => null];

if (function_exists('processDueMandateDebits')) {
    $summary['mandates'] = processDueMandateDebits();
}

if (function_exists('processDueMandateCharges')) {
    $summary['recurring'] = processDueMandateCharges(50);
}

recordCronHeartbeat('mandates', 'ok');

if ($isCli) {
    echo "Mandate processing: " . json_encode($summary) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'summary' => $summary]);
}
