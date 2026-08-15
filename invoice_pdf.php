<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
if (function_exists('decryptMerchantPiiFields')) {
    $merchant = decryptMerchantPiiFields($merchant);
}
ensureInvoiceSchema();
$id = $_GET['id'] ?? '';
$stmt = getDB()->prepare('SELECT * FROM invoices WHERE invoice_id = ? AND merchant_id = ?');
$stmt->execute([$id, $merchant['id']]);
$inv = $stmt->fetch();
if (!$inv) {
    require_once __DIR__ . '/includes/page_ux.php';
    uxSoftErrorExit('Invoice not found', 'This invoice is missing or does not belong to your account.', 404, 'invoices.php');
}

require_once __DIR__ . '/lib/SimpleInvoicePdf.php';
$pdf = new SimpleInvoicePdf();
$content = $pdf->generate($inv, $merchant);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice-' . $inv['invoice_id'] . '.pdf"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
