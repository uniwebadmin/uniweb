<?php require_once __DIR__ . '/config.php'; redirect(isAdminLoggedIn() ? 'admin_dashboard.php' : 'admin_login.php');
