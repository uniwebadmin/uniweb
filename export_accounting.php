<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$where = 'merchant_id=?';
$params = [(int)$merchant['id']];
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= ' AND DATE(created_at)>=?'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= ' AND DATE(created_at)<=?'; $params[] = $to; }
$transactions = $db->prepare("SELECT txn_id, amount, split_amount, platform_fee, description, created_at FROM transactions WHERE {$where} AND status='success' AND is_test=0 ORDER BY created_at ASC");
$transactions->execute($params);
$settlements = $db->prepare("SELECT settlement_id, amount, fee, net_amount, utr, created_at FROM settlements WHERE {$where} AND status IN ('completed','settled','success') ORDER BY created_at ASC");
$settlements->execute($params);
try {
    $refunds = $db->prepare("SELECT r.refund_id, r.amount, r.reason, r.created_at, t.txn_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.merchant_id=? AND r.status='completed'" . ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? ' AND DATE(r.created_at)>=?' : '') . ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? ' AND DATE(r.created_at)<=?' : '') . ' ORDER BY r.created_at ASC');
    $refunds->execute($params);
    $refundRows = $refunds->fetchAll();
} catch (Throwable $e) { $refundRows = []; }
$clean = static function ($value): string { $value = (string)$value; return $value !== '' && preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value; };
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="tally-accounting-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['Voucher Date', 'Voucher Type', 'Reference', 'Debit Ledger', 'Credit Ledger', 'Amount', 'Narration']);
foreach ($transactions->fetchAll() as $row) {
    $net = (float)($row['split_amount'] ?: $row['amount']);
    fputcsv($out, array_map($clean, [date('d-m-Y', strtotime($row['created_at'])), 'Receipt', $row['txn_id'], 'Payment Gateway Clearing', 'Sales Collection', number_format((float)$row['amount'], 2, '.', ''), $row['description'] ?: 'Customer payment']));
    if ((float)$row['platform_fee'] > 0) fputcsv($out, array_map($clean, [date('d-m-Y', strtotime($row['created_at'])), 'Journal', $row['txn_id'], 'Payment Gateway Fee', 'Payment Gateway Clearing', number_format((float)$row['platform_fee'], 2, '.', ''), 'Platform fee']));
}
foreach ($refundRows as $row) fputcsv($out, array_map($clean, [date('d-m-Y', strtotime($row['created_at'])), 'Payment', $row['refund_id'], 'Sales Returns / Refunds', 'Payment Gateway Clearing', number_format((float)$row['amount'], 2, '.', ''), 'Refund for ' . $row['txn_id'] . ($row['reason'] ? ' — ' . $row['reason'] : '')]));
foreach ($settlements->fetchAll() as $row) fputcsv($out, array_map($clean, [date('d-m-Y', strtotime($row['created_at'])), 'Contra', $row['settlement_id'], 'Bank Account', 'Payment Gateway Clearing', number_format((float)$row['net_amount'], 2, '.', ''), 'Settlement' . ($row['utr'] ? ' UTR ' . $row['utr'] : '')]));
fclose($out);
exit;
