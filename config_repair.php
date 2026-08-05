<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
if (!file_exists($live)) {
    die('config.php missing');
}

echo '=== load test ===' . "\n";
try {
    ob_start();
    require_once $live;
    ob_end_clean();
    echo 'config.php loads OK' . "\n";
} catch (Throwable $e) {
    @ob_end_clean();
    echo 'ERROR: ' . $e->getMessage() . "\n";
    echo 'FILE: ' . $e->getFile() . "\n";
    echo 'LINE: ' . $e->getLine() . "\n";
}

echo "\n=== duplicate scan ===\n";
$src = file_get_contents($live);
$origLen = strlen($src);
$needle = 'function getPublicStats';
$first = strpos($src, $needle);
$second = $first !== false ? strpos($src, $needle, $first + strlen($needle)) : false;
echo 'count: ' . substr_count($src, $needle) . "\n";
echo 'second at: ' . ($second !== false ? $second : 'n/a') . "\n";
