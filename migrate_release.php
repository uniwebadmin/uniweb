<?php
/**
 * Apply pending database migrations using the existing watchdog/cron key.
 *
 * Usage: migrate_release.php?key=YOUR_EXISTING_WATCHDOG_KEY
 * Safe to re-run — only applies migrations not yet recorded in schema_migrations.
 * See migrations/README.md for details. Do not invent a new key — use the same
 * cron watchdog key that cron_auto_audit.php uses.
 */

$isCli = PHP_SAPI === 'cli';
$key = $_GET['key'] ?? $_POST['key'] ?? ($argv[1] ?? '');
$keyArg = '';
if (strpos($key, 'key=') === 0) {
    $keyArg = substr($key, 4);
} else {
    $keyArg = $key;
}

if (!$isCli) {
    require_once __DIR__ . '/config.php';
    $expectedKey = autoAuditWatchdogKey();
    if ($keyArg !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid key', 'message' => 'Watchdog key did not match. Use the same key as cron auto-audit.']);
        exit;
    }
} else {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/includes/migrations.php';

if (!$isCli && !headers_sent()) {
    header('Content-Type: application/json');
}

try {
    $result = applyPendingMigrations(__DIR__ . '/migrations');
    $appliedFiles = $result['applied_files'] ?? [];
    $appliedCount = (int)($result['applied'] ?? count($appliedFiles));
    echo json_encode([
        'ok' => true,
        'applied' => $appliedCount,
        'applied_files' => $appliedFiles,
        'skipped' => (int)($result['skipped'] ?? 0),
        'details' => $result['details'] ?? [],
        'pending_after' => $result['pending_after'] ?? [],
        'ran_at' => date('Y-m-d H:i:s'),
        'message' => $appliedCount > 0
            ? ('Applied ' . $appliedCount . ' migration(s).')
            : 'No pending migrations — all up to date.',
    ], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    if (!$isCli && !headers_sent()) {
        http_response_code(500);
    }
    $msg = $e->getMessage();
    $file = null;
    if (preg_match('/Migration failed:\s*(\S+)/', $msg, $m)) {
        $file = $m[1];
    }
    $isConn = str_contains($msg, '[2002]')
        || str_contains($msg, '[2006]')
        || stripos($msg, 'connection refused') !== false
        || stripos($msg, 'getaddrinfo') !== false;
    echo json_encode([
        'ok' => false,
        'migration' => $file,
        'error' => $msg,
        'message' => $isConn
            ? 'Database is not running or not reachable. Start MariaDB / MySQL, then open this same link again. Do not drop the database.'
            : ($file
                ? ('Migration ' . $file . ' failed. SQL/error is in "error". Do not drop the database — re-run after the file is fixed.')
                : 'Migration failed. See "error" for the SQL message. Do not drop the database.'),
    ], JSON_PRETTY_PRINT);
}
