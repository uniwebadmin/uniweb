<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();
echo "=== UniWeb Night Setup ===\n\n";
$cols = [
    "ALTER TABLE merchants ADD COLUMN auto_provisioned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE merchants ADD COLUMN enabled_methods TEXT DEFAULT NULL",
    "ALTER TABLE merchants ADD COLUMN provision_pack_id VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE merchants ADD COLUMN provision_profile VARCHAR(64) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN gateway_code VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN pack_id VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN link_label VARCHAR(128) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN link_collection_mode VARCHAR(32) DEFAULT NULL",
];
foreach ($cols as $sql) {
    try { $db->exec($sql); echo "OK\n"; } catch (Throwable $e) { echo "SKIP\n"; }
}
try {
    $db->exec("CREATE TABLE IF NOT EXISTS axis_api_logs (
        id INT AUTO_INCREMENT PRIMARY KEY, endpoint VARCHAR(255) NOT NULL, method VARCHAR(10) DEFAULT 'POST',
        request_body TEXT, response_body TEXT, http_code INT DEFAULT 0, merchant_id INT DEFAULT NULL,
        status VARCHAR(32) DEFAULT 'ok', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK axis_api_logs\n";
} catch (Throwable $e) {}
$demo = $db->query("SELECT * FROM merchants WHERE email='demo@uniweb.co.in' LIMIT 1")->fetch();
if ($demo && empty($demo['auto_provisioned'])) {
    $adminId = (int)($db->query("SELECT id FROM admins LIMIT 1")->fetchColumn() ?: 0);
    $r = autoProvisionMerchant((int)$demo['id'], $adminId);
    echo "Demo pack: " . ($r['ok'] ? count($r['pack']['links'] ?? []) . " links" : 'fail') . "\n";
}
echo "DONE\n";
