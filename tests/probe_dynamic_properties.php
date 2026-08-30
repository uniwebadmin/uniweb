<?php
declare(strict_types=1);

/** Dev probe — scan app PHP for likely undeclared dynamic properties (PHP 8.2+). CLI only. */
$root = dirname(__DIR__);
$skipDirs = ['vendor', 'includes/phpqrcode', 'node_modules', 'tools', '.git'];
$issues = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    foreach ($skipDirs as $skip) {
        if (str_contains($path, '/' . $skip . '/')) {
            continue 2;
        }
    }
    $src = (string)file_get_contents($path);
    if (!preg_match('/class\s+(\w+)/', $src, $classMatch)) {
        continue;
    }
    $class = $classMatch[1];
    if (str_contains($src, '#[AllowDynamicProperties]') || str_contains($src, 'AllowDynamicProperties')) {
        continue;
    }
    preg_match_all('/(?:public|protected|private)\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)/', $src, $decl);
    $declProps = array_flip($decl[1] ?? []);
    // Constructor property promotion (PHP 8.0+)
    if (preg_match('/function\s+__construct\s*\(([\s\S]*?)\)/', $src, $ctor)) {
        preg_match_all('/(?:public|protected|private)\s+(?:readonly\s+)?(?:\??[\w\\\\|]+\s+)?\$(\w+)/', $ctor[1], $promoted);
        foreach ($promoted[1] ?? [] as $p) {
            $declProps[$p] = true;
        }
    }
    preg_match_all('/\$this->(\w+)\s*=/', $src, $sets);
    foreach (array_unique($sets[1] ?? []) as $prop) {
        if (!isset($declProps[$prop])) {
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $issues[] = [$class, $prop, str_replace('\\', '/', $rel)];
        }
    }
}

echo 'dynamic_property_candidates=' . count($issues) . PHP_EOL;
foreach ($issues as $row) {
    echo implode(' | ', $row) . PHP_EOL;
}
