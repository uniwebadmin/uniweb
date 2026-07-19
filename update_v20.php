<?php
/** Wallet repair v20 — run once: https://uniweb.co.in/update_v20.php?key=uniweb-v20 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isAdminLoggedIn() && ($_GET['key'] ?? '') !== 'uniweb-v20') {
    http_response_code(403);
    die('Forbidden');
}

$db = getDB();
echo "=== UniWeb Wallet Repair v20 ===\n\n";

$before = [
    'bad_merchants' => (int)$db->query('SELECT COUNT(*) FROM merchants WHERE wallet_balance > 1000')->fetchColumn(),
    'bad_ledger' => (int)$db->query('SELECT COUNT(*) FROM wallet_transactions WHERE ABS(amount) > 1000')->fetchColumn(),
    'bad_txns' => (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND ((is_test=1 AND amount > 100) OR amount > 500000)")->fetchColumn(),
    'bad_links' => (int)$db->query("SELECT COUNT(*) FROM payment_links WHERE amount > 100 AND (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%')")->fetchColumn(),
    'min_settlement' => getSetting('min_settlement_amount', '100'),
    'platform' => getPlatformWalletBalance(),
];
echo "BEFORE:\n";
foreach ($before as $k => $v) {
    echo "  $k: $v\n";
}

$result = repairAllWallets();

echo "\nREPAIR:\n";
echo "  backfilled: " . ($result['backfilled'] ?? 0) . "\n";
echo "  platform: " . ($result['platform'] ?? 0) . "\n";
echo "  min_settlement: " . ($result['min_settlement'] ?? 0) . "\n";
foreach ($result['merchants'] ?? [] as $line) {
    echo "  merchant: $line\n";
}

$after = [
    'bad_merchants' => (int)$db->query('SELECT COUNT(*) FROM merchants WHERE wallet_balance > 1000')->fetchColumn(),
    'bad_ledger' => (int)$db->query('SELECT COUNT(*) FROM wallet_transactions WHERE ABS(amount) > 1000')->fetchColumn(),
    'corrupt_flag' => hasCorruptWalletData() ? 'YES' : 'NO',
    'platform' => getPlatformWalletBalance(),
];
echo "\nAFTER:\n";
foreach ($after as $k => $v) {
    echo "  $k: $v\n";
}

echo "\nOK — delete update_v20.php after verifying wallet pages.\n";
