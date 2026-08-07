<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/qr_events.php';
requireLogin();

$db = getDB();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$isAdmin = isAdminLoggedIn();

$targetMerchantId = $merchantId;
if ($isAdmin && isset($_GET['merchant_id']) && is_numeric($_GET['merchant_id'])) {
    $targetMerchantId = (int)$_GET['merchant_id'];
}

$type = trim((string)($_GET['type'] ?? ''));
if ($type === 'instant_upi') {
    $filterSql = "merchant_id = ? AND qr_type = 'instant_upi'";
} else {
    $filterSql = "merchant_id = ? AND qr_type != 'instant_upi'";
}
$params = [$targetMerchantId];
$status = trim((string)($_GET['status'] ?? ''));
if (in_array($status, ['active', 'inactive'], true)) {
    $filterSql .= ' AND status = ?';
    $params[] = $status;
}
if (in_array($type, ['fixed', 'upi_dynamic', 'all_methods'], true)) {
    $filterSql .= ' AND qr_type = ?';
    $params[] = $type;
}

$stmt = $db->prepare("SELECT id, qr_code, label, amount, qr_type, status FROM merchant_qr_codes WHERE {$filterSql} ORDER BY created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

if (empty($rows)) {
    flash('error', 'No QR codes to download.');
    redirect($isAdmin ? 'admin_qr_codes.php' : 'qr_code.php');
}

$zip = new ZipArchive();
$zipName = 'uniweb-qr-' . preg_replace('/[^a-z0-9_-]/i', '-', $merchant['business_name'] ?? 'merchant') . '-' . date('Y-m-d') . '.zip';
$tmpPath = sys_get_temp_dir() . '/' . uniqid('uniweb_qr_', true) . '.zip';
if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    flash('error', 'Could not create ZIP file.');
    redirect($isAdmin ? 'admin_qr_codes.php' : 'qr_code.php');
}

$baseUrl = APP_URL . '/qr_image.php?d=';
foreach ($rows as $row) {
    if ($row['qr_type'] === 'instant_upi') {
        $scanUrl = APP_URL . '/qr_upi_redirect.php?code=' . rawurlencode($row['qr_code']);
    } else {
        $scanUrl = APP_URL . '/qr_pay.php?code=' . rawurlencode($row['qr_code']);
    }
    $data = base64_encode(strtr($scanUrl, '+/', '-_'));
    $imageUrl = $baseUrl . rawurlencode($data) . '&s=600&logo=1';
    $image = @file_get_contents($imageUrl);
    if ($image === false) {
        continue;
    }
    $safeLabel = preg_replace('/[^a-z0-9_-]/i', '-', $row['label']);
    $fileName = $row['qr_code'] . ($safeLabel ? '-' . substr($safeLabel, 0, 30) : '') . '.png';
    $zip->addFromString($fileName, $image);
}

// Add a small CSV index inside the ZIP
$csv = "qr_code,label,type,amount,status\n";
foreach ($rows as $row) {
    $amt = $row['qr_type'] === 'fixed' ? $row['amount'] : 'Open';
    $csv .= implode(',', [
        $row['qr_code'],
        '"' . str_replace('"', '""', $row['label']) . '"',
        $row['qr_type'],
        $amt,
        $row['status'],
    ]) . "\n";
}
$zip->addFromString('index.csv', $csv);
$zip->close();

if (!headers_sent()) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: no-store');
}
readfile($tmpPath);
unlink($tmpPath);

if ($isAdmin) {
    logStaffActivity('qr_zip_downloaded', 'merchant_id=' . $targetMerchantId . ' count=' . count($rows), $targetMerchantId, 'qr_code', '');
} else {
    foreach ($rows as $row) {
        logQrEvent($db, (int)$row['id'], $targetMerchantId, 'download', ['bulk_zip' => true, 'count' => count($rows)]);
    }
}
exit;
