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

$commonFromEnd = 0;
for ($i = 1; $i <= $max; $i++) {
    if ($liveC[$liveLen - $i] !== $devC[$devLen - $i]) {
        break;
    }
    $commonFromEnd = $i;
}

$extraStart = $liveLen - ($liveLen - $devLen + $commonFromEnd); // approximate

echo 'live size: ' . $liveLen . "\n";
echo 'dev  size: ' . $devLen . "\n";
echo 'diverge from start: ' . $divFromStart . "\n";
echo 'common from end: ' . $commonFromEnd . "\n";
echo 'extra bytes block starts around: ' . $extraStart . "\n";

if ($extraStart < $liveLen && $extraStart >= 0) {
    $extra = substr($liveC, $extraStart);
    $masked = preg_replace('/(["\'])(?:\\\\\1|.)*?\1/s', '$1$1', $extra);
    $masked = preg_replace('/\b\d+\b/', '0', (string)$masked);
    echo "\n--- live extra tail (masked) ---\n" . $masked . "\n--- end ---\n";
}
