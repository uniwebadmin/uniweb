<?php
declare(strict_types=1);

// Load error catcher first so global handlers are registered before any other include can fatal
if (!function_exists('logPlatformError') && is_file(__DIR__ . '/error_catcher.php')) {
    require_once __DIR__ . '/error_catcher.php';
}
if (function_exists('initErrorCatcher') && !defined('UNIWEB_ERROR_CATCHER_INIT')) {
    initErrorCatcher();
}

/**
 * Simple .env file loader — no dependencies.
 * Reads KEY=VALUE pairs from .env file and sets them as environment variables.
 * Used for secrets management (DB credentials, API keys, etc.)
 *
 * Usage in config:
 *   loadEnvFile(__DIR__ . '/.env');
 *   $dbHost = env('DB_HOST', '127.0.0.1');
 */

function loadEnvFile(string $path): void
{
    if (!is_file($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) return;

    foreach ($lines as $line) {
        $line = trim($line);
        // Skip comments and empty lines
        if ($line === '' || $line[0] === '#') continue;
        // Skip lines without =
        if (strpos($line, '=') === false) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove surrounding quotes
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        // Only set if not already in environment (real env vars take precedence)
        if (!array_key_exists($key, $_ENV) && !getenv($key)) {
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }
}

/**
 * Get environment variable with fallback.
 */
function env(string $key, string $default = ''): string
{
    $val = $_ENV[$key] ?? getenv($key);
    return $val !== false && $val !== null ? (string)$val : $default;
}

/**
 * Get environment variable as integer.
 */
function envInt(string $key, int $default = 0): int
{
    $val = env($key, '');
    return $val !== '' ? (int)$val : $default;
}

/**
 * Get environment variable as boolean.
 */
function envBool(string $key, bool $default = false): bool
{
    $val = strtolower(env($key, ''));
    if ($val === 'true' || $val === '1' || $val === 'yes' || $val === 'on') return true;
    if ($val === 'false' || $val === '0' || $val === 'no' || $val === 'off') return false;
    return $default;
}
