<?php
/** Fix merchant modes — run once */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'uniweb-v22') { die('Forbidden'); }
$db = getDB();
$db->exec("UPDATE merchants SET account_mode='test' WHERE kyc_status IS NULL OR kyc_status != 'verified'");
foreach ($db->query('SELECT id, email FROM merchants')->fetchAll() as $m) {
    syncMerchantWallet((int)$m['id']);
    $w = ensureMerchantWalletReady((int)$m['id']);
    echo $m['email'] . ' → TEST bal=' . $w['balance'] . ' avail=' . $w['available'] . "\n";
}
echo "DONE\n";
