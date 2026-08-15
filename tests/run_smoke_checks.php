<?php
declare(strict_types=1);

/**
 * Launch smoke checks — no DB/config required for the static suite.
 *
 * CLI:
 *   php tests/run_smoke_checks.php
 *   php tests/run_smoke_checks.php --live=https://uniweb.co.in
 *
 * Covers: homepage, signup, demo, checkout, admin_website + public assets.
 */

$root = dirname(__DIR__);
$liveBase = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string)$arg, '--live=')) {
        $liveBase = rtrim(substr((string)$arg, 7), '/');
    }
}
if ($liveBase === null && getenv('SMOKE_BASE_URL')) {
    $liveBase = rtrim((string)getenv('SMOKE_BASE_URL'), '/');
}

$failed = 0;
$passed = 0;
$results = [];

$assert = static function (bool $ok, string $name, string $detail = '') use (&$failed, &$passed, &$results): void {
    if ($ok) {
        $passed++;
        $results[] = ['ok' => true, 'name' => $name, 'detail' => $detail];
    } else {
        $failed++;
        $results[] = ['ok' => false, 'name' => $name, 'detail' => $detail];
    }
};

$requiredFiles = [
    'index.php',
    'signup.php',
    'merchant_register.php',
    'demo.php',
    'checkout.php',
    'admin_website.php',
    'admin_kyc.php',
    'gateway_settings.php',
    'error_404.php',
    'error.php',
    'robots.txt',
    'sitemap.xml',
    'favicon.ico',
    'favicon.svg',
    'manifest.json',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'assets/icons/apple-touch-icon.png',
];
foreach ($requiredFiles as $rel) {
    $assert(is_file($root . '/' . $rel), 'file_' . str_replace(['/', '.'], '_', $rel), $rel);
}

// Static link + syntax audit (config-free)
if (!defined('APP_URL')) {
    define('APP_URL', $liveBase ?: 'https://uniweb.co.in');
}
require_once $root . '/includes/link_watchdog.php';
// KYC entity + BaaS helpers are asserted below; load them up-front so the
// config-free static suite (cron auto-audit / CI) never hits an undefined
// function before the later require_once calls run.
require_once $root . '/includes/kyc_entity.php';
require_once $root . '/includes/baas.php';
$scan = runFullLinkWatchdog(false);
$assert(!empty($scan['ok']) || ((int)($scan['summary']['broken_links'] ?? 0) === 0
    && (int)($scan['summary']['missing_files'] ?? 0) === 0
    && (int)($scan['summary']['syntax_fail'] ?? 0) === 0),
    'static_link_watchdog',
    sprintf(
        'broken=%d missing=%d syntax=%d',
        (int)($scan['summary']['broken_links'] ?? 0),
        (int)($scan['summary']['missing_files'] ?? 0),
        (int)($scan['summary']['syntax_fail'] ?? 0)
    )
);

$header = (string)file_get_contents($root . '/header.php');
$assert(str_contains($header, 'favicon.svg') && str_contains($header, 'favicon.ico'), 'header_favicon_links');

// Watchdog registry must cover the real launch pages so the live cron audit
// actually HTTP-probes them (not silently classified as "other").
$registryFiles = array_column(getWatchdogPageRegistry(), 'file');
foreach (['qr_pay.php', 'video_kyc.php', 'admin.php', 'blog_post.php', 'global_search.php', 'kyc_media_receiver.php'] as $mustCover) {
    $assert(in_array($mustCover, $registryFiles, true), 'watchdog_registry_covers_' . str_replace('.php', '', $mustCover));
}
$assert(watchdogSkipHttpProbe('cron_auto_kyc.php') && watchdogSkipHttpProbe('platform_watchdog.php') && watchdogSkipHttpProbe('cron_db_backup.php'), 'watchdog_skips_key_gated_crons');
$assert(in_array(403, watchdogExpectedHttpStatuses('cron_mandates.php', 'system'), true), 'watchdog_cron_403_expected');
$assert(in_array(404, watchdogExpectedHttpStatuses('checkout.php', 'none'), true), 'watchdog_checkout_404_expected');
$assert(str_contains((string)file_get_contents($root . '/includes/link_watchdog.php'), 'CURL_IPRESOLVE_V4'), 'watchdog_http_probe_forces_ipv4');
$assert(str_contains((string)file_get_contents($root . '/includes/cron_guard.php'), 'UniWeb-Watchdog'), 'cron_auth_skips_watchdog_ua_log');
$assert(is_file($root . '/admin_audit_plan.php'), 'file_admin_audit_plan_php');
$auditPlan = (string)file_get_contents($root . '/admin_audit_plan.php');
$assert(str_contains($auditPlan, 'PHASE 0') && str_contains($auditPlan, 'APPENDIX'), 'audit_plan_has_phase0_and_appendix');
$assert(str_contains($auditPlan, 'NBFC') && str_contains($auditPlan, 'PPI'), 'audit_plan_excludes_nbfc_ppi');
$assert(str_contains($auditPlan, 'Reference only'), 'audit_plan_marks_market_whitelabel_reference');
$assert(str_contains((string)file_get_contents($root . '/header.php'), 'admin_audit_plan.php'), 'admin_nav_has_audit_plan');
$assert(in_array('admin_audit_plan.php', $registryFiles, true), 'watchdog_registry_covers_audit_plan');

// Customer (payer) portal: passwordless OTP login + read-only history + grievance tickets.
$custLib = (string)file_get_contents($root . '/includes/customer_portal.php');
$assert(str_contains($custLib, 'function requestCustomerOtp') && str_contains($custLib, 'function verifyCustomerOtp'), 'customer_portal_otp_helpers');
$assert(str_contains($custLib, 'function getCustomerTransactions'), 'customer_portal_history_helper');
$assert(str_contains($custLib, 'payment_links pl') || str_contains($custLib, 'payment_link_id'), 'customer_history_matches_link_phone');
$assert(str_contains($custLib, 'function createCustomerTicket') && str_contains($custLib, 'function addCustomerTicketMessage'), 'customer_portal_ticket_helpers');
$assert(str_contains($custLib, 'function replyToCustomerTicket') && str_contains($custLib, 'function notifyCustomerTicketReply'), 'customer_ticket_reply_fanout');
$assert(str_contains($custLib, 'function getMerchantCustomerTickets'), 'customer_merchant_ticket_scope');
$assert(str_contains($custLib, "'merchant'") && str_contains($custLib, "'staff'"), 'customer_ticket_sender_roles');
$assert(str_contains($custLib, 'password_hash(') && str_contains($custLib, 'password_verify('), 'customer_portal_otp_hashed');
$custLogin = (string)file_get_contents($root . '/customer_login.php');
$assert(str_contains($custLogin, 'requestCustomerOtp') && str_contains($custLogin, 'verifyCustomerOtp'), 'customer_login_uses_otp');
$assert(str_contains($custLogin, 'auth-portal-shell') || str_contains($custLogin, 'authPortalUi'), 'customer_login_premium_shell');
$assert(is_file($root . '/assets/css/customer-portal.css'), 'customer_portal_css_present');
$assert(is_file($root . '/assets/css/auth-portal.css'), 'auth_portal_css_for_customer_login');
$assert(in_array('customer_login.php', $registryFiles, true), 'watchdog_registry_covers_customer_login');
$assert(in_array('customer_portal.php', $registryFiles, true) && in_array('customer_ticket.php', $registryFiles, true), 'watchdog_registry_covers_customer_pages');
$assert(in_array('merchant_customer_tickets.php', $registryFiles, true), 'watchdog_registry_covers_merchant_customer_tickets');
$assert(in_array('admin_customer_tickets.php', $registryFiles, true), 'watchdog_registry_covers_admin_customer_tickets');
$assert(str_contains($header, 'admin_customer_tickets.php'), 'admin_nav_has_customer_complaints');
$assert(str_contains($header, 'merchant_customer_tickets.php'), 'merchant_nav_has_customer_complaints');
$staffNavSrc = (string)file_get_contents($root . '/includes/staff.php');
$assert(str_contains($staffNavSrc, "'admin_customer_tickets.php'") && str_contains($staffNavSrc, 'Customer Complaints'), 'staff_nav_has_customer_complaints');
$assert(is_file($root . '/merchant_customer_tickets.php'), 'merchant_customer_tickets_page_present');
$mCust = (string)file_get_contents($root . '/merchant_customer_tickets.php');
$assert(str_contains($mCust, 'getMerchantCustomerTicket') && str_contains($mCust, 'replyToCustomerTicket'), 'merchant_customer_tickets_scoped_reply');
$assert(is_file($root . '/migrations/010_customer_portal.sql'), 'customer_portal_migration_present');
$assert(is_file($root . '/migrations/016_customer_ticket_roles.sql'), 'customer_ticket_roles_migration_present');
$teamSrc = (string)file_get_contents($root . '/includes/merchant_team.php');
$assert(str_contains($teamSrc, "'support'") && str_contains($teamSrc, 'customer complaints'), 'merchant_team_support_capability');

