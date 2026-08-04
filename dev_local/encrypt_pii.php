<?php
/**
 * One-time backfill: encrypt all existing plaintext bank account numbers,
 * payout beneficiary account numbers, and KYC document numbers.
 *
 * Run after migration 032 is applied and ENCRYPTION_KEY is configured:
 *
 *   php dev_local/encrypt_pii.php
 *
 * Safe to re-run: it skips any value already prefixed with enc:v1:.
 */
require_once __DIR__ . '/../config.php';

$db = getDB();

$tables = [
    'bank_accounts' => ['col' => 'account_number', 'last4' => 'account_number_last4'],
    'payout_beneficiaries' => ['col' => 'account_number', 'last4' => 'account_number_last4'],
    'kyc_verifications' => ['col' => 'doc_number', 'last4' => 'doc_number_last4'],
];

$total = 0;
foreach ($tables as $table => $cols) {
    $col = $cols['col'];
    $last4Col = $cols['last4'];
    $st = $db->prepare("SELECT id, `{$col}`, `{$last4Col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''");
    $st->execute();
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $val = (string)$row[$col];
        if (isSensitiveEncrypted($val)) {
            continue;
        }
        $enc = sensitiveEncrypt($val);
        $last4 = sensitiveLast4Raw($val);
        $up = $db->prepare("UPDATE `{$table}` SET `{$col}`=?, `{$last4Col}`=? WHERE id=?");
        $up->execute([$enc, $last4, (int)$row['id']]);
        $total++;
    }
}

echo json_encode(['ok' => true, 'encrypted_rows' => $total]) . PHP_EOL;
