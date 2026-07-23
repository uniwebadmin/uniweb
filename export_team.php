<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureMerchantTeamSchema();
$merchant = getMerchant();
$members = listMerchantTeamMembers((int)$merchant['id']);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="team-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Name', 'Email', 'Role', 'Status']);
fputcsv($out, [$merchant['name'] ?? 'Owner', $merchant['email'] ?? '', 'owner', 'active']);
foreach ($members as $row) {
    fputcsv($out, [$row['name'] ?? '', $row['email'] ?? '', $row['role'] ?? '', $row['status'] ?? '']);
}
fclose($out);
exit;