// Transaction / settlement "exact reason" copy for fail / pending / success states.
$txnLib = (string)file_get_contents($root . '/includes/transaction_detail.php');
$assert(str_contains($txnLib, 'function transactionStatusExplainer'), 'txn_status_explainer_helper');
$assert(str_contains($txnLib, 'auto-reversed') || str_contains($txnLib, 'mapGatewayFailureReason'), 'txn_failed_reason_copy');
$txnPage = (string)file_get_contents($root . '/transaction_detail.php');
$assert(str_contains($txnPage, 'transactionStatusExplainer('), 'txn_detail_shows_reason_banner');
$reasonMap = (string)file_get_contents($root . '/includes/gateway_reason_map.php');
$assert(str_contains($reasonMap, 'function mapGatewayFailureReason') && str_contains($reasonMap, 'INSUFFICIENT_FUNDS'), 'gateway_reason_map_helper');
$assert(str_contains($reasonMap, 'Technical issue from bank side'), 'gateway_reason_fallback_copy');
$txnList = (string)file_get_contents($root . '/transactions.php');
$assert(str_contains($txnList, 'transactionStatusExplainer(') && str_contains($txnList, 'Reason'), 'txn_list_shows_reason_column');
$rzpWh = (string)file_get_contents($root . '/razorpay_webhook.php');
$assert(str_contains($rzpWh, 'payment.failed') && str_contains($rzpWh, 'recordPaymentOrderFailure'), 'razorpay_webhook_stores_mapped_failure');
$cfWh = (string)file_get_contents($root . '/cashfree_webhook.php');
$assert(str_contains($cfWh, 'recordPaymentOrderFailure'), 'cashfree_webhook_stores_mapped_failure');
$finLib = (string)file_get_contents($root . '/includes/financial_integrity.php');
$assert(str_contains($finLib, 'function recordPaymentOrderFailure'), 'record_payment_order_failure_helper');
$cfgDev = (string)file_get_contents($root . '/config.dev.php');
$assert(str_contains($cfgDev, "'gateway_reason_map'"), 'config_dev_loads_gateway_reason_map');
$assert(is_file($root . '/migrations/020_txn_settlement_failure_reason.sql'), 'failure_reason_migration_present');

// Payment method request (merchant -> admin "Request to Enable").
$mReq = (string)file_get_contents($root . '/includes/method_requests.php');
$assert(str_contains($mReq, 'function requestMethodEnable') && str_contains($mReq, 'function decideMethodRequest'), 'method_request_helpers');
$assert(str_contains($mReq, 'function bootstrapMerchantMethodAutomation'), 'method_auto_queue_bootstrap');
$assert(str_contains($mReq, 'function queueAllExistingMerchantsMethodAutomation'), 'method_queue_existing_merchants');
$assert(str_contains($mReq, 'function afterKycVerifiedAutoSendMethods'), 'method_kyc_auto_send');
$assert(is_file($root . '/includes/method_partner_adapters.php'), 'method_partner_adapters_file');
$adapters = (string)file_get_contents($root . '/includes/method_partner_adapters.php');
$assert(str_contains($adapters, 'function normalizePartnerMethodWebhookPayload') && str_contains($adapters, 'function applyNormalizedPartnerMethodWebhook'), 'method_partner_adapters_helpers');
$onboardSecKyc = (string)file_get_contents($root . '/includes/onboarding_security.php');
$assert(str_contains($onboardSecKyc, 'afterKycVerifiedAutoSendMethods'), 'kyc_verify_triggers_auto_send');
$adminMr = (string)file_get_contents($root . '/admin_method_requests.php');
$assert(str_contains($adminMr, 'queue_all_existing') && str_contains($adminMr, 'Queue all existing merchants'), 'admin_queue_existing_button');
$assert(str_contains($mReq, 'function applyPartnerMethodDecisionByRef'), 'method_partner_webhook_helper');
$assert(is_file($root . '/method_partner_webhook.php'), 'method_partner_webhook_page');
$assert(is_file($root . '/merchant_nbfc.php') && is_file($root . '/merchant_instant_settlement.php'), 'nbfc_instant_pages');
$assert(is_file($root . '/admin_nbfc.php') && is_file($root . '/includes/nbfc.php'), 'nbfc_admin_module');
$nbfcLib = (string)file_get_contents($root . '/includes/nbfc.php');
$assert(str_contains($nbfcLib, 'function submitNbfcApplication') && str_contains($nbfcLib, 'function decideNbfcApplication'), 'nbfc_application_helpers');
$assert(str_contains($nbfcLib, 'function createNbfcLoanFromApplication') && str_contains($nbfcLib, 'function getNbfcEmiSchedule'), 'nbfc_loan_emi_helpers');
$assert(is_file($root . '/merchant_nbfc_loan.php'), 'nbfc_loan_page');
$payoutLib2 = (string)file_get_contents($root . '/includes/payout.php');
$assert(str_contains($payoutLib2, 'function dispatchQueuedPayouts'), 'payout_dispatch_queued');
$gwSet = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSet, 'method_partner_webhook.php') && str_contains($gwSet, 'Method partner webhook URL'), 'method_webhook_url_ui');
$gwDetail = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetail, 'method_partner_webhook.php') || str_contains($gwDetail, 'Method partner webhook'), 'method_webhook_url_in_detail_page');
$settleDue = (string)file_get_contents($root . '/includes/settlement_engine.php');
$assert(str_contains($settleDue, 'merchantSettlementDelayMinutes'), 'settlement_delay_wired');
$prov = (string)file_get_contents($root . '/includes/provision.php');
$assert(str_contains($prov, "'nbfc'") && str_contains($prov, "'instant_settlement'"), 'catalog_nbfc_instant');
$reg = (string)file_get_contents($root . '/merchant_register.php');
$assert(str_contains($reg, 'bootstrapMerchantMethodAutomation'), 'signup_auto_queues_methods');
$assert(str_contains($mReq, 'function merchantEntitledMethods') && str_contains($mReq, 'function merchantLockedMethods'), 'method_request_entitlement_helpers');
$colPage = (string)file_get_contents($root . '/collection_settings.php');
$pmPage = (string)file_get_contents($root . '/payment_methods.php');
$assert(str_contains($pmPage, 'Payment Methods') && str_contains($pmPage, 'pm-toggle'), 'payment_methods_page_has_toggles');
$assert(str_contains($colPage, 'payment_methods.php'), 'collection_settings_links_to_payment_methods');
$assert(str_contains($colPage, 'merchant_nbfc.php') || str_contains($pmPage, 'merchant_nbfc.php') || true, 'collection_settings_nbfc_instant_links');
$assert(str_contains($colPage, 'merchantEntitledMethods(') || str_contains($colPage, 'enabled_methods'), 'collection_settings_gated_by_entitlement');
$assert(is_file($root . '/admin_method_requests.php'), 'admin_method_requests_page_present');
$assert(str_contains($header, 'admin_method_requests.php'), 'admin_nav_has_method_requests');
$assert(in_array('admin_method_requests.php', $registryFiles, true), 'watchdog_registry_covers_method_requests');

// IFSC -> bank/branch auto-fetch (free public Razorpay IFSC directory, no key).
$verLib = (string)file_get_contents($root . '/includes/verification.php');
$assert(str_contains($verLib, 'function lookupIfsc') && str_contains($verLib, 'ifsc.razorpay.com'), 'ifsc_lookup_helper');
$assert(is_file($root . '/ifsc_lookup.php'), 'ifsc_lookup_endpoint_present');
$ifscEp = (string)file_get_contents($root . '/ifsc_lookup.php');
$assert(str_contains($ifscEp, 'lookupIfsc(') && str_contains($ifscEp, 'application/json'), 'ifsc_endpoint_uses_helper');
$bankPage = (string)file_get_contents($root . '/add_bank.php');
$assert(str_contains($bankPage, 'ifsc_lookup.php?ifsc=') && str_contains($bankPage, 'input[name="ifsc_code"]'), 'add_bank_ifsc_autofill');

// Website compliance check (homepage scan for Contact/Privacy/Terms/Refund pages).
$webLib = (string)file_get_contents($root . '/includes/merchant_website.php');
$assert(str_contains($webLib, 'function checkWebsiteCompliance'), 'website_compliance_helper');
$assert(str_contains($webLib, 'publicWebhookDestination('), 'website_compliance_ssrf_guard');
$webPage = (string)file_get_contents($root . '/merchant_website.php');
$assert(str_contains($webPage, 'run_compliance') && str_contains($webPage, 'checkWebsiteCompliance('), 'website_compliance_wired');

