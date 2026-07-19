<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'uniweb-platform') { die('Forbidden'); }
$db = getDB();
echo "BEFORE stored=" . getPlatformWalletBalance() . "\n";
echo "BEFORE ledger=" . $db->query('SELECT COALESCE(SUM(amount),0) FROM platform_wallet_transactions')->fetchColumn() . "\n";
echo "BEFORE pending=" . $db->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn() . "\n";
$bal = repairPlatformWallet();
$w = ensurePlatformWalletReady();
echo "AFTER repair=$bal\n";
echo "UI balance={$w['balance']} available={$w['available']} min=" . getEffectivePlatformMinWithdraw((float)$w['available']) . "\n";
echo "DONE\n";
