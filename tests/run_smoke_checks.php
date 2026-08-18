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
    'merchant_register.php',
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
$sidebarNav = (string)file_get_contents($root . '/includes/sidebar_nav.php');
$navSrc = $header . "\n" . $sidebarNav;
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
$assert(str_contains($navSrc, 'admin_audit_plan.php'), 'admin_nav_has_audit_plan');
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
$assert(str_contains($navSrc, 'admin_customer_tickets.php'), 'admin_nav_has_customer_complaints');
$assert(str_contains($navSrc, 'merchant_customer_tickets.php'), 'merchant_nav_has_customer_complaints');
$staffNavSrc = (string)file_get_contents($root . '/includes/staff.php');
$assert(str_contains($staffNavSrc, "'admin_customer_tickets.php'") && str_contains($staffNavSrc, 'Customer Complaints'), 'staff_nav_has_customer_complaints');
$assert(is_file($root . '/merchant_customer_tickets.php'), 'merchant_customer_tickets_page_present');
$mCust = (string)file_get_contents($root . '/merchant_customer_tickets.php');
$assert(str_contains($mCust, 'getMerchantCustomerTicket') && str_contains($mCust, 'replyToCustomerTicket'), 'merchant_customer_tickets_scoped_reply');
$assert(is_file($root . '/migrations/010_customer_portal.sql'), 'customer_portal_migration_present');
$assert(is_file($root . '/migrations/016_customer_ticket_roles.sql'), 'customer_ticket_roles_migration_present');
$teamSrc = (string)file_get_contents($root . '/includes/merchant_team.php');
$assert(str_contains($teamSrc, "'support'") && str_contains($teamSrc, 'customer complaints'), 'merchant_team_support_capability');
$assert(str_contains($teamSrc, "'support' => ['label' => 'Support'"), 'merchant_team_support_selectable_role');

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
$assert(is_file($root . '/merchant_instant_settlement.php'), 'instant_settlement_page');
$assert(!is_file($root . '/merchant_nbfc.php') && !is_file($root . '/merchant_nbfc_loan.php') && !is_file($root . '/admin_nbfc.php') && !is_file($root . '/includes/nbfc.php'), 'nbfc_product_removed');
$assert(!is_file($root . '/customer_wallet.php'), 'no_customer_ppi_wallet_page');
$payoutLib2 = (string)file_get_contents($root . '/includes/payout.php');
$assert(str_contains($payoutLib2, 'function dispatchQueuedPayouts'), 'payout_dispatch_queued');
$gwSet = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($gwSet, 'method_partner_webhook.php') && str_contains($gwSet, 'Method partner webhook URL'), 'method_webhook_url_ui');
$gwDetail = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetail, 'method_partner_webhook.php') || str_contains($gwDetail, 'Method partner webhook'), 'method_webhook_url_in_detail_page');
$settleDue = (string)file_get_contents($root . '/includes/settlement_engine.php');
$assert(str_contains($settleDue, 'merchantSettlementDelayMinutes'), 'settlement_delay_wired');
$prov = (string)file_get_contents($root . '/includes/provision.php');
$assert(!str_contains($prov, "'nbfc'") && str_contains($prov, "'instant_settlement'"), 'catalog_no_nbfc_keeps_instant');
$reg = (string)file_get_contents($root . '/merchant_register.php');
$assert(str_contains($reg, 'bootstrapMerchantMethodAutomation'), 'signup_auto_queues_methods');
$assert(str_contains($mReq, 'function merchantEntitledMethods') && str_contains($mReq, 'function merchantLockedMethods'), 'method_request_entitlement_helpers');
$colPage = (string)file_get_contents($root . '/collection_settings.php');
$pmPage = (string)file_get_contents($root . '/payment_methods.php');
$assert(str_contains($pmPage, 'Payment Methods') && str_contains($pmPage, 'pm-toggle'), 'payment_methods_page_has_toggles');
// 1b: Settings hub "Payment Methods" card must open payment_methods.php (not Collection Mode)
$ms1b = (string)file_get_contents($root . '/merchant_settings.php');
$assert(str_contains($ms1b, "['payment_methods.php', 'Payment Methods'") && str_contains($ms1b, "['collection_settings.php', 'Collection Mode'"), 'p1b_settings_payment_methods_card_correct');
$assert(str_contains((string)file_get_contents($root . '/payment_links.php'), 'href="payment_methods.php"') && !str_contains((string)file_get_contents($root . '/payment_links.php'), 'href="collection_settings.php" class="underline">Payment Methods'), 'p1b_payment_links_methods_href');
$assert(str_contains($colPage, 'payment_methods.php'), 'collection_settings_links_to_payment_methods');
$assert(!str_contains($colPage, 'merchant_nbfc.php') && !str_contains($pmPage, 'merchant_nbfc.php'), 'no_nbfc_links_in_method_uis');
$assert(str_contains($colPage, 'merchantEntitledMethods(') || str_contains($colPage, 'enabled_methods') || str_contains($colPage, 'renderMerchantMethodRequestSection'), 'collection_settings_gated_by_entitlement');
$assert(str_contains($colPage, 'renderMerchantMethodRequestSection') && str_contains($colPage, "'form_action' => 'collection_settings.php'"), 'collection_settings_request_ui');
$assert(str_contains($mReq, 'Request enable') && str_contains($mReq, 'function renderMerchantMethodRequestSection'), 'collection_settings_shared_request_panel');
$assert(is_file($root . '/admin_method_requests.php'), 'admin_method_requests_page_present');
$assert(str_contains($navSrc, 'admin_method_requests.php'), 'admin_nav_has_method_requests');
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
$assert(str_contains($navSrc, 'admin_payout.php') && str_contains($navSrc, 'merchant_payout.php'), 'nav_has_payout_pages');
$assert(str_contains($navSrc, 'merchant_payout_keys.php'), 'nav_has_payout_api_keys');
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
$assert(str_contains($navSrc, "'collapsed' => true") && (str_contains($navSrc, "'title' => 'Advanced · Risk'") || str_contains($navSrc, "'title' => 'Advanced'")), 'p4_admin_nav_advanced_collapsed');
$assert(str_contains($navSrc, 'Advanced · Risk') && str_contains($navSrc, 'Advanced · Money') && str_contains($navSrc, 'Advanced · Ops') && str_contains($navSrc, 'Advanced · Security'), 'ia02_advanced_subgroups');
$assert(!is_file($root . '/admin_nbfc.php') && !is_file($root . '/merchant_nbfc.php'), 'p4_nbfc_pages_gone');
$morningP4 = (string)file_get_contents($root . '/includes/morning_ops.php');
$assert(str_contains($morningP4, "function_exists('getPendingKycQueue')"), 'p4_morning_ops_no_queue_redeclare');

// P4-M01 / P4-M02 / P4-SM01 — full merchant menu, settlement labels, sub-merchant rules
$assert(str_contains($navSrc, 'qr_upi_print.php') && str_contains($navSrc, 'Instant UPI QR'), 'p4_merchant_nav_instant_upi_qr');
$assert(str_contains($navSrc, 'add_bank.php') && str_contains($navSrc, 'Settlement Bank'), 'p4_merchant_nav_settlement_bank');
$assert(str_contains($navSrc, 'Collect / P2M') && str_contains($navSrc, "'title' => 'Settlements'"), 'p4_merchant_nav_full_groups');
$assert(str_contains($header, 'merchant-group-panel') && (str_contains($header, "max-height:<?= \$isOpen ? '2000'") || str_contains($header, "max-height:<?= \$isOpen ? (!empty(\$group['collapsed'])")), 'p4_merchant_groups_open_full');
$assert(!str_contains($navSrc, 'merchant_nbfc.php') && !str_contains($navSrc, 'admin_nbfc.php'), 'p4_nav_has_no_nbfc_urls');
$assert(str_contains($navSrc, "['admin_payment_links.php', 'Payment Links']") && str_contains($navSrc, "['admin_qr_codes.php', 'QR Codes']"), 'sum_admin_payment_links_qr_in_nav');
$assert(!str_contains($navSrc, "['admin_link_audit.php'") && !str_contains($navSrc, "['admin_throughput.php'"), 'sum_no_dup_watchdog_throughput_nav');
$assert(!str_contains($navSrc, "['video_kyc.php'"), 'sum_video_kyc_not_separate_nav');
$assert(is_file($root . '/pci.php') && str_contains((string)file_get_contents($root . '/pci.php'), 'pci_dss.php'), 'sum_pci_alias_redirect');
$assert(str_contains((string)file_get_contents($root . '/admin_throughput.php'), 'admin_transaction_monitor.php'), 'sum_throughput_redirects_to_monitor');
$assert(str_contains((string)file_get_contents($root . '/chargebacks.php'), '>Chargebacks</h2>') && !str_contains((string)file_get_contents($root . '/chargebacks.php'), 'Disputes &amp; chargebacks'), 'dup05_chargebacks_title_only');
$colSetDup = (string)file_get_contents($root . '/collection_settings.php');
$assert(str_contains($colSetDup, 'do not dual-write enabled_methods') && !str_contains($colSetDup, 'enabled_methods=?'), 'dup02_collection_no_method_dual_write');
$assert(str_contains((string)file_get_contents($root . '/admin_financial_reports.php'), 'Reports hub') && str_contains((string)file_get_contents($root . '/admin_reports.php'), 'Reports hub'), 'dup03_reports_hub_tabs');
$walletIconPos = strpos($navSrc, "['wallet.php', 'Settlement Balance', '");
$stlIconPos = strpos($navSrc, "['settlements.php'");
$assert($walletIconPos !== false && $stlIconPos !== false, 'dup01_balance_and_settlements_in_nav');
$walletIconSnippet = $walletIconPos !== false ? substr($navSrc, $walletIconPos, 220) : '';
$stlIconSnippet = $stlIconPos !== false ? substr($navSrc, $stlIconPos, 280) : '';
$assert(str_contains($walletIconSnippet, 'M17 9V7a2 2 0 00-2-2H5') && str_contains($stlIconSnippet, 'M9 5H7a2 2 0 00-2 2v12'), 'dup01_distinct_balance_settlements_icons');
$assert(str_contains((string)file_get_contents($root . '/includes/page_ux.php'), 'function uxSoftErrorExit'), 'sum_soft_error_helper');
$assert(str_contains((string)file_get_contents($root . '/invoice_pdf.php'), 'uxSoftErrorExit') && str_contains((string)file_get_contents($root . '/admin_kyc_doc.php'), 'uxSoftErrorExit'), 'sum_pdf_kyc_soft_errors');
$assert(str_contains((string)file_get_contents($root . '/solutions.php'), 'Settlements & balance'), 'sum_public_wallet_wording');
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), "'watchdog'") && str_contains((string)file_get_contents($root . '/global_search.php'), "'payment links'"), 'sum_search_aliases_expanded');
$assert(str_contains((string)file_get_contents($root . '/includes/staff.php'), 'admin_payment_links.php') && str_contains((string)file_get_contents($root . '/includes/staff.php'), 'admin_qr_codes.php'), 'sum_staff_nav_has_payment_links');
$assert(str_contains((string)file_get_contents($root . '/includes/page_ux.php'), 'function requireMerchantAccount'), 'tech03_require_merchant_account_helper');
$assert(str_contains((string)file_get_contents($root . '/merchant_launch.php'), 'requireMerchantAccount') && str_contains((string)file_get_contents($root . '/merchant_setup.php'), 'requireMerchantAccount'), 'tech03_launch_setup_use_helper');
$assert(str_contains((string)file_get_contents($root . '/dashboard.php'), 'requireMerchantAccount'), 'tech03_dashboard_uses_helper');
$assert(str_contains((string)file_get_contents($root . '/includes/ops_security.php'), 'isLoggedIn()') && str_contains((string)file_get_contents($root . '/includes/ops_security.php'), 'isAdminLoggedIn()'), 'tech01_abort_uses_real_login_checks');
$assert(str_contains((string)file_get_contents($root . '/includes/cloud_modules.php'), 'page_ux.php'), 'tech07_cloud_modules_loads_page_ux');
$walletP4 = (string)file_get_contents($root . '/wallet.php');
$assert(str_contains($walletP4, 'Settlement Balance') && str_contains($walletP4, 'not a customer PPI wallet'), 'p4_wallet_settlement_not_ppi');
$langP4 = (string)file_get_contents($root . '/lang/en.php');
$assert(str_contains($langP4, "'wallet_title' => 'Settlement Balance'"), 'p4_wallet_title_settlement');
$assert(str_contains($langP4, "'agents' => 'Agents'"), 'p4_agents_label_not_submerchant');
$agentsP4 = (string)file_get_contents($root . '/agents.php');
$assert(str_contains($agentsP4, 'Your Agents') && !str_contains($agentsP4, 'Your Sub-Merchants / Agents'), 'p4_agents_page_not_submerchant_heading');
$assert(str_contains($agentsP4, 'franchise') && str_contains($agentsP4, 'Team Members'), 'pnl_sm01_agents_documented_vs_team');
$assert(!str_contains($navSrc, "\$t('agents', 'Agents')") && str_contains($navSrc, 'Agents (franchise children)'), 'pnl_sm01_agents_hidden_from_nav');
$assert(str_contains((string)file_get_contents($root . '/merchant_instant_settlement.php'), 'Not a live instant bank payout') && str_contains((string)file_get_contents($root . '/merchant_instant_settlement.php'), 'partner keys'), 'pnl_m02_instant_settlement_honest');
$assert(!preg_match("/requireStaffAccess\(\[[^\]]*'\s*risk\s*'/", (string)file_get_contents($root . '/admin_risk_engine.php')), 'pnl_st02_no_phantom_risk_role');
$assert(str_contains((string)file_get_contents($root . '/includes/risk.php'), 'function riskHubNavHtml'), 'pnl_st03_risk_hub_helper');
$assert(str_contains((string)file_get_contents($root . '/admin_risk.php'), "riskHubNavHtml('rules')") && str_contains((string)file_get_contents($root . '/admin_aml.php'), "riskHubNavHtml('flags')") && str_contains((string)file_get_contents($root . '/admin_risk_engine.php'), "riskHubNavHtml('engine')"), 'pnl_st03_risk_hub_on_pages');
$staffNavSrcPnl = (string)file_get_contents($root . '/includes/staff.php');
$assert(str_contains($staffNavSrcPnl, 'admin_payment_links.php') && str_contains($staffNavSrcPnl, 'Do not add gateway_settings.php'), 'pnl_st01_staff_has_links_no_registry');
$assert(str_contains((string)file_get_contents($root . '/contact.php'), 'recordPublicContactInquiry'), 'pnl_pub01_contact_saves_ticket');
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

