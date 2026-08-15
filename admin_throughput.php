<?php
/**
 * Throughput monitor merged into Transaction Monitor (Deep Audit DUP-04).
 * Keep this file so old bookmarks still open the live ops page.
 */
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);
$qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
redirect('admin_transaction_monitor.php' . $qs);
