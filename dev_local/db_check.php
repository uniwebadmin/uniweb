<?php
require_once __DIR__ . '/../config.php';
$db = getDB();
echo "DB OK. Merchants: " . $db->query('SELECT COUNT(*) FROM merchants')->fetchColumn() . "\n";