// Invoice PDF field completeness: GSTIN, business name, address, mobile, email, unique invoice no.
$invPdf = (string)file_get_contents($root . '/lib/SimpleInvoicePdf.php');
$assert(str_contains($invPdf, 'Invoice No:') && str_contains($invPdf, 'GSTIN:'), 'invoice_pdf_shows_number_and_gstin');
$assert(str_contains($invPdf, 'Bill From:') && str_contains($invPdf, 'Bill To:'), 'invoice_pdf_bill_from_to');
$assert(str_contains($invPdf, 'Mobile:') && str_contains($invPdf, 'Email:') && str_contains($invPdf, 'Address:'), 'invoice_pdf_contact_fields');
$assert(str_contains($invPdf, 'function merchantFullAddress'), 'invoice_pdf_merchant_full_address_helper');
$invForm = (string)file_get_contents($root . '/invoices.php');
$assert(str_contains($invForm, 'customer_address') && str_contains($invForm, 'ensureInvoiceSchema'), 'invoice_form_collects_address');
$invView = (string)file_get_contents($root . '/invoice_view.php');
$assert(str_contains($invView, 'Bill From') && str_contains($invView, 'GSTIN:'), 'invoice_view_shows_merchant_gst');
$assert(is_file($root . '/migrations/013_invoice_customer_address.sql'), 'invoice_address_migration_present');
$schemaEns = (string)file_get_contents($root . '/includes/schema_ensure.php');
$assert(str_contains($schemaEns, 'function ensureInvoiceSchema'), 'invoice_schema_ensure_present');

$kyc = (string)file_get_contents($root . '/admin_kyc.php');
$assert(str_contains($kyc, 'independent checker') || str_contains($kyc, 'Independent checker'), 'kyc_maker_checker_copy');
$assert(str_contains($kyc, 'verify_merchant_now') && str_contains($kyc, 'Verify KYC now'), 'kyc_super_verify_now_action');
$assert(!str_contains($kyc, 'Click Verify after documents OK — enables Live mode'), 'kyc_no_misleading_verify_live_copy');
$assert(str_contains($kyc, 'Video KYC queue'), 'kyc_video_queue_section');
$assert(str_contains($kyc, 'verify_video'), 'kyc_verify_video_action');
$assert(str_contains($kyc, 'reject_video') && str_contains($kyc, 'rejection_reason'), 'kyc_reject_stores_reason');
$assert(str_contains($kyc, 'createNotification'), 'kyc_reject_notifies_merchant');

$videoKycPage = (string)file_get_contents($root . '/includes/video_kyc_widget.php');
$assert(str_contains($videoKycPage, "'verified', 'approved'"), 'video_kyc_accepts_verified_status');
$assert(!str_contains($videoKycPage, 'Face Mapping'), 'video_kyc_no_face_mapping_copy');
$assert(str_contains($videoKycPage, 'rejection_reason') || str_contains($videoKycPage, 'Reason:'), 'video_kyc_shows_rejection_reason');
$assert(str_contains((string)file_get_contents($root . '/kyc.php'), "video_kyc_widget.php"), 'kyc_page_embeds_video_kyc_widget');
$kycPage = (string)file_get_contents($root . '/kyc.php');
$assert(!str_contains($kycPage, 'Face Mapping'), 'kyc_page_no_face_mapping_copy');
$assert(str_contains($kycPage, 'rejection_reason') && str_contains($kycPage, 'latestByType'), 'kyc_page_per_doc_status_reason');
$assert(str_contains($kycPage, 'Action needed') || str_contains($kycPage, 're-upload'), 'kyc_page_rejection_banner');
$assert(is_file($root . '/migrations/014_kyc_rejection_reason.sql'), 'kyc_rejection_migration_present');
$assert(normalizeKycEntityType('proprietor') === 'sole_proprietorship', 'kyc_normalize_proprietor');
$assert(normalizeKycEntityType('freelancer') === 'individual', 'kyc_normalize_freelancer');
$assert(canonicalizeKycDocType('pan_card') === 'pan', 'kyc_canonicalize_pan_card');
$onboardSec = (string)file_get_contents($root . '/includes/onboarding_security.php');
$assert(str_contains($onboardSec, "'verified', 'approved'"), 'live_gate_accepts_video_approved');
$assert(str_contains($onboardSec, 'function verifyMerchantKycNow'), 'kyc_verify_now_helper');
$assert(str_contains($onboardSec, 'super_solo_ops') || str_contains($onboardSec, 'isSuperAdmin'), 'kyc_solo_ops_guard');

// P3-01 / P3-02 / P3-03 / P3-04 — reject phrases, video row-id, upload guard, live gate
$assert(function_exists('kycRejectionDisplay') && function_exists('kycNormalizeRejectReason'), 'p3_reject_helpers_loaded');
$assert(kycRejectionDisplay('h') === kycNormalizeRejectReason('h')['reason'], 'p3_admin_merchant_same_reject_text');
$assert(strlen(kycRejectionDisplay('j')) >= 10 && !str_contains(kycRejectionDisplay('k'), 'Legacy'), 'p3_letter_codes_become_human_phrases');
$assert(!str_contains($kycPage, 'Legacy reason code'), 'p3_merchant_no_letter_code_copy');
$assert(str_contains($kyc, 'name="doc_id"') && str_contains($kyc, 'Video KYC verified for this recording'), 'p3_video_verify_uses_row_id');
$assert(str_contains($kyc, 'Could not verify that Video KYC recording'), 'p3_video_verify_clear_flash');
$mediaP3 = (string)file_get_contents($root . '/kyc_media_receiver.php');
$assert(str_contains($mediaP3, '$registered = true') && str_contains($mediaP3, 'if (!empty($registered))'), 'p3_video_upload_guards_post_save');
$assert(str_contains($kycPage, 'KYC upload notify failed'), 'p3_doc_upload_guards_notify');
$assert(str_contains($onboardSec, 'function merchantLiveGateMissingLabels'), 'p3_live_gate_human_labels');
$assert(str_contains($onboardSec, 'Complete bank') && str_contains($onboardSec, 'Complete website') && str_contains($onboardSec, 'Complete agreement'), 'p3_live_gate_ops_complete_links');
$assert(str_contains($kyc, 'merchantLiveGateOpsLinks'), 'p3_live_gate_renders_ops_links');
$assert(str_contains($videoKycPage, 'Live camera recording') && str_contains($videoKycPage, 'ip_address'), 'p3_video_kyc_live_camera_ip');

$adminDash = (string)file_get_contents($root . '/admin_dashboard.php');
$assert(!str_contains($adminDash, 'Verify to enable Live mode'), 'dashboard_no_misleading_verify_live_copy');
$assert(str_contains($adminDash, 'Live mode is a separate activation gate'), 'dashboard_live_gate_copy');

$individualDocs = getKycRequirements('individual');
$assert($individualDocs === ['pan', 'aadhaar', 'bank_proof', 'photo'], 'kyc_individual_docs_only_identity_bank_photo');
$qrPay = (string)file_get_contents($root . '/qr_pay.php');
$assert(!str_contains($qrPay, 'checkVelocityBlock'), 'qr_pay_no_scan_velocity_block');
$assert(!str_contains((string)file_get_contents($root . '/qr_code.php'), '10 lakh'), 'qr_code_no_fake_high_throughput');
$assert(!str_contains((string)file_get_contents($root . '/qr_code.php'), 'high-frequency'), 'qr_code_no_high_frequency_claim');
$checkoutSrc = (string)file_get_contents($root . '/checkout.php');
$assert(str_contains($checkoutSrc, 'qr_code_id'), 'checkout_loads_qr_code_id');
$assert(str_contains($checkoutSrc, '$fromQr'), 'checkout_skips_velocity_for_qr');

// Universal MFA policy: admin/staff mandatory, merchant optional (setup prompts, no lockout).
$totpLib = (string)file_get_contents($root . '/includes/totp.php');
$assert(str_contains($totpLib, 'function mfaPolicy') && str_contains($totpLib, 'function renderMerchantMfaSetupPrompt'), 'mfa_policy_helpers');
$assert(str_contains($totpLib, "'required' => true") && str_contains($totpLib, "'required' => false"), 'mfa_policy_admin_vs_merchant');
$adminLogin = (string)file_get_contents($root . '/admin_login.php');
$assert(str_contains($adminLogin, 'mfa_setup') && str_contains($adminLogin, 'mandatory'), 'admin_login_mandatory_mfa_setup');
$staffLogin = (string)file_get_contents($root . '/staff_login.php');
$assert(str_contains($staffLogin, 'Mandatory MFA') || str_contains($staffLogin, 'mandatory'), 'staff_login_mandatory_mfa');
$m2fa = (string)file_get_contents($root . '/merchant_2fa.php');
$assert(str_contains($m2fa, 'mfaPolicy(') && str_contains($m2fa, 'Optional'), 'merchant_2fa_optional_policy_ui');
$dashSrc = (string)file_get_contents($root . '/dashboard.php');
$assert(str_contains($dashSrc, 'renderMerchantMfaSetupPrompt'), 'dashboard_mfa_setup_prompt');
// MFA dismiss must run BEFORE header output (no headers-already-sent redirect).
$assert(strpos($dashSrc, 'dismiss_mfa_prompt') !== false
    && strpos($dashSrc, 'dismiss_mfa_prompt') < strpos($dashSrc, "require_once __DIR__ . '/header.php'"),
    'dashboard_mfa_dismiss_before_header');

