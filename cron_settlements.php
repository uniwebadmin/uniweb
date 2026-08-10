<?php
/**
 * Cron endpoint — run every 15 minutes on server
 * URL: /cron_settlements.php?key=YOUR_CRON_KEY
 */
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
$expected = getSettlementCronKey();
if ($key !== $expected && !isAdminLoggedIn()) {
    http_response_code(403);
    die('Forbidden');
}

try {
    $results = runScheduledSettlementBatches();
    logSettlementCronRun($results);
    recordCronHeartbeat('settlements', 'ok');

    echo 'Settlement cron ' . date('Y-m-d H:i:s') . "\n";
    echo 'Processed: ' . count($results) . " merchant batch(es)\n";
    foreach ($results as $r) {
        echo (!empty($r['ok']) ? 'OK' : 'FAIL') . ' — ' . ($r['message'] ?? $r['error'] ?? '—') . "\n";
    }

    if (function_exists('dispatchQueuedPayouts')) {
        $payout = dispatchQueuedPayouts(20);
        echo 'Payout dispatch: ' . ($payout['message'] ?? json_encode($payout)) . "\n";
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Settlement cron error ' . date('Y-m-d H:i:s') . "\n";
    echo $e->getMessage() . "\n";
}
