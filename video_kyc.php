<?php
require_once __DIR__ . '/config.php';
requireLogin();
ensureKycSchema();
require_once __DIR__ . '/includes/kyc_upload.php';
$merchant = getMerchant();
$db = getDB();

// Video KYC now lives inline inside the main KYC Verification page.
redirect('kyc.php?section=video');
