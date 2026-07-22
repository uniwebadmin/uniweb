<?php
/**
 * Versioned migration gate for releases.
 *
 * URL: migrate_release.php?key=YOUR_EXISTING_WATCHDOG_KEY
 *
 * Use the same key as cron_auto_audit.php (Gateway Settings → “Test cron now”).
 * Do not invent a new CRON_KEY. See migrations/README.md for owner steps (011–017).
 */
require_once __DIR__ . '/config.php';

$auth = validateCronRequest();
if (empty($auth['ok'])) {
    rejectCronRequest($auth['error'] ?? 'Invalid key');
}

header('Content-Type: application/json');
try {
    $applied = applyPendingMigrations(__DIR__ . '/migrations');
    if (function_exists('backfillLegacyWalletOpeningBalances')) {
        try {
            backfillLegacyWalletOpeningBalances();
        } catch (Throwable $e) {
            // Non-fatal if already applied / tables not ready for backfill.
        }
    }
    echo json_encode([
        'ok' => true,
        'applied' => $applied,
        'pending_after' => array_column(pendingMigrations(__DIR__ . '/migrations'), 'version'),
        'ran_at' => date('c'),
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
