<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
$dev  = __DIR__ . '/config.dev.php';
if (!file_exists($live) || !file_exists($dev)) {
    die('missing file');
}

$liveC = file_get_contents($live);
$devC = file_get_contents($dev);
$liveLen = strlen($liveC);
$devLen = strlen($devC);

$max = min($liveLen, $devLen);
$divFromStart = -1;
for ($i = 0; $i < $max; $i++) {
    if ($liveC[$i] !== $devC[$i]) {
        $divFromStart = $i;
        break;
    }
}

$marker = 'unset($__includes, $__inc, $__path, $__loaded);';
$pos = strrpos($liveC, $marker);
$realEnd = ($pos !== false) ? ($pos + strlen($marker)) : 'NOT FOUND';

echo 'live size: ' . $liveLen . "\n";
echo 'dev  size: ' . $devLen . "\n";
echo 'marker found at: ' . ($pos !== false ? $pos : 'not found') . "\n";
echo 'real config ends at: ' . $realEnd . "\n";

if ($pos !== false) {
    $extra = substr($liveC, $pos + strlen($marker));
    $masked = preg_replace('/(["\'])(?:\\\\\1|.)*?\1/s', '$1$1', $extra);
    $masked = preg_replace('/\b\d+\b/', '0', (string)$masked);
    echo "\n--- live appended extra (masked) ---\n" . $masked . "\n--- end ---\n";
} else {
    echo "marker not found\n";
}
