<?php
/**
 * Axis UAT connectivity probe — run once then DELETE
 * https://uniweb.co.in/axis_probe.php
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== UniWeb Axis UAT Probe ===\n\n";
echo "Server IP (whitelist on Axis portal): " . axisServerPublicIp() . "\n";
echo "API Base: " . axisApiBase() . "\n";
echo "Client ID: " . substr(axisCredentials()['client_id'], 0, 8) . "…\n\n";

$test = axisTestConnection();
echo "Token OK: " . ($test['token_ok'] ? 'YES' : 'NO') . "\n";
echo "Message: " . $test['message'] . "\n\n";

echo "--- Last 5 token logs ---\n";
foreach (array_slice(axisGetRecentLogs(20), 0, 5) as $log) {
    if (!str_contains($log['status'] ?? '', 'token')) continue;
    echo $log['created_at'] . " | HTTP " . $log['http_code'] . " | " . $log['endpoint'] . "\n";
    echo "  " . mb_substr($log['response_body'] ?? '', 0, 250) . "\n\n";
}

echo "DELETE axis_probe.php after reading.\n";
