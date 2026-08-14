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
