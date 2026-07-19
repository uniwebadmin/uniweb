<?php
require_once __DIR__ . '/config.php';
requireLogin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
    jsonResponse(['success' => false, 'message' => 'Invalid request'], 403);
}

$merchant = getMerchant();
$type = $_POST['type'] ?? '';
$number = trim($_POST['number'] ?? '');

if (!$type || !$number) {
    jsonResponse(['success' => false, 'message' => 'Type and number required']);
}

if ($type === 'bank') {
    $ifsc = trim($_POST['ifsc'] ?? '');
    $result = verifyBankAccount($number, $ifsc, (int)$merchant['id']);
} else {
    $result = verifyDocument($type, $number, (int)$merchant['id']);
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
        if ($col) {
            getDB()->prepare("UPDATE merchants SET $col = ? WHERE id = ?")->execute([$number, $merchant['id']]);
        }
    }
}

jsonResponse($result);
