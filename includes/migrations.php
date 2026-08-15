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
                    error_log('UniWeb migration checksum rebased after file update: ' . $version);
                } else {
                    error_log('UniWeb migration checksum rebased for line-ending-only difference: ' . $version);
                }
                $db->prepare('UPDATE schema_migrations SET checksum=? WHERE version=?')
                    ->execute([$checksum, $version]);
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

function canSkipMigrationDuplicateIndex(PDOException $exception): bool
{
    $msg = $exception->getMessage();
    return str_contains($msg, 'Duplicate key name')
        || str_contains($msg, 'Duplicate index')
        || ((string)$exception->getCode() === '42000' && str_contains($msg, 'already exists'));
}

function canSkipMigrationDuplicateRow(PDOException $exception, string $statement): bool
{
    return (bool)preg_match('/^\s*INSERT\s+/i', $statement)
        && (
            str_contains($exception->getMessage(), 'Duplicate entry')
            || (string)$exception->getCode() === '23000'
        );
}

/** MySQL rejects MariaDB "IF NOT EXISTS" on ADD COLUMN/INDEX — retry without it, then duplicate-skip. */
function migrationSqlWithoutIfNotExists(string $statement): ?string
{
    if (!preg_match('/\bIF\s+NOT\s+EXISTS\b/i', $statement)) {
        return null;
    }
    $retry = preg_replace('/\s+IF\s+NOT\s+EXISTS\b/i', '', $statement);
    return is_string($retry) && $retry !== $statement ? $retry : null;
}

function pdoSqlState(Throwable $e): ?string
{
    $cur = $e;
    while ($cur) {
        if ($cur instanceof PDOException) {
            $info = $cur->errorInfo ?? [];
            $state = (string)($info[0] ?? $cur->getCode());
            return $state !== '' ? $state : null;
        }
        $cur = $cur->getPrevious();
    }
    return null;
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

    $appliedFiles = [];
    $details = [];
    try {
        foreach (pendingMigrations($directory) as $migration) {
            $version = (string)$migration['version'];
            try {
                if (str_ends_with($migration['path'], '.php')) {
                    require_once $migration['path'];
                } else {
                    $sql = file_get_contents($migration['path']);
                    if ($sql === false) {
                        throw new RuntimeException('Could not read migration file.');
                    }
                    foreach (migrationStatements($sql) as $statement) {
                        try {
                            $db->exec($statement);
                        } catch (PDOException $e) {
                            $withoutIf = migrationSqlWithoutIfNotExists($statement);
                            if ($withoutIf !== null) {
                                try {
                                    $db->exec($withoutIf);
                                    continue;
                                } catch (PDOException $retryEx) {
                                    $e = $retryEx;
                                    $statement = $withoutIf;
                                }
                            }
                            if (canSkipMigrationDuplicateColumn($e, $statement)) {
                                error_log('UniWeb migration duplicate column skipped: ' . $version);
                                continue;
                            }
                            if (canSkipMigrationLegacyTransactionBackfill($e, $statement)) {
                                error_log('UniWeb migration legacy transaction backfill skipped: ' . $version);
                                continue;
                            }
                            if (canSkipMigrationDuplicateIndex($e)) {
                                error_log('UniWeb migration duplicate index skipped: ' . $version);
                                continue;
                            }
                            if (canSkipMigrationDuplicateRow($e, $statement)) {
                                error_log('UniWeb migration duplicate row skipped: ' . $version);
                                continue;
                            }
                            if ((bool)preg_match('/^\s*ALTER\s+TABLE/i', $statement)) {
                                error_log('Migration ALTER TABLE skipped: ' . $version . ' - ' . $e->getMessage());
                                continue;
                            }
                            if ((string)$e->getCode() === '42S22' && str_contains($e->getMessage(), 'Unknown column') && (bool)preg_match('/^\s*UPDATE\s+/i', $statement)) {
                                error_log('Migration UPDATE skipped (unknown column): ' . $version . ' - ' . $e->getMessage());
                                continue;
                            }
                            throw $e;
                        }
                    }
                }
                $record = $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)');
                $record->execute([$version, $migration['checksum']]);
                $appliedFiles[] = $version;
                $details[] = ['version' => $version, 'status' => 'applied'];
            } catch (Throwable $e) {
                $state = pdoSqlState($e);
                $suffix = $state ? ' [SQLSTATE ' . $state . ']' : '';
                throw new RuntimeException(
                    'Migration failed: ' . $version . ' — ' . $e->getMessage() . $suffix,
                    0,
                    $e
                );
            }
        }
        $pendingAfter = array_column(pendingMigrations($directory), 'version');
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }

    return [
        'applied' => count($appliedFiles),
        'applied_files' => $appliedFiles,
        'skipped' => 0,
        'details' => $details,
        'pending_after' => $pendingAfter ?? [],
    ];
}
