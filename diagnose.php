<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
$dev  = __DIR__ . '/config.dev.php';
echo 'live size: ' . (file_exists($live) ? filesize($live) : 'n/a') . "\n";
echo 'dev  size: ' . (file_exists($dev) ? filesize($dev) : 'n/a') . "\n";

if (!file_exists($live) || !file_exists($dev)) {
    die('missing file');
}

$liveC = file_get_contents($live);
$devC = file_get_contents($dev);
$max = min(strlen($liveC), strlen($devC));
$div = -1;
for ($i = 0; $i < $max; $i++) {
    if ($liveC[$i] !== $devC[$i]) {
        $div = $i;
        break;
    }
}
$extra = strlen($liveC) - strlen($devC);
echo 'diverge at byte: ' . $div . "\n";
echo 'live extra bytes: ' . $extra . "\n";
if ($div > 0) {
    echo "\n--- dev around diverge ---\n" . substr($devC, max(0, $div - 200), 400) . "\n";
    echo "\n--- live around diverge ---\n" . substr($liveC, max(0, $div - 200), 400) . "\n";
}