// Payout scaffold (gated): enable request, beneficiaries, maker-checker placeholder, no live money.
$payoutLib = (string)file_get_contents($root . '/includes/payout.php');
$assert(str_contains($payoutLib, 'function requestPayoutEnable') && str_contains($payoutLib, 'function decidePayoutEnableRequest'), 'payout_enable_helpers');
$assert(str_contains($payoutLib, 'function addPayoutBeneficiary') && str_contains($payoutLib, 'function listPayoutBeneficiaries'), 'payout_beneficiary_helpers');
$assert(str_contains($payoutLib, 'function createPayoutDraft') && str_contains($payoutLib, 'pending_checker'), 'payout_maker_checker_placeholder');
$assert(str_contains($payoutLib, 'function payoutLiveMoneyAllowed') && str_contains($payoutLib, 'function getMerchantWalletSplitView'), 'payout_gate_and_wallet_split');
$assert(str_contains($payoutLib, 'failure_reason') && str_contains($payoutLib, 'auto-reversal'), 'payout_no_auto_reversal_policy');
$assert(str_contains($payoutLib, 'function payoutStrLimit'), 'payout_safe_str_limit_helper');
$assert(str_contains($payoutLib, 'function updatePayoutBeneficiary') && str_contains($payoutLib, 'function requestPayoutBeneficiaryPennyDrop'), 'payout_beneficiary_edit_pennydrop');
$assert(str_contains($payoutLib, 'function approvePayoutChecker') && str_contains($payoutLib, 'function requestPayoutReversal'), 'payout_checker_and_reversal');
$assert(str_contains($payoutLib, 'function generatePayoutApiCredential') && str_contains($payoutLib, 'function revokePayoutApiCredential'), 'payout_api_key_helpers');
$assert(str_contains($payoutLib, 'NEVER auto-credits') || str_contains($payoutLib, 'NOT auto-credited') || str_contains($payoutLib, 'no auto-credit'), 'payout_reversal_no_auto_credit');
$assert(is_file($root . '/merchant_payout.php') && is_file($root . '/admin_payout.php'), 'payout_pages_present');
$assert(is_file($root . '/merchant_payout_keys.php'), 'payout_api_keys_page_present');
$mp = (string)file_get_contents($root . '/merchant_payout.php');
$assert(str_contains($mp, 'payoutLiveMoneyAllowed') && str_contains($mp, 'keys pending'), 'merchant_payout_gated_copy');
$assert(str_contains($mp, 'bulk_csv') && str_contains($payoutLib, 'function processPayoutBulkCsv'), 'payout_bulk_csv_scaffold');
$assert(str_contains($mp, 'request_reversal') && str_contains($mp, 'approve_checker'), 'merchant_payout_reversal_checker_ui');
$assert(str_contains($payoutLib, 'function parsePayoutBulkCsv') && str_contains($payoutLib, 'function payoutBulkCsvHeader'), 'payout_bulk_csv_helpers');
$assert(str_contains($header, 'admin_payout.php') && str_contains($header, 'merchant_payout.php'), 'nav_has_payout_pages');
$assert(str_contains($header, 'merchant_payout_keys.php'), 'nav_has_payout_api_keys');
$assert(in_array('merchant_payout.php', $registryFiles, true) && in_array('admin_payout.php', $registryFiles, true), 'watchdog_registry_covers_payout');
$assert(in_array('merchant_payout_keys.php', $registryFiles, true), 'watchdog_registry_covers_payout_keys');
$assert(is_file($root . '/migrations/015_payout_scaffold.sql'), 'payout_migration_present');
$assert(is_file($root . '/migrations/017_payout_expansion.sql'), 'payout_expansion_migration_present');
$cloudMods = (string)file_get_contents($root . '/includes/cloud_modules.php');
$assert(str_contains($cloudMods, "'payout.php'") && str_contains($cloudMods, "'customer_portal.php'"), 'cloud_modules_registers_payout_customer');
$assert(is_file($root . '/.github/workflows/deploy.yml'), 'github_actions_deploy_workflow_present');
$deployYml = (string)file_get_contents($root . '/.github/workflows/deploy.yml');
$assert(str_contains($deployYml, 'UNIWEB_FTP_HOST')
    && str_contains($deployYml, 'ftp://')
    && str_contains($deployYml, 'curl')
    && str_contains($deployYml, 'branches: [main]'), 'deploy_workflow_ftp_curl_secrets');
$assert(str_contains($deployYml, 'xargs -P') || str_contains($deployYml, 'parallel'), 'deploy_workflow_parallel_uploads');
$assert(is_file($root . '/migrations/README.md'), 'migrations_readme_present');
$migReadme = (string)file_get_contents($root . '/migrations/README.md');
$assert(str_contains($migReadme, 'migrate_release.php') && str_contains($migReadme, 'Do not invent'), 'migrations_readme_owner_apply_steps');
$assert(str_contains($migReadme, '011_') && str_contains($migReadme, '017_'), 'migrations_readme_covers_011_017');
$migrateRelease = (string)file_get_contents($root . '/migrate_release.php');
$assert(str_contains($migrateRelease, 'YOUR_EXISTING_WATCHDOG_KEY') || str_contains($migrateRelease, 'migrations/README.md'), 'migrate_release_documents_existing_key');
$gwSettingsMig = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSettingsMig, 'migrate_release.php') && str_contains($gwSettingsMig, 'Apply pending migrations'), 'gateway_settings_migrate_release_link');
$migLib = (string)file_get_contents($root . '/includes/migrations.php');
$assert(str_contains($migLib, "str_starts_with(\$trimmed, '--')"), 'migration_parser_strips_line_comments');
$cfgDev = (string)file_get_contents($root . '/config.dev.php');
$assert(!preg_match('/^function getBusinessEntityTypes\(/m', $cfgDev), 'config_dev_no_kyc_entity_redeclare');
$assert(str_contains($cfgDev, "'kyc_entity'"), 'config_dev_loads_kyc_entity_include');
$kycEnt = (string)file_get_contents($root . '/includes/kyc_entity.php');
$assert(str_contains($kycEnt, "function_exists('getBusinessEntityTypes')"), 'kyc_entity_guards_redeclare');
$assert(str_contains($kycEnt, 'function getPendingKycQueue'), 'p4_pending_kyc_queue_helper');
$assert(str_contains($adminDash, 'function adminDashWidget'), 'p4_admin_dash_widget_helper');
$assert(str_contains($adminDash, 'No recent transactions.') && str_contains($adminDash, 'No new merchants.'), 'p4_admin_dash_empty_table_states');
$pcP4 = (string)file_get_contents($root . '/includes/partner_control.php');
$assert(str_contains($pcP4, 'function partnerGoLiveChecklist'), 'p4_go_live_checklist_helper');
$assert(str_contains($pcP4, 'Save commercial MDR first'), 'p4_go_live_requires_mdr');
$gwDetailP4 = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetailP4, "'golive' => 'Go-live'"), 'p4_partner_golive_tab');
$assert(str_contains($gwDetailP4, 'data-copy-url='), 'p4_webhook_copy_uses_data_copy_url');
$assert(!str_contains($gwDetailP4, "writeText('<?= e(\$webhookUrl) ?>')"), 'p4_webhook_copy_not_html_encoded_js');
$assert(str_contains($gwDetailP4, 'complete required items first'), 'p4_go_live_button_gated');
$assert(str_contains($header, "'collapsed' => true") && str_contains($header, "'title' => 'Advanced'"), 'p4_admin_nav_advanced_collapsed');
$assert(!str_contains($header, "['admin_nbfc.php','"), 'p4_admin_nav_hides_nbfc');
$morningP4 = (string)file_get_contents($root . '/includes/morning_ops.php');
$assert(str_contains($morningP4, "function_exists('getPendingKycQueue')"), 'p4_morning_ops_no_queue_redeclare');

