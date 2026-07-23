<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$recQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
try {
    $st = getDB()->prepare('SELECT mandate_ref, customer_name, customer_vpa, amount, frequency, status, created_at FROM recurring_mandates WHERE merchant_id=? ORDER BY id DESC');
    $st->execute([(int)$merchant['id']]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}
if ($recQ !== '') {
    $rows = array_values(array_filter($rows, static function ($row) use ($recQ) {
        $hay = strtolower(($row['mandate_ref'] ?? '') . ' ' . ($row['customer_name'] ?? '') . ' ' . ($row['customer_vpa'] ?? ''));
        return str_contains($hay, strtolower($recQ));
    }));
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="recurring-mandates-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Mandate Ref', 'Customer', 'VPA', 'Amount', 'Frequency', 'Status', 'Created']);
foreach ($rows as $r) {
    fputcsv($out, [$r['mandate_ref'], $r['customer_name'], $r['customer_vpa'], $r['amount'], $r['frequency'], $r['status'], $r['created_at'] ?? '']);
}
fclose($out);
exit;
