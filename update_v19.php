<?php
/** Nuclear wallet repair v19 — run once then delete */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isAdminLoggedIn() && ($_GET['key'] ?? '') !== 'uniweb-v19') {
    http_response_code(403);
    die('Forbidden');
}
$r = nukeCorruptWalletState();
$db = getDB();
foreach ($db->query('SELECT id FROM merchants')->fetchAll() as $m) {
    refreshMerchantWalletBalance((int)$m['id']);
}
echo "V19 NUCLEAR REPAIR OK\n";
echo "platform: " . getPlatformWalletBalance() . "\n";
echo "min_settlement: " . getSetting('min_settlement_amount', '100') . "\n";
echo "min_platform: " . getSetting('min_platform_settlement', '1') . "\n";
$bad = (int)$db->query('SELECT COUNT(*) FROM merchants WHERE wallet_balance > 1000')->fetchColumn();
echo "bad_merchants: $bad\n";
echo "DELETE this file.\n";