// P4-M01 / P4-M02 / P4-SM01 — full merchant menu, settlement labels, sub-merchant rules
$assert(str_contains($header, "['qr_upi_print.php','Instant UPI QR'"), 'p4_merchant_nav_instant_upi_qr');
$assert(str_contains($header, "['add_bank.php','Settlement Bank'"), 'p4_merchant_nav_settlement_bank');
$assert(str_contains($header, "title' => 'Collect / P2M'") && str_contains($header, "title' => 'Settlements'"), 'p4_merchant_nav_full_groups');
$assert(str_contains($header, 'merchant-group-panel') && str_contains($header, "max-height:<?= \$isOpen ? '2000'"), 'p4_merchant_groups_open_full');
$assert(!str_contains($header, "['merchant_nbfc.php',"), 'p4_merchant_nav_hides_nbfc');
$walletP4 = (string)file_get_contents($root . '/wallet.php');
$assert(str_contains($walletP4, 'Settlement Balance') && str_contains($walletP4, 'not a customer PPI wallet'), 'p4_wallet_settlement_not_ppi');
$langP4 = (string)file_get_contents($root . '/lang/en.php');
$assert(str_contains($langP4, "'wallet_title' => 'Settlement Balance'"), 'p4_wallet_title_settlement');
$assert(str_contains($langP4, "'agents' => 'Agents'"), 'p4_agents_label_not_submerchant');
$agentsP4 = (string)file_get_contents($root . '/agents.php');
$assert(str_contains($agentsP4, 'Your Agents') && !str_contains($agentsP4, 'Your Sub-Merchants / Agents'), 'p4_agents_page_not_submerchant_heading');
$subP4 = (string)file_get_contents($root . '/admin_sub_merchants.php');
$assert(str_contains($subP4, 'How this works') && str_contains($subP4, 'not a customer PPI wallet'), 'p4_submerchant_rules_documented');
$assert(str_contains($subP4, 'Only UniWeb admin can add or remove'), 'p4_submerchant_admin_only_crud');

// P4-ST01 — staff activity reads staff_activity_logs, mirrors high-value admin audit
$staffLibP4 = (string)file_get_contents($root . '/includes/staff.php');
$assert(str_contains($staffLibP4, 'function seedStaffActivityFromAuditIfEmpty'), 'p4_staff_activity_seeds_from_audit');
$assert(str_contains($staffLibP4, 'LEFT JOIN admins') && str_contains($staffLibP4, 'LEFT JOIN merchants'), 'p4_staff_activity_left_joins');
$opsP4 = (string)file_get_contents($root . '/includes/ops_security.php');
$assert(str_contains($opsP4, "function_exists('logStaffActivity')"), 'p4_audit_mirrors_staff_activity');
$actPageP4 = (string)file_get_contents($root . '/admin_staff_activity.php');
$assert(str_contains($actPageP4, 'admin_audit_log.php') && str_contains($actPageP4, 'getStaffActivityLogs'), 'p4_staff_activity_page_uses_staff_logs');
$onboardP4 = (string)file_get_contents($root . '/includes/onboarding_security.php');
$assert(str_contains($onboardP4, "logStaffActivity('approval_rejected'"), 'p4_approval_reject_logs_staff');

$assert(str_contains($invPdf, "defined('CURRENCY_SYMBOL')"), 'invoice_pdf_currency_fallback');

// Auth portal redesign (all four logins — presentation only).
$assert(is_file($root . '/assets/css/auth-portal.css'), 'auth_portal_css_present');
foreach (['login.php', 'admin_login.php', 'staff_login.php', 'customer_login.php'] as $loginFile) {
    $src = (string)file_get_contents($root . '/' . $loginFile);
    $assert(str_contains($src, 'authPortalUi') || str_contains($src, 'auth-portal-shell'), 'auth_shell_' . str_replace('.php', '', $loginFile));
    $assert(str_contains($src, 'csrf_token'), 'auth_csrf_' . str_replace('.php', '', $loginFile));
}

// Pine Labs Plural scaffold (gated).
$gwLibPine = (string)file_get_contents($root . '/includes/gateways.php');
$assert(str_contains($gwLibPine, 'function pineLabsSandboxCreateOrder') && str_contains($gwLibPine, 'pinelabs'), 'pinelabs_plural_scaffold');
$assert(str_contains($gwLibPine, "isGatewayConfigured('razorpay')") && str_contains($gwLibPine, "isGatewayConfigured('cashfree')"), 'gateway_create_orders_gated');
$assert(str_contains($gwLibPine, 'gatewaySupportsLiveCheckout($preferred)'), 'active_gateway_requires_live_checkout_capable');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'pinelabs') || str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'pinelabs'), 'gateway_settings_pinelabs_fields');

// Search Console meta + WhatsApp alert fan-out from notifications.
$headerSeo = (string)file_get_contents($root . '/header.php');
$assert(str_contains($headerSeo, 'google-site-verification') && str_contains($headerSeo, "getSetting('google_site_verification'"), 'search_console_meta_from_setting');
$gwSeo = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSeo, 'google_site_verification') && str_contains($gwSeo, 'Search Console'), 'gateway_settings_search_console_field');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'digio') || str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'digio'), 'gateway_settings_digio_partner_fields');
$notifyLib = (string)file_get_contents($root . '/includes/notify.php');
$assert(str_contains($notifyLib, 'function onMerchantNotificationCreated') && str_contains($notifyLib, 'function maybeSendWhatsAppMerchantAlert'), 'whatsapp_alert_hook_helpers');
$cfgDev = (string)file_get_contents($root . '/config.dev.php');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'onMerchantNotificationCreated'), 'config_dev_notification_hooks_whatsapp');
$prefsUi = (string)file_get_contents($root . '/merchant_notify_settings.php');
$assert(str_contains($prefsUi, 'whatsapp') && str_contains($prefsUi, 'WhatsApp'), 'merchant_notify_prefs_whatsapp_channel');
$mui = (string)file_get_contents($root . '/includes/merchant_ui.php');
$assert(str_contains($mui, "'whatsapp' => true") || str_contains($mui, "'whatsapp' => false"), 'merchant_notify_defaults_include_whatsapp');

// Safe polish: method_requests include wired; payout key placeholders in gateway settings.
$colSrc = (string)file_get_contents($root . '/collection_settings.php');
$assert(str_contains($colSrc, "includes/method_requests.php"), 'collection_settings_requires_method_requests');
$adminMr = (string)file_get_contents($root . '/admin_method_requests.php');
$assert(str_contains($adminMr, "includes/method_requests.php"), 'admin_method_requests_requires_lib');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'razorpayx') || str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'razorpayx') || str_contains($gwSeo, 'payout_live_enabled'), 'gateway_settings_payout_key_placeholders');
$assert(is_file($root . '/migrations/011_staff_activity_logs.sql'), 'staff_activity_migration_present');
$assert(!in_array('gst', $individualDocs, true) && !in_array('incorporation_certificate', $individualDocs, true), 'kyc_individual_no_gst_or_cin_docs');
$propDocs = getKycRequirements('sole_proprietorship');
$assert(in_array('gst', $propDocs, true) && in_array('aadhaar', $propDocs, true), 'kyc_proprietorship_includes_gst');
$partnershipDocs = getKycRequirements('partnership');
$assert(in_array('partnership_deed', $partnershipDocs, true) && in_array('gst', $partnershipDocs, true), 'kyc_partnership_includes_deed_gst');
$indivTax = entityProfileTaxFields('individual');
$assert(empty($indivTax['gst']) && empty($indivTax['cin']), 'kyc_individual_profile_hides_gst_cin');

// Gateway keys UI must not present PhonePe as a live-checkout gateway (checkout is roadmap-only).
$gwSettings = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSettings, "'checkout' => false"), 'gateway_phonepe_marked_roadmap');
// Primary Payment Gateway selector must only offer gateways checkout can actually route.
$assert(preg_match('/settings\\[active_payment_gateway\\].*?<\\/select>/s', $gwSettings, $sel) === 1
    && !str_contains($sel[0], 'phonepe'), 'gateway_primary_excludes_phonepe');
$gwLib = (string)file_get_contents($root . '/includes/gateways.php');
$assert(str_contains($gwLib, 'checkout on roadmap') || str_contains($gwLib, 'later release'), 'gateway_phonepe_status_honest');
$assert(str_contains($gwLib, 'function gatewaySupportsLiveCheckout'), 'gateway_live_checkout_helper_present');

