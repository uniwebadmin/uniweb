<?php
declare(strict_types=1);

// Backfill plaintext sensitive data with AES-256-GCM encryption.
// This migration is idempotent: it skips values already prefixed with 'enc:v1:'.

if (!function_exists('sensitiveEncrypt')) {
    require_once __DIR__ . '/../includes/crypto.php';
}

if (!defined('ENCRYPTION_KEY') || ENCRYPTION_KEY === '') {
    // No encryption key configured yet; this is a no-op migration.
    // The key must be set before PII encryption is enforced.
    return;
}

$db = getDB();

$tables = [
    ['table' => 'merchants', 'column' => 'aadhaar_number', 'id' => 'id'],
    ['table' => 'bank_accounts', 'column' => 'account_number', 'id' => 'id'],
    ['table' => 'payout_beneficiaries', 'column' => 'account_number', 'id' => 'id'],
    ['table' => 'kyc_verifications', 'column' => 'doc_number', 'id' => 'id'],
];

foreach ($tables as $t) {
    $table = $t['table'];
    $column = $t['column'];
    $id = $t['id'];

    $rows = $db->query(
        "SELECT {$id}, {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != '' AND {$column} NOT LIKE 'enc:v1:%'"
    )->fetchAll();

    $update = $db->prepare("UPDATE {$table} SET {$column} = ? WHERE {$id} = ?");
    foreach ($rows as $row) {
        $update->execute([sensitiveEncrypt($row[$column]), $row[$id]]);
    }
}
