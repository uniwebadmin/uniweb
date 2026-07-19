<?php
/**
 * UNIWEB Collection Engine v7
 * Run once: https://uniweb.co.in/update_v7.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace">';
try {
    $db = getDB();
    $cols = [
        "ALTER TABLE merchants ADD COLUMN collection_mode VARCHAR(32) NOT NULL DEFAULT 'direct_upi'",
        "ALTER TABLE merchants ADD COLUMN axis_va_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN axis_va_number VARCHAR(32) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN axis_va_ifsc VARCHAR(16) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN axis_va_upi VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN payu_child_key VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN razorpay_linked_account_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN cashfree_vendor_id VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE transactions ADD COLUMN collection_mode VARCHAR(32) DEFAULT NULL",
    ];
    foreach ($cols as $sql) {
        try { $db->exec($sql); echo "OK: $sql\n"; } catch (Throwable $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
    }

    $settings = [
        ['default_collection_mode', 'direct_upi'],
        ['payu_environment', 'test'],
        ['payu_merchant_key', 'eP7SKi'],
        ['payu_merchant_salt', 'gPHc50rR6ojqUx71b0wTpPh4QfCz9GI7'],
        ['axis_environment', 'uat'],
        ['axis_api_key', '8f192785d93831ca9f36a5cf1b599657'],
        ['axis_api_secret', '41105ea6c644921a2b737b7ad26384ea'],
        ['platform_margin_pct', '0.10'],
        ['axis_va_ifsc', 'UTIB0000000'],
        ['default_commission', '0.10'],
    ];
    $ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    foreach ($settings as [$k, $v]) {
        $ins->execute([$k, $v]);
        echo "OK setting: $k\n";
    }
    echo "\n✅ Migration v7 done. DELETE update_v7.php now.\n";
    echo "Add Axis API keys in Admin → Gateway Settings.\n";
} catch (Throwable $e) {
    echo '❌ ' . $e->getMessage();
}
echo '</pre>';
