<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$file = __DIR__ . '/config.php';
echo 'exists: ' . (file_exists($file) ? 'yes' : 'no') . "\n";
echo 'size: ' . (file_exists($file) ? filesize($file) : 'n/a') . "\n";
echo 'disable_functions: ' . (ini_get('disable_functions') ?: 'none') . "\n";

if (file_exists($file)) {
    $src = file_get_contents($file);
    // Mask string/number values to avoid leaking secrets while keeping structure
    $masked = preg_replace('/(["\'])(?:\\\\\1|.)*?\1/s', '$1$1', $src);
    $masked = preg_replace('/\b\d+\b/', '0', (string)$masked);
    $tail = substr((string)$masked, -2000);
    echo "\n--- config.php tail (masked) ---\n" . $tail . "\n--- end ---\n";
}
