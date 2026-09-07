<?php
/**
 * Standalone KYC forward queue — primary Owner path is admin_kyc.php?tab=forward (KYC Ops hub).
 */
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'kyc']);
require_once __DIR__ . '/includes/kyc_ops.php';

$redirect = kycOpsForwardHandlePost(true);
if ($redirect !== null) {
    redirect($redirect);
}

$ctx = kycOpsForwardLoadContext();
extract($ctx, EXTR_SKIP);
$kycForwardPanelStandalone = true;
$kycForwardPanelPostUrl = 'admin_forward_queue.php';

$pageTitle = 'KYC Forward Queue';
require_once __DIR__ . '/header.php';
require __DIR__ . '/includes/admin_kyc_forward_panel.php';
require_once __DIR__ . '/footer.php';
