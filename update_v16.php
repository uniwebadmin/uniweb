<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$r = forceWalletDataReset();
echo "FORCE RESET OK\n";
echo "platform: " . $r['platform'] . "\n";
echo "links: " . ($r['links'] ?? 0) . "\n";
