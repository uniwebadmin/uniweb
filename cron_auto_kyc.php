<?php
/**
 * Cron: Zero-Touch Auto KYC Engine
 * Runs every 10 minutes via Hostinger cron.
 * Auto-approves clean KYC docs and auto-verifies eligible merchants.
 */

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
$cronKey = $_GET['key'] ?? '';
$expectedKey = getSetting('cron_auto_kyc_key', '');
if (!$isCli) {
    if ($expectedKey !== '' && !hash_equals($expectedKey, $cronKey)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'auth']);
        exit;
    }
}

if (!function_exists('runAutoKycEngine') && is_file(__DIR__ . '/includes/auto_kyc.php')) {
    require_once __DIR__ . '/includes/auto_kyc.php';
}

if (!function_exists('runAutoKycEngine')) {
    echo json_encode(['ok' => false, 'error' => 'auto_kyc engine not available']);
    exit;
}

$summary = runAutoKycEngine();

$forwardSummary = ['processed' => 0, 'forwarded' => 0, 'errors' => 0];
if (function_exists('processPartnerForwardQueue')) {
    $forwardSummary = processPartnerForwardQueue();
}

if ($isCli) {
    echo "Auto KYC run: " . json_encode($summary) . "\n";
    echo "Partner forward: " . json_encode($forwardSummary) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'summary' => $summary, 'forward' => $forwardSummary]);
}