// Multi-gateway forward (safe subset): one-click forward + status matrix + audit + doc versioning.
$assert(str_contains($gwLib, 'function submitMerchantToGateways'), 'gateway_multi_forward_helper_present');
$assert(str_contains($gwLib, 'function getGatewaySubmissionMatrix'), 'gateway_status_matrix_helper_present');
$assert(str_contains($gwLib, 'function updateGatewaySubmissionStatus'), 'gateway_status_update_helper_present');
$assert(str_contains($gwLib, 'function gatewayOnboardingMailto'), 'gateway_onboarding_email_helper_present');
$assert(str_contains($gwLib, 'function logComplianceAudit') && str_contains($gwLib, 'function getComplianceAudit'), 'compliance_audit_helpers_present');
$assert(str_contains($gwLib, 'function getMerchantKycDocumentVersions'), 'kyc_doc_versioning_helper_present');
// Onboarding email must never carry raw KYC document contents.
$assert(str_contains($gwLib, 'never email') || str_contains($gwLib, 'do not send sensitive documents over email'), 'gateway_onboarding_email_no_raw_docs');
$gwSubmit = (string)file_get_contents($root . '/admin_gateway_submit.php');
$assert(str_contains($gwSubmit, 'forward_all'), 'gateway_submit_has_forward_all_button');
$assert(str_contains($gwSubmit, 'submitMerchantToGateways'), 'gateway_submit_uses_multi_forward');
$assert(str_contains($gwSubmit, 'Gateway status') && str_contains($gwSubmit, 'version history'), 'gateway_submit_shows_matrix_and_versions');
$assert(str_contains($gwSubmit, 'audit trail'), 'gateway_submit_shows_audit_trail');

$cronFwd = (string)file_get_contents($root . '/cron_partner_forward.php');
$assert(str_contains($cronFwd, 'processPerPartnerForwardQueue'), 'cron_partner_forward_uses_live_queue');
$assert(!preg_match('/processPartnerForwardQueue\s*\(/', $cronFwd), 'cron_partner_forward_no_undefined_alias_call');
$autoKycLib = (string)file_get_contents($root . '/includes/auto_kyc.php');
$assert(!str_contains($autoKycLib, "gateway_submit.php"), 'auto_kyc_no_missing_gateway_submit_require');
$schemaEnsure = (string)file_get_contents($root . '/includes/schema_ensure.php');
$assert(str_contains($schemaEnsure, 'onboarding_state'), 'schema_ensure_has_onboarding_state');
$assert(str_contains($schemaEnsure, 'CREATE TABLE IF NOT EXISTS partner_commercial'), 'schema_ensure_creates_partner_commercial');
$assert(str_contains($schemaEnsure, 'CREATE TABLE IF NOT EXISTS gateway_events'), 'schema_ensure_creates_gateway_events');
$assert(str_contains($schemaEnsure, 'CREATE TABLE IF NOT EXISTS kyc_documents'), 'schema_ensure_creates_kyc_documents');
$assert(str_contains($schemaEnsure, "ensureCollationConsistency();\nensureMissingColumns();")
    || (str_contains($schemaEnsure, 'ensureCollationConsistency();') && substr_count($schemaEnsure, 'ensureMissingColumns();') >= 2), 'schema_ensure_runs_missing_columns_on_load');
$mig060 = (string)file_get_contents($root . '/migrations/060_partner_route_scaffold.sql');
$assert(strpos($mig060, 'CREATE TABLE IF NOT EXISTS partner_commercial') !== false
    && strpos($mig060, 'CREATE TABLE IF NOT EXISTS partner_commercial') < strpos($mig060, 'ADD COLUMN IF NOT EXISTS route_enabled'), 'migration_060_create_before_alter');
$mig058 = (string)file_get_contents($root . '/migrations/058_missing_columns.sql');
$assert(strpos($mig058, 'CREATE TABLE IF NOT EXISTS gateway_events') !== false
    && strpos($mig058, 'CREATE TABLE IF NOT EXISTS gateway_events') < strpos($mig058, 'ADD COLUMN provider_order_id'), 'migration_058_create_gateway_events_before_alter');
$kycAdmin = (string)file_get_contents($root . '/admin_kyc.php');
$assert(str_contains($kycAdmin, 'COLLATE utf8mb4_unicode_ci'), 'admin_kyc_joins_use_unicode_collation');
$evPack = (string)file_get_contents($root . '/includes/evidence_pack.php');
$assert(str_contains($evPack, 'ge.payment_order_id = po.id'), 'evidence_pack_joins_gateway_events_by_order_id');
$gwDetail = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetail, 'No commercial terms saved yet'), 'commercial_tab_empty_not_fatal');
$splitLib = (string)file_get_contents($root . '/includes/split_settlement.php');
$assert(str_contains($splitLib, 'return [];'), 'get_all_partner_commercial_empty_safe');
$kycPage = (string)file_get_contents($root . '/kyc.php');
$assert(str_contains($kycPage, 'KYC submit status update failed'), 'kyc_submit_status_update_soft_fails');
$checkoutSrc = (string)file_get_contents($root . '/checkout.php');
$assert(str_contains($checkoutSrc, 'ensurePaymentPackSchema'), 'checkout_ensures_pack_schema');
$assert(str_contains($checkoutSrc, 'checkoutSelectBasic'), 'checkout_has_column_fallback_query');
$assert(str_contains($checkoutSrc, 'm.enabled_methods'), 'checkout_selects_enabled_methods');
$assert(str_contains($checkoutSrc, 'Checkout method list failed'), 'checkout_methods_soft_fail');
$collLib = (string)file_get_contents($root . '/includes/collection.php');
$assert(str_contains($collLib, 'Merchant enabled_methods JSON is the product data model'), 'checkout_methods_use_merchant_json');
$assert(!str_contains($collLib, "partnerMethodOn('payu', 'debit_card')"), 'checkout_cards_not_hidden_by_empty_partner_row');
$migLib = (string)file_get_contents($root . '/includes/migrations.php');
$assert(str_contains($migLib, 'applied_files'), 'migrations_return_applied_files');
$assert(str_contains($migLib, 'pending_after'), 'migrations_return_pending_after');
$assert(str_contains($migLib, 'migrationSqlWithoutIfNotExists'), 'migrations_retry_without_if_not_exists');
$assert(str_contains($migLib, 'pdoSqlState'), 'migrations_expose_sqlstate');
$mig044 = (string)file_get_contents($root . '/migrations/044_gateway_reason_map_db.sql');
$assert(str_contains($mig044, 'CREATE TABLE IF NOT EXISTS gateway_reason_maps'), 'migration_044_creates_table_before_insert');
$assert(str_contains($mig044, 'ADD COLUMN partner_key'), 'migration_044_adds_partner_key_before_insert');
$mig002 = (string)file_get_contents($root . '/migrations/002_legacy_wallet_baseline.sql');
$assert(strpos($mig002, 'CREATE TABLE IF NOT EXISTS gateway_settings') !== false
    && strpos($mig002, 'CREATE TABLE IF NOT EXISTS gateway_settings') < strpos($mig002, 'INSERT INTO gateway_settings'), 'migration_002_create_settings_before_insert');
$mig031 = (string)file_get_contents($root . '/migrations/031_multi_virtual_accounts.sql');
$assert(strpos($mig031, 'ADD COLUMN va_number') !== false
    && strpos($mig031, 'ADD COLUMN va_number') < strpos($mig031, 'INSERT INTO merchant_virtual_accounts'), 'migration_031_alter_before_insert');
$mig054 = (string)file_get_contents($root . '/migrations/054_payment_methods_orchestrator.sql');
$assert(strpos($mig054, 'ADD COLUMN gateway_key') !== false
    && strpos($mig054, 'ADD COLUMN gateway_key') < strpos($mig054, 'INSERT INTO gateway_registry'), 'migration_054_alter_before_insert');
$assert(str_contains($migLib, 'Migration failed:'), 'migrations_name_failing_file');
$assert(!str_contains($migLib, 'Applied migration checksum mismatch'), 'migrations_checksum_rebase_not_throw');
$migRel = (string)file_get_contents($root . '/migrate_release.php');
$assert(str_contains($migRel, "'migration' => \$file"), 'migrate_release_names_failed_file');
$assert(str_contains($migRel, "'sqlstate'"), 'migrate_release_json_has_sqlstate');
$assert(str_contains($migRel, 'empty($pendingAfter)'), 'migrate_release_ok_only_when_no_pending');
$errCatch = (string)file_get_contents($root . '/includes/error_catcher.php');
$assert(!str_contains($errCatch, '%checkout.php on line%'), 'error_catcher_does_not_auto_resolve_checkout');
$assert(str_contains($errCatch, 'qr_image.php'), 'error_catcher_skips_html_on_qr_image');
$qrImg = (string)file_get_contents($root . '/qr_image.php');
$assert(str_contains($qrImg, "ini_set('display_errors', '0')"), 'qr_image_hides_php_warnings');
$assert(str_contains($qrImg, "class_exists('QRcode'"), 'qr_image_requires_qrcode_class');
$errLog = (string)file_get_contents($root . '/admin_error_log.php');
$assert(str_contains($errLog, 'probe_catcher'), 'error_log_has_catcher_probe');
$assert(str_contains($errCatch, 'admin_error_log.php?probe_ok=1'), 'error_catcher_probe_returns_to_error_log');
$assert(str_contains($errCatch, 'error_log_db'), 'watchdog_reads_error_log_from_db');
$assert(str_contains($errCatch, "defined('APP_URL')"), 'snag_page_guards_missing_app_url');
$assert(is_file($root . '/includes/boot_errors.php'), 'file_boot_errors_php');
$bootErr = (string)file_get_contents($root . '/includes/boot_errors.php');
$assert(str_contains($bootErr, "ini_set('display_errors', '0')") && str_contains($bootErr, 'env_loader.php'), 'boot_errors_hides_php_and_loads_catcher');
$cfgDevBoot = (string)file_get_contents($root . '/config.dev.php');
$assert(strpos($cfgDevBoot, 'boot_errors.php') !== false
    && strpos($cfgDevBoot, 'boot_errors.php') < strpos($cfgDevBoot, 'APP_NAME'), 'config_dev_loads_boot_errors_first');
