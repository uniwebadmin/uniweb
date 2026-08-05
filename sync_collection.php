<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$remote = 'https://raw.githubusercontent.com/6396601005/uniweb/main/includes/collection.php';
$local = __DIR__ . '/includes/collection.php';

$ch = curl_init($remote);
if ($ch === false) {
    die('curl init failed');
}
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
$code = curl_exec($ch);
$err = curl_error($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code === false || $code === '') {
    die('download failed: ' . $err);
}
if ($http !== 200) {
    die('http status: ' . $http);
}

if (!file_put_contents($local, $code, LOCK_EX)) {
    die('write failed');
}

echo 'includes/collection.php synced from raw GitHub' . "\n";
echo 'http: ' . $http . ', size: ' . strlen($code) . "\n";
echo 'has duplicate fn: ' . (strpos($code, 'function getAmlHighValueThreshold') !== false ? 'yes' : 'no') . "\n";
