<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
if (!file_exists($live)) {
    die('config.php missing');
}

$code = file_get_contents($live);
echo "Original first 200 chars:\n" . substr($code, 0, 200) . "\n\n";

// Step 1: Remove any DB_PORT define that comes before declare(strict_types=1)
// Look for the pattern: <?php\n define('DB_PORT'...;\n declare(strict_types=1)
$lines = explode("\n", $code);
$fixed_lines = [];
$skipped_db_port = false;
foreach ($lines as $i => $line) {
    if (trim($line) === "declare(strict_types=1);" && $skipped_db_port === false) {
        // Check if previous non-empty line was a DB_PORT define
        $fixed_lines[] = $line;
        continue;
    }
    if (strpos($line, "define('DB_PORT'") !== false && $i < 5) {
        // Skip this line - it's the misplaced DB_PORT define
        echo "Skipping misplaced line $i: $line\n";
        $skipped_db_port = true;
        continue;
    }
    $fixed_lines[] = $line;
}
$code = implode("\n", $fixed_lines);

// Step 2: If DB_PORT is not defined anywhere in the file, add it after declare(strict_types=1)
if (strpos($code, "define('DB_PORT'") === false) {
    $code = str_replace(
        "declare(strict_types=1);",
        "declare(strict_types=1);\ndefine('DB_PORT', getenv('DB_PORT') !== false ? getenv('DB_PORT') : '3306');",
        $code,
    );
    echo "Added DB_PORT define after declare(strict_types=1)\n";
} else {
    echo "DB_PORT define already exists in file\n";
}

// Write fixed config.php
$backup = __DIR__ . '/config.php.bak.' . date('YmdHis');
file_put_contents($backup, file_get_contents($live));
file_put_contents($live, $code, LOCK_EX);
echo "Wrote backup to $backup\n";
echo "Fixed config.php written\n\n";

if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "Opcache reset\n";
}

// Step 3: Test load
echo "=== Load test ===\n";
try {
    require_once $live;
    echo "config.php loads OK\n";
    echo "DB_PORT = " . (defined('DB_PORT') ? DB_PORT : 'NOT DEFINED') . "\n";
} catch (Throwable $e) {
    echo 'CATCH: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

// Step 4: Test index.php
echo "\n=== Index.php load ===\n";
set_error_handler(function ($s, $m, $f, $l) {
    echo "ERROR[$s]: $m in $f:$l\n";
    return true;
});
set_exception_handler(function (Throwable $e) {
    echo 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
});

ob_start();
try {
    require_once __DIR__ . '/index.php';
    echo "index.php loaded OK\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo 'CATCH INDEX: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
ob_end_flush();
