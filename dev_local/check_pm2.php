<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/../includes/payment_methods.php';
}
ensurePaymentMethodsTable();
$methods = getMerchantPaymentMethods(1);
echo "=== Payment Methods for Merchant ID 1 ===\n";
echo "Count: " . count($methods) . "\n\n";
foreach ($methods as $m) {
    echo "Key: {$m['gateway_key']}\n";
    echo "  Name: {$m['gateway_name']}\n";
    echo "  Enabled: " . (int)$m['is_enabled'] . "\n";
    echo "  Collection: " . (int)$m['supports_collection'] . " | Payout: " . (int)$m['supports_payout'] . " | Refund: " . (int)$m['supports_refund'] . " | Recurring: " . (int)$m['supports_recurring'] . "\n\n";
}
