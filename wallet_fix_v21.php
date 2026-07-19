<?php
/** Wallet fix v21 — https://uniweb.co.in/wallet_fix_v21.php?key=uniweb-v21 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isAdminLoggedIn() && ($_GET['key'] ?? '') !== 'uniweb-v21') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDB();

function hardRebuildMerchantWallet(int $merchantId): float
{
    $db = getDB();
    $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id=?')->execute([$merchantId]);
    $db->prepare('UPDATE merchants SET wallet_balance=0 WHERE id=?')->execute([$merchantId]);
    $db->prepare("UPDATE transactions SET wallet_credited=0 WHERE merchant_id=? AND status='success'")->execute([$merchantId]);
    $ids = $db->prepare("SELECT id FROM transactions WHERE merchant_id=? AND status='success' ORDER BY id ASC");
    $ids->execute([$merchantId]);
    foreach ($ids->fetchAll() as $r) {
        creditWalletsFromTransaction((int)$r['id']);
    }
    return rebuildMerchantWalletBalance($merchantId);
}

echo "=== WALLET FIX v21 ===\n\n";

// Fix gateway settings
$db->exec("UPDATE gateway_settings SET setting_value='100' WHERE setting_key='min_settlement_amount'");
$db->exec("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement'");
$db->exec("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance' AND CAST(setting_value AS DECIMAL(20,2)) > 1000");
clearSettingCache();

// Fix corrupt test payment links
$db->exec("UPDATE payment_links SET amount=LEAST(amount,100) WHERE (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%') AND amount > 100");
$db->exec("UPDATE transactions SET amount=LEAST(amount,100), split_amount=LEAST(COALESCE(split_amount,amount),100), platform_fee=LEAST(COALESCE(platform_fee,0),100) WHERE is_test=1 AND status='success' AND amount > 100");

// Delete absurd ledger rows globally
$db->exec('DELETE FROM wallet_transactions WHERE ABS(amount) > 1000');
$db->exec("UPDATE settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing') AND amount > 1000");
$db->exec("DELETE FROM settlement_batches WHERE net_amount > 1000 OR gross_amount > 1000");

dedupeWalletTransactionCredits();

$merchants = $db->query('SELECT id, email, wallet_balance, account_mode, kyc_status FROM merchants')->fetchAll();
foreach ($merchants as $m) {
    $mid = (int)$m['id'];
    $isTest = isMerchantTest($m);
    $st = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE merchant_id=?');
    $st->execute([$mid]);
    $ledger = round((float)$st->fetchColumn(), 2);
    $stored = round((float)$m['wallet_balance'], 2);
    $threshold = walletCorruptThreshold($isTest);

    $needs = $ledger > $threshold
        || $stored > $threshold
        || abs($ledger - $stored) > 0.02
        || (int)$db->query("SELECT COUNT(*) FROM wallet_transactions WHERE merchant_id=$mid AND ABS(amount)>1000")->fetchColumn() > 0;

    if ($needs) {
        $bal = hardRebuildMerchantWallet($mid);
        echo "REBUILT {$m['email']}: $bal\n";
    } else {
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$ledger, $mid]);
        echo "OK {$m['email']}: ledger=$ledger\n";
    }
}

rebuildPlatformWalletBalance();
echo "\nplatform: " . getPlatformWalletBalance() . "\n";
echo "min_settlement: " . getSetting('min_settlement_amount') . "\n";
echo "DONE — delete wallet_fix_v21.php and wallet_diagnose.php\n";
