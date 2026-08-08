<?php
require_once __DIR__ . '/config.php';
requireLogin();
require_once __DIR__ . '/lib/SimpleStatementPdf.php';

$merchant = getMerchant();
$db = getDB();

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$viewTest = isDashboardTestMode($merchant);

$where = 'merchant_id = ? AND COALESCE(is_test,0) = ?';
$params = [$merchant['id'], $viewTest ? 1 : 0];

if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $where .= ' AND DATE(created_at) >= ?';
    $params[] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $where .= ' AND DATE(created_at) <= ?';
    $params[] = $to;
}

$stmt = $db->prepare("SELECT txn_id, amount, status, payment_method, created_at FROM transactions WHERE $where ORDER BY created_at DESC LIMIT 200");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$summaryStmt = $db->prepare("SELECT COUNT(*) as count, COALESCE(SUM(CASE WHEN status='success' THEN amount ELSE 0 END),0) as total_amount, COALESCE(SUM(CASE WHEN status='success' THEN platform_fee ELSE 0 END),0) as total_fee, SUM(CASE WHEN status='success' THEN 1 ELSE 0 END) as success_count, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed_count FROM transactions WHERE $where");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$net = (float)$summary['total_amount'] - (float)$summary['total_fee'];
$summary['net_amount'] = $net;

$fromLabel = $from !== '' ? date('d M Y', strtotime($from)) : 'Start';
$toLabel = $to !== '' ? date('d M Y', strtotime($to)) : date('d M Y');

$pdf = new SimpleStatementPdf();
$binary = $pdf->generate($merchant, $transactions, $summary, $fromLabel, $toLabel);

$filename = 'statement-' . ($merchant['merchant_code'] ?? 'merchant') . '-' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($binary));
header('Cache-Control: no-store');
echo $binary;
exit;