// P4-TM01 — merchant team invite, roles, audit
$teamSrcP4 = (string)file_get_contents($root . '/includes/merchant_team.php');
$assert(str_contains($teamSrcP4, 'merchant_team_events'), 'p4_team_events_schema');
$assert(str_contains($teamSrcP4, "'support' => ['label' => 'Support'"), 'p4_team_support_role');
$assert(str_contains($teamSrcP4, 'function merchantTeamCapabilityMatrix') && str_contains($teamSrcP4, 'function logMerchantTeamEvent'), 'p4_team_matrix_and_audit_helpers');
$teamPageP4 = (string)file_get_contents($root . '/merchant_team.php');
$assert(str_contains($teamPageP4, 'Role matrix') && (str_contains($teamPageP4, 'Team member activity') || str_contains($teamPageP4, 'Team activity')), 'p4_team_page_matrix_and_audit');
$assert(str_contains((string)file_get_contents($root . '/qr_upi_print.php'), 'bypass the UniWeb ledger') || str_contains((string)file_get_contents($root . '/qr_upi_print.php'), 'may bypass'), 'ia04_instant_upi_ledger_warning');
$assert(str_contains($navSrc, "'Team Members'") || str_contains($navSrc, 'Team Members'), 'ia06_merchant_team_members_label');
$assert(str_contains($navSrc, 'Employees / Staff'), 'ia06_admin_employees_staff_label');
$assert(str_contains((string)file_get_contents($root . '/solutions.php'), 'Settlements & balance'), 'ia05_solutions_settlement_balance_copy');
$assert(str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'Partner Registry → Partner Detail → Keys'), 'pnl_a01_keys_only_in_registry_banner');
$assert(str_contains($teamPageP4, "action\" value=\"role\"") || str_contains($teamPageP4, "name=\"action\" value=\"role\""), 'p4_team_role_change_ui');
$assert(str_contains($teamPageP4, 'data-copy-url='), 'p4_team_invite_copy_url');

// P4-C01 — customer portal is pay/support, not PPI wallet
$cpLibP4 = (string)file_get_contents($root . '/includes/customer_portal.php');
$assert(str_contains($cpLibP4, 'function customerPortalScopeCopy') && str_contains($cpLibP4, 'not a PPI'), 'p4_customer_scope_copy_helper');
$cpNavP4 = (string)file_get_contents($root . '/includes/customer_portal_nav.php');
$assert(!str_contains($cpNavP4, 'wallet.php') && !str_contains($cpNavP4, 'customer_wallet'), 'p4_customer_nav_no_wallet');
$assert(str_contains($cpNavP4, 'no PPI wallet'), 'p4_customer_nav_scope_comment');
$assert(!is_file($root . '/customer_wallet.php'), 'p4_no_customer_wallet_page');
$assert(str_contains((string)file_get_contents($root . '/customer_portal.php'), 'customerPortalScopeCopy()'), 'p4_customer_portal_shows_scope');
$assert(str_contains((string)file_get_contents($root . '/customer_portal.php'), 'Wallet apps'), 'p4_customer_wallet_filter_is_payment_method');

// P4-W01 — nav URLs resolve to files (or are removed)
$navCrawlFiles = [
    'header.php',
    'footer.php',
    'dashboard.php',
    'staff_dashboard.php',
    'global_search.php',
    'includes/customer_portal_nav.php',
    'includes/staff.php',
    'includes/sidebar_nav.php',
];
$navPhpRefs = [];
foreach ($navCrawlFiles as $navFile) {
    $navPath = $root . '/' . $navFile;
    if (!is_file($navPath)) {
        continue;
    }
    $navSrc = (string)file_get_contents($navPath);
    if (preg_match_all('/href=["\']([a-zA-Z0-9_\\/-]+\\.php)/', $navSrc, $hrefHits)) {
        foreach ($hrefHits[1] as $ref) {
            $navPhpRefs[] = $ref;
        }
    }
    if (preg_match_all("/\\['([a-zA-Z0-9_\\/-]+\\.php)'/", $navSrc, $arrHits)) {
        foreach ($arrHits[1] as $ref) {
            $navPhpRefs[] = $ref;
        }
    }
}
$navPhpRefs = array_values(array_unique($navPhpRefs));
$navMissing = [];
foreach ($navPhpRefs as $ref) {
    if (!is_file($root . '/' . $ref)) {
        $navMissing[] = $ref;
    }
}
$assert($navMissing === [], 'p4_nav_urls_resolve', $navMissing === [] ? (string)count($navPhpRefs) . ' urls' : implode(', ', $navMissing));
$assert(count($navPhpRefs) >= 80, 'p4_nav_crawl_coverage', (string)count($navPhpRefs));

// P5-01 — failed checks have labels; cron key masked
$autoP5 = (string)file_get_contents($root . '/includes/auto_audit.php');
$assert(str_contains($autoP5, 'function collectAutoAuditFailedChecks') && str_contains($autoP5, 'function maskAuditSecrets'), 'p5_auto_audit_failed_labels_helpers');
$assert(str_contains($autoP5, "'failed_list'"), 'p5_auto_audit_stores_failed_list');
$cronP5 = (string)file_get_contents($root . '/cron_auto_audit.php');
$assert(str_contains($cronP5, "'failed_checks'") && str_contains($cronP5, "'key_masked'"), 'p5_cron_json_lists_failed_checks');
$wdP5 = (string)file_get_contents($root . '/admin_watchdog.php');
$assert(str_contains($wdP5, 'Failed checks') && str_contains($wdP5, 'maskSecretKey'), 'p5_watchdog_ui_failed_labels_key_masked');
$assert(!str_contains($wdP5, 'cron_auto_audit.php?key=' . '<?=') && str_contains($wdP5, 'cron_auto_audit.php?key=****'), 'p5_watchdog_cron_url_key_masked');

// P5-02 — KYC/live enqueue always leaves a queue row (idempotent)
$fwdP5 = (string)file_get_contents($root . '/includes/partner_forward_queue.php');
$autoP5Fwd = (string)file_get_contents($root . '/includes/auto_kyc.php');
$assert(str_contains($fwdP5, "\$targets = ['unassigned']") && str_contains($fwdP5, 'enqueuePartnerForward'), 'p5_forward_enqueue_fallback_row');
$assert(str_contains($autoP5Fwd, 'resolveKycPendingFlags'), 'p5_auto_kyc_clears_aml_on_verify');
// 5a: fan-out = every partner with keys (partnerIsConfigured), not chargeable-only / active-without-keys
$assert(str_contains($fwdP5, 'partnerIsConfigured($partnerKey)') && !str_contains($fwdP5, 'isPartnerChargeable'), 'p5a_enqueue_all_partners_with_keys');
$assert(!str_contains($fwdP5, 'isGatewayActive($partnerKey)'), 'p5a_enqueue_no_active_without_keys_tier');
$assert(str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), 'one queue row per partner that already has keys'), 'p5a_forward_queue_copy_keys');
$qP5 = (string)file_get_contents($root . '/includes/partner_forward_queue.php');
$assert(str_contains($qP5, "status IN ('queued','retry','processing','staged','success')"), 'p5_forward_enqueue_idempotent');
$assert(str_contains((string)file_get_contents($root . '/includes/onboarding_security.php'), 'enqueueMerchantToAllEnabledPartners'), 'p5_kyc_verify_enqueues_forward');
// 5b: push uses partnerIsConfigured (not fake keys_configured); staged outcome until adapters
$assert(str_contains($qP5, 'function pushPackageToPartner') && str_contains($qP5, 'partnerIsConfigured($partnerKey)') && !str_contains($qP5, "keys_configured"), 'p5b_push_uses_partnerIsConfigured');
$assert(str_contains($qP5, "'staged'") && str_contains($qP5, "status='staged'"), 'p5b_push_staged_when_adapter_pending');
$assert(str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), 'status=staged') && str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), '>Staged<'), 'p5b_forward_queue_staged_filter');
// 5c: adapter registry + queue stats on existing forward page (no Phase 11 route)
$assert(str_contains($qP5, 'function getKycForwardAdapterRegistry') && str_contains($qP5, 'function runKycForwardAdapter') && str_contains($qP5, 'local_record'), 'p5c_kyc_forward_adapter_registry');
$assert(str_contains($qP5, 'function getForwardQueueStats') && str_contains($qP5, 'by_status'), 'p5c_forward_queue_stats_helper');
$assert(str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), 'getForwardQueueStats') && str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), 'Partner adapters'), 'p5c_forward_queue_stats_ui');
$assert(!str_contains($qP5, 'success-rate route') && str_contains($qP5, 'not Phase 11'), 'p5c_no_phase11_success_routing');

// P5-03 — event_key dedup + optional archive
$nP5 = (string)file_get_contents($root . '/includes/notifications.php');
$assert(str_contains($nP5, 'function notifyMerchant') && str_contains($nP5, 'event_key'), 'p5_notify_event_key_dedup');
$assert(str_contains($nP5, 'function archiveOldNotifications') && str_contains($nP5, 'archived_at'), 'p5_notify_archive_helper');
$assert(str_contains((string)file_get_contents($root . '/notifications.php'), 'archive_read'), 'p5_notify_archive_button');

// P5-04 — kyc_pending skip if open; clear on verify
$amlP5 = (string)file_get_contents($root . '/includes/risk.php');
$assert(str_contains($amlP5, 'function recordAmlFlag') && str_contains($amlP5, 'already flagged'), 'p5_aml_record_dedup');
$assert(str_contains($amlP5, 'function syncKycPendingAmlFlags') && str_contains($amlP5, 'function resolveKycPendingFlags'), 'p5_aml_kyc_pending_sync_and_clear');
$assert(str_contains((string)file_get_contents($root . '/admin_aml.php'), 'syncKycPendingAmlFlags'), 'p5_aml_page_uses_sync_helper');
$assert(str_contains((string)file_get_contents($root . '/includes/onboarding_security.php'), 'resolveKycPendingFlags'), 'p5_kyc_verify_clears_aml');

$gsLive = (string)file_get_contents($root . '/global_search.php');
$assert(str_contains($gsLive, 'FROM merchant_qr_codes') && !str_contains($gsLive, 'batch_label'), 'live_search_uses_qr_codes_not_batch_label');
$capLive = (string)file_get_contents($root . '/includes/financial_integrity.php');
$assert(!preg_match('/INSERT INTO transactions\s*\(\s*txn_id\s*,\s*transaction_id\s*,\s*merchant_id/i', $capLive), 'live_capture_insert_no_transaction_id_column');
$assert(str_contains($capLive, 'function providerCredentialsMatchOrderMode') && str_contains($capLive, 'return null;'), 'live_bound_gateway_skips_mode_mismatch');
$idxLive = (string)file_get_contents($root . '/index.php');
$assert(str_contains($idxLive, "is_file(__DIR__ . '/header.php')") && str_contains($idxLive, 'UniWeb is updating'), 'live_index_header_missing_503');
$errLive = (string)file_get_contents($root . '/includes/error_catcher.php');
$assert(str_contains($errLive, "message NOT LIKE 'Watchdog probe:%'"), 'live_error_count_skips_watchdog_probe');
$coLive = (string)file_get_contents($root . '/checkout.php');
$assert(str_contains($coLive, 'Instant test pay failed') && str_contains($coLive, 'is_array($cf)'), 'live_checkout_test_pay_and_cashfree_null_safe');

