<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureInvoiceSchema();
$db = getDB();

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$status = trim($_GET['status'] ?? 'all');
$where = 'merchant_id = ?';
$params = [(int)$merchant['id']];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $where .= " AND (LOWER(invoice_id) LIKE ? OR LOWER(customer_name) LIKE ? OR LOWER(COALESCE(customer_email,'')) LIKE ? OR LOWER(COALESCE(customer_phone,'')) LIKE ? OR CAST(total_amount AS CHAR) LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like);
}
if (in_array($status, ['sent', 'paid', 'overdue', 'cancelled'], true)) {
    $where .= ' AND status = ?';
    $params[] = $status;
}

$stmt = $db->prepare("SELECT invoice_id, customer_name, customer_email, customer_phone, amount, tax_amount, total_amount, status, due_date, created_at FROM invoices WHERE {$where} ORDER BY created_at DESC LIMIT 5000");
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="invoices-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Invoice ID', 'Customer', 'Email', 'Phone', 'Amount', 'Tax', 'Total', 'Status', 'Due Date', 'Created']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['invoice_id'],
        $r['customer_name'],
        $r['customer_email'] ?? '',
        $r['customer_phone'] ?? '',
        $r['amount'],
        $r['tax_amount'],
        $r['total_amount'],
        $r['status'],
        $r['due_date'] ?? '',
        $r['created_at'],
    ]);
}
fclose($out);
exit;
