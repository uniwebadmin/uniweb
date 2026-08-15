<?php
$files = [
    'includes/smart_routing.php', 'includes/circuit_breaker.php', 'includes/risk.php',
    'includes/reconciliation.php', 'includes/grievance_engine.php', 'includes/rolling_reserve.php',
    'includes/split_settlement.php', 'includes/settlement_engine.php', 'includes/webhook_reliability.php',
    'includes/sub_merchant.php', 'includes/evidence_pack.php', 'includes/auto_kyc.php',
    'includes/mandates.php', 'includes/payout_jobs.php', 'includes/payout.php',
    'includes/payout_adapters.php', 'includes/payout_worker.php', 'includes/rate_limiter.php',
    'includes/gateway_reason_map.php', 'includes/va_manager.php', 'includes/method_requests.php',
    'includes/velocity_check.php', 'includes/merchant_health.php', 'includes/bank_reconciliation.php',
    'beneficiaries.php', 'admin_gateway_matrix.php', 'admin_disputes.php', 'admin_chargebacks.php',
    'admin_refunds.php', 'admin_grievance.php', 'admin_rolling_reserve.php', 'admin_reconciliation.php',
    'admin_circuit_breaker.php', 'admin_risk.php', 'admin_risk_engine.php', 'admin_sub_merchants.php',
    'admin_payout.php', 'admin_settlements.php', 'admin_settlement_batches.php',
    'admin_settlement_settings.php', 'admin_method_requests.php', 'admin_virtual_accounts.php',
    'admin_transaction_monitor.php', 'admin_webhook_reliability.php', 'admin_merchant_health.php',
    'admin_auto_kyc.php', 'admin_security.php', 'admin_security_hardening.php',
    'admin_bank_reconciliation.php', 'admin_financial_reports.php', 'admin_incidents.php',
    'admin_nodal_accounts.php', 'admin_partner.php', 'admin_partners.php',
    'admin_partner_commercial.php', 'admin_partner_decentro.php',
    'admin_website_reviews.php', 'admin_support.php', 'admin_customer_tickets.php',
    'business_agreement.php', 'includes/agreement_pdf.php',
    'cron_db_backup.php', 'cron_auto_kyc.php', 'cron_mandates.php',
    'includes/qr_events.php', 'includes/webhook_queue.php',
    'includes/platform_api.php', 'includes/platform_health.php',
    'includes/financial_integrity.php', 'includes/wallet.php',
    'includes/collection.php', 'includes/pg_webhooks.php',
    'includes/onboarding.php', 'includes/onboarding_security.php',
    'includes/kyc_upload.php', 'includes/kyc_entity.php',
    'includes/video_kyc_widget.php', 'includes/partner_engine.php',
    'includes/partners.php', 'includes/baas.php', 'includes/provision.php',
    'admin_kyc.php', 'admin_kyc_doc.php', 'kyc.php',
    'admin_edit_merchant.php', 'admin_view_merchant.php',
    'admin_ledger_state.php', 'admin_platform_wallet.php',
    'admin_audit_log.php', 'admin_staff_activity.php',
    'admin_manage_staff.php', 'admin_stepup.php',
    'admin_forgot_password.php', 'admin_reset_password.php',
    'admin_merchant_banks.php', 'admin_bank_reconciliation.php',
    'admin_throughput.php', 'admin_watchdog.php',
    'admin_platform_status.php', 'admin_error_log.php',
    'admin_link_audit.php', 'admin_website.php',
    'admin_customer_view.php', 'admin_customer_message.php',
    'admin_reason_map.php', 'admin_integration_matrix.php',
    'admin_partner_requests.php', 'admin_method_requests.php',
    'admin_aml.php',
    'includes/mailer.php', 'includes/notify.php',
    'includes/staff.php', 'includes/totp.php',
    'includes/otp.php', 'includes/address_form.php',
    'includes/contact_change.php', 'includes/id_click.php',
    'includes/merchant_team.php', 'includes/multi_merchant.php',
    'includes/merchant_website.php', 'includes/merchant_webhooks.php',
    'includes/merchant_admin_view.php', 'includes/merchant_profile.php',
    'includes/merchant_ui.php', 'includes/page_ux.php',
    'includes/schema_ensure.php', 'includes/migrations.php',
    'includes/auto_audit.php', 'includes/morning_ops.php',
    'includes/nodal.php',
    'includes/refunds.php', 'includes/chargebacks.php',
    'includes/upi_confirm.php', 'includes/qr_svg.php',
    'includes/qr_events.php', 'includes/recurring.php',
    'includes/settlement_delay_spec.php', 'includes/smart_routing.php',
    'includes/verification.php', 'includes/whatsapp_webhooks.php',
    'includes/partner_engine.php', 'includes/method_partner_adapters.php',
    'includes/global_search_ui.php', 'includes/kyc_timeline.php',
    'includes/trust_strip.php', 'includes/ui_links.php',
    'includes/transaction_detail.php', 'includes/integration_matrix.php',
    'includes/public_legal_page.php', 'includes/link_watchdog.php',
    'includes/rbl.php', 'includes/axis.php',
    'includes/gateways.php', 'includes/gateway_reason_map.php',
];

$root = 'c:\\Users\\start\\OneDrive\\Desktop\\uniweb1\\';
$yes = 0; $no = 0;
foreach ($files as $f) {
    $path = $root . str_replace('/', '\\', $f);
    if (is_file($path)) {
        echo "YES  $f\n";
        $yes++;
    } else {
        echo "NO   $f\n";
        $no++;
    }
}
echo "\n--- Total: $yes exists, $no missing ---\n";
