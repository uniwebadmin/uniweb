<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
if (!file_exists($live)) {
    die('config.php missing');
}

$buffer = [];
function logRepair(string $s): void {
    global $buffer;
    $buffer[] = $s;
    echo $s . "\n";
    if (ob_get_level()) { ob_flush(); }
    flush();
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err) {
        echo 'SHUTDOWN_ERROR: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] . "\n";
    }
});

set_error_handler(function ($s, $m, $f, $l) {
    echo "ERROR[$s]: $m in $f:$l\n";
    return false;
});

set_exception_handler(function (Throwable $e) {
    echo 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
});

echo "=== load test ===\n";
try {
    require_once $live;
    echo "config.php loads OK\n";
} catch (Throwable $e) {
    echo 'CATCH: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}

echo "\n=== duplicate scan ===\n";
$src = file_get_contents($live);
$origLen = strlen($src);
$needle = 'function getPublicStats';
$first = strpos($src, $needle);
$second = $first !== false ? strpos($src, $needle, $first + strlen($needle)) : false;
echo 'count: ' . substr_count($src, $needle) . "\n";
echo 'second at: ' . ($second !== false ? $second : 'n/a') . "\n";
