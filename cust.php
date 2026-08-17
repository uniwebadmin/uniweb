<?php
/**
 * Short URL /cust → public home (WIRING-C1-C2-HYGIENE).
 * Customer login remains at customer_login.php.
 */
require_once __DIR__ . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('index.php' . $qs);
