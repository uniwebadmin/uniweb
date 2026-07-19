<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'uniweb-v23') { die('Forbidden'); }
$db = getDB();
// Platform not live yet — all merchants test until admin approves KYC
$db->exec("UPDATE merchants SET account_mode='test' WHERE kyc_status IS NULL OR kyc_status != 'verified'");
foreach ($db->query('SELECT id, email, account_mode, kyc_status, wallet_balance FROM merchants')->fetchAll() as $m) {
    syncMerchantWallet((int)$m['id']);
    $w = ensureMerchantWalletReady((int)$m['id']);
    $mode = isMerchantTest($m) ? 'TEST' : 'LIVE';
    echo "{$m['email']} [$mode] bal={$w['balance']} avail={$w['available']} min=" . getEffectiveMinSettlement($m, (float)$w['available']) . "\n";
}
echo "DONE\n";
