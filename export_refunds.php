<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureRefundsEngine();

$refunds = getMerchantRefunds((int)$merchant['id']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="refunds-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Refund ID', 'Transaction ID', 'Amount', 'Status', 'Reason', 'Created']);
foreach ($refunds as $r) {
    fputcsv($out, [
        $r['refund_id'] ?? '',
        $r['txn_id'] ?? '',
        $r['amount'] ?? '',
        $r['status'] ?? '',
        $r['reason'] ?? '',
        $r['created_at'] ?? '',
    ]);
}
fclose($out);
exit;