$navLibP6 = (string)file_get_contents($root . '/includes/sidebar_nav.php');
$gsP6 = (string)file_get_contents($root . '/global_search.php');
$gsUiP6 = (string)file_get_contents($root . '/includes/global_search_ui.php');
$hdrP6 = (string)file_get_contents($root . '/header.php');
$assert(str_contains($navLibP6, 'function uniwebMerchantNavGroups') && str_contains($navLibP6, 'function uniwebAdminNavGroups'), 'p6_nav_catalog_helpers');
$assert(str_contains($hdrP6, 'uniwebMerchantNavGroups()') && str_contains($hdrP6, 'uniwebAdminNavGroups()'), 'p6_header_uses_shared_nav');
$assert(str_contains($gsP6, 'uniwebMerchantSearchPages()') && str_contains($gsP6, 'uniwebAdminSearchPages()'), 'p6_search_pages_1to1_header');
$assert(str_contains($gsP6, 'staffNavForRole') && str_contains($gsP6, 'isSuperAdmin'), 'p6_search_role_scoped');
$assert(str_contains($gsP6, 'FROM admins') && str_contains($gsP6, 'FROM support_tickets') && str_contains($gsP6, 'FROM customer_tickets'), 'p6_search_staff_and_tickets');
$assert(str_contains($gsP6, 'pan_hash') && str_contains($gsP6, 'gstin_hash') && str_contains($gsP6, 'mandate_ref'), 'p6_search_gstin_pan_mandates');
$assert(str_contains($gsP6, 'function_exists(\'uwDetectIdKind\')') || str_contains($gsP6, 'uwDetectIdKind'), 'p6_search_id_prefixes');
$assert(str_contains($gsP6, 'mb_strlen($q) < $minLen') && str_contains($gsP6, '$minLen = 2'), 'p6_search_min_two_chars');
// SRCH-04 — staff search loops gated by canPage / staffCanAccess
$assert(str_contains($gsP6, "\$canRefunds = \$canPage('admin_refunds.php')") && str_contains($gsP6, "\$canKyc = \$canPage('admin_kyc.php')"), 'srch04_refunds_kyc_gated');
$assert(str_contains($gsP6, "\$canForward = \$canPage('admin_forward_queue.php')") && str_contains($gsP6, 'if ($canForward)'), 'srch04_forward_gated');
$assert(str_contains($gsP6, 'if ($canRefunds)') && str_contains($gsP6, 'if ($canKyc)') && str_contains($gsP6, 'if ($canChargebacks)'), 'srch04_money_loops_gated');
// SRCH-06 — high-value money / risk entities (program coverage step)
$assert(str_contains($gsP6, 'FROM payout_orders') && str_contains($gsP6, 'FROM payout_beneficiaries'), 'srch02_beneficiaries_searchable');
$assert(str_contains((string)file_get_contents($root . '/admin_platform_wallet.php'), 'Platform Fee Ledger') && str_contains((string)file_get_contents($root . '/admin_wallet.php'), 'admin_platform_wallet.php'), 'dup09_wallet_cross_links');
$assert(str_contains($gsP6, 'FROM merchant_method_requests') && str_contains($gsP6, 'FROM aml_flags') && str_contains($gsP6, 'FROM disputes'), 'srch06_kyc_risk_entities');
$assert(str_contains($gsP6, "'chargebacks'") && str_contains($gsP6, "'virtual accounts'") && str_contains($gsP6, "'api docs'"), 'srch06_settings_nicknames');
$assert(str_contains($gsUiP6, 'Ctrl K') && str_contains($gsUiP6, 'GSTIN') && str_contains($gsUiP6, 'q.length<2'), 'p6_search_visible_examples');
preg_match_all("/\\['([a-zA-Z0-9_\\/-]+\\.php)'/", $navLibP6, $p6NavHits);
$p6NavUrls = array_values(array_unique($p6NavHits[1] ?? []));
$p6Missing = [];
foreach ($p6NavUrls as $ref) {
    if (str_starts_with($ref, 'nbfc') || str_contains($ref, 'nbfc')) {
        continue;
    }
    if (!is_file($root . '/' . $ref)) {
        $p6Missing[] = $ref;
    }
}
$assert($p6Missing === [], 'p6_nav_catalog_files_exist', $p6Missing === [] ? (string)count($p6NavUrls) . ' urls' : implode(', ', $p6Missing));
$assert(count($p6NavUrls) >= 70, 'p6_nav_catalog_coverage', (string)count($p6NavUrls));

