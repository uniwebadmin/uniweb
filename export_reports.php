<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$mid = (int)$merchant['id'];

$daily = $db->prepare("SELECT DATE(created_at) as d, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY d");
$daily->execute([$mid]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reports-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Section', 'Key', 'Count', 'Total Amount']);
foreach ($daily->fetchAll() as $r) {
    fputcsv($out, ['daily_collection', $r['d'], $r['cnt'], $r['total']]);
}

$methods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE merchant_id=? AND status='success' GROUP BY payment_method");
$methods->execute([$mid]);
foreach ($methods->fetchAll() as $r) {
    fputcsv($out, ['payment_method', $r['payment_method'], $r['cnt'], $r['total']]);
}

$statuses = $db->prepare("SELECT status, COUNT(*) as cnt FROM transactions WHERE merchant_id=? GROUP BY status");
$statuses->execute([$mid]);
foreach ($statuses->fetchAll() as $r) {
    fputcsv($out, ['transaction_status', $r['status'], $r['cnt'], '']);
}
fclose($out);
exit;
