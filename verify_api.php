<?php
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/includes/kyc_submit_guard.php';
if (!function_exists('evaluateMerchantNameAgainstRegistry')) {
    require_once __DIR__ . '/includes/kyc_verify.php';
}
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 403);
}

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$action = (string)($_POST['action'] ?? 'verify');
$type = (string)($_POST['type'] ?? '');
$number = trim((string)($_POST['number'] ?? ''));

if ($action === 'aadhaar_otp') {
    $verifyFp = kycSubmitFingerprint('aadhaar_otp', [$merchantId, preg_replace('/\s+/', '', $number), (string)($_POST['reference_id'] ?? '')]);
    $lock = claimKycSubmitLock($merchantId, 'verify_aadhaar_otp', $verifyFp, 120);
    if (!empty($lock['replay'])) {
        jsonResponse(['success' => true, 'status' => 'verified', 'message' => 'Already verified — refresh KYC page.']);
    }
    if (empty($lock['ok'])) {
        jsonResponse(['success' => false, 'message' => $lock['message'] ?? 'Verification already in progress.'], 429);
    }
    $otp = trim((string)($_POST['otp'] ?? ''));
    $referenceId = trim((string)($_POST['reference_id'] ?? ''));
    $result = confirmAadhaarOtp($merchantId, $number, $otp, $referenceId);
    if (($result['status'] ?? '') === 'verified') {
        autoApproveVerifiedKycDoc($merchantId, 'aadhaar', $number);
    }
    jsonResponse($result);
}

if (!$type || !$number) {
    jsonResponse(['success' => false, 'message' => 'Type and number required']);
}

$verifyFp = kycSubmitFingerprint('verify', [$merchantId, $type, strtoupper(preg_replace('/\s+/', '', $number) ?? '')]);
$lock = claimKycSubmitLock($merchantId, 'verify_' . $type, $verifyFp, 120);
if (!empty($lock['replay'])) {
    jsonResponse(['success' => true, 'status' => 'submitted', 'message' => 'Already submitted — refresh KYC page for status.']);
}
if (empty($lock['ok'])) {
    jsonResponse(['success' => false, 'message' => $lock['message'] ?? 'Verification already in progress.'], 429);
}

if ($type === 'bank') {
    $ifsc = trim($_POST['ifsc'] ?? '');
    $result = verifyBankAccount($number, $ifsc, $merchantId);
    $registryName = '';
    if (is_array($result['data'] ?? null)) {
        $registryName = extractRegistryNameFromVerificationPayload($result);
    }
    if ($registryName !== '') {
        $nameEval = evaluateMerchantNameAgainstRegistry($merchantId, $registryName, 'bank');
        if (empty($nameEval['ok'])) {
            $result['success'] = false;
            $result['status'] = 'failed';
            $result['message'] = (string)($nameEval['mismatch'] ?? mapKycFailReason('name_mismatch', 'bank'));
            $result['name_match_score'] = (float)($nameEval['score'] ?? 0.0);
        }
    }
} else {
    $result = verifyDocument($type, $number, $merchantId);
    $registryName = '';
    if (is_array($result['data'] ?? null)) {
        $registryName = extractRegistryNameFromVerificationPayload($result);
    }
    if ($registryName !== '' && in_array($type, ['pan', 'gst', 'cin'], true)) {
        $nameEval = evaluateMerchantNameAgainstRegistry($merchantId, $registryName, $type);
        if (empty($nameEval['ok'])) {
            $result['success'] = false;
            $result['status'] = 'failed';
            $result['message'] = (string)($nameEval['mismatch'] ?? mapKycFailReason('name_mismatch', $type));
            $result['name_match_score'] = (float)($nameEval['score'] ?? 0.0);
        }
    }
    if (in_array($type, ['pan', 'gst', 'cin', 'udyam', 'iec', 'aadhaar'], true)) {
        $col = match($type) {
            'pan' => 'pan_number',
            'gst' => 'gstin',
            'cin' => 'cin_llpin',
            'udyam' => 'udyam_number',
            'iec' => 'iec_number',
            'aadhaar' => 'aadhaar_number',
            default => null,
        };
        if ($col && ($result['status'] ?? '') === 'verified') {
            $stored = sensitiveEncrypt($number);
            getDB()->prepare("UPDATE merchants SET $col = ? WHERE id = ?")->execute([$stored, $merchant['id']]);
            autoApproveVerifiedKycDoc($merchantId, $type, $number);
        }
    }
}

jsonResponse($result);
