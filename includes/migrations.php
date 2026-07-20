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

function pendingMigrations(string $directory): array
{
    $db = getDB();
    ensureMigrationRegistry($db);
    $applied = [];
    foreach ($db->query('SELECT version, checksum FROM schema_migrations')->fetchAll() as $row) {
        $applied[(string)$row['version']] = (string)$row['checksum'];
    }

    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_STRING);
    $pending = [];
    foreach ($files as $file) {
        $version = basename($file);
        $checksum = hash_file('sha256', $file);
        if (isset($applied[$version])) {
            if (!hash_equals($applied[$version], $checksum)) {
                throw new RuntimeException('Applied migration checksum mismatch: ' . $version);
            }
            continue;
        }
        $pending[] = ['version' => $version, 'path' => $file, 'checksum' => $checksum];
    }
    return $pending;
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
            $sql = file_get_contents($migration['path']);
            if ($sql === false) {
                throw new RuntimeException('Could not read migration ' . $migration['version']);
            }
            foreach (migrationStatements($sql) as $statement) {
                $db->exec($statement);
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
