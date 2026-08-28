<?php
require_once __DIR__ . '/config.php';
$params = $_GET;
$params['view'] = 'ops';
header('Location: admin_financial_reports.php?' . http_build_query($params), true, 302);
exit;
