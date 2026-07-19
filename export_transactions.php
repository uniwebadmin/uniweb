<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$filter = trim($_GET['status'] ?? 'all');
if (!in_array($filter, ['all', 'pending', 'success', 'failed', 'refunded'], true)) $filter = 'all';
$qrId = (int)($_GET['qr_id'] ?? 0);
$q = trim($_GET['q'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$method = trim($_GET['method'] ?? 'all');
$viewTest = isDashboardTestMode($merchant);
$where = 'merchant_id = ? AND is_test = ?';
$params = [$merchant['id'], $viewTest ? 1 : 0];
if ($qrId > 0) {
    $where .= ' AND payment_link_id IN (SELECT id FROM payment_links WHERE qr_code_id = ?)';
    $params[] = $qrId;
}
if ($filter !== 'all') { $where .= ' AND status = ?'; $params[] = $filter; }
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (
        LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(utr,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_phone,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_email,''))) LIKE ? OR LOWER(TRIM(COALESCE(description,''))) LIKE ? OR
        CAST(amount AS CHAR) LIKE ? OR LOWER(CAST(COALESCE(metadata,'') AS CHAR)) LIKE ? OR
        payment_link_id IN (SELECT id FROM payment_links WHERE LOWER(TRIM(link_id)) LIKE ?)
    )";
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($method !== 'all' && in_array($method, ['upi','card','netbanking','wallet','qr','razorpay','cashfree','payu'], true)) {
    $where .= ' AND payment_method = ?';
    $params[] = $method;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= ' AND DATE(created_at) >= ?'; $params[] = $from; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= ' AND DATE(created_at) <= ?'; $params[] = $to; }
$stmt = $db->prepare("SELECT * FROM transactions WHERE $where ORDER BY created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$csvSafe = static function ($value): string {
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="transactions-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($out, ['Txn ID','Amount','Status','Method','Customer Name','Customer Phone','Customer Email','UTR','Description','Date']);
foreach ($rows as $r) {
    fputcsv($out, array_map($csvSafe, [$r['txn_id'], $r['amount'], $r['status'], $r['payment_method'], $r['customer_name'], $r['customer_phone'], $r['customer_email'] ?? '', $r['utr'], $r['description'], $r['created_at']]));
}
fclose($out);
exit;
