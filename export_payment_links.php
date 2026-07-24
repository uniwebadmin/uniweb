<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensurePaymentPackSchema();
$merchant = getMerchant();
$db = getDB();
$testMode = isDashboardTestMode($merchant);
$modeFilter = $testMode ? 1 : 0;
$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$linkStatus = trim($_GET['status'] ?? 'all');
$linkWhere = 'pl.merchant_id = ? AND pl.is_test = ?';
$linkParams = [$merchant['id'], $modeFilter];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $linkWhere .= " AND (LOWER(TRIM(COALESCE(pl.link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.description,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.status,''))) LIKE ? OR CAST(pl.amount AS CHAR) LIKE ?)";
    array_push($linkParams, $like, $like, $like, $like, $like);
}
if (in_array($linkStatus, ['active', 'inactive', 'expired'], true)) {
    $linkWhere .= ' AND pl.status = ?';
    $linkParams[] = $linkStatus;
}
$paidCountSql = "(SELECT COUNT(*) FROM transactions t WHERE t.payment_link_id = pl.id AND t.status = 'success')";
$having = $linkStatus === 'paid' ? ' HAVING paid_count > 0' : ($linkStatus === 'unpaid' ? ' HAVING paid_count = 0' : '');
$countStmt = $db->prepare("SELECT COUNT(*) FROM (SELECT pl.id, $paidCountSql AS paid_count FROM payment_links pl WHERE $linkWhere$having) x");
$countStmt->execute($linkParams);
$linkTotal = (int)$countStmt->fetchColumn();
$listParams = listPageParams(20);
$links = $db->prepare("SELECT pl.*, $paidCountSql AS paid_count FROM payment_links pl WHERE $linkWhere$having ORDER BY pl.created_at DESC LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
$links->execute($linkParams);
$rows = $links->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payment-links-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Link ID', 'Method', 'Amount', 'Views', 'Paid', 'Status', 'Expires']);
foreach ($rows as $link) {
    fputcsv($out, [
        $link['link_id'],
        $link['link_label'] ?? '',
        $link['amount'],
        $link['view_count'] ?? 0,
        $link['paid_count'] ?? 0,
        $link['status'],
        $link['expires_at'] ?? '',
    ]);
}
fclose($out);
exit;
