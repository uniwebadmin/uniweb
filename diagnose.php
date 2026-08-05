<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$root = __DIR__;
$dirs = ['', 'includes', 'migrations', 'lang', 'lib', 'plugins'];
$out = [];
foreach ($dirs as $d) {
    $p = $root . ($d ? '/' . $d : '');
    if (!is_dir($p)) {
        $out[$d] = ['missing' => true, 'files' => []];
        continue;
    }
    $files = array_values(array_filter(scandir($p), function ($f) { return $f[0] !== '.'; }));
    $out[$d] = ['count' => count($files), 'files' => $files];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
