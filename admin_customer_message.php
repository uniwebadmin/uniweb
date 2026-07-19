<?php
require_once __DIR__ . '/config.php';
if (!isAdminLoggedIn()) {
    redirect('admin_login.php');
}
$merchantId = (int)($_GET['merchant_id'] ?? $_POST['merchant_id'] ?? 0);
if ($merchantId) {
    redirect('admin_view_merchant.php?id=' . $merchantId);
}
flash('info', 'Contact merchants via Email or WhatsApp from Merchant View.');
redirect('manage_merchant.php');
