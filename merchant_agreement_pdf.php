<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/schema_ensure.php';
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
    require_once __DIR__ . '/includes/page_ux.php';
    uxSoftErrorExit('Agreement not found', 'This agreement record is missing or does not belong to your account.', 404, 'merchant_agreement.php');
}
$path = merchantAgreementPdfPath($id, $merchantId);
if (!$path) {
    // Stored copy missing (e.g. never generated or private storage was reset) — regenerate on demand.
    generateAndStoreMerchantAgreementPdf($merchant, $acceptance, merchantAgreementSections());
    $path = merchantAgreementPdfPath($id, $merchantId);
}
if (!$path) {
    require_once __DIR__ . '/includes/page_ux.php';
    uxSoftErrorExit('PDF not available yet', 'Re-accept the agreement or contact support.', 404, 'merchant_agreement.php');
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="UniWeb-Merchant-Agreement-' . $id . '.pdf"');
header('Cache-Control: private, no-store');
readfile($path);
exit;
