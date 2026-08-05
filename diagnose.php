<?php
declare(strict_types=1);

try {
    require_once __DIR__ . '/config.php';
    echo "config OK\n";
    if (function_exists('getRecentPlatformErrors')) {
        $errors = getRecentPlatformErrors(10, false);
        echo json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "getRecentPlatformErrors missing\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "FILE: " . $e->getFile() . "\n";
    echo "LINE: " . $e->getLine() . "\n";
    echo "TRACE: " . $e->getTraceAsString() . "\n";
}
