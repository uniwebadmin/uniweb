<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$db = getDB();
$db->exec("UPDATE payment_links SET status='expired' WHERE link_id='DEMO28CC7D' OR (merchant_id IN (SELECT id FROM merchants WHERE email='demo@uniweb.co.in') AND amount != 1)");
$demo = ensureDemoMerchant();
echo "Fixed. New link: " . $demo['pay_url'] . "\nDELETE fix_demo.php\n";
