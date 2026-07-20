<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/SimpleAgreementPdf.php';

function agreementPrivateDir(): string
{
    $base = defined('PRIVATE_STORAGE_DIR') ? PRIVATE_STORAGE_DIR : (dirname(__DIR__) . '/../uniweb_private/');
    $dir = rtrim($base, '/\\') . DIRECTORY_SEPARATOR . 'agreements' . DIRECTORY_SEPARATOR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir;
}

function ensureAgreementPdfColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!function_exists('schemaExecQuiet')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN signature_name VARCHAR(190) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN pdf_filename VARCHAR(120) DEFAULT NULL");
}

function generateAndStoreMerchantAgreementPdf(array $merchant, array $acceptance, array $sections): ?string
{
    ensureAgreementPdfColumns();
    $pdf = new SimpleAgreementPdf();
    $binary = $pdf->generate([
        'version' => $acceptance['agreement_version'] ?? merchantAgreementVersion(),
        'acceptance_id' => (string)($acceptance['id'] ?? ''),
        'legal_name' => $acceptance['legal_name'] ?? '',
        'merchant_code' => $acceptance['merchant_code'] ?? '',
        'signature_name' => $acceptance['signature_name'] ?? '',
        'accepted_at' => !empty($acceptance['accepted_at']) ? date('d M Y, h:i:s A', strtotime((string)$acceptance['accepted_at'])) . ' IST' : '',
        'accepted_ip' => $acceptance['accepted_ip'] ?? '',
        'document_hash' => $acceptance['document_hash'] ?? '',
    ], $sections);
    $filename = 'UWA-' . (int)($acceptance['id'] ?? 0) . '-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($merchant['merchant_code'] ?? 'merchant')) . '.pdf';
    $path = agreementPrivateDir() . $filename;
    if (@file_put_contents($path, $binary) === false) {
        return null;
    }
    getDB()->prepare('UPDATE merchant_agreement_acceptances SET pdf_filename=? WHERE id=?')
        ->execute([$filename, (int)$acceptance['id']]);
    return $filename;
}

function merchantAgreementPdfPath(int $acceptanceId, int $merchantId): ?string
{
    ensureAgreementPdfColumns();
    $st = getDB()->prepare('SELECT pdf_filename FROM merchant_agreement_acceptances WHERE id=? AND merchant_id=?');
    $st->execute([$acceptanceId, $merchantId]);
    $filename = $st->fetchColumn();
    if (!$filename) {
        return null;
    }
    $path = agreementPrivateDir() . basename((string)$filename);
    return is_file($path) ? $path : null;
}

function emailMerchantAgreementAccepted(array $merchant, array $acceptance): void
{
    $email = trim((string)($merchant['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    require_once __DIR__ . '/mailer.php';
    $downloadUrl = APP_URL . '/merchant_agreement_pdf.php?id=' . (int)$acceptance['id'];
    $body = "Dear " . ($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant') . ",\n\n"
        . "Your Merchant Services Agreement (version " . ($acceptance['agreement_version'] ?? '') . ") has been accepted and recorded.\n\n"
        . "Audit reference: UWA-" . (int)$acceptance['id'] . "\n"
        . "Accepted at: " . ($acceptance['accepted_at'] ?? '') . "\n"
        . "IP address: " . ($acceptance['accepted_ip'] ?? '') . "\n\n"
        . "Download your signed PDF copy:\n" . $downloadUrl . "\n\n"
        . "— " . APP_NAME . " Compliance";
    sendPlatformEmail($email, APP_NAME . ' — Merchant Agreement accepted', $body);
}

function notifyMerchantLiveActivated(int $merchantId): void
{
    $db = getDB();
    $st = $db->prepare('SELECT business_name, name, email, merchant_code FROM merchants WHERE id=?');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    if (!$merchant) {
        return;
    }
    createNotification($merchantId, 'Live Mode activated', 'Your merchant account is now in Live Mode. Real payments can be collected using your enabled methods.');
    require_once __DIR__ . '/mailer.php';
    $email = trim((string)($merchant['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $body = "Dear " . ($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant') . ",\n\n"
            . "Your UniWeb merchant account (" . ($merchant['merchant_code'] ?? '') . ") is now in Live Mode.\n\n"
            . "Next steps:\n"
            . "1. Switch dashboard to Live Mode if not already active\n"
            . "2. Create a new payment link (Test links do not move real money)\n"
            . "3. Confirm settlement bank account is verified\n\n"
            . "Dashboard: " . APP_URL . "/dashboard.php\n\n"
            . "— " . APP_NAME;
        sendPlatformEmail($email, APP_NAME . ' — Live Mode is now active', $body);
    }
}
