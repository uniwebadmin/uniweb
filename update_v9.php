<?php
/** One-time: demo merchant + live settings. DELETE after run. */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();
$demo = ensureDemoMerchant();
$settings = [
    ['active_payment_gateway', 'payu'],
    ['maintenance_mode', '0'],
    ['default_collection_mode', 'payu_split'],
];
$ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach ($settings as [$k, $v]) {
    $ins->execute([$k, $v]);
    echo "OK: $k\n";
}
echo "\nDemo merchant ready:\n";
echo "Pay URL: " . $demo['pay_url'] . "\n";
echo "Login: " . $demo['login_email'] . " / " . $demo['login_password'] . "\n";
echo "\nDELETE update_v9.php now.\n";
