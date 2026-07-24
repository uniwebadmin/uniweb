<?php
/**
 * Universal ID hop — open any UniWeb entity ID (TXN, LNK, CT, STL, …).
 */
require_once __DIR__ . '/config.php';
if (!function_exists('uwResolveIdUrl')) {
    require_once __DIR__ . '/includes/id_click.php';
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    flash('error', 'ID required.');
    redirect(function_exists('isAdminLoggedIn') && isAdminLoggedIn() ? 'admin_dashboard.php' : (function_exists('isLoggedIn') && isLoggedIn() ? 'dashboard.php' : 'index.php'));
}

$url = uwResolveIdUrl($id);
if ($url) {
    redirect($url);
}

$audience = uwIdClickAudience();
if ($audience === 'public') {
    flash('info', 'Sign in to open this ID.');
    // Prefer customer login for CT*, else merchant login
    if (preg_match('/^CT/i', $id)) {
        redirect('customer_login.php');
    }
    redirect('login.php');
}

flash('error', 'No page found for ID: ' . $id);
redirect($audience === 'admin' ? 'admin_dashboard.php' : ($audience === 'customer' ? 'customer_portal.php' : 'dashboard.php'));
