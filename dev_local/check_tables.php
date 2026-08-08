<?php
require_once __DIR__ . '/../config.php';
if (!function_exists('getMerchantPaymentMethods')) {
    require_once __DIR__ . '/../includes/payment_methods.php';
}
$db = getDB();
try {
    $r = $db->query('SELECT COUNT(*) FROM merchant_payment_methods');
    echo 'merchant_payment_methods table exists: YES, rows=' . $r->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'merchant_payment_methods table missing: ' . $e->getMessage() . PHP_EOL;
}
try {
    $r = $db->query('SELECT COUNT(*) FROM gateway_registry');
    echo 'gateway_registry table exists: YES, rows=' . $r->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'gateway_registry table missing: ' . $e->getMessage() . PHP_EOL;
}
try {
    $r = $db->query('SELECT COUNT(*) FROM gateway_method_map');
    echo 'gateway_method_map table exists: YES, rows=' . $r->fetchColumn() . PHP_EOL;
} catch (Throwable $e) {
    echo 'gateway_method_map table missing: ' . $e->getMessage() . PHP_EOL;
}
