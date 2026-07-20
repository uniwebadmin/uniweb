<?php
declare(strict_types=1);

// Backward-compatible alias — .htaccess now prefers error.php for all ErrorDocuments.
$_GET['code'] = (string)(int)($_GET['code'] ?? 404);
if ((int)$_GET['code'] < 400) {
    $_GET['code'] = '404';
}
require __DIR__ . '/error.php';
