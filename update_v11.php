<?php
/**
 * v11 — merchant auto-provision + method-specific payment links
 * Run: https://uniweb.co.in/update_v11.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$db = getDB();

$merchantCols = [
    "ALTER TABLE merchants ADD COLUMN auto_provisioned TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE merchants ADD COLUMN enabled_methods TEXT DEFAULT NULL",
    "ALTER TABLE merchants ADD COLUMN provision_pack_id VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE merchants ADD COLUMN provision_profile VARCHAR(64) DEFAULT NULL",
];
foreach ($merchantCols as $sql) {
    try { $db->exec($sql); echo "OK merchants col\n"; } catch (Throwable $e) { echo "SKIP: {$e->getMessage()}\n"; }
}

$linkCols = [
    "ALTER TABLE payment_links ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN gateway_code VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN pack_id VARCHAR(32) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN link_label VARCHAR(128) DEFAULT NULL",
    "ALTER TABLE payment_links ADD COLUMN link_collection_mode VARCHAR(32) DEFAULT NULL",
];
foreach ($linkCols as $sql) {
    try { $db->exec($sql); echo "OK payment_links col\n"; } catch (Throwable $e) { echo "SKIP: {$e->getMessage()}\n"; }
}

echo "\nDone. Admin → Partner Requests | Merchant → Payment Pack\n";
echo "DELETE update_v11.php\n";
