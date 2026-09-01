<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$mid = (int)$merchant['id'];
$viewTest = isDashboardTestMode($merchant);
$testFlag = $viewTest ? 1 : 0;

// B5: Respect date/method filters from reports page + test/live scope
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$method = trim($_GET['method'] ?? 'all');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = '';
$validMethods = ['upi','card','netbanking','wallet','razorpay','cashfree','payu'];
if ($method !== 'all' && !in_array($method, $validMethods, true)) $method = 'all';

$dateWhere = 'merchant_id = ? AND is_test = ? AND status = ?';
$dateParams = [$mid, $testFlag, 'success'];
if ($from !== '') { $dateWhere .= ' AND DATE(created_at) >= ?'; $dateParams[] = $from; }
if ($to !== '') { $dateWhere .= ' AND DATE(created_at) <= ?'; $dateParams[] = $to; }
if ($method !== 'all') { $dateWhere .= ' AND payment_method = ?'; $dateParams[] = $method; }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reports-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');

// Section 1: Daily collection breakdown
fputcsv($out, ['=== Daily Collection ===']);
fputcsv($out, ['Date', 'Count', 'Total Amount', 'Platform Fee', 'Merchant Net']);
$daily = $db->prepare("SELECT DATE(created_at) as d, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total, COALESCE(SUM(platform_fee),0) as fee, COALESCE(SUM(split_amount),0) as net FROM transactions WHERE {$dateWhere} GROUP BY DATE(created_at) ORDER BY d");
$daily->execute($dateParams);
foreach ($daily->fetchAll() as $r) {
    fputcsv($out, [$r['d'], $r['cnt'], $r['total'], $r['fee'], $r['net']]);
}

// Section 2: Payment method breakdown
fputcsv($out, []);
fputcsv($out, ['=== Payment Method Breakdown ===']);
fputcsv($out, ['Method', 'Count', 'Total Amount', 'Platform Fee', 'Merchant Net']);
$methods = $db->prepare("SELECT payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total, COALESCE(SUM(platform_fee),0) as fee, COALESCE(SUM(split_amount),0) as net FROM transactions WHERE {$dateWhere} GROUP BY payment_method ORDER BY total DESC");
$methods->execute($dateParams);
foreach ($methods->fetchAll() as $r) {
    fputcsv($out, [$r['payment_method'], $r['cnt'], $r['total'], $r['fee'], $r['net']]);
}

// Section 3: Transaction status breakdown
fputcsv($out, []);
fputcsv($out, ['=== Transaction Status Breakdown ===']);
fputcsv($out, ['Status', 'Count']);
$statusWhere = 'merchant_id = ? AND is_test = ?';
$statusParams = [$mid, $testFlag];
if ($from !== '') { $statusWhere .= ' AND DATE(created_at) >= ?'; $statusParams[] = $from; }
if ($to !== '') { $statusWhere .= ' AND DATE(created_at) <= ?'; $statusParams[] = $to; }
$statuses = $db->prepare("SELECT status, COUNT(*) as cnt FROM transactions WHERE {$statusWhere} GROUP BY status");
$statuses->execute($statusParams);
foreach ($statuses->fetchAll() as $r) {
    fputcsv($out, [$r['status'], $r['cnt']]);
}

// Section 4: Day x Method cross-tab
fputcsv($out, []);
fputcsv($out, ['=== Day x Method Cross-Tab ===']);
fputcsv($out, ['Date', 'Method', 'Count', 'Total Amount']);
$cross = $db->prepare("SELECT DATE(created_at) as d, payment_method, COUNT(*) as cnt, COALESCE(SUM(amount),0) as total FROM transactions WHERE {$dateWhere} GROUP BY DATE(created_at), payment_method ORDER BY d, payment_method");
$cross->execute($dateParams);
foreach ($cross->fetchAll() as $r) {
    fputcsv($out, [$r['d'], $r['payment_method'], $r['cnt'], $r['total']]);
}

// Section 5: Settlement summary
fputcsv($out, []);
fputcsv($out, ['=== Settlement Summary ===']);
fputcsv($out, ['Settlement ID', 'Amount', 'Fee', 'Net', 'Status', 'Date']);
$settleWhere = 'merchant_id = ?';
$settleParams = [$mid];
if ($from !== '') { $settleWhere .= ' AND DATE(created_at) >= ?'; $settleParams[] = $from; }
if ($to !== '') { $settleWhere .= ' AND DATE(created_at) <= ?'; $settleParams[] = $to; }
$settle = $db->prepare("SELECT settlement_id, amount, fee, net_amount, status, created_at FROM settlements WHERE {$settleWhere} ORDER BY created_at DESC LIMIT 500");
$settle->execute($settleParams);
foreach ($settle->fetchAll() as $r) {
    fputcsv($out, [$r['settlement_id'], $r['amount'], $r['fee'], $r['net_amount'], $r['status'], $r['created_at']]);
}

fclose($out);
exit;
