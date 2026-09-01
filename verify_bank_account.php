<?php
require_once __DIR__ . '/config.php';
requireLogin();

header('Content-Type: application/json');

$accountNumber = preg_replace('/\s+/', '', trim($_POST['account_number'] ?? ''));
$ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
$csrf = $_POST['csrf_token'] ?? '';

if (!verifyCsrf($csrf)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid or expired session. Please refresh the page.']);
    exit;
}

if (!$accountNumber || strlen($accountNumber) < 9) {
    echo json_encode(['ok' => false, 'error' => 'Enter a valid account number.']);
    exit;
}
if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
    echo json_encode(['ok' => false, 'error' => 'Enter a valid 11-digit IFSC code.']);
    exit;
}

$merchant = getMerchant();
$verify = verifyBankAccount($accountNumber, $ifsc, (int)$merchant['id']);
$ifscInfo = lookupIfsc($ifsc) ?: [];

$isVerified = ($verify['status'] ?? '') === 'verified';
$pennyDropLive = function_exists('partnerIsConfigured') && (
    partnerIsConfigured('decentro') || partnerIsConfigured('digio') || partnerIsConfigured('cashfree')
);
$response = [
    'ok' => true,
    'verified' => $isVerified,
    'parked' => !$isVerified && !$pennyDropLive,
    'status' => $verify['status'] ?? 'submitted',
    'message' => $isVerified
        ? ($verify['message'] ?? 'Bank account verified.')
        : ($pennyDropLive
            ? ($verify['message'] ?? 'Verification submitted — penny drop in progress.')
            : 'Saved for manual review — instant penny-drop is PARKED until partner keys are live.'),
    'account_holder' => $isVerified ? ($verify['account_holder'] ?? '') : '',
    'bank' => $ifscInfo['bank'] ?? '',
    'branch' => $ifscInfo['branch'] ?? '',
    'city' => $ifscInfo['city'] ?? '',
    'district' => $ifscInfo['district'] ?? '',
    'state' => $ifscInfo['state'] ?? '',
];

echo json_encode($response);
