<?php
/**
 * Apply pending database migrations using the existing watchdog/cron key.
 *
 * Usage: migrate_release.php?key=YOUR_EXISTING_WATCHDOG_KEY
 * Safe to re-run — only applies migrations not yet recorded in _migrations_applied.
 * See migrations/README.md for details. Do not invent a new key — use the same
 * cron watchdog key that cron_auto_audit.php uses.
 */

// CLI: no key needed
$isCli = PHP_SAPI === 'cli';
$key = $_GET['key'] ?? ($argv[1] ?? '');
$keyArg = '';
if (strpos($key, 'key=') === 0) {
    $keyArg = substr($key, 4);
} else {
    $keyArg = $key;
}

if (!$isCli) {
    // Web access: require valid cron key
    require_once __DIR__ . '/config.php';
    $expectedKey = autoAuditWatchdogKey();
    if ($keyArg !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid key']);
        exit;
    }
} else {
    // CLI: load config
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/includes/migrations.php';

header('Content-Type: application/json');

try {
    $result = applyPendingMigrations();
    echo json_encode([
        'ok' => true,
        'applied' => $result['applied'] ?? 0,
        'skipped' => $result['skipped'] ?? 0,
        'details' => $result['details'] ?? [],
        'message' => ($result['applied'] ?? 0) > 0
            ? 'Migrations applied successfully.'
            : 'No pending migrations — all up to date.',
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'message' => 'Migration failed. Check error log.',
    ], JSON_PRETTY_PRINT);
}
