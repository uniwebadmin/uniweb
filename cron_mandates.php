<?php
/**
 * Cron: Process due mandate debits
 * Runs daily via Hostinger cron.
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

if (!function_exists('processDueMandateDebits')) {
    echo json_encode(['ok' => false, 'error' => 'mandates engine not available']);
    exit;
}

$summary = processDueMandateDebits();

if ($isCli) {
    echo "Mandate processing: " . json_encode($summary) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'summary' => $summary]);
}
