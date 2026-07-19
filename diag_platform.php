<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');
echo "Platform status diagnostic\n\n";

try {
    require_once __DIR__ . '/config.php';
    echo "config.php: OK\n";

    $healthFile = __DIR__ . '/includes/platform_health.php';
    if (!is_file($healthFile)) {
        throw new RuntimeException('Missing includes/platform_health.php on server');
    }
    require_once $healthFile;
    echo "platform_health.php: OK\n";

    $readiness = getPlatformReadiness();
    echo 'getPlatformReadiness: OK (' . $readiness['pct'] . "%)\n";

    $health = platformHealthSummary();
    echo 'platformHealthSummary: OK (' . $health['pct'] . "%)\n";
    echo 'services: ' . count($health['services']) . "\n";
} catch (Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
}
