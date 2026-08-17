<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$p = trim($_GET['p'] ?? '');
if ($p !== '') {
    redirect(function_exists('adminPartnerDetailUrl') ? adminPartnerDetailUrl($p) : ('admin_gateway_detail.php?partner=' . rawurlencode($p) . '&tab=keys&env=test'));
}
redirect('admin_gateway_registry.php');
