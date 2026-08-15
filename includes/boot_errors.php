<?php
declare(strict_types=1);

/**
 * P0-03: First file every bootstrap must load.
 * Turns display_errors Off, registers the catcher, so fatals go to Error Log
 * instead of a white screen / SQL dump in the browser.
 */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
@error_reporting(E_ALL);

if (is_file(__DIR__ . '/env_loader.php')) {
    require_once __DIR__ . '/env_loader.php';
} elseif (is_file(__DIR__ . '/error_catcher.php')) {
    require_once __DIR__ . '/error_catcher.php';
}

if (function_exists('initErrorCatcher')) {
    initErrorCatcher();
}
