<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrf($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$merchantId = (int)($_POST['merchant_id'] ?? 0);
$field = trim((string)($_POST['field'] ?? ''));
$allowedFields = ['pan_number', 'gstin', 'cin_llpin', 'aadhaar_number', 'udyam_number', 'iec_number'];

if ($merchantId <= 0 || !in_array($field, $allowedFields, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid parameters']);
    exit;
}

requireMerchantAccess($merchantId);

$db = getDB();
$st = $db->prepare("SELECT {$field} FROM merchants WHERE id = ? LIMIT 1");
$st->execute([$merchantId]);
$row = $st->fetch();

if (!$row || empty($row[$field])) {
    echo json_encode(['value' => '—']);
    exit;
}

$encrypted = (string)$row[$field];
if (!isSensitiveEncrypted($encrypted)) {
    echo json_encode(['value' => '—']);
    exit;
}

$plain = sensitiveDecrypt($encrypted);

// C5: Audit the reveal
if (function_exists('recordImmutableAudit')) {
    recordImmutableAudit(
        'pii_reveal',
        $merchantId,
        'merchant',
        (string)$merchantId,
        'Revealed full ' . $field
    );
}

echo json_encode(['value' => $plain]);