// Phase 7 — public website (homepage, pricing honesty, contact ticket, legal pack)
$enP7 = (string)file_get_contents($root . '/lang/en.php');
$idxP7 = (string)file_get_contents($root . '/index.php');
$contactP7 = (string)file_get_contents($root . '/contact.php');
$schemaP7 = (string)file_get_contents($root . '/includes/schema_ensure.php');
$grievP7 = (string)file_get_contents($root . '/grievance.php');
$priceP7 = (string)file_get_contents($root . '/pricing.php');
$compP7 = (string)file_get_contents($root . '/compliance.php');
$termsP7 = (string)file_get_contents($root . '/terms.php');
$statusP7 = (string)file_get_contents($root . '/status.php');
$adminSupP7 = (string)file_get_contents($root . '/admin_support.php');
$assert(str_contains($enP7, 'Collect payments with UPI, QR and links') && str_contains($enP7, 'one merchant console'), 'p7_hero_copy');
$assert(str_contains($idxP7, 'Collect. Operate. Settle.') && str_contains($idxP7, 'Start Test Mode — free'), 'p7_homepage_pillars_and_cta');
$assert(str_contains($idxP7, 'Talk to sales') && !str_contains($idxP7, 'Watch platform tour'), 'ia01_homepage_two_ctas');
$assert(str_contains($idxP7, 'Settlement balance') && str_contains($idxP7, 'not a consumer wallet'), 'ia01_homepage_no_ppi_wallet_claim');
$assert(str_contains((string)file_get_contents($root . '/docs/CRON_INVENTORY.md'), 'Forbidden') && str_contains((string)file_get_contents($root . '/docs/CRON_INVENTORY.md'), 'Do not** add cron scripts'), 'tech08_cron_forbidden_documented');
$assert(!str_contains($idxP7, 'Starter') && !preg_match('/0% UPI forever/i', $idxP7), 'p7_homepage_no_fake_starter_tier');
$assert(str_contains($priceP7, 'Partner MDR') && (str_contains($priceP7, 'UniWeb platform commission') || str_contains($priceP7, 'UniWeb platform fee')) && str_contains($priceP7, 'GST'), 'p7_pricing_fee_stack');
$assert(str_contains($priceP7, 'commission') && str_contains($priceP7, 'white-label software package'), 'b1_pricing_commission_only_copy');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Revenue model: commission on successful transactions'), 'b1_partner_commercial_commission_banner');
$assert(!str_contains((string)file_get_contents($root . '/admin_partner_requests.php'), 'White-label / Route / Easy Split'), 'b1_no_wl_sell_row_in_partner_requests');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_registry.php'), 'Partners do not own merchants'), 'b2_registry_partners_not_merchant_owners');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'no separate partner merchant portal'), 'b2_detail_no_partner_merchant_portal');
$assert(str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), 'Partners (rails / keys)'), 'b2_nav_partners_rails_keys');
$assert(str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), "'owner_today'") && str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), "'title' => 'Today'"), 'g1_admin_today_group');
$assert(str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), 'Platform Settings (SMTP / cron)'), 'g1_platform_settings_not_keys_home');
$assert(str_contains((string)file_get_contents($root . '/header.php'), "force_open"), 'g1_today_force_open_in_header');
$assert(str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), "'money_more'") && str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), "['merchant_launch.php', 'Launch Center'"), 'g2_merchant_launch_and_money_fold');
$assert(str_contains((string)file_get_contents($root . '/admin_login.php'), 'Owner / Admin sign in') && str_contains((string)file_get_contents($root . '/staff_login.php'), 'Employee / Staff sign in'), 'g3_admin_staff_login_labels');
$assert(str_contains((string)file_get_contents($root . '/login.php'), 'Shop / Merchant portal') && str_contains((string)file_get_contents($root . '/customer_login.php'), 'Customer — pay & complaints'), 'g3_shop_customer_login_labels');
// C5 — global search feature catalog must include every sidebar_nav URL
require_once $root . '/includes/sidebar_nav.php';
$c5AdminMissing = uniwebNavSearchMissingUrls('admin');
$c5MerchantMissing = uniwebNavSearchMissingUrls('merchant');
$assert($c5AdminMissing === [], 'c5_admin_nav_all_in_search' . ($c5AdminMissing ? (':' . implode(',', $c5AdminMissing)) : ''));
$assert($c5MerchantMissing === [], 'c5_merchant_nav_all_in_search' . ($c5MerchantMissing ? (':' . implode(',', $c5MerchantMissing)) : ''));
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), 'uniwebAdminSearchPages') && str_contains((string)file_get_contents($root . '/global_search.php'), "'today'"), 'c5_search_uses_nav_helpers_and_today_alias');
$assert(str_contains((string)file_get_contents($root . '/login.php'), 'No partner login portal'), 'b2_merchant_login_no_partner_portal');
$assert(str_contains((string)file_get_contents($root . '/admin_login.php'), 'Partners (banks/PGs) have no UniWeb login'), 'b2_admin_login_no_partner_login');
$assert(str_contains((string)file_get_contents($root . '/faq.php'), 'Which login do I use?'), 'b2_faq_login_matrix');
$assert(!str_contains((string)file_get_contents($root . '/roadmap.php'), 'partner-led rails for larger merchants'), 'b2_roadmap_no_partner_owned_book');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Partner API credentials (Admin pipe)'), 'b3_keys_tab_admin_pipe');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Merchants never receive these partner keys'), 'b3_keys_merchants_never_get_partner_secrets');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Test keys first, then Live'), 'b3_test_tab_prefers_test_then_live');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_registry.php'), 'Test keys → Test Connection → Live keys'), 'b3_registry_pipe_order');
$assert(str_contains((string)file_get_contents($root . '/api_settings.php'), 'not</em> Razorpay, Cashfree, PayU or Decentro partner keys') || str_contains((string)file_get_contents($root . '/api_settings.php'), 'not Razorpay, Cashfree, PayU or Decentro partner keys'), 'b3_merchant_api_not_partner_keys');
$assert(str_contains((string)file_get_contents($root . '/includes/integration_matrix.php'), 'Partner Registry → Partner Detail → Keys'), 'b3_matrix_points_to_registry_not_gateway_settings');
$assert(!str_contains((string)file_get_contents($root . '/includes/integration_matrix.php'), 'owner paste in gateway_settings.php'), 'b3_matrix_no_wrong_paste_path');
$assert(str_contains((string)file_get_contents($root . '/payment_links.php'), 'Collect on your site — share a payment link'), 'b4_payment_links_collect_banner');
$assert(str_contains((string)file_get_contents($root . '/payment_links.php'), 'Put this on your website'), 'b4_payment_links_embed_modal');
$assert(str_contains((string)file_get_contents($root . '/qr_code.php'), 'Not a full white-label UniWeb app'), 'b4_qr_no_full_wl');
$assert(str_contains((string)file_get_contents($root . '/merchant_website.php'), 'Put a Pay button on your website'), 'b4_website_pay_button_copy');
$assert(str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'Your domain + this checkout look'), 'b4_customize_branding_limit');
$assert(str_contains((string)file_get_contents($root . '/api_docs.php'), 'Put on your site:'), 'b4_api_docs_put_on_site');
$assert(str_contains((string)file_get_contents($root . '/includes/sidebar_nav.php'), "['merchant_website.php', 'Website / Pay button'"), 'b4_nav_website_under_collect');
$assert(str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'function paymentMethodDisplayPriority'), 'b5_upi_first_priority_helper');
$assert(str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'sortPaymentMethodsUpiFirst'), 'b5_upi_first_sort_helper');
$assert(str_contains((string)file_get_contents($root . '/payment_methods.php'), 'Collect order: UPI first, then Card, then Net Banking'), 'b5_methods_page_upi_first_banner');
// 1c: merchant Payment Methods copy must not name PayU/Razorpay/Cashfree or Partner Registry
$pm1c = (string)file_get_contents($root . '/payment_methods.php');
$assert(!str_contains($pm1c, 'Partner Registry') && !str_contains($pm1c, 'Needs partner keys') && !str_contains($pm1c, 'Partner rails ready'), 'p1c_methods_no_partner_registry_copy');
$assert(!str_contains($pm1c, '>PayU<') && !str_contains($pm1c, '>Razorpay<') && !str_contains($pm1c, '>Cashfree<'), 'p1c_methods_no_partner_brand_badges');
$assert(str_contains($pm1c, 'Waiting on Admin') || str_contains($pm1c, 'waiting on Admin'), 'p1c_methods_admin_wait_wording');
$assert(str_contains($pm1c, 'Admin connects the payment network once'), 'p1c_methods_how_it_works_merchant_facing');
$assert(str_contains((string)file_get_contents($root . '/includes/collection.php'), 'Waiting for activation'), 'b5_checkout_soft_waiting_keys');
$assert(!str_contains((string)file_get_contents($root . '/includes/collection.php'), 'Provider keys not configured'), 'b5_checkout_no_harsh_keys_copy');
$assert(str_contains((string)file_get_contents($root . '/collection_settings.php'), 'UPI → Card → Net Banking'), 'b5_collection_settings_order_hint');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Go-live order: UPI → Card → Net Banking'), 'b5_admin_partner_methods_upi_first');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), "['upi', 10]"), 'b5_partner_seed_upi_priority_10');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), 'Go-live path: Signup → Docs → Verify → Live'), 'b6_admin_kyc_golive_path_banner');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), 'id="verify-queue"'), 'b6_admin_verify_queue_anchor');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), 'Document rejected. Merchant sees:'), 'b6_reject_doc_shows_merchant_reason');
$assert(str_contains((string)file_get_contents($root . '/kyc.php'), 'Signup → Docs → Verify → Live'), 'b6_merchant_kyc_ppt_flow');
$assert(str_contains((string)file_get_contents($root . '/includes/video_kyc_widget.php'), 'no file picker') && str_contains((string)file_get_contents($root . '/includes/video_kyc_widget.php'), 'getUserMedia'), 'b6_video_live_camera_not_gallery');
$assert(str_contains((string)file_get_contents($root . '/kyc_media_receiver.php'), 'ip_address') && str_contains((string)file_get_contents($root . '/kyc_media_receiver.php'), 'recorded_at'), 'b6_video_saves_ip_and_time');
$assert(str_contains((string)file_get_contents($root . '/admin_auto_kyc.php'), 'keys + commercial'), 'b6_partner_forward_needs_keys_contract');
$assert(str_contains((string)file_get_contents($root . '/includes/collection.php'), 'function commissionSplitRealtimePreview'), 'b7_split_preview_helper');
$assert(str_contains((string)file_get_contents($root . '/includes/collection.php'), 'mdr_m, mdr_p, partner_fee, pricing_snapshot'), 'b7_create_txn_persists_mp_snapshot');
$assert(str_contains((string)file_get_contents($root . '/includes/wallet.php'), 'never double-credit platform fee'), 'b7_platform_fee_no_double_credit');
$assert(str_contains((string)file_get_contents($root . '/transaction_detail.php'), 'Admin cut (UniWeb)') && str_contains((string)file_get_contents($root . '/transaction_detail.php'), 'Merchant baaki'), 'b7_txn_detail_ppt_split_labels');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'Example on ₹100'), 'b7_commercial_rupee100_example');
$assert(str_contains((string)file_get_contents($root . '/includes/split_settlement.php'), 'route_mode_parked'), 'b7_route_sdk_parked_on_execute');
$assert(str_contains((string)file_get_contents($root . '/collection_settings.php'), 'Commission Preview (realtime feel)'), 'b7_collection_settings_realtime_preview');
$assert(str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), 'function getSettlementCycleOptions'), 'b8_cycle_options_helper');
$assert(str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), "default_batch_interval_minutes', '1440'") || str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), "default_batch_interval_minutes', (string)settlementMinutesFromCycle"), 'b8_default_cycle_t1_minutes');
$assert(str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), 'function syncSettlementCycleSetting'), 'b8_cycle_binds_existing_settings');
$assert(str_contains((string)file_get_contents($root . '/admin_settlement_settings.php'), 'Settlement cycle: T+0 / T+1 / T+2'), 'b8_admin_cycle_banner');
$assert(str_contains((string)file_get_contents($root . '/merchant_settlement_settings.php'), 'Your settlement status'), 'b8_merchant_cycle_status');
$assert(str_contains((string)file_get_contents($root . '/settlements.php'), 'Settlement cycle status'), 'b8_settlements_page_cycle_status');
$assert(str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'settings[settlement_cycle]') && str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'syncSettlementCycleSetting'), 'b8_gateway_settings_cycle_select');
// Block 9 — Dispute / Support Admin-first (V1 single forward; bulk parked; no new app)
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'Admin first — complaint → Admin → resolve / forward'), 'b9_admin_disputes_first_banner');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'forward_partner') && str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'Forward (single)'), 'b9_admin_single_forward_ui');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'Bulk select + smart partner route') && str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'parked'), 'b9_bulk_smart_route_parked');
$assert(str_contains((string)file_get_contents($root . '/includes/schema_ensure.php'), 'function forwardDisputeToPartner') && str_contains((string)file_get_contents($root . '/includes/schema_ensure.php'), 'forwarded_partner'), 'b9_forward_helper_and_status');
$assert(str_contains((string)file_get_contents($root . '/disputes.php'), 'Admin reviews first') && str_contains((string)file_get_contents($root . '/disputes.php'), 'Admin will review first'), 'b9_merchant_admin_first_copy');
// 3a: merchant disputes honour ?q=/?id= and open a detail panel (DSP deep links)
$disp3a = (string)file_get_contents($root . '/disputes.php');
$assert(str_contains($disp3a, "\$_GET['q']") && str_contains($disp3a, "\$_GET['id']"), 'p3a_disputes_reads_q_and_id');
$assert(str_contains($disp3a, 'dispute-detail') && str_contains($disp3a, 'disputes.php?id='), 'p3a_disputes_row_opens_detail');
$assert(str_contains($disp3a, 'name="q"') && str_contains($disp3a, 'Admin note / resolution'), 'p3a_disputes_search_and_detail_panel');
// 3b: merchant support ?q=TKT… opens ticket detail; search results link to support_ticket.php
$sup3b = (string)file_get_contents($root . '/support.php');
$assert(str_contains($sup3b, "\$_GET['q']") && str_contains($sup3b, 'support_ticket.php?id=') && str_contains($sup3b, 'TKT[A-F0-9]'), 'p3b_support_q_redirects_to_ticket');
$assert(str_contains($sup3b, 'name="q"') && str_contains($sup3b, 'Search tickets'), 'p3b_support_has_search_box');
$gs3b = (string)file_get_contents($root . '/global_search.php');
$assert(str_contains($gs3b, "support_ticket.php?id=") && !str_contains($gs3b, "'support.php?q='") && !str_contains($gs3b, '"support.php?q='), 'p3b_search_ticket_opens_detail');
// 3c: notification click opens dispute/support detail (not just dashboard)
$n3c = (string)file_get_contents($root . '/includes/notifications.php');
$assert(str_contains($n3c, 'function notificationActionUrl') && str_contains($n3c, 'support_ticket.php?id=') && str_contains($n3c, 'disputes.php?id='), 'p3c_notif_action_urls_dispute_support');
$assert(str_contains($n3c, 'TKT[A-F0-9]') && str_contains($n3c, 'DSP[A-F0-9]'), 'p3c_notif_extracts_tkt_and_dsp');
// 4a: Integration Status Board is status-only — keys CTA points to Partner Registry
$im4a = (string)file_get_contents($root . '/admin_integration_matrix.php');
$assert(str_contains($im4a, 'Status board only') && str_contains($im4a, 'partner keys are not pasted here'), 'p4a_integration_board_keys_not_here');
$assert(str_contains($im4a, 'admin_gateway_registry.php') && str_contains($im4a, 'Open Partner Registry'), 'p4a_integration_board_registry_cta');
$assert(str_contains($im4a, 'Integration Status Board') && !str_contains($im4a, 'Gateway × Operation Matrix'), 'p4a_integration_board_title_clear');
// 4b: Gateway Routing Matrix — keys not pasted here; CTA to Partner Registry
$gm4b = (string)file_get_contents($root . '/admin_gateway_matrix.php');
$assert(str_contains($gm4b, 'partner keys are not pasted here') && str_contains($gm4b, 'Open Partner Registry'), 'p4b_gateway_matrix_keys_not_here');
$assert(str_contains($gm4b, 'admin_gateway_registry.php') && str_contains($gm4b, 'Merchant rail / onboarding status'), 'p4b_gateway_matrix_registry_cta');
// Merchant QR delete + Admin partner Turn OFF / Change keys / Delete inactive
$qrDel = (string)file_get_contents($root . '/qr_code.php');
$assert(str_contains($qrDel, "action === 'delete'") && str_contains($qrDel, 'DELETE FROM merchant_qr_codes') && str_contains($qrDel, '>Delete</button>'), 'merchant_qr_has_delete');
$pmDel = (string)file_get_contents($root . '/includes/payment_methods.php');
$assert(str_contains($pmDel, 'function deleteInactiveGateway') && str_contains($pmDel, 'Built-in partners/methods cannot be deleted'), 'partner_delete_inactive_helper');
$gdDel = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gdDel, "action === 'delete'") && str_contains($gdDel, 'Change keys') && str_contains($gdDel, 'Turn OFF'), 'partner_detail_change_keys_and_turn_off');
// 4c: Website & API Keys + Partner Requests are not PG key paste homes
$aw4c = (string)file_get_contents($root . '/admin_website.php');
$assert(str_contains($aw4c, 'partner PG keys are not pasted here') && str_contains($aw4c, 'Partner Registry (PG keys)'), 'p4c_website_keys_not_pg_home');
$assert(!str_contains($aw4c, 'Platform PG Keys →'), 'p4c_website_no_misleading_pg_keys_button');
$pr4c = (string)file_get_contents($root . '/admin_partner_requests.php');
$assert(str_contains($pr4c, 'partner keys are not pasted here') && str_contains($pr4c, 'Open Partner Registry'), 'p4c_partner_requests_guide_only');
$assert(str_contains((string)file_get_contents($root . '/admin_support.php'), 'Admin first — support queue') && str_contains((string)file_get_contents($root . '/admin_grievance.php'), 'Admin first — grievance officer queue'), 'b9_support_grievance_hub_banners');
$assert(!is_file($root . '/admin_dispute_bulk.php') && !is_file($root . '/dispute_app.php'), 'b9_no_new_dispute_app');
// WIRING-C1-C2-HYGIENE-ORDERED
$assert(!is_file($root . '/demo.php') && !is_file($root . '/ping.php'), 'wiring_demo_ping_deleted');
$assert(!is_file($root . '/AGENTS.md') && is_file($root . '/.cursor/AGENTS.md'), 'wiring_root_md_agents_moved_off_webroot');
$assert(str_contains((string)file_get_contents($root . '/mobile.php'), "redirect('index.php')"), 'wiring_mobile_redirect_home');
$assert(str_contains((string)file_get_contents($root . '/cust.php'), "redirect('index.php"), 'wiring_cust_redirect_home');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), "filterMerchantId") && str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'merchant_id'), 'wiring_disputes_merchant_id_filter');
$assert(str_contains((string)file_get_contents($root . '/admin_customer_tickets.php'), '$filterMerchantId') && str_contains((string)file_get_contents($root . '/admin_customer_tickets.php'), 'adminMerchantLink'), 'wiring_customer_tickets_filter_and_open_merchant');
$assert(str_contains((string)file_get_contents($root . '/admin_support.php'), '$filterMerchantId'), 'wiring_support_merchant_id_filter');
$assert(str_contains((string)file_get_contents($root . '/admin_view_merchant.php'), 'admin_disputes.php?merchant_id=') && str_contains((string)file_get_contents($root . '/admin_view_merchant.php'), 'admin_customer_tickets.php?merchant_id=') && str_contains((string)file_get_contents($root . '/admin_view_merchant.php'), 'admin_support.php?merchant_id='), 'wiring_merchant_profile_chips');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), '$filterMerchantId') && str_contains((string)file_get_contents($root . '/admin_view_merchant.php'), 'admin_kyc.php?merchant_id='), 'wiring_kyc_merchant_deep_link');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'adminMerchantLink'), 'wiring_disputes_open_merchant');
$assert(!str_contains((string)file_get_contents($root . '/admin_disputes.php'), "transactionDetailUrl(\$d['txn_id'])"), 'phase1_a2_dispute_id_not_txn_link');
$assert(str_contains((string)file_get_contents($root . '/admin_view_merchant.php'), '>Merchant API<'), 'phase1_a4_merchant_api_chip_label');
// Block 10 — Live corridor soft-launch checklist (owner clicks; no new app)
$assert(str_contains((string)file_get_contents($root . '/admin_dashboard.php'), 'Live corridor — soft launch checklist'), 'b10_admin_live_corridor_checklist');
$assert(str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'Live corridor (soft launch)') && str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'Apply pending migrations'), 'b10_gateway_soft_launch_banner');
// Admin template: hide parked Split/Route from Platform Settings dropdown
$colLibPark = (string)file_get_contents($root . '/includes/collection.php');
$gsPark = (string)file_get_contents($root . '/gateway_settings.php');
$assert(str_contains($colLibPark, 'function getAdminTemplateCollectionModes') && str_contains($colLibPark, "'payu_split', 'razorpay_route', 'cashfree_route'"), 'admin_template_modes_park_split_route');
$assert(str_contains($gsPark, 'getAdminTemplateCollectionModes') && str_contains($gsPark, 'Route/Split SDK is not live'), 'gateway_settings_uses_admin_template_modes');
$assert(!preg_match('/foreach\s*\(\s*getCollectionModes\(\)/', $gsPark), 'gateway_settings_no_raw_all_collection_modes');
$assert(str_contains((string)file_get_contents($root . '/merchant_launch.php'), 'Instant Test Pay'), 'b10_merchant_launch_instant_test_pay');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'CR-01') && str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'stale_createNotification'), 'b10_cr01_stale_notification_guard');
$assert(str_contains($contactP7, 'recordPublicContactInquiry') && str_contains($contactP7, 'sendPlatformEmail') && str_contains($contactP7, '1 business day'), 'p7_contact_saves_ticket_and_sla');
$assert(str_contains($schemaP7, 'contact_inquiries') && str_contains($schemaP7, "generateId('CTI')"), 'p7_contact_inquiry_schema');
$assert(is_file($root . '/migrations/061_contact_inquiries.sql'), 'p7_contact_inquiry_migration');
$assert(str_contains($adminSupP7, 'listPublicContactInquiries') && str_contains($adminSupP7, 'closePublicContactInquiry'), 'p7_admin_support_website_inquiries');
$assert(str_contains($grievP7, 'COMPANY_CEO') && !str_contains($grievP7, 'Rohan Sharma'), 'p7_grievance_named_company_officer');
$assert(str_contains($compP7, 'PPI') && str_contains($compP7, 'NBFC'), 'p7_compliance_excludes_ppi_nbfc');
$assert(str_contains($termsP7, 'PPI') && str_contains($termsP7, 'NBFC'), 'p7_terms_excludes_ppi_nbfc');
$assert(str_contains($statusP7, 'Checkout') && str_contains($statusP7, 'Dashboard') && str_contains($statusP7, 'Webhooks') && str_contains($statusP7, 'KYC') && str_contains($statusP7, 'Settlements') && str_contains($statusP7, 'IST'), 'p7_status_named_components');
$assert(str_contains((string)file_get_contents($root . '/faq.php'), '1 business day') && str_contains((string)file_get_contents($root . '/about.php'), 'Shop'), 'p7_faq_sla_and_about_segments');

