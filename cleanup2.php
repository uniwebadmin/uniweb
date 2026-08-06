<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/plain; charset=utf-8');

echo "=== UniWeb Live Cleanup Round 2 ===\n\n";

$base = __DIR__;

$junkFiles = [
    'config_repair.php',
    'fix_config.php',
    'diagnose.php',
    'sync_collection.php',
    'diag.php',
    'wallet_diagnose.php',
    'morning_ops.php',
    'update_axis_keys.php',
    'update_mdr.php',
    'merchant_launch_test.php',
    'migrate_release.php',
    'platform_demo.php',
    'cleanup2.php',
];

$deleted = 0;
$failed = 0;

foreach ($junkFiles as $f) {
    $path = $base . '/' . $f;
    if (file_exists($path)) {
        $size = filesize($path);
        if (unlink($path)) {
            echo "DELETED: $f ($size bytes)\n";
            $deleted++;
        } else {
            echo "FAILED: $f\n";
            $failed++;
        }
    } else {
        echo "NOT FOUND: $f\n";
    }
}

// config.php.bak.* files
echo "\n--- Backup files ---\n";
$baks = glob($base . '/config.php.bak.*');
if ($baks) {
    foreach ($baks as $bak) {
        $name = basename($bak);
        $size = filesize($bak);
        if (unlink($bak)) {
            echo "DELETED: $name ($size bytes)\n";
            $deleted++;
        } else {
            echo "FAILED: $name\n";
            $failed++;
        }
    }
} else {
    echo "No backup files found\n";
}

// _inbox directory
echo "\n--- _inbox ---\n";
$inbox = $base . '/_inbox';
if (is_dir($inbox)) {
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    $count = 0;
    foreach ($rii as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
            $count++;
        }
    }
    rmdir($inbox);
    echo "DELETED: _inbox/ ($count files)\n";
    $deleted++;
} else {
    echo "_inbox/ not found\n";
}

// Root .sql files
echo "\n--- Root .sql ---\n";
$sqls = glob($base . '/*.sql');
if ($sqls) {
    foreach ($sqls as $sql) {
        $name = basename($sql);
        if (unlink($sql)) { echo "DELETED: $name\n"; $deleted++; }
        else { echo "FAILED: $name\n"; $failed++; }
    }
} else {
    echo "None\n";
}

// Root .log files
echo "\n--- Root .log ---\n";
$logs = glob($base . '/*.log');
if ($logs) {
    foreach ($logs as $log) {
        $name = basename($log);
        if (unlink($log)) { echo "DELETED: $name\n"; $deleted++; }
        else { echo "FAILED: $name\n"; $failed++; }
    }
} else {
    echo "None\n";
}

// db_*.json files
echo "\n--- db_*.json ---\n";
$jsons = glob($base . '/db_*.json');
if ($jsons) {
    foreach ($jsons as $json) {
        $name = basename($json);
        if (unlink($json)) { echo "DELETED: $name\n"; $deleted++; }
        else { echo "FAILED: $name\n"; $failed++; }
    }
} else {
    echo "None\n";
}

echo "\n=== Done: $deleted deleted, $failed failed ===\n";

// Verify site
echo "\n--- Verify ---\n";
echo "config.php: " . (file_exists($base . '/config.php') ? 'OK' : 'MISSING') . "\n";
echo "index.php: " . (file_exists($base . '/index.php') ? 'OK' : 'MISSING') . "\n";
echo "includes/: " . (is_dir($base . '/includes') ? 'OK' : 'MISSING') . "\n";
echo "assets/: " . (is_dir($base . '/assets') ? 'OK' : 'MISSING') . "\n";
