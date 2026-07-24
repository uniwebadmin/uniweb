<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
requireLogin();
requireMerchantTeamCapability('support');
ensureCustomerPortalSchema();

$merchant = getMerchant();
$statusFilter = (string)($_GET['status'] ?? '');
$tickets = getMerchantCustomerTickets((int)$merchant['id'], $statusFilter ?: null);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="customer-tickets-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fputcsv($out, ['Ticket ID', 'Customer Phone', 'Subject', 'Txn Reference', 'Status', 'Updated']);
foreach ($tickets as $t) {
    fputcsv($out, [
        $t['ticket_id'] ?? '',
        $t['customer_phone'] ?? '',
        $t['subject'] ?? '',
        $t['txn_reference'] ?? '',
        $t['status'] ?? '',
        $t['updated_at'] ?? '',
    ]);
}
fclose($out);
exit;