// Live checkout + Watchdog (P8 companion)
$errCatchP8 = (string)file_get_contents($root . '/includes/error_catcher.php');
$autoP8 = (string)file_get_contents($root . '/includes/auto_audit.php');
$finP8 = (string)file_get_contents($root . '/includes/financial_integrity.php');
$hdrP8 = (string)file_get_contents($root . '/header.php');
$cssP8 = (string)file_get_contents($root . '/assets/css/portal-polish.css');
$themeP8 = (string)file_get_contents($root . '/assets/css/theme-light.css');
$healthP8 = (string)file_get_contents($root . '/health.php');
$dashP8 = (string)file_get_contents($root . '/dashboard.php');
$assert(str_contains($errCatchP8, 'array|string $context') && str_contains($errCatchP8, 'function uwRecordAuditEvent'), 'p8_log_error_accepts_string_and_safe_audit');
$assert(!str_contains($autoP8, 'json_encode($isolationViolations)'), 'p8_isolation_log_passes_array');
$assert(str_contains($finP8, 'uwRecordAuditEvent(\'payment_capture\'') || str_contains($finP8, 'uwRecordAuditEvent("payment_capture"') || str_contains($finP8, "uwRecordAuditEvent('payment_capture'"), 'p8_capture_uses_safe_audit');
$assert(str_contains($healthP8, "echo 'OK'") && str_contains($healthP8, 'schemaEnsureSkipHeavy') === false, 'p8_health_plain_ok');
$assert(str_contains($healthP8, 'UNIWEB_HEALTH_PROBE') && str_contains($healthP8, 'UNIWEB_HEALTH_ANSWERED'), 'p8_health_probe_flags');
$assert(str_contains($schemaP7, 'UNIWEB_HEALTH_PROBE') && str_contains($schemaP7, "health.php"), 'p8_health_skips_heavy_schema');
$assert(str_contains($errCatchP8, 'There is no active transaction') && str_contains($errCatchP8, 'UNIWEB_HEALTH_ANSWERED'), 'p8_auto_resolve_stale_txn_and_health_ok');
$assert(str_contains($finP8, 'function uniwebPdoCommit') && str_contains($finP8, 'function uniwebPreparePaymentCaptureSchema'), 'p8_capture_ddl_before_transaction');
$wdSrc = (string)file_get_contents($root . '/includes/link_watchdog.php');
$assert(str_contains($wdSrc, '$st === 503') && str_contains($wdSrc, "basename(\$relFile) === 'health.php'"), 'p8_watchdog_retries_503');
$assert(str_contains((string)file_get_contents($root . '/includes/platform_api.php'), "'ok' => true") && str_contains((string)file_get_contents($root . '/includes/platform_api.php'), 'error(s) in Error Log — open admin_error_log.php'), 'p8_error_log_self_check_not_fail');
$assert(str_contains($errCatchP8, "\$checkId === 'error_log'"), 'p8_watchdog_does_not_log_error_log_count');
$assert(str_contains($hdrP8, 'portal-polish.css?v=20260815a') && str_contains($hdrP8, '.portal-main{overflow-x:auto}'), 'p8_css_cache_bust_and_no_clip');
$assert(str_contains($cssP8, 'writing-mode: horizontal-tb') && str_contains($cssP8, 'direction: ltr') && !str_contains($cssP8, ".portal-main .glass {\n  overflow: hidden"), 'p8_tables_ltr_scroll');
$assert(!str_contains($themeP8, 'overflow-x: clip'), 'p8_theme_no_overflow_clip');
$assert(str_contains($dashP8, 'Create a payment link') && str_contains((string)file_get_contents($root . '/includes/page_ux.php'), 'function uxEmptyCta'), 'p8_empty_states_next_action');
$assert(str_contains($errCatchP8, 'Call to undefined function recordAuditEvent'), 'p8_auto_resolve_stale_test_pay_error');

$cmpP9 = (string)file_get_contents($root . '/compare.php');
$assert(is_file($root . '/compare.php') && is_file($root . '/docs/MARKET_COMPARISON.md'), 'p9_compare_page_and_reference');
$assert(str_contains($cmpP9, 'Razorpay') && str_contains($cmpP9, 'Cashfree') && str_contains($cmpP9, 'PayU') && str_contains($cmpP9, 'Juspay') && str_contains($cmpP9, 'Stripe') && str_contains($cmpP9, 'Worldline') && str_contains($cmpP9, 'Decentro'), 'p9_all_peers_named');
$assert(str_contains($cmpP9, 'Route') && str_contains($cmpP9, 'Owner') && str_contains($cmpP9, 'PPI'), 'p9_no_parity_promises');
$assert(str_contains((string)file_get_contents($root . '/includes/page_ux.php'), 'function userFacingError') && str_contains((string)file_get_contents($root . '/checkout.php'), 'userFacingError'), 'p9_actionable_errors_helper');
$assert(str_contains((string)file_get_contents($root . '/footer.php'), 'compare.php') && str_contains((string)file_get_contents($root . '/sitemap.xml'), 'compare.php'), 'p9_compare_linked_footer_sitemap');
$assert(!str_contains($cmpP9, 'nbfc.php') || str_contains($cmpP9, 'Not a UniWeb product'), 'p9_nbfc_not_sold');

$assert(str_contains($cmpP9, 'Aggregator model') && str_contains($cmpP9, 'Methods only') && str_contains($cmpP9, 'Typical market PG'), 'p4_market_compare_matrix_on_compare_page');
$dashP4 = (string)file_get_contents($root . '/admin_dashboard.php');
$platP4 = (string)file_get_contents($root . '/admin_platform_status.php');
$assert(str_contains($dashP4, 'UniWeb vs market') && str_contains($dashP4, 'admin_forward_queue.php') && str_contains($dashP4, 'Partner Registry →'), 'p4_admin_dashboard_market_bar_and_forward');
$assert(str_contains($platP4, 'Partner Registry (keys)') && str_contains($platP4, 'Platform API guide (Advanced)') && !str_contains($platP4, 'Website & API Keys'), 'p4_platform_status_keys_not_website_page');
$assert(str_contains((string)file_get_contents($root . '/lang/en.php'), 'One UniWeb account') && str_contains((string)file_get_contents($root . '/solutions.php'), 'no separate signup at each payment company'), 'p4_signup_and_solutions_one_portal');
$assert(str_contains((string)file_get_contents($root . '/chargebacks.php'), 'disputes.php') && str_contains((string)file_get_contents($root . '/chargebacks.php'), 'main lane'), 'p4_chargebacks_points_to_disputes');
$healthP4 = (string)file_get_contents($root . '/includes/platform_health.php');
$assert(str_contains($healthP4, 'tab=test') || str_contains($healthP4, 'adminPartnerTestUrl'), 'p4_gateway_health_opens_registry');

$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'CT[A-F0-9]{8,}') && str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'merchant_customer_tickets.php?q='), 'p5_notif_ct_complaint_deep_link');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'adminDisputesReturnUrl') && str_contains((string)file_get_contents($root . '/admin_disputes.php'), '_merchant_id'), 'p5_admin_disputes_preserves_search_on_post');
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), "'pg keys' => 'admin_gateway_registry.php'") && str_contains((string)file_get_contents($root . '/global_search.php'), "'api keys' => \$isMerchant ? 'api_settings.php' : 'admin_gateway_registry.php'"), 'p5_search_pg_keys_registry_not_website');
$assert(str_contains((string)file_get_contents($root . '/admin_website.php'), 'Platform API guide') && !str_contains((string)file_get_contents($root . '/admin_website.php'), '$pageTitle = \'Website & API Keys\''), 'p5_admin_website_renamed_not_pg_keys');
$assert(str_contains((string)file_get_contents($root . '/chargebacks.php'), "redirect('disputes.php')") && str_contains((string)file_get_contents($root . '/disputes.php'), 'legacy list'), 'p5_chargebacks_silo_merged_to_disputes');
$assert(str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'Git') && str_contains((string)file_get_contents($root . '/gateway_settings.php'), 'SFTP/FTP'), 'p5_deploy_git_pull_not_ftp_only');
$assert(str_contains((string)file_get_contents($root . '/admin_forward_queue.php'), 'not sent to the bank'), 'p5_forward_staged_honest_not_at_partner');

$assert(str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'partnerForwardQueueUpgradeLegacySchema') && str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'forwardQueueNextScheduleAt'), 'p6a_forward_queue_single_schema_and_schedule');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'function enqueueMerchantToAllEnabledPartners') && str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'function syncGatewaySubmissionToForwardQueue'), 'p6a_forward_enqueue_single_module');
$assert(!str_contains((string)file_get_contents($root . '/includes/auto_kyc.php'), 'function enqueueMerchantToAllEnabledPartners') && !str_contains((string)file_get_contents($root . '/includes/auto_kyc.php'), 'scheduled_at DATETIME NOT NULL') && !str_contains((string)file_get_contents($root . '/includes/auto_kyc.php'), 'function queueMerchantForPartnerForward'), 'p6a_auto_kyc_no_duplicate_forward_helpers');
$assert(str_contains((string)file_get_contents($root . '/includes/gateways.php'), 'syncGatewaySubmissionToForwardQueue'), 'p6a_gateway_submit_syncs_forward_queue');
$assert(str_contains((string)file_get_contents($root . '/includes/onboarding_security.php'), 'partner_forward_queue.php') && !str_contains((string)file_get_contents($root . '/includes/onboarding_security.php'), "require_once __DIR__ . '/auto_kyc.php'"), 'p6a_onboarding_enqueue_via_forward_module');
$assert(str_contains((string)file_get_contents($root . '/includes/payout_adapters.php'), 'UNIWEB_TEST_') && str_contains((string)file_get_contents($root . '/includes/payout_adapters.php'), 'dispatchImplemented'), 'p6a_payout_mock_labeled_stub_not_fake_live');
$assert(str_contains((string)file_get_contents($root . '/includes/verification.php'), 'decentroPartnerCredential') && str_contains((string)file_get_contents($root . '/includes/rbl.php'), 'rblPartnerCredential'), 'p6a_decentro_rbl_registry_credentials');
$assert(str_contains((string)file_get_contents($root . '/includes/rbl.php'), 'no demo defaults') && !str_contains((string)file_get_contents($root . '/includes/rbl.php'), 'VAOPENBANK'), 'p6a_rbl_no_demo_corp_defaults');
$assert(str_contains((string)file_get_contents($root . '/includes/cloud_modules.php'), "'auto_kyc.php'") && str_contains((string)file_get_contents($root . '/config.dev.php'), "'auto_kyc'"), 'p6a_auto_kyc_loaded_via_cloud_modules');
$assert(str_contains((string)file_get_contents($root . '/includes/va_manager.php'), 'vaSupportedCreationGateways') && str_contains((string)file_get_contents($root . '/includes/gateways.php'), "'pinelabs'"), 'p6a_va_supported_list_and_pinelabs_enum');
$assert(str_contains((string)file_get_contents($root . '/includes/auto_kyc.php'), "severity IN ('high','critical')"), 'p6a_auto_kyc_aml_fail_closed');

$peReg = (string)file_get_contents($root . '/includes/partner_engine.php');
$pmReg = (string)file_get_contents($root . '/includes/payment_methods.php');
$imReg = (string)file_get_contents($root . '/includes/integration_matrix.php');
$gwReg = (string)file_get_contents($root . '/includes/gateways.php');
$assert(str_contains($peReg, "'worldline'") && str_contains($peReg, "'digio'") && str_contains($peReg, 'function getGatewaySubmissionPartnerKeys') && str_contains($peReg, 'function getIntegrationMatrixPartnerLabels'), 'p6b_partner_registry_canonical_helpers');
$assert(str_contains($pmReg, "registry_kind") && str_contains($pmReg, 'gatewayRegistryKindClause') && str_contains($pmReg, "registry_kind='method'") && str_contains($pmReg, "registry_kind='partner'"), 'p6b_gateway_registry_kind_split');
$assert(str_contains($imReg, 'getIntegrationMatrixPartnerLabels') && !preg_match("/'worldline'\\s*=>\\s*'Worldline'/", $imReg), 'p6b_integration_matrix_from_registry_not_hardcoded');
$assert(str_contains($gwReg, 'getGatewaySubmissionPartnerKeys') && str_contains($gwReg, 'MODIFY gateway VARCHAR(40)') && !str_contains($gwReg, 'function isGatewayActive'), 'p6b_gateway_submit_varchar_no_duplicate_active');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'getPartnerRegistry()') && str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'getKycForwardPartnerKeys'), 'p6b_credential_and_kyc_lists_from_registry');
$assert(is_file($root . '/migrations/066_registry_kind.sql') && is_file($root . '/migrations/067_gateway_submissions_varchar.sql'), 'p6b_registry_migrations_present');
$peCfg = (string)file_get_contents($root . '/includes/partner_engine.php');
$gwCfg = (string)file_get_contents($root . '/includes/gateways.php');
$assert(str_contains($peCfg, 'function partnerHasSavedCredentials') && !preg_match('/function partnerIsConfigured[\\s\\S]{0,400}isGatewayConfigured\\(/', $peCfg), 'p6b_partner_is_configured_no_gateway_recursion');
$assert(str_contains($gwCfg, 'partnerHasSavedCredentials($gateway)') && !str_contains($gwCfg, 'partnerIsConfigured($gateway)'), 'p6b_is_gateway_configured_uses_saved_creds_not_partner_is_configured');

