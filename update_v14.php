<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$r = purgeCorruptWalletData();
echo "Wallet repair v14 complete\n";
echo "Platform: {$r['platform']}\n";
foreach ($r['merchants'] as $line) echo "$line\n";
