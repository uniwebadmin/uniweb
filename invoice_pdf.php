<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureInvoiceSchema();
$id = $_GET['id'] ?? '';
$stmt = getDB()->prepare('SELECT * FROM invoices WHERE invoice_id = ? AND merchant_id = ?');
$stmt->execute([$id, $merchant['id']]);
$inv = $stmt->fetch();
if (!$inv) { http_response_code(404); die('Invoice not found.'); }

require_once __DIR__ . '/lib/SimpleInvoicePdf.php';
$pdf = new SimpleInvoicePdf();
$content = $pdf->generate($inv, $merchant);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="invoice-' . $inv['invoice_id'] . '.pdf"');
header('Content-Length: ' . strlen($content));
echo $content;
exit;