// P7b — wiring / deep-link (~25)
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'highlightDisputeId') && str_contains((string)file_get_contents($root . '/admin_disputes.php'), "\$_GET['id']"), 'p7b_admin_disputes_q_and_id_highlight');
$assert(str_contains((string)file_get_contents($root . '/admin_support.php'), 'focusTicketId') && str_contains((string)file_get_contents($root . '/admin_support.php'), 'TKT[A-F0-9]'), 'p7b_admin_support_tkt_auto_open');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'customer complaint') && str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'merchant_customer_tickets.php'), 'p7b_ct_notify_not_dashboard');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'batch complete') && str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'transactions.php'), 'p7b_settlement_title_to_transactions');
$assert(str_contains((string)file_get_contents($root . '/includes/platform_health.php'), 'adminPartnerTestUrl') || str_contains((string)file_get_contents($root . '/includes/platform_health.php'), 'tab=test'), 'p7b_health_test_connection_registry_not_settings');
$assert(str_contains((string)file_get_contents($root . '/includes/collection.php'), 'Platform checkout') && !str_contains((string)file_get_contents($root . '/includes/collection.php'), 'Razorpay/Cashfree pool'), 'p7b_collection_mode_no_partner_pool_label');
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), 'admin_disputes.php?q=') && !str_contains((string)file_get_contents($root . '/global_search.php'), 'admin_chargebacks.php?q='), 'p7b_chargeback_search_to_disputes');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_submit.php'), 'gateway_submissions') && str_contains((string)file_get_contents($root . '/admin_gateway_submit.php'), 'admin_forward_queue.php'), 'p7b_gateway_submit_vs_forward_queue_copy');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'getPartnerRegistry') || str_contains((string)file_get_contents($root . '/includes/partner_forward_queue.php'), 'partnerDisplayName'), 'p7b_kyc_notify_partner_name');

// Payout adapters — live dispatch implemented (still gated by payout_live_enabled)
$payoutAdp = (string)file_get_contents($root . '/includes/payout_adapters.php');
$payoutApi = (string)file_get_contents($root . '/includes/payout_partner_api.php');
$assert(is_file($root . '/includes/payout_partner_api.php') && str_contains($payoutApi, 'razorpayxDispatchPayoutJob') && str_contains($payoutApi, 'cashfreeDispatchPayoutJob'), 'payout_live_razorpayx_cashfree_api_layer');
$assert(str_contains($payoutAdp, "dispatchImplemented(): bool { return true; }") && str_contains($payoutAdp, 'live API') && !str_contains($payoutAdp, 'not yet implemented for live'), 'payout_adapters_live_not_stub');
$assert(str_contains((string)file_get_contents($root . '/includes/payout.php'), 'payoutAdapterDispatchOrder') && !str_contains((string)file_get_contents($root . '/includes/payout.php'), 'RazorpayX adapter not yet implemented'), 'payout_php_delegates_to_adapters');

// Recurring / AutoPay — production structure (gated like payout)
$mandatesPhp = (string)file_get_contents($root . '/includes/mandates.php');
$gwSeo = (string)file_get_contents($root . '/gateway_settings.php');
$merchantRec = (string)file_get_contents($root . '/merchant_recurring.php');
$assert(str_contains($mandatesPhp, 'function recurringAutopayApproved') && str_contains($mandatesPhp, 'function getRecurringReadinessChecklist') && str_contains($mandatesPhp, 'function getMandatePendingReason'), 'recurring_autopay_helper_functions');
$assert(str_contains($mandatesPhp, 'decentroMandateCredentials') && str_contains($mandatesPhp, 'recurringAutopayLiveReady()') && str_contains($mandatesPhp, 'pending_reason'), 'recurring_registry_first_and_gates');
$assert(str_contains($gwSeo, 'recurring_autopay_approved') && str_contains($gwSeo, 'payout_live_enabled') && str_contains($gwSeo, 'live-money-switches'), 'gateway_settings_live_money_switches');
$assert(str_contains($merchantRec, 'getMandatePendingReason') && str_contains($merchantRec, 'Customer auth link'), 'merchant_recurring_pending_reasons_and_auth_link');
$assert(is_file($root . '/migrations/064_recurring_autopay_switch.sql'), 'migration_064_recurring_autopay_switch');
$assert(str_contains((string)file_get_contents($root . '/includes/platform_health.php'), 'recurringAutopayHealthCheck'), 'platform_health_recurring_check');

// Route / Split Phase 11 — professional scaffold (SDK still parked)
$splitLib = (string)file_get_contents($root . '/includes/split_settlement.php');
$assert(str_contains($splitLib, 'function getRouteSplitMarketMatrix') && str_contains($splitLib, 'function getRouteSplitReadinessChecklist') && str_contains($splitLib, 'function routeSplitLiveEnabled'), 'route_split_helper_functions');
$assert(str_contains($gwSeo, 'route_split_live_enabled') && str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'getRouteSplitMarketMatrix'), 'gateway_settings_route_split_switch_and_market_table');
$assert(str_contains((string)file_get_contents($root . '/admin_settlements.php'), 'Route / Split transfer queue'), 'admin_settlements_route_queue');
$assert(str_contains((string)file_get_contents($root . '/collection_settings.php'), 'Settlement vs Route'), 'merchant_collection_route_education');
$assert(is_file($root . '/migrations/065_route_split_switch.sql'), 'migration_065_route_split_switch');
$assert(str_contains((string)file_get_contents($root . '/includes/platform_health.php'), 'routeSplitHealthCheck'), 'platform_health_route_split_check');

// P9-04…08 honesty (market peers) — no fake orchestrator / coverage / licence / brand blur
$regP9 = (string)file_get_contents($root . '/admin_gateway_registry.php');
$assert(str_contains($regP9, 'Partner Registry') && !str_contains($regP9, 'Gateway Orchestrator'), 'p9_04_no_orchestrator_product_title');
$assert(str_contains((string)file_get_contents($root . '/status.php'), 'Partner Registry') && str_contains((string)file_get_contents($root . '/status.php'), 'orchestrator app'), 'p9_04_status_honest_routing');
$payMsg = (string)file_get_contents($root . '/includes/payout.php');
$assert(str_contains($payMsg, 'Easy Split') && str_contains($payMsg, 'Collect first'), 'p9_05_payout_honesty_message');
$assert(str_contains((string)file_get_contents($root . '/merchant_payout.php'), 'Easy Split') && str_contains((string)file_get_contents($root . '/admin_payout.php'), 'Easy Split'), 'p9_05_payout_ui_banners');
$assert(str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'isGatewayConfigured') && str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'isPartnerMethodEnabled'), 'p9_06_methods_hard_gated');
$assert(str_contains((string)file_get_contents($root . '/solutions.php'), 'do not fake full PayU') || str_contains((string)file_get_contents($root . '/solutions.php'), 'Partner Registry') && str_contains((string)file_get_contents($root . '/solutions.php'), 'no partner brand buttons'), 'p9_06_public_coverage_honest');
$assert(!str_contains((string)file_get_contents($root . '/includes/demo_tour.php'), 'UPI · Cards · Netbanking · Wallets'), 'p9_06_demo_no_fake_catalogue');
$gwDetailP9 = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetailP9, 'Test / Sandbox') && str_contains($gwDetailP9, 'Live / Production') && str_contains($gwDetailP9, 'Decentro is a'), 'p9_07_sandbox_vs_live_labels');
$assert(str_contains((string)file_get_contents($root . '/trust.php'), 'do not claim') && str_contains((string)file_get_contents($root . '/trust.php'), 'RBI Payment Aggregator'), 'p9_08_trust_licence_factual');
$assert(!str_contains((string)file_get_contents($root . '/index.php'), 'payment aggregator'), 'p9_08_homepage_no_pa_keyword_claim');

$wlMd = (string)file_get_contents($root . '/docs/WHITE_LABEL_CHECKLIST.md');
$assert(is_file($root . '/docs/WHITE_LABEL_CHECKLIST.md'), 'p10_white_label_checklist_file');
foreach (['WL-01', 'WL-02', 'WL-03', 'WL-04', 'WL-05', 'WL-12'] as $wlId) {
    $assert(str_contains($wlMd, $wlId), 'p10_checklist_has_' . strtolower($wlId));
}
$assert(str_contains($wlMd, 'no white-label program') || str_contains($wlMd, 'Not a command to sell') || str_contains($wlMd, 'Do **not** sell white-label'), 'p10_not_a_product_launch');
$assert(str_contains((string)file_get_contents($root . '/docs/DEEP_AUDIT_ORDERED.md'), 'WHITE_LABEL_CHECKLIST.md'), 'p10_audit_points_at_checklist');
$ccLib = (string)file_get_contents($root . '/includes/checkout_customize.php');
$assert(str_contains($ccLib, 'hide_powered_by') && str_contains($ccLib, 'function checkoutHidePoweredBy'), 'p10_hide_powered_by_helper');
$assert(str_contains($ccLib, 'NOT NULL DEFAULT 0'), 'p10_hide_powered_by_default_off');
$assert(str_contains((string)file_get_contents($root . '/checkout.php'), 'checkoutHidePoweredBy'), 'p10_checkout_respects_hide_flag');
$assert(str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'Hide “Secured by UniWeb”') || str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'Hide "Secured by UniWeb"') || str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'hide_powered_by'), 'p10_customize_has_hide_checkbox');
$assert(str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'Your domain + this checkout look') || str_contains((string)file_get_contents($root . '/checkout_customize.php'), 'Platform domain'), 'p10_domain_guide_on_customize');
$assert(str_contains((string)file_get_contents($root . '/.cursor/rules/owner-hard-nbfc-ppi-existing-only.mdc'), 'White-label program') && str_contains((string)file_get_contents($root . '/.cursor/rules/owner-hard-nbfc-ppi-existing-only.mdc'), 'Partner program = yes'), 'owner_hard_rule_no_whitelabel_product');
$apiSet = (string)file_get_contents($root . '/api_settings.php');
$assert(str_contains($apiSet, 'X-UniWeb-Signature') && str_contains($apiSet, 'hash_hmac') && str_contains($apiSet, 'Copy snippet'), 'p10_webhook_copy_block');
$staffNavSrc = (string)file_get_contents($root . '/includes/staff.php');
$assert(str_contains($staffNavSrc, 'Do not add gateway_settings.php'), 'p10_staff_nav_excludes_partner_keys');
$assert(str_contains((string)file_get_contents($root . '/includes/baas.php'), 'function isMerchantTest') && str_contains((string)file_get_contents($root . '/includes/settlement_engine.php'), 'isSettlementSandbox'), 'p10_test_live_isolation_helpers');
$homeSrc = (string)file_get_contents($root . '/index.php');
$assert(!str_contains($homeSrc, 'White-label your gateway') && !str_contains($homeSrc, 'Buy white-label'), 'p10_homepage_does_not_sell_wl');
$assert(!str_contains((string)file_get_contents($root . '/roadmap.php'), 'white-label options'), 'p10_roadmap_no_wl_product_claim');

$finIso = (string)file_get_contents($root . '/includes/financial_integrity.php');
$assert(str_contains($finIso, 'function healTestLiveIsolationFlags') && !str_contains($finIso, 'live_merchant_test_txn'), 'p10_isolation_does_not_flag_old_test_on_live');
$assert(str_contains((string)file_get_contents($root . '/includes/auto_audit.php'), 'resolvePlatformErrorsByMessageLike'), 'p10_isolation_clears_when_clean');
$assert(str_contains((string)file_get_contents($root . '/api.php'), 'DATE(created_at) >= ?') && str_contains((string)file_get_contents($root . '/reports.php'), 'Download CSV (date range)'), 'p10_wl06_date_range_csv_and_api');
$assert(str_contains((string)file_get_contents($root . '/api_docs.php'), 'Merchant onboarding API') && str_contains((string)file_get_contents($root . '/api_docs.php'), 'no public REST'), 'p10_wl07_onboarding_parked');
$assert(str_contains((string)file_get_contents($root . '/status.php'), 'health.php') && str_contains((string)file_get_contents($root . '/status.php'), '1 business day'), 'p10_wl08_status_health_sla');
$assert(str_contains((string)file_get_contents($root . '/trust.php'), 'Security questionnaire'), 'p10_wl09_trust_questionnaire');
$assert(str_contains((string)file_get_contents($root . '/includes/schema_ensure.php'), 'sla_due_at') && str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'overdue'), 'p10_wl10_dispute_timers');
$assert(str_contains((string)file_get_contents($root . '/admin_reconciliation.php'), 'Runbook:'), 'p10_wl11_recon_runbook');
$assert(str_contains((string)file_get_contents($root . '/admin_audit_log.php'), "export'] === 'csv'") && str_contains((string)file_get_contents($root . '/includes/audit_log.php'), 'function auditLogFilterWhere'), 'p10_wl12_audit_date_csv');
$assert(str_contains($wlMd, '## WL-06') && str_contains($wlMd, '## WL-12'), 'p10_checklist_wl06_wl12_sections');