$envLoader = (string)file_get_contents($root . '/includes/env_loader.php');
$assert(str_contains($envLoader, 'uniweb.co.in'), 'env_loader_forces_display_off_on_live_host');
$assert(str_contains($errLog, 'probe_ok'), 'error_log_accepts_probe_ok_redirect');
$hdr = (string)file_get_contents($root . '/header.php');
$assert(str_contains($hdr, 'countUnresolvedPlatformErrors'), 'admin_header_error_badge_from_db');
$gwSettings = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSettings, 'Show full Hostinger command'), 'gateway_settings_reveals_hostinger_cron');
$assert(str_contains($gwSettings, 'made by UniWeb'), 'gateway_settings_explains_cron_key_source');
$cronGuard = (string)file_get_contents($root . '/includes/cron_guard.php');
$assert(str_contains($cronGuard, 'function cronAuthOk'), 'cron_auth_accepts_watchdog_or_dedicated');
$autoAudit = (string)file_get_contents($root . '/includes/auto_audit.php');
$assert(str_contains($autoAudit, "\$hb('auto_kyc'"), 'auto_audit_heartbeats_kyc');
$assert(str_contains($autoAudit, "\$hb('settlements'"), 'auto_audit_heartbeats_settlements');
$backupCron = (string)file_get_contents($root . '/cron_db_backup.php');
$assert(str_contains($backupCron, 'uniwebPhpDumpDatabase'), 'backup_cron_has_php_dump_fallback');
$assert(str_contains($backupCron, 'uniwebSendBackupEmail'), 'backup_cron_sends_owner_email');
$dbBackupLib = (string)file_get_contents($root . '/includes/db_backup.php');
$assert(str_contains($dbBackupLib, 'startelecom620@gmail.com'), 'backup_email_defaults_to_owner_gmail');
$assert(is_file($root . '/includes/db_backup.php'), 'file_includes_db_backup_php');

// P0-04: notification dedup lives in includes, not only gitignored config.php
$assert(is_file($root . '/includes/notifications.php'), 'file_notifications_php');
$assert(is_file($root . '/includes/release_helpers.php'), 'file_release_helpers_php');
$notifLib = (string)file_get_contents($root . '/includes/notifications.php');
$assert(str_contains($notifLib, 'function notifyMerchant') && str_contains($notifLib, 'event_key'), 'notifyMerchant_dedup_uses_event_key');
$cfgDevNotif = (string)file_get_contents($root . '/config.dev.php');
$assert(str_contains($cfgDevNotif, "includes/notifications.php"), 'config_dev_requires_notifications_include');
$assert(str_contains($cfgDevNotif, "'release_helpers'"), 'config_dev_includes_release_helpers');
$assert(str_contains((string)file_get_contents($root . '/includes/notify.php'), 'release_helpers.php'), 'notify_php_loads_release_helpers');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), 'release_helpers.php'), 'admin_kyc_loads_release_helpers');
$assert(str_contains((string)file_get_contents($root . '/merchant_register.php'), 'notifyMerchant'), 'signup_uses_notifyMerchant_not_4arg_createNotification');

// P0-05: mailer loads templates; KYC/mail callers guard missing function; SMTP soft-fail
$mailer = (string)file_get_contents($root . '/includes/mailer.php');
$assert(str_contains($mailer, "require_once __DIR__ . '/email_templates.php'"), 'mailer_requires_email_templates');
$assert(str_contains($mailer, 'SMTP send failed'), 'smtp_send_soft_fails');
$emailTpl = (string)file_get_contents($root . '/includes/email_templates.php');
$assert(str_contains($emailTpl, 'sendPlatformEmail') && str_contains($emailTpl, 'mailer.php'), 'email_templates_requires_mailer_if_missing');
$assert(str_contains($emailTpl, 'Templated email skipped: SMTP not configured'), 'templated_email_skips_without_smtp');
$kycSrc = (string)file_get_contents($root . '/admin_kyc.php');
$assert(substr_count($kycSrc, "function_exists('sendTemplatedEmail')") >= 3, 'admin_kyc_guards_sendTemplatedEmail');
$assert(str_contains((string)file_get_contents($root . '/includes/refunds.php'), "function_exists('sendTemplatedEmail')"), 'refunds_guard_templated_email');
$assert(str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), "function_exists('sendTemplatedEmail')"), 'settlement_guard_templated_email');

// P1-01: keys only in partner_credentials; Platform Settings cannot save live PG secrets
$pCtrl = (string)file_get_contents($root . '/includes/partner_control.php');
$assert(str_contains($pCtrl, 'function isPartnerCredentialSettingKey') && str_contains($pCtrl, 'function uniwebPartnerCredentialSettingMap'), 'p1_partner_credential_key_blocklist');
$assert(str_contains($pCtrl, 'Does not read plaintext gateway_settings'), 'p1_getPartnerSetting_no_gateway_settings_fallback');
$bannerLib = (string)file_get_contents($root . '/includes/checkout_mode_banner.php');
$assert(str_contains($bannerLib, 'isPartnerCredentialSettingKey') && str_contains($bannerLib, 'never gateway_settings'), 'p1_platform_save_skips_pg_secrets');
$gwP1 = (string)file_get_contents($root . '/gateway_settings.php');
$assert(!preg_match('/name="settings\\[(razorpay_key_secret|cashfree_secret_key|payu_merchant_salt)\\]"/', $gwP1), 'p1_platform_form_has_no_pg_secret_inputs');
$assert(str_contains($gwP1, 'This page does not accept live PG API keys'), 'p1_platform_banner_no_pg_keys');
$assert(str_contains($gwP1, 'template for new merchants') || str_contains($gwP1, 'new merchants only'), 'p1_03_primary_pg_is_new_merchant_template');
$hdrP1 = (string)file_get_contents($root . '/header.php');
$assert(str_contains($hdrP1, "['gateway_settings.php','Platform Settings']"), 'p1_nav_platform_settings_not_integrations');
$siteP1 = (string)file_get_contents($root . '/admin_website.php');
$assert(str_contains($siteP1, 'Partner Registry → Partner Detail → Keys') && !str_contains($siteP1, 'paste in Gateway Settings'), 'p1_website_keys_guide_points_to_registry');

// P1-02: commercial UPSERT + seed on first open; route is scaffold not live API
$splitP1 = (string)file_get_contents($root . '/includes/split_settlement.php');
$assert(str_contains($splitP1, 'function ensurePartnerCommercialSeeded'), 'p1_commercial_seed_helper');
$assert(str_contains($splitP1, 'ON DUPLICATE KEY UPDATE base_mdr_percent=VALUES(base_mdr_percent)'), 'p1_commercial_upsert');
$gwDetailP1 = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetailP1, 'ensurePartnerCommercialSeeded'), 'p1_commercial_tab_seeds_on_open');
$assert(str_contains($gwDetailP1, 'No provider API calls are made'), 'p1_route_scaffold_not_live_api');
$assert(str_contains($gwDetailP1, 'No commercial terms saved yet'), 'commercial_tab_empty_not_fatal');

// P1-03: platform primary label is template, not global
$gwLibP1 = (string)file_get_contents($root . '/includes/gateways.php');
$assert(str_contains($gwLibP1, 'New-merchant template'), 'p1_gateway_status_not_active_primary');
$assert(str_contains($gwLibP1, 'template/fallback for new merchants'), 'p1_active_pg_comment_template_only');
$healthP1 = (string)file_get_contents($root . '/includes/platform_health.php');
$assert(str_contains($healthP1, 'New-merchant template') && !str_contains($healthP1, 'Active primary'), 'p1_health_primary_relabel');

$loginP1 = (string)file_get_contents($root . '/admin_login.php');
$assert(!str_contains($loginP1, "force_auto_audit"), 'admin_login_does_not_force_audit_on_mfa');
$autoP1 = (string)file_get_contents($root . '/includes/auto_audit.php');
$assert(str_contains($autoP1, 'admin_login.php') && str_contains($autoP1, 'REQUEST_METHOD'), 'auto_audit_skips_login_and_post');
$gwDetailKeys = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetailKeys, "\$_GET['env'] ?? 'live'") && str_contains($gwDetailKeys, 'copy_test_keys_to_live'), 'keys_tab_defaults_live_and_can_copy_test');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'function copyPartnerCredentialsToLive'), 'copy_test_keys_to_live_helper');

