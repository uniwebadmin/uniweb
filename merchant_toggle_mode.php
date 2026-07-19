<?php
require_once __DIR__ . '/config.php';
requireLogin();

if (!verifyCsrf($_GET['csrf'] ?? $_POST['csrf_token'] ?? '')) {
    flash('error', 'Invalid request.');
    redirect('dashboard.php');
}

$mode = trim($_GET['mode'] ?? $_POST['mode'] ?? '');
if (!in_array($mode, ['test', 'live'], true)) {
    redirect('dashboard.php');
}

$merchant = getMerchant();
if (!$merchant) {
    redirect('login.php');
}

$before = getDashboardViewMode($merchant);
setDashboardViewMode($merchant, $mode);
$merchant = getMerchant();
$after = getDashboardViewMode($merchant);

if ($mode === 'live' && $after !== 'live') {
    if (($merchant['kyc_status'] ?? '') !== 'verified') {
        flash('error', 'Live Mode needs KYC approval. Stay in Test Mode until admin verifies your account.');
    } elseif (!isMerchantLive($merchant)) {
        flash('error', 'Live Mode needs admin activation. Your KYC is verified — we will enable Live after review.');
    } else {
        flash('error', 'Could not switch to Live Mode. Try again or contact support.');
    }
} elseif ($mode === 'test' && $after === 'test' && $before !== 'test') {
    flash('success', 'Test Mode enabled — sandbox only. No real money will be collected.');
} elseif ($mode === 'live' && $after === 'live') {
    flash('success', 'Live Mode enabled — real payments are active.');
} elseif ($mode === 'test') {
    flash('success', 'Test Mode enabled — sandbox only. No real money will be collected.');
}

$return = basename(trim($_GET['return'] ?? $_POST['return'] ?? 'dashboard.php'));
if (!preg_match('/^[a-z0-9_.-]+$/i', $return) || !is_file(__DIR__ . '/' . $return)) {
    $return = 'dashboard.php';
}

redirect($return);
