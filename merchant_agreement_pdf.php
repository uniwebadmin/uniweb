<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/public_legal_page.php';
require_once __DIR__ . '/includes/agreement_pdf.php';
requireLogin();
ensureMerchantAgreementSchema();

$id = (int)($_GET['id'] ?? 0);
$merchant = getMerchant();
$merchantId = (int)($merchant['id'] ?? 0);
$st = getDB()->prepare('SELECT * FROM merchant_agreement_acceptances WHERE id=? AND merchant_id=?');
$st->execute([$id, $merchantId]);
$acceptance = $st->fetch();
if (!$acceptance) {
    http_response_code(404);
    exit('Agreement record not found.');
}
$path = merchantAgreementPdfPath($id, $merchantId);
if (!$path) {
    // Stored copy missing (e.g. never generated or private storage was reset) — regenerate on demand.
    generateAndStoreMerchantAgreementPdf($merchant, $acceptance, merchantAgreementSections());
    $path = merchantAgreementPdfPath($id, $merchantId);
}
if (!$path) {
    http_response_code(404);
    exit('PDF not available yet. Re-accept the agreement or contact support.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="UniWeb-Merchant-Agreement-' . $id . '.pdf"');
header('Cache-Control: private, no-store');
readfile($path);
exit;
