<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$db = getDB();
$row = $db->query("SELECT pl.id, pl.link_id, pl.amount, pl.merchant_id, m.wallet_balance, m.commission_rate FROM payment_links pl JOIN merchants m ON pl.merchant_id=m.id WHERE pl.link_id LIKE 'DEMO%' ORDER BY pl.id DESC LIMIT 1")->fetch();
print_r($row);
$db->exec("UPDATE payment_links SET amount=1.00 WHERE link_id LIKE 'DEMO%'");
echo "\nFixed all DEMO links to amount=1\nDELETE debug_amount.php\n";
