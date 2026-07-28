<?php
require __DIR__ . '/../config.php';
$db = getDB();
try {
    $rows = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    echo "Applied migrations:\n" . implode("\n", $rows) . "\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
