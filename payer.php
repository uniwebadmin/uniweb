<?php
/** Short alias /payer.php → customer portal login (Hostinger-safe name). */
require_once __DIR__ . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('customer_login.php' . $qs);
