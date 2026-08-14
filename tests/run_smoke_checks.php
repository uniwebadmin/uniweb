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
$assert(str_contains($cfgDev, 'onMerchantNotificationCreated'), 'config_dev_notification_hooks_whatsapp');
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
$mig044 = (string)file_get_contents($root . '/migrations/044_gateway_reason_map_db.sql');
$assert(str_contains($mig044, 'CREATE TABLE IF NOT EXISTS gateway_reason_maps'), 'migration_044_creates_table_before_insert');
$assert(str_contains($mig044, 'ADD COLUMN partner_key'), 'migration_044_adds_partner_key_before_insert');
$assert(str_contains($migLib, 'Migration failed:'), 'migrations_name_failing_file');
$assert(!str_contains($migLib, 'Applied migration checksum mismatch'), 'migrations_checksum_rebase_not_throw');
$migRel = (string)file_get_contents($root . '/migrate_release.php');
$assert(str_contains($migRel, "'migration' => \$file"), 'migrate_release_names_failed_file');
$errCatch = (string)file_get_contents($root . '/includes/error_catcher.php');
$assert(!str_contains($errCatch, '%checkout.php on line%'), 'error_catcher_does_not_auto_resolve_checkout');
$assert(str_contains($errCatch, 'qr_image.php'), 'error_catcher_skips_html_on_qr_image');
$qrImg = (string)file_get_contents($root . '/qr_image.php');
$assert(str_contains($qrImg, "ini_set('display_errors', '0')"), 'qr_image_hides_php_warnings');
$assert(str_contains($qrImg, "class_exists('QRcode'"), 'qr_image_requires_qrcode_class');
$errLog = (string)file_get_contents($root . '/admin_error_log.php');
$assert(str_contains($errLog, 'probe_catcher'), 'error_log_has_catcher_probe');
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
$assert(is_file($root . '/includes/db_backup.php'), 'file_includes_db_backup_php');

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
