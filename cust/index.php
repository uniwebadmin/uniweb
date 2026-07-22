<?php
/**
 * Short URL: /cust → customer portal login.
 * Physical path so Hostinger works even if .htaccess rewrite is skipped.
 */
require_once dirname(__DIR__) . '/config.php';
redirect('customer_login.php' . (!empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : ''));
