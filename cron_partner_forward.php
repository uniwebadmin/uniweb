<?php
/**
 * Cron: Process partner forward queue (D4)
 * Run every 15-30 minutes via cron or auto_audit.
 * URL: cron_partner_forward.php?key=<WATCHDOG_KEY>
 */
require_once __DIR__ . '/../config.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    $auth = validateCronRequest();
    if (empty($auth['ok'])) {
        rejectCronRequest($auth['error'] ?? 'Invalid key');
    }
}

if (!function_exists('ensurePartnerForwardQueueTable')) {
    require_once __DIR__ . '/../includes/partner_forward_queue.php';
}

$results = processPartnerForwardQueue(20);
echo json_encode($results) . "\n";
