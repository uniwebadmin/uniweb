<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
$notifQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$readFilter = trim($_GET['filter'] ?? 'all');
$where = 'merchant_id = ?';
$params = [(int)$merchant['id']];
if ($notifQ !== '') {
    $like = '%' . strtolower($notifQ) . '%';
    $where .= ' AND (LOWER(title) LIKE ? OR LOWER(message) LIKE ?)';
    array_push($params, $like, $like);
}
if ($readFilter === 'unread') {
    $where .= ' AND is_read = 0';
} elseif ($readFilter === 'read') {
    $where .= ' AND is_read = 1';
}
$stmt = $db->prepare("SELECT title, message, is_read, created_at FROM notifications WHERE {$where} ORDER BY created_at DESC LIMIT 2000");
$stmt->execute($params);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="notifications-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Title', 'Message', 'Read', 'Created']);
foreach ($stmt->fetchAll() as $r) {
    fputcsv($out, [$r['title'], $r['message'], !empty($r['is_read']) ? 'yes' : 'no', $r['created_at']]);
}
fclose($out);
exit;
