<?php
require_once __DIR__ . '/config.php';
requireSuperAdmin();
$p = trim($_GET['p'] ?? '');
if ($p !== '') {
    redirect('admin_gateway_detail.php?partner=' . rawurlencode($p));
}
redirect('admin_gateway_registry.php');
