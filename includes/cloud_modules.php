<?php
declare(strict_types=1);

/**
 * Auto-loaded by live config.php (is_file bridge) at the end of the include chain.
 * Registers modules so they load on Hostinger WITHOUT editing git-ignored config.php.
 *
 * Rules:
 * - Only list include files that may not already be required by an older live config.php.
 * - is_file() guard: missing files are skipped safely.
 * - Keep load order sane: dependencies first.
 */

$__cloudModules = [
    // Core / boot (older live configs often omit these)
    'boot_errors.php',
    'env_loader.php',
    'notifications.php',
    'release_helpers.php',

    // Payout stack
    'payout.php',
    'payout_jobs.php',
    'payout_adapters.php',
    'payout_worker.php',
    'beneficiaries.php',
    'nodal.php',

    // Customer + UX
    'customer_portal.php',
    'contact_change.php',
    'page_ux.php',
    'page_ux_compat.php',
    'id_click.php',
    'email_templates.php',
    'client_context.php',
    'multi_merchant.php',

    // Methods / partners / forward
    'method_requests.php',
    'method_partner_adapters.php',
    'payment_methods.php',
    'partner_forward_queue.php',
    'partner_payload.php',
    'partner_control.php',
    'gateway_reason_map.php',

    // QR / VA / webhooks / reliability
    'qr_events.php',
    'va_manager.php',
    'webhook_queue.php',
    'webhook_reliability.php',
    'circuit_breaker.php',
    'rate_limiter.php',
    'fast_qr_api.php',

    // Ops / risk / ledger extras
    'rolling_reserve.php',
    'grievance_engine.php',
    'merchant_health.php',
    'risk.php',
    'audit_log.php',
    'recurring.php',
    'sub_merchant.php',
    'split_settlement.php',

    // Strategy / scaffolds (no auto live money)
    'integration_matrix.php',
    'settlement_delay_spec.php',
    'kyc_timeline.php',
    'nbfc.php',
];

foreach ($__cloudModules as $__cloudModule) {
    $__cloudModulePath = __DIR__ . '/' . $__cloudModule;
    if (is_file($__cloudModulePath)) {
        require_once $__cloudModulePath;
    }
}
unset($__cloudModule, $__cloudModulePath, $__cloudModules);
