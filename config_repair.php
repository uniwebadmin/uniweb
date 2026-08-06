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

// Override any error_catcher handlers so we can see the real index.php exception
echo "\n=== index.php load ===\n";
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
    echo 'index.php loaded OK\n';
} catch (Throwable $e) {
    ob_end_clean();
    echo 'CATCH INDEX: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() . "\n";
}
ob_end_flush();
