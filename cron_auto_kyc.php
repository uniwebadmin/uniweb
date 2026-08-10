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
recordCronHeartbeat('auto_kyc', !empty($summary['ok']) ? 'ok' : 'error');

// D3: Process legacy partner forward queue (auto_kyc.php)
$forwardSummary = ['processed' => 0, 'forwarded' => 0, 'errors' => 0];
if (function_exists('processPartnerForwardQueue')) {
    $forwardSummary = processPartnerForwardQueue();
}

// D3: Process per-partner forward queue (partner_forward_queue.php — Block B)
$partnerForwardSummary = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
if (!function_exists('processPerPartnerForwardQueue')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}
if (function_exists('processPerPartnerForwardQueue')) {
    $partnerForwardSummary = processPerPartnerForwardQueue(20);
}

if ($isCli) {
    echo "Auto KYC run: " . json_encode($summary) . "\n";
    echo "Partner forward (legacy): " . json_encode($forwardSummary) . "\n";
    echo "Partner forward (per-partner): " . json_encode($partnerForwardSummary) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'summary' => $summary, 'forward' => $forwardSummary, 'partner_forward' => $partnerForwardSummary]);
}
