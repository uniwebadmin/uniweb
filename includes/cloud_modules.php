<?php
/**
 * Cloud / Hostinger include loader.
 *
 * Live `config.php` is git-ignored and must NOT be edited in PRs. Instead, live
 * config.php ends with something like:
 *   require_once __DIR__ . '/includes/cloud_modules.php';
 *
 * Register every new includes/*.php module here (is_file-guarded, dependency-safe
 * order) so SFTP deploys of this tracked file load new helpers without touching
 * secrets in config.php.
 */
declare(strict_types=1);

$__cloudDir = __DIR__;

$__cloudModules = [
    // Core schema / money / auth helpers first
    'schema_ensure.php',
    'wallet.php',
    'financial_integrity.php',
    'totp.php',
    'staff.php',
    'merchant_team.php',
    'merchant_ui.php',
    'notify.php',
    'mailer.php',
    // Feature modules
    'kyc_entity.php',
    'onboarding.php',
    'onboarding_security.php',
    'verification.php',
    'method_requests.php',
    'collection.php',
    'settlement_engine.php',
    'refunds.php',
    'chargebacks.php',
    'gateways.php',
    'axis.php',
    'pg_webhooks.php',
    'reconciliation.php',
    'payout.php',              // gated payout scaffold
    'customer_portal.php',     // payer OTP portal + tickets
    'customer_messaging.php',
    'platform_api.php',
    'platform_health.php',
    'link_watchdog.php',
    'auto_audit.php',
    'transaction_detail.php',
    'merchant_website.php',
    'merchant_webhooks.php',
    'ops_security.php',
    'velocity_check.php',
];

foreach ($__cloudModules as $__cloudFile) {
    $__cloudPath = $__cloudDir . '/' . $__cloudFile;
    if (is_file($__cloudPath)) {
        require_once $__cloudPath;
    }
}

unset($__cloudDir, $__cloudModules, $__cloudFile, $__cloudPath);
