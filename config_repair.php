<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$live = __DIR__ . '/config.php';
if (!file_exists($live)) {
    die('config.php missing');
}

$src = file_get_contents($live);
$origLen = strlen($src);

// The corruption is a duplicate copy of includes/functions.php appended.
// Look for the second occurrence of a unique function signature.
$needle = 'function getPublicStats';
$first = strpos($src, $needle);
$second = $first !== false ? strpos($src, $needle, $first + strlen($needle)) : false;

if ($second === false) {
    echo "needle: $needle\n";
    echo 'count: ' . substr_count($src, $needle) . "\n";
    echo "no duplicate block found; leaving config.php unchanged\n";
    exit;
}

$clean = rtrim(substr($src, 0, $second));
if ($clean === '') {
    die('would truncate to nothing; aborting');
}

$backup = $live . '.bak.' . date('YmdHis');
if (!copy($live, $backup)) {
    die('backup failed');
}

if (file_put_contents($live, $clean . "\n", LOCK_EX) === false) {
    die('write failed');
}

echo 'config.php repaired: ' . $origLen . ' -> ' . strlen($clean) . " bytes\n";
echo 'backup: ' . $backup . "\n";
