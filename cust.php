<?php
/**
 * Short URL helper: /cust.php and rewrite target for /cust
 */
require_once __DIR__ . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('customer_login.php' . $qs);