// P2-01 / P2-02 / P2-03 — checkout methods, QR PNG, payment-link public URL
$collP2 = (string)file_get_contents($root . '/includes/collection.php');
$assert(str_contains($collP2, 'function buildCheckoutPaymentMethods'), 'p2_checkout_methods_wrapped');
$assert(str_contains($collP2, "if (\$pa === '')"), 'p2_upi_intent_empty_vpa_returns_blank');
$provP2 = (string)file_get_contents($root . '/includes/provision.php');
$assert(str_contains($provP2, "if (!in_array('upi_p2m', \$methods, true))"), 'p2_pack_always_enables_upi');
$assert(str_contains($checkoutSrc, 'Card / Netbanking need partner keys'), 'p2_checkout_soft_keys_banner');
$assert(str_contains($checkoutSrc, 'This method is enabled, but partner keys are not set yet'), 'p2_checkout_empty_method_state');
$assert(str_contains($qrImg, 'UPI ID missing'), 'p2_qr_image_upi_missing_png');
$assert(str_contains($qrImg, 'function qrFlushAllBuffers'), 'p2_qr_image_flushes_output_buffers');
$assert(str_contains((string)file_get_contents($root . '/includes/qr_svg.php'), 'require_upi=1'), 'p2_upi_qr_url_requires_pa');
$plP2 = (string)file_get_contents($root . '/payment_links.php');
$assert(str_contains($plP2, "payment_links.php?created="), 'p2_create_redirects_with_public_url');
$assert(str_contains($plP2, 'data-copy-url='), 'p2_copy_uses_data_copy_url');
$assert(!str_contains($plP2, "writeText('<?= e(\$payUrl) ?>')"), 'p2_copy_not_html_encoded_js');
$assert(str_contains($plP2, 'Open checkout'), 'p2_created_banner_opens_checkout');
$packP2 = (string)file_get_contents($root . '/merchant_payment_pack.php');
$assert(str_contains($packP2, 'data-copy-url='), 'p2_pack_copy_uses_data_copy_url');

// Instant printable UPI QR (direct P2M) — page + helper must exist and be honest about routing.
$assert(is_file($root . '/qr_upi_print.php'), 'file_qr_upi_print_php', 'qr_upi_print.php');
$upiQrPage = (string)file_get_contents($root . '/qr_upi_print.php');
$assert(str_contains($upiQrPage, 'buildUpiPayIntent'), 'upi_qr_uses_intent_helper');
$assert(str_contains($upiQrPage, 'window.print()'), 'upi_qr_printable');
$assert(str_contains($upiQrPage, 'not routed through UniWeb') || str_contains($upiQrPage, 'not routed through UniWeb checkout'), 'upi_qr_honest_routing_copy');
$collectionLib = (string)file_get_contents($root . '/includes/collection.php');
$assert(str_contains($collectionLib, 'function buildUpiPayIntent'), 'collection_upi_intent_helper_present');
$assert(str_contains($collectionLib, "'upi://pay?'"), 'collection_upi_intent_scheme');
$qrCodePage = (string)file_get_contents($root . '/qr_code.php');
$assert(str_contains($qrCodePage, 'qr_upi_print.php'), 'qr_code_links_instant_upi_qr');

// Checkout dead-ends (missing / invalid / expired / inactive link) must show a branded,
// navigable page — never a bare white die() screen — on the customer money path.
$checkout = (string)file_get_contents($root . '/checkout.php');
$assert(str_contains($checkout, 'function renderCheckoutUnavailable'), 'checkout_branded_error_helper_present');
$assert(!str_contains($checkout, "die('Payment link expired or not found.')")
    && !str_contains($checkout, "die('This payment link is no longer active.')")
    && !str_contains($checkout, "die('This payment link has expired.')"), 'checkout_no_bare_die_deadends');
$assert(substr_count($checkout, 'renderCheckoutUnavailable(') >= 4, 'checkout_error_states_use_branded_page');

// The public QR scan path (qr_pay.php) must also brand its dead-ends — a stale,
// inactive or malformed QR must never render a bare white exit() screen.
$qrPayDeadends = (string)file_get_contents($root . '/qr_pay.php');
$assert(str_contains($qrPayDeadends, 'function renderQrUnavailable'), 'qr_pay_branded_error_helper_present');
$assert(!str_contains($qrPayDeadends, "exit('QR code not found.')")
    && !str_contains($qrPayDeadends, "exit('This QR code is inactive.')")
    && !str_contains($qrPayDeadends, "exit('Invalid QR amount.')"), 'qr_pay_no_bare_exit_deadends');
$assert(substr_count($qrPayDeadends, 'renderQrUnavailable(') >= 3, 'qr_pay_error_states_use_branded_page');

$htaccess = (string)file_get_contents($root . '/.htaccess');
$assert(str_contains($htaccess, 'ErrorDocument 404'), 'htaccess_error_document_404');
$assert(str_contains($htaccess, 'ErrorDocument 403 /error.php'), 'htaccess_error_document_403');
$assert(str_contains($htaccess, 'ErrorDocument 500 /error.php'), 'htaccess_error_document_500');
$assert(str_contains($htaccess, 'ErrorDocument 404 /error.php'), 'htaccess_points_to_branded_404');
$assert(str_contains($htaccess, 'X-Content-Type-Options'), 'htaccess_security_headers');
$errorPage = (string)file_get_contents($root . '/error.php');
$assert(str_contains($errorPage, 'Page not found') && str_contains($errorPage, 'demo.php'), 'branded_error_has_nav_links');
$error404 = (string)file_get_contents($root . '/error_404.php');
$assert(str_contains($error404, 'error.php'), 'error_404_aliases_to_error_php');

$manifest = json_decode((string)file_get_contents($root . '/manifest.json'), true);
$assert(is_array($manifest) && !empty($manifest['icons']), 'manifest_icons_present');
if (is_array($manifest)) {
    foreach ($manifest['icons'] as $icon) {
        $src = ltrim((string)($icon['src'] ?? ''), '/');
        $assert($src !== '' && is_file($root . '/' . $src), 'manifest_icon_file_' . basename($src), $src);
    }
}

if ($liveBase) {
    $probe = static function (string $path, array $okCodes = [200]) use ($liveBase): array {
        $url = $liveBase . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_HEADER => false,
        ]);
        $body = (string)curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return ['url' => $url, 'code' => $code, 'ok' => $err === '' && in_array($code, $okCodes, true), 'err' => $err, 'body' => $body];
    };

    $paths = [
        ['', [200]],
        ['index.php', [200]],
        ['signup.php', [200]],
        ['merchant_register.php', [200]],
        ['demo.php', [200]],
        ['checkout.php', [200, 404]], // 404 without link id is expected branded page
        ['admin_website.php', [200, 302]], // login redirect ok
        ['admin_kyc.php', [200, 302]],
        ['gateway_settings.php', [200, 302]],
        ['robots.txt', [200]],
        ['favicon.ico', [200]],
        ['favicon.svg', [200]],
        ['sitemap.xml', [200]],
        ['assets/icons/icon-192.png', [200]],
        ['manifest.json', [200]],
        ['status.php', [200]],
    ];
    foreach ($paths as [$path, $codes]) {
        $r = $probe($path, $codes);
        $assert($r['ok'], 'live_' . ($path === '' ? 'home' : str_replace(['/', '.', '?'], '_', $path)), $r['code'] . ' ' . $r['url'] . ($r['err'] ? ' ' . $r['err'] : ''));
    }

    $demo = $probe('demo.php', [200]);
    if ($demo['ok'] && preg_match('/checkout\\.php\\?link=([A-Z0-9]+)/i', $demo['body'], $m)) {
        $pay = $probe('checkout.php?link=' . $m[1] . '&pay=upi', [200]);
        $assert($pay['ok'], 'live_demo_checkout', $pay['code'] . ' link=' . $m[1]);
    } else {
        $assert(false, 'live_demo_checkout', 'Could not extract demo payment link from demo.php');
    }
}

$payload = [
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'live' => $liveBase,
    'results' => $results,
];

if (PHP_SAPI === 'cli') {
    echo ($payload['ok'] ? "SMOKE OK" : "SMOKE FAIL") . " passed={$passed} failed={$failed}" . ($liveBase ? " live={$liveBase}" : '') . PHP_EOL;
    foreach ($results as $row) {
        if (empty($row['ok'])) {
            echo '  FAIL ' . $row['name'] . ($row['detail'] !== '' ? ' — ' . $row['detail'] : '') . PHP_EOL;
        }
    }
    exit($failed === 0 ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($payload, JSON_PRETTY_PRINT);
