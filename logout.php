<?php
require_once __DIR__ . '/config.php';
$wasAdmin = isset($_SESSION['admin_id']);
$timedOut = ($_GET['reason'] ?? '') === 'timeout';
$_SESSION = [];
session_destroy();
session_start();
session_regenerate_id(true);
flash($timedOut ? 'error' : 'success', $timedOut
    ? 'Secure session expired after inactivity. Please login again.'
    : 'Logged out successfully.');
redirect($wasAdmin ? 'admin_login.php' : 'login.php');
