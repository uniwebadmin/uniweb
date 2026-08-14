<?php
declare(strict_types=1);

/**
 * Daily database backup cron.
 *
 * - Dumps the current DB to backups/uniweb_YYYYMMDD_His.sql.gz
 * - Uses mysqldump when exec() works; otherwise a PHP dump (Hostinger shared PHP)
 * - Keeps the last 7 days of backups
 * - Writes a .htaccess to block web access
 *
 * URL: /cron_db_backup.php?key=<watchdog_key>
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_backup.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $auth = validateCronRequest();
    if (empty($auth['ok'])) {
        rejectCronRequest($auth['error'] ?? 'Invalid key');
    }
}

set_time_limit(0);

$backupsDir = __DIR__ . '/backups';
if (!is_dir($backupsDir) && !@mkdir($backupsDir, 0750, true) && !is_dir($backupsDir)) {
    $error = 'Cannot create backups directory';
    if (function_exists('logPlatformError')) {
        logPlatformError('error', $error);
    }
    if (!$isCli) {
        sendCronJsonResponse(['ok' => false, 'error' => $error], 500);
    }
    echo $error . "\n";
    exit(1);
}

$htaccess = $backupsDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n\n<RequireAll>\n    Require all denied\n</RequireAll>\n");
}

$date = date('Ymd_His');
$dumpPath = $backupsDir . '/uniweb_' . $date . '.sql';
$gzPath = $dumpPath . '.gz';
$method = 'php';

if (!uniwebBackupExecDisabled()) {
    putenv('MYSQL_PWD=' . DB_PASS);
    $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
    $cmd = 'mysqldump -h ' . escapeshellarg(DB_HOST)
        . ' -P ' . escapeshellarg((string)$dbPort)
        . ' -u ' . escapeshellarg(DB_USER)
        . ' --single-transaction --quick --hex-blob --no-tablespaces '
        . escapeshellarg(DB_NAME)
        . ' > ' . escapeshellarg($dumpPath)
        . ' 2>/dev/null && gzip -f ' . escapeshellarg($dumpPath);
    $out = [];
    $rc = 1;
    exec($cmd, $out, $rc);
    putenv('MYSQL_PWD');
    if ($rc === 0 && is_file($gzPath) && filesize($gzPath) > 0) {
        $method = 'mysqldump';
    } else {
        @unlink($dumpPath);
        @unlink($gzPath);
    }
}

if ($method !== 'mysqldump') {
    $phpDump = uniwebPhpDumpDatabase($gzPath);
    if (empty($phpDump['ok'])) {
        $error = 'Database backup failed: ' . (string)($phpDump['error'] ?? 'unknown');
        if (function_exists('logPlatformError')) {
            logPlatformError('error', $error);
        }
        if (!$isCli) {
            sendCronJsonResponse(['ok' => false, 'error' => $error], 500);
        }
        echo $error . "\n";
        exit(1);
    }
}

foreach (glob($backupsDir . '/uniweb_*.sql.gz') ?: [] as $f) {
    if (filemtime($f) < time() - 7 * 86400) {
        @unlink($f);
    }
}

if (defined('DB_BACKUP_EMAIL') && filter_var(DB_BACKUP_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $backupEmail = DB_BACKUP_EMAIL;
} else {
    $backupEmail = getSetting('db_backup_email', getSetting('support_email', COMPANY_ADMIN_EMAIL));
}
$emailSent = false;
if ($backupEmail && filter_var($backupEmail, FILTER_VALIDATE_EMAIL) && function_exists('sendPlatformEmailWithAttachment')) {
    $subject = '[' . APP_NAME . '] DB backup ' . date('Y-m-d H:i:s');
    $body = "Automated database backup attached.\n\nFile: " . basename($gzPath) . "\nSize: " . number_format((int)filesize($gzPath)) . " bytes\n";
    $emailSent = sendPlatformEmailWithAttachment($backupEmail, $subject, $body, $gzPath);
}

$payload = [
    'ok' => true,
    'file' => basename($gzPath),
    'size' => (int)filesize($gzPath),
    'method' => $method,
    'email_sent' => $emailSent,
];

recordCronHeartbeat('db_backup', 'ok');

if (!$isCli) {
    sendCronJsonResponse($payload);
}
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
