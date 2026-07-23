<?php
declare(strict_types=1);

/**
 * Auto-loaded by config.php (via an is_file() bridge) at the end of the include
 * chain. Cloud agents register NEW include modules here so they load on live
 * WITHOUT editing the git-ignored config.php.
 *
 * Rules:
 * - Only list include files that are NOT already required by config.php.
 * - Each entry is loaded with an is_file() guard, so listing a not-yet-merged
 *   file is safe (it is simply skipped until the file exists on disk).
 * - Keep load order sane: list a module after anything it depends on.
 */

$__cloudModules = [
    'payout.php',
    'customer_portal.php',
    // Strategy pack 2026-07-23 — load without editing gitignored live config.php
    'gateway_reason_map.php',
    'contact_change.php',
    // Overnight Agent E — UX + integration scaffolds (no partner live calls)
    'page_ux.php',
    'integration_matrix.php',
    'settlement_delay_spec.php',
    'kyc_timeline.php',
];

foreach ($__cloudModules as $__cloudModule) {
    $__cloudModulePath = __DIR__ . '/' . $__cloudModule;
    if (is_file($__cloudModulePath)) {
        require_once $__cloudModulePath;
    }
}
unset($__cloudModule, $__cloudModulePath, $__cloudModules);
