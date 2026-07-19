<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/plain');
$db = getDB();
echo "COLUMNS:\n";
foreach ($db->query('DESCRIBE payment_links')->fetchAll() as $c) {
    echo $c['Field'] . " | " . $c['Type'] . "\n";
}
echo "\nROW DEMO1B4A35:\n";
print_r($db->query("SELECT * FROM payment_links WHERE link_id='DEMO1B4A35'")->fetch());
