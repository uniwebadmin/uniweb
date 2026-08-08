<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/../includes/payment_methods.php';
}
if (!function_exists('getPartnerRegistry')) {
    require_once __DIR__ . '/../includes/partner_engine.php';
}

echo "=== Syncing partners to gateway_registry ===\n";
syncPartnerGateways();

$gateways = getRegisteredGateways();
echo "Total gateways: " . count($gateways) . "\n";
$active = 0; $inactive = 0;
foreach ($gateways as $g) {
    if ((int)$g['is_active']) $active++; else $inactive++;
    echo "  " . $g['gateway_key'] . " | " . $g['gateway_name'] . " | " . ((int)$g['is_active'] ? 'ACTIVE' : 'INACTIVE') . "\n";
}
echo "Active: $active | Inactive: $inactive\n\n";

echo "=== Testing activate on first inactive gateway ===\n";
$firstInactive = null;
foreach ($gateways as $g) {
    if (!(int)$g['is_active']) { $firstInactive = $g; break; }
}
if ($firstInactive) {
    echo "Activating: " . $firstInactive['gateway_name'] . " (ID: " . $firstInactive['id'] . ")\n";
    $result = activateGatewayForAllMerchants((int)$firstInactive['id']);
    echo "Result: " . json_encode($result) . "\n";
} else {
    echo "No inactive gateway found to test.\n";
}
echo "\nOK\n";
