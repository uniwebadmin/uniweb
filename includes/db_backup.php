<?php
declare(strict_types=1);

/**
 * Hostinger-safe database dump. mysqldump/exec is often disabled on shared PHP.
 */

function uniwebBackupExecDisabled(): bool
{
    if (!function_exists('exec')) {
        return true;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return in_array('exec', $disabled, true);
}

function uniwebDefaultBackupEmail(): string
{
    return 'startelecom620@gmail.com';
}

function uniwebResolveBackupEmail(): string
{
    if (defined('DB_BACKUP_EMAIL') && filter_var(DB_BACKUP_EMAIL, FILTER_VALIDATE_EMAIL)) {
        return (string)DB_BACKUP_EMAIL;
    }
    $stored = trim((string)getSetting('db_backup_email', ''));
    if (filter_var($stored, FILTER_VALIDATE_EMAIL)) {
        return $stored;
    }
    $fallback = uniwebDefaultBackupEmail();
    if (function_exists('saveAutoAuditMeta')) {
        saveAutoAuditMeta('db_backup_email', $fallback);
    }
    return $fallback;
}

/**
 * @return array{sent:bool,attached:bool,error:string}
 */
function uniwebSendBackupEmail(string $to, string $gzPath): array
{
    $result = ['sent' => false, 'attached' => false, 'error' => ''];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $result['error'] = 'Backup email address is missing.';
        return $result;
    }
    $size = is_file($gzPath) ? (int)filesize($gzPath) : 0;
    $fileName = basename($gzPath);
    $maxAttach = 12 * 1024 * 1024;
    $subject = '[' . APP_NAME . '] Database backup ' . date('Y-m-d H:i');
    $body = "UniWeb database backup finished.\n\n"
        . "File: {$fileName}\n"
        . "Size: " . number_format($size) . " bytes\n"
        . "This email is the database copy only.\n"
        . "Full website restore = Hostinger → Files → Backups (turn that ON).\n"
        . "Gmail cannot hold the whole website.\n";

    if ($size > 0 && $size <= $maxAttach && function_exists('sendPlatformEmailWithAttachment')) {
        $result['sent'] = sendPlatformEmailWithAttachment($to, $subject, $body, $gzPath);
        $result['attached'] = $result['sent'];
    }
    if (!$result['sent'] && function_exists('sendPlatformEmail')) {
        $note = $size > $maxAttach
            ? $body . "\nAttachment skipped (file too large for Gmail). File is saved on the server.\n"
            : $body . "\nCould not attach the file. File is saved on the server. Set SMTP in Gateway Settings if this email also fails.\n";
        $result['sent'] = sendPlatformEmail($to, $subject, $note);
    }
    if (!$result['sent']) {
        $smtp = trim((string)getSetting('smtp_host', ''));
        $result['error'] = $smtp === ''
            ? 'Email did not send. Set SMTP in Gateway Settings (Hostinger mailbox), then run backup again. Also check Spam.'
            : 'Email did not send. Check SMTP username/password in Gateway Settings, then check Spam.';
    }
    return $result;
}

function uniwebSqlLiteral(PDO $db, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }
    $raw = (string)$value;
    if ($raw !== '' && (!mb_check_encoding($raw, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $raw))) {
        return '0x' . bin2hex($raw);
    }
    return $db->quote($raw);
}

/**
 * @return array{ok:bool,error?:string,tables?:int,rows?:int,bytes?:int,method?:string}
 */
function uniwebPhpDumpDatabase(string $gzPath): array
{
    $db = getDB();
    $gz = gzopen($gzPath, 'wb9');
    if ($gz === false) {
        return ['ok' => false, 'error' => 'Cannot write backup file'];
    }

    $write = static function (string $chunk) use ($gz): void {
        gzwrite($gz, $chunk);
    };

    $write('-- UniWeb PHP dump ' . date('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = 0;
    $rows = 0;
    try {
        foreach ($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $t) {
            $name = (string)$t[0];
            if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                continue;
            }
            $createRow = $db->query('SHOW CREATE TABLE `' . $name . '`')->fetch(PDO::FETCH_ASSOC);
            if (!$createRow) {
                continue;
            }
            $ddl = (string)($createRow['Create Table'] ?? $createRow['Create View'] ?? '');
            if ($ddl === '') {
                continue;
            }
            $isView = isset($createRow['Create View']);
            if ($isView) {
                $write("DROP VIEW IF EXISTS `{$name}`;\n{$ddl};\n\n");
                $tables++;
                continue;
            }
            $write("DROP TABLE IF EXISTS `{$name}`;\n{$ddl};\n\n");
            $tables++;

            $offset = 0;
            $batch = 200;
            while (true) {
                $chunk = $db->query("SELECT * FROM `{$name}` LIMIT {$batch} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
                if (!$chunk) {
                    break;
                }
                foreach ($chunk as $row) {
                    $cols = [];
                    $vals = [];
                    foreach ($row as $col => $val) {
                        $cols[] = '`' . str_replace('`', '``', (string)$col) . '`';
                        $vals[] = uniwebSqlLiteral($db, $val);
                    }
                    $write('INSERT INTO `' . $name . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ");\n");
                    $rows++;
                }
                $offset += $batch;
                if (count($chunk) < $batch) {
                    break;
                }
            }
            $write("\n");
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
    } catch (Throwable $e) {
        gzclose($gz);
        @unlink($gzPath);
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    gzclose($gz);
    if (!is_file($gzPath) || filesize($gzPath) === 0) {
        return ['ok' => false, 'error' => 'Backup file was empty'];
    }

    return [
        'ok' => true,
        'tables' => $tables,
        'rows' => $rows,
        'bytes' => (int)filesize($gzPath),
        'method' => 'php',
    ];
}
