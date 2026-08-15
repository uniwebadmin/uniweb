<?php
declare(strict_types=1);

/**
 * Public health-check for uptime monitors. Fast path: no heavy schema work.
 * Returns plain "OK" or HTTP 503.
 */

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

try {
    require_once __DIR__ . '/config.php';
    getDB()->query('SELECT 1');
    echo 'OK';
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
    }
    echo 'ERROR';
}
