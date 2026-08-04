<?php
declare(strict_types=1);

function ensureMigrationRegistry(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(100) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function migrationStatements(string $sql): array
{
    // Strip accidental PHP open tags (broken overnight files) and SQL line comments.
    $sql = preg_replace('/^\s*<\?php\s*/i', '', $sql) ?? $sql;
    $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
    $cleaned = [];
    foreach ($lines as $line) {
        $trimmed = ltrim($line);
        if (str_starts_with($trimmed, '--')) {
            continue;
        }
        $cleaned[] = $line;
    }
    $sql = implode("\n", $cleaned);

    $statements = [];
    $buffer = '';
    $quoted = false;
    $quote = '';
    $escaped = false;
    $length = strlen($sql);
    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        if ($escaped) {
            $buffer .= $char;
            $escaped = false;
            continue;
        }
        if ($quoted && $char === '\\') {
            $buffer .= $char;
            $escaped = true;
            continue;
        }
        if ($char === "'" || $char === '"') {
            if (!$quoted) {
                $quoted = true;
                $quote = $char;
            } elseif ($quote === $char) {
                $quoted = false;
                $quote = '';
            }
            $buffer .= $char;
            continue;
        }
        if (!$quoted && $char === ';') {
            if (trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }
    if (trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }
    return $statements;
}

function migrationEolChecksumVariants(string $file): array
{
    $contents = file_get_contents($file);
    if ($contents === false) {
        throw new RuntimeException('Could not read migration file: ' . basename($file));
    }
    $lf = preg_replace("/\r\n|\r/", "\n", $contents) ?? $contents;
    return array_values(array_unique([
        hash('sha256', $lf),
        hash('sha256', str_replace("\n", "\r\n", $lf)),
    ]));
}

function pendingMigrations(string $directory): array
{
    $db = getDB();
    ensureMigrationRegistry($db);
    $applied = [];
    foreach ($db->query('SELECT version, checksum FROM schema_migrations')->fetchAll() as $row) {
        $applied[(string)$row['version']] = (string)$row['checksum'];
    }

    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.{sql,php}', GLOB_BRACE) ?: [];
    sort($files, SORT_STRING);
    $pending = [];
    foreach ($files as $file) {
        $version = basename($file);
        $checksum = hash_file('sha256', $file);
        if (isset($applied[$version])) {
            if (!hash_equals($applied[$version], $checksum)) {
                if (!in_array($applied[$version], migrationEolChecksumVariants($file), true)) {
                    throw new RuntimeException('Applied migration checksum mismatch: ' . $version);
                }
                $db->prepare('UPDATE schema_migrations SET checksum=? WHERE version=? AND checksum=?')
                    ->execute([$checksum, $version, $applied[$version]]);
                error_log('UniWeb migration checksum rebased for line-ending-only difference: ' . $version);
            }
            continue;
        }
        $pending[] = ['version' => $version, 'path' => $file, 'checksum' => $checksum];
    }
    return $pending;
}

function canSkipMigrationDuplicateColumn(PDOException $exception, string $statement): bool
{
    return (string)$exception->getCode() === '42S21'
        && str_contains($exception->getMessage(), 'Duplicate column name')
        && (bool)preg_match('/^\s*ALTER\s+TABLE\s+[`a-zA-Z0-9_]+\s+ADD\s+COLUMN\s+/i', $statement);
}

function canSkipMigrationLegacyTransactionBackfill(PDOException $exception, string $statement): bool
{
    return (string)$exception->getCode() === '42S22'
        && str_contains($exception->getMessage(), "Unknown column 'transaction_id'")
        && (bool)preg_match('/^\s*UPDATE\s+transactions\s+SET\s+txn_id\s*=\s*transaction_id\s+WHERE\s+txn_id\s+IS\s+NULL\s+AND\s+transaction_id\s+IS\s+NOT\s+NULL\s*$/i', $statement);
}

function applyPendingMigrations(string $directory): array
{
    $db = getDB();
    $lockName = 'uniweb_schema_migrations';
    $lock = $db->prepare('SELECT GET_LOCK(?, 15)');
    $lock->execute([$lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Could not acquire migration lock.');
    }

    $applied = [];
    try {
        foreach (pendingMigrations($directory) as $migration) {
            if (str_ends_with($migration['path'], '.php')) {
                try {
                    require_once $migration['path'];
                } catch (Throwable $e) {
                    error_log('PHP migration failed: ' . $migration['version'] . ' - ' . $e->getMessage());
                    continue;
                }
            } else {
                $sql = file_get_contents($migration['path']);
                if ($sql === false) {
                    throw new RuntimeException('Could not read migration ' . $migration['version']);
                }
                foreach (migrationStatements($sql) as $statement) {
                    try {
                        $db->exec($statement);
                    } catch (PDOException $e) {
                        if (canSkipMigrationDuplicateColumn($e, $statement)) {
                            error_log('UniWeb migration duplicate column skipped: ' . $migration['version']);
                            continue;
                        }
                        if (canSkipMigrationLegacyTransactionBackfill($e, $statement)) {
                            error_log('UniWeb migration legacy transaction backfill skipped: ' . $migration['version']);
                            continue;
                        }
                        throw $e;
                    }
                }
            }
            $record = $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
            $record->execute([$migration['version'], $migration['checksum']]);
            $applied[] = $migration['version'];
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }
    return $applied;
}
