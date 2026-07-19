<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (($_GET['key'] ?? '') !== 'uniweb-v25') {
    http_response_code(403);
    die('Forbidden');
}
if (function_exists('opcache_reset')) {
    opcache_reset();
}

echo "=== WALLET FIX v25 " . date('Y-m-d H:i:s') . " ===\n\n";

echo "BEFORE corrupt scan:\n";
foreach (scanCorruptAmounts() as $table => $rows) {
    echo "  [$table]\n";
    foreach ($rows as $r) {
        echo '    ' . json_encode($r) . "\n";
    }
}
if (empty(scanCorruptAmounts())) {
    echo "  (none found)\n";
}

echo "\nRunning walletFullRepair()...\n";
$result = walletFullRepair();

echo "\nAFTER corrupt scan:\n";
$after = scanCorruptAmounts();
if (empty($after)) {
    echo "  (none found)\n";
} else {
    foreach ($after as $table => $rows) {
        echo "  [$table] " . count($rows) . " rows\n";
    }
}

echo "\nMERCHANTS:\n";
foreach ($result['merchants'] as $line) {
    echo "  $line\n";
}

$p = $result['platform'];
echo "\nPLATFORM:\n";
echo "  balance={$p['balance']} available={$p['available']} pending={$p['pending']} min=" . getEffectivePlatformMinWithdraw((float)$p['available']) . "\n";
echo "  CAP_V26=" . (defined('UNIWEB_WALLET_CAP_V26') ? 'yes' : 'no') . "\n";
try {
    $rf = new ReflectionFunction('walletMoney');
    echo "  walletMoney defined at: " . $rf->getFileName() . ':' . $rf->getStartLine() . "\n";
} catch (Throwable $e) {
    echo "  walletMoney reflection failed\n";
}
try {
    $rfm = new ReflectionFunction('formatMoney');
    echo "  formatMoney defined at: " . $rfm->getFileName() . ':' . $rfm->getStartLine() . "\n";
} catch (Throwable $e) { /* ok */ }
$lines = file(__DIR__ . '/includes/baas.php');
echo "  baas.php walletMoney source:\n    " . trim(implode(' ', array_slice($lines, 223, 4))) . "\n";
$cfg = file(__DIR__ . '/config.php');
echo "  config formatMoney source:\n    " . trim(implode(' ', array_slice($cfg, 140, 4))) . "\n";
echo "  config line 9: " . trim(file(__DIR__ . '/config.php')[8]) . "\n";
echo "  formatMoney(0)=" . formatMoney(0) . "\n";
echo "  formatMoney(1)=" . formatMoney(1) . "\n";
echo "  walletMoney(2621450,true)=" . walletMoney(2621450, true) . "\n";

echo "\nDONE — delete this file after use.\n";