// WL-13…15 + EXIST + LIVE-01 (park / honesty — no white-label product)
$assert(str_contains($wlMd, '## WL-13') && str_contains($wlMd, 'admin_gateway_matrix.php'), 'wl13_matrix_in_checklist');
$assert(str_contains($wlMd, '## WL-14') && str_contains($wlMd, 'named contract'), 'wl14_dual_control_parked');
$assert(str_contains($wlMd, '## WL-15') && str_contains($wlMd, 'Not a UniWeb product'), 'wl15_portal_shell_not_sold');
$assert(str_contains($wlMd, '## WL-EXIST') && str_contains($wlMd, 'hide_powered_by') && str_contains($wlMd, 'HMAC'), 'wl_exist_buyer_have_table');
$assert(str_contains($wlMd, '## LIVE-01') && str_contains($wlMd, 'Instant Test Pay'), 'live01_owner_smoke_in_checklist');
$assert(str_contains($wlMd, '## LIVE-02') && str_contains($wlMd, '062') && str_contains($wlMd, 'Encrypt PII'), 'live02_migrations_pii_checklist');
$assert(str_contains($wlMd, '## LIVE-03') && str_contains($wlMd, 'BLOCK_A_CLEANUP.md'), 'live03_backup_before_cleanup_checklist');
$assert(is_file($root . '/migrations/062_widen_merchant_pii_cipher.sql') && is_file($root . '/migrations/063_payment_link_amount_type.sql'), 'live02_migrations_062_063_present');
$assert(str_contains((string)file_get_contents($root . '/admin_encrypt_pii.php'), 'LIVE-02 order') && str_contains((string)file_get_contents($root . '/gateway_settings.php'), '062'), 'live02_admin_ui_order_hints');
$assert(str_contains((string)file_get_contents($root . '/docs/BLOCK_A_CLEANUP.md'), 'Never hard-delete money') && !str_contains((string)file_get_contents($root . '/docs/BLOCK_A_CLEANUP.md'), 'NBFC pages (hidden, parked)'), 'live03_block_a_no_nbfc_keep');
$assert(is_file($root . '/_inbox/chat/LIVE_02_migrations_pii.txt') && is_file($root . '/_inbox/chat/LIVE_03_backup_before_cleanup.txt'), 'live02_03_owner_inbox_notes');
$assert(is_file($root . '/admin_gateway_matrix.php') && str_contains((string)file_get_contents($root . '/admin_gateway_matrix.php'), 'multi-MID'), 'wl13_matrix_page_honest');
$assert(str_contains((string)file_get_contents($root . '/trust.php'), 'Q: Who holds card data?') && str_contains((string)file_get_contents($root . '/trust.php'), 'do not claim UniWeb PCI Level 1'), 'wl09_trust_q_map');
$pciDss = (string)file_get_contents($root . '/pci_dss.php');
$assert(str_contains($pciDss, 'do not claim an independent PCI Level 1') && !str_contains($pciDss, 'UniWeb operates as a payment aggregator'), 'wl09_pci_no_fake_pa_badge');
$assert(!str_contains($pciDss, '>Compliant<') && str_contains($pciDss, 'Readiness map'), 'wl09_pci_no_compliant_badge_cells');
$assert(str_contains((string)file_get_contents($root . '/api_docs.php'), 'Written exception (parked)'), 'wl07_docs_written_exception');

$p11 = (string)file_get_contents($root . '/docs/PHASE11_ROUTE.md');
$assert(is_file($root . '/docs/PHASE11_ROUTE.md') && str_contains($p11, 'P11-01') && str_contains($p11, 'P11-02'), 'p11_route_reference_file');
$assert(str_contains($p11, 'No SDK') || str_contains($p11, 'Does not** call'), 'p11_no_sdk_early');
$splitLib = (string)file_get_contents($root . '/includes/split_settlement.php');
$assert(str_contains($splitLib, 'route_split_live_enabled') && str_contains($splitLib, 'route_mode_live'), 'p11_route_gated_no_sdk');
$routeApi = (string)@file_get_contents($root . '/includes/route_split_partner_api.php');
$assert(str_contains($routeApi, 'razorpayRouteCreateTransfer') && str_contains($routeApi, 'cashfreeEasySplitPostCapture') && str_contains($routeApi, 'executePartnerRouteRefundReversal'), 'p11_route_live_api_layer');
$assert(!is_file($root . '/includes/nbfc.php') && !is_file($root . '/merchant_nbfc.php') && !is_file($root . '/admin_nbfc.php'), 'p11_nbfc_fully_removed');
$colLib = (string)file_get_contents($root . '/includes/collection.php');
$assert(str_contains($colLib, "\$keys = ['direct_upi', 'platform_pg']"), 'p11_merchant_modes_no_live_route');
$navLib = (string)file_get_contents($root . '/includes/sidebar_nav.php');
$assert(!str_contains($navLib, 'merchant_nbfc.php') && !str_contains($navLib, 'admin_nbfc.php'), 'p11_nav_no_nbfc_urls');
$assert(!is_file($root . '/customer_wallet.php'), 'p11_no_customer_ppi_page');
$assert(is_file($root . '/.cursor/rules/owner-hard-nbfc-ppi-existing-only.mdc'), 'owner_hard_rule_nbfc_ppi_existing');
$assert(!str_contains((string)file_get_contents($root . '/collection_settings.php'), 'payu_child_key') && !str_contains((string)file_get_contents($root . '/collection_settings.php'), 'razorpay_linked_account_id') && !str_contains((string)file_get_contents($root . '/collection_settings.php'), 'cashfree_vendor_id'), 'p11_collection_route_ids_hidden_from_merchant');
$assert(str_contains((string)file_get_contents($root . '/collection_settings.php'), 'Partners stay with Admin') || str_contains((string)file_get_contents($root . '/collection_settings.php'), 'Partner Split / Route IDs are not shown'), 'p1a_merchant_no_partner_id_fields');
$assert(str_contains((string)file_get_contents($root . '/docs/DEEP_AUDIT_ORDERED.md'), 'PHASE11_ROUTE.md'), 'p11_audit_points_at_map');
$assert(str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'enable Platform switch first') || str_contains((string)file_get_contents($root . '/admin_gateway_detail.php'), 'locked — future ticket'), 'p11_live_status_locked_in_ui');

$cryptoCleanup = (string)file_get_contents($root . '/includes/crypto.php');
$assert(str_contains($cryptoCleanup, 'function encryptSensitive') && str_contains($cryptoCleanup, 'function sensitiveUiPlain'), 'cleanup_b_encrypt_decrypt_ui_helpers');
$assert(str_contains($cryptoCleanup, 'function decryptMerchantPiiFields') && str_contains($cryptoCleanup, "'address'"), 'cleanup_b_merchant_pii_decrypt_helper');
$assert(str_contains((string)file_get_contents($root . '/my_account.php'), 'sensitiveUiPlain') && str_contains((string)file_get_contents($root . '/admin_edit_merchant.php'), 'sensitiveUiPlain'), 'cleanup_b_merchant_admin_show_plain');
$assert(str_contains((string)file_get_contents($root . '/includes/merchant_admin_view.php'), 'decryptMerchantPiiFields'), 'cleanup_b03_admin_view_decrypt');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_payload.php'), 'decryptMerchantPiiFields'), 'cleanup_b04_partner_payload_decrypt');
$assert(is_file($root . '/migrations/062_widen_merchant_pii_cipher.sql') && str_contains((string)file_get_contents($root . '/includes/schema_ensure.php'), 'ensureSensitivePiiColumnWidths'), 'cleanup_b02_widen_cipher_columns');
$assert(str_contains((string)file_get_contents($root . '/admin_encrypt_pii.php'), "'address'") && str_contains((string)file_get_contents($root . '/my_account.php'), 'sensitiveUiSave(trim((string)($_POST[\'address\']'), 'cleanup_b_address_encrypt_paths');
$assert(str_contains((string)file_get_contents($root . '/customer_profile.php'), 'sensitiveUiPlain'), 'cleanup_b05_customer_profile_plain');
$assert(is_file($root . '/docs/BLOCK_A_CLEANUP.md') && str_contains((string)file_get_contents($root . '/docs/BLOCK_A_CLEANUP.md'), 'Never hard-delete money'), 'cleanup_a_runbook_present');
$assert(str_contains((string)file_get_contents($root . '/includes/onboarding.php'), "url' => 'merchant_payment_pack.php'") && !str_contains((string)file_get_contents($root . '/includes/onboarding.php'), 'merchant_launch_test.php'), 'cleanup_a03_test_pay_link_fixed');
$assert(str_contains((string)file_get_contents($root . '/includes/admin_demo_table.php'), 'merchant_register.php') && !str_contains((string)file_get_contents($root . '/includes/admin_demo_table.php'), 'platform_demo.php'), 'cleanup_a03_demo_tour_link_fixed');
$assert(str_contains((string)file_get_contents($root . '/.htaccess'), 'RewriteRule ^tests/') && str_contains((string)file_get_contents($root . '/.htaccess'), 'RewriteRule ^scripts/'), 'cleanup_a01_htaccess_blocks_junk_dirs');
$assert(str_contains((string)file_get_contents($root . '/.github/workflows/deploy.yml'), 'dev_local/') && str_contains((string)file_get_contents($root . '/.github/workflows/deploy.yml'), 'scripts/'), 'cleanup_a01_ftp_excludes_junk');
$assert(is_file($root . '/docs/CLEANUP_SENSITIVE_CLICKABLE_AUDIT.md') && str_contains((string)file_get_contents($root . '/.cursor/AGENTS.md'), 'CLEANUP_SENSITIVE_CLICKABLE_AUDIT.md'), 'cleanup_audit_noted_in_agents');
$assert(str_contains((string)file_get_contents($root . '/admin_dashboard.php'), 'admin_disputes.php') && str_contains((string)file_get_contents($root . '/admin_dashboard.php'), 'Open Disputes'), 'cleanup_c_disputes_card_clickable');
$assert(str_contains((string)file_get_contents($root . '/admin_dashboard.php'), 'admin_transactions.php?status=failed') && str_contains((string)file_get_contents($root . '/admin_dashboard.php'), "Today's Volume"), 'cleanup_c05_volume_and_failed_cards');
// C4: dashboard numbers open filtered lists
$dashC4 = (string)file_get_contents($root . '/admin_dashboard.php');
$assert(str_contains($dashC4, 'manage_merchant.php?status=active') && str_contains($dashC4, 'admin_settlements.php?status=pending') && str_contains($dashC4, 'admin_disputes.php?status=open'), 'c4_admin_dashboard_stat_filters');
$assert(str_contains((string)file_get_contents($root . '/manage_merchant.php'), "\$_GET['status']") && str_contains((string)file_get_contents($root . '/admin_disputes.php'), "statusFilter === 'open'"), 'c4_list_pages_accept_status_filter');
$assert(str_contains((string)file_get_contents($root . '/dashboard.php'), 'settlements.php?status=pending') && str_contains((string)file_get_contents($root . '/dashboard.php'), 'settlements.php?status=completed'), 'c4_merchant_balance_cards_clickable');
$assert(str_contains((string)file_get_contents($root . '/includes/ui_links.php'), 'function adminStaffLink') && str_contains((string)file_get_contents($root . '/includes/ui_links.php'), 'function adminPartnerDetailUrl'), 'cleanup_c_staff_partner_link_helpers');
// C6: staff name → Staff Activity (filtered)
$uiLinksC6 = (string)file_get_contents($root . '/includes/ui_links.php');
$assert(str_contains($uiLinksC6, 'adminStaffActivityUrl($staffId)') && str_contains($uiLinksC6, 'function adminStaffProfileLink'), 'c6_staff_name_link_goes_to_activity');
$assert(str_contains((string)file_get_contents($root . '/admin_manage_staff.php'), 'adminStaffLink((int)$s[\'id\']') && str_contains((string)file_get_contents($root . '/admin_manage_staff.php'), 'Profile →'), 'c6_manage_staff_name_to_activity');
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), 'adminStaffActivityUrl((int)$row[\'id\'])'), 'c6_search_staff_opens_activity');
$assert(str_contains((string)file_get_contents($root . '/admin_audit_log.php'), 'adminStaffLink') && str_contains((string)file_get_contents($root . '/admin_error_log.php'), 'adminStaffLink'), 'c6_audit_error_actor_to_activity');
$assert(str_contains((string)file_get_contents($root . '/admin_manage_staff.php'), 'adminStaffLink') && str_contains((string)file_get_contents($root . '/admin_gateway_registry.php'), 'adminPartnerDetailUrl'), 'cleanup_c03_c04_staff_partner_click');
// A3: Partner Detail entry points open Keys (Test) first
$uiLinksA3 = (string)file_get_contents($root . '/includes/ui_links.php');
$gwDetailA3 = (string)file_get_contents($root . '/admin_gateway_detail.php');
$registryA3 = (string)file_get_contents($root . '/admin_gateway_registry.php');
$assert(str_contains($uiLinksA3, "tab=keys&env=") && str_contains($uiLinksA3, "\$env = 'test'"), 'a3_partner_detail_url_defaults_keys_test');
$assert(str_contains($gwDetailA3, "\$_GET['tab'] ?? 'keys'") && str_contains($gwDetailA3, "\$_GET['env'] ?? 'test'"), 'a3_detail_page_defaults_keys_test_env');
$assert(str_contains($registryA3, 'adminPartnerDetailUrl') && !preg_match('/admin_gateway_detail\.php\?partner=<\?=\s*urlencode\(\$g\[\'gateway_key\'\]\)\s*\?>"/', $registryA3), 'a3_registry_configure_uses_keys_url');
$assert(str_contains((string)file_get_contents($root . '/admin_partner.php'), 'adminPartnerDetailUrl') || str_contains((string)file_get_contents($root . '/admin_partner.php'), 'tab=keys&env=test'), 'a3_admin_partner_redirect_keys');
$assert(str_contains((string)file_get_contents($root . '/global_search.php'), 'adminStaffActivityUrl') && str_contains((string)file_get_contents($root . '/dashboard.php'), 'transactions.php?range=today'), 'cleanup_c07_c08_search_and_merchant_cards');
$assert(str_contains((string)file_get_contents($root . '/admin_kyc.php'), 'adminMerchantLink((int)$videoRow') && str_contains((string)file_get_contents($root . '/staff_dashboard.php'), 'admin_view_merchant.php?id='), 'cleanup_c01_kyc_staff_queue_names');

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
// 2a: Payment Methods toggles must sync merchants.enabled_methods (checkout source of truth)
$pmLib2a = (string)file_get_contents($root . '/includes/payment_methods.php');
$assert(str_contains($pmLib2a, 'function syncMerchantEnabledMethodsFromToggles') && str_contains($pmLib2a, 'UPDATE merchants SET enabled_methods=?'), 'p2a_sync_enabled_methods_helper');
$assert(substr_count($pmLib2a, 'syncMerchantEnabledMethodsFromToggles($merchantId)') >= 2, 'p2a_toggle_and_bulk_call_sync');
$mReqLayer = (string)file_get_contents($root . '/includes/method_requests.php');
$assert(str_contains($mReqLayer, 'toggleMerchantPaymentMethod($merchantId, $methodKey, true, \'system_unlock\')'), 'p3_unlock_syncs_toggle_layer');
$assert(str_contains($mReqLayer, 'function merchantCanToggleMethodOn'), 'p3_permission_gate_helper');
$assert(str_contains($mReqLayer, 'function renderMerchantMethodRequestSection'), 'p3_shared_method_request_ui');
$assert(str_contains($pmLib2a, 'merchantCanToggleMethodOn($merchantId, $methodKey, $updatedBy)'), 'p3_toggle_checks_permission');
$pmPage = (string)file_get_contents($root . '/payment_methods.php');
$assert(str_contains($pmPage, 'renderMerchantMethodRequestSection') && str_contains($pmPage, 'request_method'), 'p3_merchant_request_ui');
// 2b: net_banking (registry/toggles) must normalize to netbanking (checkout catalog)
$assert(str_contains($pmLib2a, 'function normalizeCheckoutMethodKey') && str_contains($pmLib2a, "'net_banking', 'nb' => 'netbanking'"), 'p2b_normalize_netbanking_alias');
$assert(str_contains((string)file_get_contents($root . '/includes/provision.php'), 'normalizeCheckoutMethodKeys'), 'p2b_enabled_methods_normalized');
$assert(str_contains((string)file_get_contents($root . '/includes/collection.php'), 'normalizeCheckoutMethodKeys'), 'p2b_checkout_allow_normalized');
// 2c: checkout must build Card/NB/EMI/Wallet tabs from allow(); normalize runtime + checkout wiring
$assert(str_contains($collLib, "\$allow('debit_card')") && str_contains($collLib, "\$allow('credit_card')") && str_contains($collLib, "\$allow('netbanking')"), 'p2c_checkout_builds_card_nb_tabs');
$assert(str_contains($collLib, "\$allow('emi')") && str_contains($collLib, "\$allow('wallet')"), 'p2c_checkout_builds_emi_wallet_tabs');
// EMI must exist in gateway_registry seed so Admin can Activate and merchant can toggle (checkout allow already supports emi)
$assert(str_contains($pmLib2a, "['emi', 'EMI'") || str_contains($pmLib2a, "['emi', 'EMI',"), 'emi_seeded_in_gateway_registry');
$assert(str_contains((string)file_get_contents($root . '/payment_methods.php'), "'emi' => '📅'") && str_contains((string)file_get_contents($root . '/payment_methods.php'), "'emi'"), 'emi_merchant_toggle_ui');
$assert(str_contains((string)file_get_contents($root . '/includes/provision.php'), "'emi' =>"), 'emi_in_payment_method_catalog');
$assert(str_contains($checkoutSrc, 'getCheckoutPaymentMethods($link)'), 'p2c_checkout_calls_method_builder');
$assert(str_contains($pmLib2a, 'normalizeCheckoutMethodKeys(getMerchantEnabledMethodKeys'), 'p2c_sync_writes_normalized_keys');
require_once $root . '/includes/payment_methods.php';
$assert(function_exists('normalizeCheckoutMethodKey') && normalizeCheckoutMethodKey('net_banking') === 'netbanking', 'p2c_runtime_net_banking_to_netbanking');
$normKeys = normalizeCheckoutMethodKeys(['upi_p2m', 'net_banking', 'debit_card']);
$assert(in_array('netbanking', $normKeys, true) && !in_array('net_banking', $normKeys, true) && in_array('debit_card', $normKeys, true), 'p2c_runtime_keys_list_normalized');
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
$assert(str_contains($pCtrl, 'function resolvePartnerCredentialValue'), 'p2_keys_plane_legacy_alias_resolver');
$assert(str_contains($pCtrl, 'pinelabs_access_code') && str_contains((string)file_get_contents($root . '/includes/partner_engine.php'), 'pinelabs_access_code'), 'p2_pinelabs_field_names_aligned');
$assert(str_contains((string)file_get_contents($root . '/includes/payout.php'), "getPartnerSetting('razorpayx'"), 'p2_payout_reads_registry_not_gateway_settings');
$assert(str_contains((string)file_get_contents($root . '/kyc.php'), 'merchantForwardQueueStatusLabel'), 'p3_kyc_forward_honest_staged');
$assert(str_contains((string)file_get_contents($root . '/admin_disputes.php'), 'name="q"'), 'p3_admin_disputes_search_q');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'merchant_customer_tickets.php'), 'p3_complaint_notify_deep_link');
$assert(str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'function merchantPaymentMethodLabel'), 'p3_merchant_method_labels_generic');
$assert(!str_contains((string)file_get_contents($root . '/checkout.php'), 'Razorpay checkout is temporarily'), 'p3_checkout_errors_no_partner_brand');
$bannerLib = (string)file_get_contents($root . '/includes/checkout_mode_banner.php');
$assert(str_contains($bannerLib, 'isPartnerCredentialSettingKey') && str_contains($bannerLib, 'never gateway_settings'), 'p1_platform_save_skips_pg_secrets');
$gwP1 = (string)file_get_contents($root . '/gateway_settings.php');
$assert(!preg_match('/name="settings\\[(razorpay_key_secret|cashfree_secret_key|payu_merchant_salt)\\]"/', $gwP1), 'p1_platform_form_has_no_pg_secret_inputs');
$assert(str_contains($gwP1, 'This page does not accept live PG API keys'), 'p1_platform_banner_no_pg_keys');
$assert(str_contains($gwP1, 'template for new merchants') || str_contains($gwP1, 'new merchants only'), 'p1_03_primary_pg_is_new_merchant_template');
$assert(str_contains($navSrc, 'gateway_settings.php') && str_contains($navSrc, 'Platform Settings'), 'p1_nav_platform_settings_not_integrations');
$siteP1 = (string)file_get_contents($root . '/admin_website.php');
$assert(str_contains($siteP1, 'Partner Registry → Partner Detail → Keys') && !str_contains($siteP1, 'paste in Gateway Settings'), 'p1_website_keys_guide_points_to_registry');
$benSrc = (string)file_get_contents($root . '/includes/beneficiaries.php');
$assert(!str_contains($benSrc, "getSetting('decentro_api_key'") && str_contains($benSrc, 'decentroClientId()'), 'p1_beneficiaries_registry_decentro');
$assert(str_contains($pCtrl, 'function partnerCredentialEnvBucket') && str_contains($pCtrl, 'function cashfreePayoutClientId'), 'p1_partner_env_and_payout_helpers');
$assert(!str_contains((string)file_get_contents($root . '/includes/pg_webhooks.php'), "getSetting('cashfree_secret_key'"), 'p1_pg_webhooks_cashfree_registry_only');
$assert(str_contains((string)file_get_contents($root . '/includes/payment_methods.php'), 'Partner Registry'), 'p1_save_gateway_config_deprecated');
$assert(!str_contains((string)file_get_contents($root . '/includes/rbl.php'), 'getSetting($field'), 'p1_rbl_no_gateway_settings_fallback');

