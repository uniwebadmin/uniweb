<?php
require_once __DIR__ . '/../config.php';
$db = getDB();
$rows = $db->query("SELECT id, username, role, is_active, email FROM admins ORDER BY id LIMIT 10")->fetchAll();
echo "=== Admins in DB ===\n";
foreach ($rows as $a) {
    echo "ID: {$a['id']} | Username: {$a['username']} | Role: {$a['role']} | Active: {$a['is_active']} | Email: {$a['email']}\n";
}
