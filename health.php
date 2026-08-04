<?php
declare(strict_types=1);

/**
 * Public health-check endpoint for external uptime monitors (UptimeRobot, etc.).
 * Returns plain "OK" if the app and database are reachable; otherwise HTTP 503.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    $db = getDB();
    $db->query('SELECT 1');
    echo 'OK';
} catch (Throwable $e) {
    http_response_code(503);
    echo 'ERROR: ' . $e->getMessage();
}
