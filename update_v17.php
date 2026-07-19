<?php
/** One-time wallet repair — run once on live then delete */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
if (!isAdminLoggedIn() && ($_GET['key'] ?? '') !== 'uniweb-v17') {
    http_response_code(403);
    die('Forbidden');
}
$r = forceWalletDataReset();
walletSanityRepair();
echo "V17 REPAIR OK\n";
echo "platform: " . getPlatformWalletBalance() . "\n";
echo "available: " . (ensurePlatformWalletReady()['available'] ?? 0) . "\n";
echo "min_platform: " . normalizedSettingAmount('min_platform_settlement', '1') . "\n";
echo "min_merchant: " . normalizedSettingAmount('min_settlement_amount', '100') . "\n";
echo "uncredited: " . getUncreditedSuccessCount() . "\n";
echo "DELETE this file after run.\n";
