<?php
/**
 * Cron morning ops — fixes demo, verified merchants, link scan, logs health.
 * URL: morning_ops.php?key=SAME_AS_platform_watchdog
 */
require_once __DIR__ . '/config.php';

$auth = validateCronRequest();
if (empty($auth['ok'])) {
    rejectCronRequest($auth['error'] ?? 'Invalid key');
}

$ops = runMorningPlatformOps();
if (function_exists('runPlatformWatchdog')) {
    runPlatformWatchdog();
}

sendCronJsonResponse([
    'ok' => true,
    'ran_at' => date('Y-m-d H:i:s'),
    'ops' => $ops,
]);