// P1-02: commercial UPSERT + seed on first open; route is scaffold not live API
$splitP1 = (string)file_get_contents($root . '/includes/split_settlement.php');
$assert(str_contains($splitP1, 'function ensurePartnerCommercialSeeded'), 'p1_commercial_seed_helper');
$assert(str_contains($splitP1, 'ON DUPLICATE KEY UPDATE base_mdr_percent=VALUES(base_mdr_percent)'), 'p1_commercial_upsert');
$gwDetailP1 = (string)file_get_contents($root . '/admin_gateway_detail.php');
$assert(str_contains($gwDetailP1, 'ensurePartnerCommercialSeeded'), 'p1_commercial_tab_seeds_on_open');
$assert(str_contains($gwDetailP1, 'No provider API calls') && str_contains($gwDetailP1, 'SDK'), 'p1_route_scaffold_not_live_api');
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
$assert(str_contains($gwDetailKeys, "\$_GET['env'] ?? 'test'") && str_contains($gwDetailKeys, 'copy_test_keys_to_live'), 'keys_tab_defaults_test_and_can_copy_to_live');
$assert(str_contains((string)file_get_contents($root . '/includes/partner_control.php'), 'function copyPartnerCredentialsToLive'), 'copy_test_keys_to_live_helper');

// P2-01 / P2-02 / P2-03 — checkout methods, QR PNG, payment-link public URL
$collP2 = (string)file_get_contents($root . '/includes/collection.php');
$assert(str_contains($collP2, 'function buildCheckoutPaymentMethods'), 'p2_checkout_methods_wrapped');
$assert(str_contains($collP2, "if (\$pa === '')"), 'p2_upi_intent_empty_vpa_returns_blank');
$provP2 = (string)file_get_contents($root . '/includes/provision.php');
$assert(str_contains($provP2, "if (!in_array('upi_p2m', \$methods, true))"), 'p2_pack_always_enables_upi');
$assert(str_contains($checkoutSrc, 'Card / Net Banking will appear when UniWeb activates'), 'p2_checkout_soft_keys_banner');
$assert(!str_contains($checkoutSrc, 'Pay with Razorpay') && !str_contains($checkoutSrc, 'Powered by PayU'), 'motive_checkout_no_partner_brand_cta');
$assert(!str_contains((string)file_get_contents($root . '/includes/checkout_footer.php'), 'via Razorpay'), 'motive_checkout_footer_no_partner_list');
$assert(str_contains((string)file_get_contents($root . '/includes/provision.php'), "'label' => 'UPI'") && !str_contains((string)file_get_contents($root . '/includes/provision.php'), 'UPI via PayU'), 'motive_provision_method_labels_generic');
$assert(str_contains((string)file_get_contents($root . '/merchant_website.php'), 'no separate signup with payment networks'), 'motive_merchant_website_no_direct_pg_nudge');
$assert(str_contains($checkoutSrc, 'This method is enabled, but partner keys are not set yet'), 'p2_checkout_empty_method_state');
$assert(str_contains($qrImg, 'UPI ID missing'), 'p2_qr_image_upi_missing_png');
$assert(str_contains($qrImg, 'function qrFlushAllBuffers'), 'p2_qr_image_flushes_output_buffers');
$assert(str_contains((string)file_get_contents($root . '/includes/qr_svg.php'), 'require_upi=1'), 'p2_upi_qr_url_requires_pa');
$plP2 = (string)file_get_contents($root . '/payment_links.php');
$assert(str_contains($plP2, "payment_links.php?created="), 'p2_create_redirects_with_public_url');
$assert(str_contains($plP2, "amount_type") && str_contains($plP2, 'Open amount'), 'payment_links_open_and_fixed_amount');
$packPage = (string)file_get_contents($root . '/merchant_payment_pack.php');
$assert(str_contains($packPage, 'Fixed') && str_contains($packPage, 'Open') && str_contains($packPage, 'Regenerate Pack'), 'payment_pack_shows_fixed_and_open');
$provSrc = (string)file_get_contents($root . '/includes/provision.php');
$assert(str_contains($provSrc, "\$amountType === 'open'") && str_contains($provSrc, 'paymentLinkIsOpenAmount'), 'payment_pack_creates_open_and_fixed');
$assert(str_contains((string)file_get_contents($root . '/checkout.php'), 'Enter Payment Amount') && str_contains((string)file_get_contents($root . '/checkout.php'), 'pay_amount'), 'checkout_open_amount_entry');
$assert(is_file($root . '/migrations/063_payment_link_amount_type.sql'), 'migration_063_amount_type');
$pmLib = (string)file_get_contents($root . '/includes/payment_methods.php');
$assert(str_contains($pmLib, 'function get_available_pay_methods') && str_contains($pmLib, 'merchantEntitledMethods') && str_contains($pmLib, "gateway === 'direct'"), 'payment_links_methods_use_merchant_entitlement');
$assert(str_contains($pmLib, 'function catalogKeyToPartnerMethodName'), 'catalog_to_partner_method_mapper');
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
$assert(str_contains($htaccess, '\\.(env|log|sql|md)$') || str_contains($htaccess, '.md)$'), 'cr02_htaccess_denies_markdown');
$assert(str_contains((string)file_get_contents($root . '/includes/notifications.php'), 'stale_createNotification'), 'cr01_stale_createNotification_detector');
$assert(str_contains((string)file_get_contents($root . '/config.dev.php'), "includes/notifications.php"), 'cr01_config_dev_loads_notifications');
$errorPage = (string)file_get_contents($root . '/error.php');
$assert(str_contains($errorPage, 'Page not found') && str_contains($errorPage, 'merchant_register.php'), 'branded_error_has_nav_links');
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

    // Instant Test Pay path: merchant signup still public; demo.php removed (hygiene).
    $reg = $probe('merchant_register.php', [200]);
    $assert($reg['ok'], 'live_merchant_register_after_demo_removed', $reg['code'] . ' ' . $reg['url']);
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
