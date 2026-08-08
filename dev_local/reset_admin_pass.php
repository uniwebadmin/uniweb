<?php
require_once __DIR__ . '/../config.php';
$db = getDB();
$hash = password_hash('Admin@123', PASSWORD_ARGON2ID);
$db->prepare("UPDATE admins SET password=? WHERE username='abdulbarik'")->execute([$hash]);
echo "Admin password reset for user 'abdulbarik'\n";
echo "New password: Admin@123\n";
echo "Login URL: http://localhost:8000/admin_login.php\n";
