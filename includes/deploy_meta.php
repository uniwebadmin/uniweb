<?php
declare(strict_types=1);

/** Deploy label for admin banners — APP_VERSION + optional git short hash. */
function uniwebDeployMeta(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $version = defined('APP_VERSION') ? (string)APP_VERSION : 'unknown';
    $commit = '';
    $root = dirname(__DIR__);
    $headFile = $root . '/.git/HEAD';
    if (is_readable($headFile)) {
        $head = trim((string)file_get_contents($headFile));
        if (str_starts_with($head, 'ref:')) {
            $ref = trim(substr($head, 4));
            $refFile = $root . '/.git/' . $ref;
            if (is_readable($refFile)) {
                $commit = substr(trim((string)file_get_contents($refFile)), 0, 7);
            }
        } elseif (preg_match('/^[a-f0-9]{7,40}$/i', $head)) {
            $commit = substr($head, 0, 7);
        }
    }
    $label = $version . ($commit !== '' ? '+' . $commit : '');
    $cache = ['version' => $version, 'commit' => $commit, 'label' => $label];
    return $cache;
}

/** Count pending SQL/PHP migrations (081+ etc.). */
function uniwebPendingMigrationCount(): int
{
    if (!function_exists('pendingMigrations')) {
        $path = __DIR__ . '/migrations.php';
        if (!is_file($path)) {
            return 0;
        }
        require_once $path;
    }
    try {
        $dir = dirname(__DIR__) . '/migrations';
        if (!is_dir($dir)) {
            return 0;
        }
        return count(pendingMigrations($dir));
    } catch (Throwable $e) {
        return 0;
    }
}
