<?php
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';
$applied = applyPendingMigrations(dirname(__DIR__) . '/migrations');
$files = $applied['applied_files'] ?? (array_is_list($applied) ? $applied : []);
fwrite(STDERR, 'applied: ' . (count($files) ? implode(', ', $files) : 'none (already up to date)') . PHP_EOL);
$n = count(getDB()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
fwrite(STDERR, 'tables: ' . $n . PHP_EOL);
fwrite(STDERR, 'APP_URL=' . APP_URL . ' DB=' . DB_NAME . PHP_EOL);
