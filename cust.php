<?php
/** Short URL /cust → customer login (same as /cust/index.php). */
require_once __DIR__ . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('customer_login.php' . $qs);
