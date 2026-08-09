<?php
/**
 * Cron: Process partner forward queue (D4)
 * Run every 15-30 minutes via cron or auto_audit.
 */
require_once __DIR__ . '/../config.php';
if (!function_exists('ensurePartnerForwardQueueTable')) {
    require_once __DIR__ . '/../includes/partner_forward_queue.php';
}

$results = processPartnerForwardQueue(20);
echo json_encode($results) . "\n";
