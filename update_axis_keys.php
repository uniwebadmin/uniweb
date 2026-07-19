<?php
/**
 * One-time: update Axis credentials + clear cached token
 * Run: https://uniweb.co.in/update_axis_keys.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

$updates = [
    'axis_client_id' => '8f192785d93831ca9f36a5cf1b599657',
    'axis_client_secret' => 'a1a9e84b33315f62bae07b19e85978f8',
    'axis_api_key' => '8f192785d93831ca9f36a5cf1b599657',
    'axis_api_secret' => 'a1a9e84b33315f62bae07b19e85978f8',
    'axis_app_name' => 'UNIWEB Collection API',
    'axis_application_id' => '0a166352-d873-4450-a9c9-5ffdf2bdbef9',
    'axis_oauth_redirect' => 'https://uniweb.co.in',
    'axis_environment' => 'uat',
    'axis_base_url' => 'https://sakshamuat.axisbank.co.in',
    'axis_access_token' => '',
    'axis_token_expires' => '0',
];

$stmt = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
foreach ($updates as $k => $v) {
    $stmt->execute([$k, $v]);
    echo "OK: $k\n";
}

echo "\nUAT Base: https://sakshamuat.axisbank.co.in/gateway/api/\n";
echo "Portal: https://apiportal.axis.bank.in/portal/\n";
echo "Webhook: " . APP_URL . "/axis_webhook.php\n";
echo "OAuth Redirect: https://uniweb.co.in\n\n";

$test = axisTestConnection();
echo "Token test: " . ($test['token_ok'] ? 'SUCCESS' : 'FAILED') . "\n";
echo $test['message'] . "\n";
echo "\nDELETE update_axis_keys.php now.\n";
