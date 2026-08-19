<?php
/**
 * Cron: Zero-Touch Auto KYC Engine
 * Runs every 10 minutes via Hostinger cron.
 * Auto-approves clean KYC docs and auto-verifies eligible merchants.
 */

require_once __DIR__ . '/config.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli && !cronAuthOk('cron_auto_kyc_key')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'auth']);
    exit;
}

if (!function_exists('runAutoKycEngine') && is_file(__DIR__ . '/includes/auto_kyc.php')) {
    require_once __DIR__ . '/includes/auto_kyc.php';
}

if (!function_exists('cloudModulesAutoKycCronGate') && is_file(__DIR__ . '/includes/cloud_modules_workflow.php')) {
    require_once __DIR__ . '/includes/cloud_modules_workflow.php';
}

if (function_exists('cloudModulesAutoKycCronGate')) {
    $kycGate = cloudModulesAutoKycCronGate();
    if (empty($kycGate['ok'])) {
        recordCronHeartbeat('auto_kyc', 'error');
        if ($isCli) {
            echo 'Auto KYC gate blocked: ' . ($kycGate['error'] ?? 'unknown') . "\n";
        } else {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => (string)($kycGate['error'] ?? 'gate blocked'), 'mode' => $kycGate['mode'] ?? '']);
        }
        exit;
    }
}

if (!function_exists('runAutoKycEngine')) {
    echo json_encode(['ok' => false, 'error' => 'auto_kyc engine not available']);
    exit;
}

$summary = runAutoKycEngine();
recordCronHeartbeat('auto_kyc', !empty($summary['ok']) ? 'ok' : 'error');

// D3: Process per-partner forward queue once (do not also call the legacy alias)
$partnerForwardSummary = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];
if (!function_exists('processPerPartnerForwardQueue')) {
    require_once __DIR__ . '/includes/partner_forward_queue.php';
}
if (function_exists('processPerPartnerForwardQueue')) {
    $partnerForwardSummary = processPerPartnerForwardQueue(20);
}
$forwardSummary = [
    'processed' => (int)($partnerForwardSummary['processed'] ?? 0),
    'forwarded' => (int)($partnerForwardSummary['success'] ?? 0),
    'errors' => (int)($partnerForwardSummary['failed'] ?? 0),
];

if ($isCli) {
    echo "Auto KYC run: " . json_encode($summary) . "\n";
    echo "Partner forward (legacy): " . json_encode($forwardSummary) . "\n";
    echo "Partner forward (per-partner): " . json_encode($partnerForwardSummary) . "\n";
} else {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'summary' => $summary, 'forward' => $forwardSummary, 'partner_forward' => $partnerForwardSummary]);
}
