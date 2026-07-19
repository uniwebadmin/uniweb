<?php
/**
 * Axis Bank UAT — logs table + settings
 * Run once: https://uniweb.co.in/update_v10.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

$db->exec("CREATE TABLE IF NOT EXISTS axis_api_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(255) NOT NULL,
    method VARCHAR(10) NOT NULL DEFAULT 'POST',
    request_body TEXT,
    response_body TEXT,
    http_code INT DEFAULT 0,
    merchant_id INT DEFAULT NULL,
    status VARCHAR(32) DEFAULT 'ok',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "OK: axis_api_logs table\n";

$settings = [
    ['axis_environment', 'uat'],
    ['axis_base_url', 'https://sakshamuat.axisbank.co.in'],
    ['axis_app_name', 'UNIWEB Collection API'],
    ['axis_client_id', '8f192785d93831ca9f36a5cf1b599657'],
    ['axis_client_secret', 'a1a9e84b33315f62bae07b19e85978f8'],
    ['axis_api_key', '8f192785d93831ca9f36a5cf1b599657'],
    ['axis_api_secret', 'a1a9e84b33315f62bae07b19e85978f8'],
    ['axis_va_ifsc', 'UTIB0000000'],
    ['axis_allow_mock', '0'],
    ['axis_channel_id', ''],
    ['axis_corporate_id', ''],
    ['axis_master_account', ''],
    ['axis_token_url', ''],
    ['axis_webhook_secret', bin2hex(random_bytes(16))],
];
$ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=IF(VALUES(setting_value)!="", VALUES(setting_value), setting_value)');
foreach ($settings as [$k, $v]) {
    $ins->execute([$k, $v]);
    echo "OK: $k\n";
}

echo "\nWebhook URL for Axis Portal:\n" . APP_URL . "/axis_webhook.php\n";
echo "\nAdmin: Admin → Axis UAT Dashboard\n";
echo "DELETE update_v10.php now.\n";
