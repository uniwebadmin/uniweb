<?php
declare(strict_types=1);

/**
 * P0-04 / P0-05: load helpers that live config.php may omit from $__includes.
 * Safe to require from any page after config.php (needs getDB for notify).
 */
if (is_file(__DIR__ . '/notifications.php')) {
    require_once __DIR__ . '/notifications.php';
}
if (is_file(__DIR__ . '/mailer.php')) {
    require_once __DIR__ . '/mailer.php';
}
