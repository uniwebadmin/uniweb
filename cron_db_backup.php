<?php
declare(strict_types=1);

/**
 * Daily database backup cron.
 *
 * - Dumps the current DB to backups/uniweb_YYYYMMDD_His.sql.gz
 * - Keeps the last 7 days of backups
 * - Writes a .htaccess to block web access
 * - Emails the backup file to db_backup_email (fallback: support_email / admin email)
 *
 * Run from CLI (e.g. Hostinger Cron Jobs):
 *   php /home/your-account/domains/uniweb.co.in/public_html/cron_db_backup.php
 *
 * Run from browser (for testing only):
 *   /cron_db_backup.php?key=<watchdog_key>
 */

require_once __DIR__ . '/config.php';

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
    $error = 'Cannot create backups directory: ' . $backupsDir;
    if (function_exists('logPlatformError')) {
        logPlatformError('error', $error);
    } else {
        error_log($error);
    }
    if (!$isCli) {
        sendCronJsonResponse(['ok' => false, 'error' => $error], 500);
    }
    echo $error . "\n";
    exit(1);
}

// Prevent direct web access to backup files.
$htaccess = $backupsDir . '/.htaccess';
if (!is_file($htaccess)) {
    @file_put_contents($htaccess, "Order deny,allow\nDeny from all\n\n<RequireAll>\n    Require all denied\n</RequireAll>\n");
}

$date = date('Ymd_His');
$dumpPath = $backupsDir . '/uniweb_' . $date . '.sql';
$gzPath = $dumpPath . '.gz';

putenv('MYSQL_PWD=' . DB_PASS);

$dbPort = defined('DB_PORT') ? DB_PORT : '3306';

if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))), true)) {
    $error = 'exec() is disabled on this hosting — cannot run mysqldump. Use Hostinger Backups or phpMyAdmin export instead.';
    if (function_exists('logPlatformError')) {
        logPlatformError('warning', $error);
    } else {
        error_log($error);
    }
    if (!$isCli) {
        sendCronJsonResponse(['ok' => false, 'error' => $error], 200);
    }
    echo $error . "\n";
    exit(0);
}

$cmd = 'mysqldump -h ' . escapeshellarg(DB_HOST)
    . ' -P ' . escapeshellarg((string)$dbPort)
    . ' -u ' . escapeshellarg(DB_USER)
    . ' --single-transaction --quick --hex-blob --no-tablespaces '
    . escapeshellarg(DB_NAME)
    . ' > ' . escapeshellarg($dumpPath)
    . ' 2>/dev/null && gzip -f ' . escapeshellarg($dumpPath);

exec($cmd, $out, $rc);

if ($rc !== 0 || !is_file($gzPath) || filesize($gzPath) === 0) {
    if (is_file($dumpPath)) {
        @unlink($dumpPath);
    }
    if (is_file($gzPath)) {
        @unlink($gzPath);
    }
    $error = 'mysqldump/gzip failed. Exit code: ' . $rc;
    if ($out) {
        $error .= ' | ' . implode(' ', $out);
    }
    if (function_exists('logPlatformError')) {
        logPlatformError('error', $error);
    } else {
        error_log($error);
    }
    if (!$isCli) {
        sendCronJsonResponse(['ok' => false, 'error' => $error], 500);
    }
    echo $error . "\n";
    exit(1);
}

// Retention: delete backups older than 7 days.
foreach (glob($backupsDir . '/*.sql.gz') as $f) {
    if (filemtime($f) < time() - 7 * 86400) {
        @unlink($f);
    }
}

// Off-site copy by email. Hard-code address in config.php with DB_BACKUP_EMAIL.
if (defined('DB_BACKUP_EMAIL') && filter_var(DB_BACKUP_EMAIL, FILTER_VALIDATE_EMAIL)) {
    $backupEmail = DB_BACKUP_EMAIL;
} else {
    $backupEmail = getSetting('db_backup_email', getSetting('support_email', COMPANY_ADMIN_EMAIL));
}
$emailSent = false;
if ($backupEmail && filter_var($backupEmail, FILTER_VALIDATE_EMAIL)) {
    $subject = '[' . APP_NAME . '] DB backup ' . date('Y-m-d H:i:s');
    $body = "Automated database backup attached.\n\nFile: " . basename($gzPath) . "\nSize: " . number_format(filesize($gzPath)) . " bytes\n";
    if (function_exists('sendPlatformEmailWithAttachment')) {
        $emailSent = sendPlatformEmailWithAttachment($backupEmail, $subject, $body, $gzPath);
    } else {
        $error = 'sendPlatformEmailWithAttachment is not available';
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', $error);
        } else {
            error_log($error);
        }
    }
    if (!$emailSent) {
        if (function_exists('logPlatformError')) {
            logPlatformError('warning', 'DB backup email could not be sent to ' . $backupEmail);
        } else {
            error_log('DB backup email could not be sent to ' . $backupEmail);
        }
    }
} else {
    if (function_exists('logPlatformError')) {
        logPlatformError('warning', 'DB backup email not configured (db_backup_email / support_email)');
    } else {
        error_log('DB backup email not configured (db_backup_email / support_email)');
    }
}

$payload = [
    'ok' => true,
    'file' => $gzPath,
    'size' => filesize($gzPath),
    'email' => $backupEmail,
    'email_sent' => $emailSent,
];

if (!$isCli) {
    sendCronJsonResponse($payload);
}
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
