<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');
$db = getDB();
$db->exec("UPDATE payment_links SET status='expired' WHERE merchant_id IN (SELECT id FROM merchants WHERE email='demo@uniweb.co.in')");
echo json_encode(ensureDemoMerchant());
