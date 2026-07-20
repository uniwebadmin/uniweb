<?php
declare(strict_types=1);

/**
 * One-shot migration: move legacy public uploads/kyc/* into KYC_PRIVATE_DIR.
 * Run: php scripts/migrate_legacy_kyc.php
 * Or via browser (super admin cron key): migrate_release.php?key=CRON_KEY&script=legacy_kyc
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/kyc_upload.php';

ensureKycSchema();
$db = getDB();
$legacyRoot = realpath(__DIR__ . '/../uploads/kyc');
$privateRoot = defined('KYC_PRIVATE_DIR') ? KYC_PRIVATE_DIR : (PRIVATE_STORAGE_DIR . 'kyc' . DIRECTORY_SEPARATOR);

if (!$legacyRoot || !is_dir($legacyRoot)) {
    echo "No legacy uploads/kyc directory.\n";
    exit(0);
}

$rows = $db->query("SELECT id, merchant_id, file_path, file_name FROM kyc_documents WHERE file_path LIKE 'uploads/kyc/%' OR file_path LIKE '%/uploads/kyc/%'")->fetchAll();
$moved = 0;
$skipped = 0;
foreach ($rows as $row) {
    $rel = ltrim(str_replace('\\', '/', (string)$row['file_path']), '/');
    $src = dirname(__DIR__) . '/' . $rel;
    if (!is_file($src)) {
        $skipped++;
        continue;
    }
    $destDir = rtrim($privateRoot, '/\\') . DIRECTORY_SEPARATOR . (int)$row['merchant_id'];
    if (!is_dir($destDir)) {
        mkdir($destDir, 0750, true);
    }
    $destName = basename($src);
    $dest = $destDir . DIRECTORY_SEPARATOR . $destName;
    if (!is_file($dest)) {
        if (!@rename($src, $dest)) {
            if (!@copy($src, $dest)) {
                $skipped++;
                continue;
            }
            @unlink($src);
        }
    }
    $newPath = 'private://' . (int)$row['merchant_id'] . '/' . $destName;
    $db->prepare('UPDATE kyc_documents SET file_path=? WHERE id=?')->execute([$newPath, (int)$row['id']]);
    $moved++;
}
echo "Legacy KYC migration complete. Moved: {$moved}, skipped: {$skipped}\n";
