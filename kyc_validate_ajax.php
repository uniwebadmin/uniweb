<?php
/**
 * AJAX: Inline KYC field validation with green tick / red cross.
 * Called from kyc.php form fields on blur.
 */
require_once __DIR__ . '/config.php';
requireLogin();
if (!function_exists('validateKycField')) {
    require_once __DIR__ . '/includes/kyc_verify.php';
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!verifyCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_GET['token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Session expired.']);
    exit;
}

$field = (string)($_GET['field'] ?? '');
$value = (string)($_GET['value'] ?? '');
$allowed = ['pan', 'gst', 'cin', 'iec', 'ifsc', 'udyam', 'aadhaar'];

if (!in_array($field, $allowed, true)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid field.']);
    exit;
}

$result = validateKycField($field, $value);

if ($field === 'gst' && $result['valid']) {
    $merchant = getMerchant();
    $panInGst = $result['pan'] ?? '';
    $rawPan = (string)($merchant['pan_number'] ?? '');
    $merchantPan = strtoupper(trim(isSensitiveEncrypted($rawPan) ? sensitiveDecrypt($rawPan) : $rawPan));
    if ($merchantPan !== '' && $panInGst !== '' && $panInGst !== $merchantPan) {
        echo json_encode([
            'ok' => true,
            'valid' => false,
            'reason' => 'GSTIN PAN does not match your profile PAN. Profile PAN: ' . pii_mask_pan($rawPan) . ', GSTIN PAN: ' . $panInGst,
        ]);
        exit;
    }
}

echo json_encode([
    'ok' => true,
    'valid' => $result['valid'],
    'reason' => $result['reason'] ?? '',
    'normalized' => $result['normalized'] ?? $value,
]);
