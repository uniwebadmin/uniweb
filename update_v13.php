<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$result = purgeCorruptWalletData();
echo "=== Wallet Repair v13 ===\n\n";
echo "Min settlement: {$result['min_settlement']}\n";
echo "Min platform: {$result['min_platform']}\n";
echo "Platform balance: {$result['platform']}\n\n";
echo "Merchants:\n";
foreach ($result['merchants'] as $line) {
    echo "  $line\n";
}
echo "\nDone. DELETE update_v13.php from server.\n";
