<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$merchantId = (int)$merchant['id'];

$settlementQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$settlementStatus = trim($_GET['status'] ?? 'all');
if (!in_array($settlementStatus, ['all', 'pending', 'processing', 'completed', 'failed'], true)) {
    $settlementStatus = 'all';
}
$settlementFrom = trim($_GET['from'] ?? '');
$settlementTo = trim($_GET['to'] ?? '');

$settlementWhere = 's.merchant_id = ?';
$settlementParams = [$merchantId];
if ($settlementQ !== '') {
    $like = '%' . strtolower($settlementQ) . '%';
    $settlementSearch = ["LOWER(TRIM(COALESCE(s.settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(s.utr,''))) LIKE ?"];
    $settlementSearchParams = [$like, $like];
    if (is_numeric($settlementQ)) {
        $settlementSearch[] = 's.net_amount = ?';
        $settlementSearchParams[] = (float)$settlementQ;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementQ)) {
        $settlementSearch[] = 'DATE(s.created_at) = ?';
        $settlementSearchParams[] = $settlementQ;
    }
    $settlementWhere .= ' AND (' . implode(' OR ', $settlementSearch) . ')';
    array_push($settlementParams, ...$settlementSearchParams);
}
if ($settlementStatus !== 'all') {
    $settlementWhere .= ' AND s.status = ?';
    $settlementParams[] = $settlementStatus;
}
if ($settlementFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementFrom)) {
    $settlementWhere .= ' AND DATE(s.created_at) >= ?';
    $settlementParams[] = $settlementFrom;
}
if ($settlementTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $settlementTo)) {
    $settlementWhere .= ' AND DATE(s.created_at) <= ?';
    $settlementParams[] = $settlementTo;
}

$settlements = $db->prepare("SELECT s.*, b.bank_name, b.account_number FROM settlements s LEFT JOIN bank_accounts b ON s.bank_account_id = b.id WHERE $settlementWhere ORDER BY s.created_at DESC");
$settlements->execute($settlementParams);
$rows = $settlements->fetchAll();

$csvSafe = static function ($value): string {
    $value = (string)$value;
    if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="settlements-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
fputcsv($out, ['Settlement ID', 'Net Amount', 'Gross', 'Fee', 'Status', 'Bank', 'UTR', 'Date']);
foreach ($rows as $r) {
    fputcsv($out, array_map($csvSafe, [
        $r['settlement_id'],
        $r['net_amount'],
        $r['amount'],
        $r['fee'] ?? 0,
        $r['status'],
        $r['bank_name'] ?? '',
        $r['utr'] ?? '',
        $r['created_at'],
    ]));
}
fclose($out);
exit;
