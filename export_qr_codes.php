<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantQrCodes();
$merchant = getMerchant();
$db = getDB();
$isTest = isDashboardTestMode($merchant);
$qrQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$stmt = $db->prepare('SELECT qr_code, label, qr_type, amount, status, scan_count, created_at FROM merchant_qr_codes WHERE merchant_id=? AND is_test=? ORDER BY created_at DESC');
$stmt->execute([(int)$merchant['id'], $isTest ? 1 : 0]);
$rows = $stmt->fetchAll();
if ($qrQ !== '') {
    $rows = array_values(array_filter($rows, static function ($qr) use ($qrQ) {
        $hay = strtolower(($qr['label'] ?? '') . ' ' . ($qr['qr_code'] ?? '') . ' ' . ($qr['qr_type'] ?? ''));
        return str_contains($hay, strtolower($qrQ));
    }));
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="qr-codes-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['QR Code', 'Label', 'Type', 'Amount', 'Status', 'Scans', 'Created']);
foreach ($rows as $r) {
    fputcsv($out, [$r['qr_code'], $r['label'], $r['qr_type'], $r['amount'], $r['status'], $r['scan_count'], $r['created_at']]);
}
fclose($out);
exit;
