<?php
/**
 * Versioned migration gate for releases.
 * URL: migrate_release.php?key=CRON_KEY
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
