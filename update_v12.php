<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$db = getDB();
ensureWalletEngine();
$db->exec('UPDATE payment_links SET amount=1.00 WHERE amount>10');
$db->exec('UPDATE transactions SET amount=1.00, platform_fee=0, split_amount=1.00, wallet_credited=0');
$db->exec('DELETE FROM wallet_transactions');
$db->exec('DELETE FROM platform_wallet_transactions');
$db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
$db->exec('UPDATE merchants SET wallet_balance=0');
backfillWalletCredits();
$mid = $db->query("SELECT id FROM merchants WHERE email='demo@uniweb.co.in' LIMIT 1")->fetchColumn();
if ($mid) {
    $success = (int)$db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'")->execute([$mid]) ? 0 : 0;
    $st = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
    $st->execute([$mid]);
    $cnt = (int)$st->fetchColumn();
    $bal = max(1, $cnt) * 1.0;
    $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$bal, $mid]);
    echo "Demo wallet set to $bal ($cnt success txns)\n";
}
echo 'Platform: ' . getSetting('platform_wallet_balance') . "\nDELETE update_v12.php";
