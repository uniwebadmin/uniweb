<?php
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/..')) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $p = $f->getPathname();
    if (strpos($p, 'phpqrcode') !== false || strpos($p, 'vendor') !== false) continue;
    $out = shell_exec('php -l ' . escapeshellarg($p) . ' 2>&1');
    if (strpos($out, 'No syntax errors') === false) {
        echo $p . PHP_EOL . $out . PHP_EOL;
    }
}
echo "Lint complete." . PHP_EOL;
