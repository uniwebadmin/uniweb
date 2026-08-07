<?php
declare(strict_types=1);

/**
 * Evidence Pack Generator (P2.4)
 *
 * Bundles a merchant's complete compliance trail into a downloadable ZIP:
 *   - Immutable audit log entries
 *   - KYC documents (metadata + file copies)
 *   - Transaction history
 *   - Settlement history
 *   - Risk/AML flags
 *   - Merchant profile snapshot
 *
 * Used by admin_view_merchant.php or admin_kyc.php "Download Evidence Pack" button.
 */

function generateEvidencePack(int $merchantId): array
{
    $db = getDB();

    // Fetch merchant
    $st = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    $businessName = preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)($merchant['business_name'] ?? 'merchant'));
    $zipName = 'evidence_pack_' . $businessName . '_' . $merchantId . '_' . date('Ymd_His') . '.zip';
    $tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;

    if (!class_exists('ZipArchive')) {
        return ['ok' => false, 'error' => 'ZipArchive not available on this server.'];
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'error' => 'Could not create ZIP file.'];
    }

    // 1. Merchant profile JSON
    $profileData = [
        'generated_at' => date('Y-m-d H:i:s'),
        'generated_by' => ($_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'system'),
        'merchant' => $merchant,
    ];
    $zip->addFromString('01_merchant_profile.json', json_encode($profileData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    // 2. Immutable audit log
    try {
        $st = $db->prepare('SELECT * FROM immutable_audit_log WHERE merchant_id = ? ORDER BY id DESC LIMIT 5000');
        $st->execute([$merchantId]);
        $auditRows = $st->fetchAll();
        $zip->addFromString('02_audit_log.json', json_encode($auditRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('02_audit_log_ERROR.txt', 'Could not fetch audit log: ' . $e->getMessage());
    }

    // 3. KYC documents metadata
    try {
        $st = $db->prepare('SELECT id, doc_type, file_name, storage_key, mime_type, file_size, scan_status, status, ip_address, client_ip, user_agent, lat, lng, geo_accuracy_m, geo_source, rejection_reason, reviewed_at, created_at, retention_until FROM kyc_documents WHERE merchant_id = ? ORDER BY created_at DESC');
        $st->execute([$merchantId]);
        $kycDocs = $st->fetchAll();
        $zip->addFromString('03_kyc_documents.json', json_encode($kycDocs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Copy actual KYC files
        $kycDir = rtrim(KYC_PRIVATE_DIR, '/\\') . DIRECTORY_SEPARATOR . $merchantId . DIRECTORY_SEPARATOR;
        foreach ($kycDocs as $doc) {
            $filePath = $kycDir . basename((string)$doc['file_name']);
            if (is_file($filePath)) {
                $zip->addFile($filePath, '03_kyc_files/' . $doc['doc_type'] . '_' . $doc['file_name']);
            }
        }
    } catch (Throwable $e) {
        $zip->addFromString('03_kyc_documents_ERROR.txt', 'Could not fetch KYC docs: ' . $e->getMessage());
    }

    // 4. Transaction history
    try {
        $st = $db->prepare('SELECT * FROM transactions WHERE merchant_id = ? ORDER BY id DESC LIMIT 10000');
        $st->execute([$merchantId]);
        $txns = $st->fetchAll();
        $zip->addFromString('04_transactions.json', json_encode($txns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('04_transactions_ERROR.txt', 'Could not fetch transactions: ' . $e->getMessage());
    }

    // 5. Settlement history
    try {
        $st = $db->prepare('SELECT * FROM settlement_batches WHERE merchant_id = ? ORDER BY id DESC LIMIT 1000');
        $st->execute([$merchantId]);
        $settlements = $st->fetchAll();
        $zip->addFromString('05_settlements.json', json_encode($settlements, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('05_settlements_ERROR.txt', 'Could not fetch settlements: ' . $e->getMessage());
    }

    // 6. Risk/AML flags
    try {
        $st = $db->prepare('SELECT * FROM risk_flags WHERE merchant_id = ? ORDER BY id DESC');
        $st->execute([$merchantId]);
        $riskFlags = $st->fetchAll();
        $zip->addFromString('06_risk_flags.json', json_encode($riskFlags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('06_risk_flags_NA.txt', 'No risk flags table or data available.');
    }

    // 7. Gateway events
    try {
        $st = $db->prepare('SELECT ge.* FROM gateway_events ge
            JOIN payment_orders po ON po.merchant_id = ?
            WHERE ge.provider_order_id = po.provider_order_id
            ORDER BY ge.id DESC LIMIT 5000');
        $st->execute([$merchantId]);
        $events = $st->fetchAll();
        $zip->addFromString('07_gateway_events.json', json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('07_gateway_events_ERROR.txt', 'Could not fetch gateway events: ' . $e->getMessage());
    }

    // 8. Notifications sent to merchant
    try {
        $st = $db->prepare('SELECT * FROM notifications WHERE merchant_id = ? ORDER BY id DESC LIMIT 1000');
        $st->execute([$merchantId]);
        $notifications = $st->fetchAll();
        $zip->addFromString('08_notifications.json', json_encode($notifications, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        $zip->addFromString('08_notifications_ERROR.txt', 'Could not fetch notifications: ' . $e->getMessage());
    }

    // 9. README
    $readme = "UniWeb Evidence Pack\n";
    $readme .= "====================\n\n";
    $readme .= "Merchant: " . ($merchant['business_name'] ?? 'Unknown') . " (ID: $merchantId)\n";
    $readme .= "Generated: " . date('Y-m-d H:i:s') . "\n";
    $readme .= "Generated by: " . ($_SESSION['staff_email'] ?? $_SESSION['admin_email'] ?? 'system') . "\n\n";
    $readme .= "Contents:\n";
    $readme .= "  01_merchant_profile.json — Full merchant record\n";
    $readme .= "  02_audit_log.json — Immutable audit trail (last 5000 entries)\n";
    $readme .= "  03_kyc_documents.json — KYC document metadata\n";
    $readme .= "  03_kyc_files/ — Actual KYC document files\n";
    $readme .= "  04_transactions.json — Transaction history (last 10000)\n";
    $readme .= "  05_settlements.json — Settlement batches (last 1000)\n";
    $readme .= "  06_risk_flags.json — Risk/AML flags\n";
    $readme .= "  07_gateway_events.json — Gateway webhook events\n";
    $readme .= "  08_notifications.json — Notifications sent to merchant\n";
    $zip->addFromString('README.txt', $readme);

    $zip->close();

    // Record audit
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'evidence_pack_downloaded',
            $merchantId,
            'merchant',
            (string)$merchantId,
            'Evidence Pack generated for compliance/audit'
        );
    }

    return ['ok' => true, 'path' => $tmpZip, 'filename' => $zipName, 'size' => filesize($tmpZip)];
}

function downloadEvidencePack(int $merchantId): void
{
    $result = generateEvidencePack($merchantId);
    if (empty($result['ok'])) {
        http_response_code(500);
        echo 'Error: ' . ($result['error'] ?? 'Unknown error');
        return;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
    header('Content-Length: ' . $result['size']);
    header('Cache-Control: no-store');

    readfile($result['path']);
    @unlink($result['path']);
    exit;
}
