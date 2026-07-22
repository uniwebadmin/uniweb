<?php
/**
 * Hostinger: an empty or partial /cust/ directory can shadow clean-URL rewrites.
 * This index always sends payers to the real customer login entrypoint.
 */
require_once dirname(__DIR__) . '/config.php';
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('customer_login.php' . $qs);
