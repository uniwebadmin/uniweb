<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$r = repairAllWallets();
echo "repairAllWallets OK\n";
echo "platform: " . ($r['platform'] ?? 0) . "\n";
echo "fixed_txns: " . ($r['fixed_txns'] ?? 0) . "\n";
echo "min_platform: " . ($r['purged']['min_platform'] ?? 1) . "\n";
echo "links_fixed: " . fixCorruptPaymentLinks() . "\n";
