<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/config.php';
echo 'exists: ' . (file_exists($file) ? 'yes' : 'no') . "\n";
echo 'size: ' . (file_exists($file) ? filesize($file) : 'n/a') . "\n";

if (function_exists('shell_exec')) {
    $lint = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
    echo "lint: " . trim((string)$lint) . "\n";
}
