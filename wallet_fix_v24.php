<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'uniweb-v24') { die('Forbidden'); }
$db = getDB();
$db->exec("UPDATE merchants SET account_mode='test'");
foreach ($db->query('SELECT id, email FROM merchants')->fetchAll() as $m) {
    syncMerchantWallet((int)$m['id']);
    $row = $db->prepare('SELECT * FROM merchants WHERE id=?');
    $row->execute([(int)$m['id']]);
    $merch = $row->fetch();
    $w = ensureMerchantWalletReady((int)$m['id']);
    echo $m['email'] . ' [' . (isMerchantTest($merch) ? 'TEST' : 'LIVE') . '] bal=' . $w['balance'] . ' avail=' . $w['available'] . ' min=' . getEffectiveMinSettlement($merch, (float)$w['available']) . "\n";
}
echo "DONE — KYC approve hone par admin se LIVE hoga\n";
