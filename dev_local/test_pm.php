<?php
$_SESSION = [];
require_once __DIR__ . '/../config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/../includes/payment_methods.php';
}
echo "Function exists: " . (function_exists('getMerchantPaymentMethods') ? 'YES' : 'NO') . "\n";
echo "Function toggleMerchantPaymentMethod exists: " . (function_exists('toggleMerchantPaymentMethod') ? 'YES' : 'NO') . "\n";
echo "Function registerGateway exists: " . (function_exists('registerGateway') ? 'YES' : 'NO') . "\n";
echo "Function getMerchantEnabledMethodKeys exists: " . (function_exists('getMerchantEnabledMethodKeys') ? 'YES' : 'NO') . "\n";

// Test table creation
ensurePaymentMethodsTable();
echo "Tables ensured.\n";

// Test getting methods for merchant 1
$methods = getMerchantPaymentMethods(1);
echo "Methods count: " . count($methods) . "\n";
foreach ($methods as $m) {
    echo "  - {$m['gateway_key']} ({$m['gateway_name']}) enabled=" . (int)$m['is_enabled'] . "\n";
}
echo "OK\n";
