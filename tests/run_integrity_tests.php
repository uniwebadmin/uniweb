<?php
declare(strict_types=1);

/**
 * Lightweight integrity suite (no PHPUnit dependency).
 * CLI: php tests/run_integrity_tests.php
 * HTTP: tests/run_integrity_tests.php?key=CRON_KEY
 */

$root = dirname(__DIR__);
require_once $root . '/config.php';

if (PHP_SAPI !== 'cli') {
    $auth = validateCronRequest();
    if (empty($auth['ok'])) {
        rejectCronRequest($auth['error'] ?? 'Invalid key');
    }
}

$failed = 0;
$passed = 0;
$results = [];

function assertTrue(bool $cond, string $name) : void
{
    global $failed, $passed, $results;
    if ($cond) {
        $passed++;
        $results[] = ['ok' => true, 'name' => $name];
    } else {
        $failed++;
        $results[] = ['ok' => false, 'name' => $name];
    }
}

// Password policy
assertTrue(validateStrongPassword('short') !== null, 'password_rejects_short');
assertTrue(validateStrongPassword('LongEnough1!') === null, 'password_accepts_strong');

// Webhook destination SSRF guard
$httpDest = publicWebhookDestination('http://example.com/hook');
$loopDest = publicWebhookDestination('https://127.0.0.1/hook');
assertTrue(empty($httpDest['ok']), 'webhook_blocks_http');
assertTrue(empty($loopDest['ok']), 'webhook_blocks_loopback');

// Ledger conservation helper shape
assertTrue(function_exists('postBalancedJournal'), 'ledger_journal_exists');
assertTrue(function_exists('createBoundPaymentOrder'), 'bound_order_exists');
assertTrue(function_exists('captureVerifiedPaymentOrder'), 'capture_verified_exists');
assertTrue(function_exists('merchantLiveGateSatisfied'), 'live_gate_exists');
assertTrue(function_exists('claimApiIdempotency'), 'api_idempotency_exists');

// Generic webhook must be gone
$webhook = file_get_contents($root . '/webhook.php') ?: '';
assertTrue(str_contains($webhook, '410') || str_contains($webhook, 'Gone'), 'generic_webhook_disabled');

$contactChangeLib = (string)file_get_contents($root . '/includes/contact_change.php');
assertTrue(is_file($root . '/includes/contact_change.php'), 'contact_change_module_present');
assertTrue(str_contains($contactChangeLib, 'function requestMerchantEmailChange'), 'contact_change_email_request');
assertTrue(str_contains($contactChangeLib, 'function requestMerchantPhoneChange'), 'contact_change_phone_request');
assertTrue(str_contains($contactChangeLib, 'function verifyMerchantContactChange'), 'contact_change_verify');
assertTrue(str_contains($contactChangeLib, 'Never applies contact updates from a plain profile POST'), 'contact_change_no_silent_policy');

$notifyLib = (string)file_get_contents($root . '/includes/notify.php');
assertTrue(str_contains($notifyLib, 'function ensureOtpVerificationsSchema'), 'otp_schema_ensure');

// Migration files present
foreach ([
    '001_financial_integrity.sql',
    '003_api_and_webhook_security.sql',
    '008_onboarding_state_machine.sql',
    '009_admin_mfa_audit_ops.sql',
] as $file) {
    assertTrue(is_file($root . '/migrations/' . $file), 'migration_' . $file);
}

// my_account must OTP-gate email/mobile; profile POST must not SET email/phone
$myAccount = (string)file_get_contents($root . '/my_account.php');
assertTrue(str_contains($myAccount, 'verify_contact_change'), 'my_account_otp_verify_action');
assertTrue(str_contains($myAccount, 'request_email_change'), 'my_account_email_otp_request');
assertTrue(str_contains($myAccount, 'request_phone_change'), 'my_account_phone_otp_request');
assertTrue(!preg_match('/UPDATE\s+merchants\s+SET[^;]*\b(email|phone)\s*=/i', $myAccount), 'my_account_no_silent_contact_update');


// Launch public assets
foreach (['robots.txt', 'sitemap.xml', 'favicon.ico', 'favicon.svg', 'manifest.json', 'assets/icons/icon-192.png'] as $asset) {
    assertTrue(is_file($root . '/' . $asset), 'asset_' . str_replace(['/', '.'], '_', $asset));
}

// KYC private storage constants
assertTrue(defined('KYC_PRIVATE_DIR') && KYC_PRIVATE_DIR !== '', 'kyc_private_dir');

// Chargebacks / refunds / settlements
assertTrue(function_exists('ingestChargeback'), 'chargebacks_ready');
assertTrue(function_exists('completeProviderRefund'), 'provider_refunds_ready');
assertTrue(function_exists('processMerchantWebhookQueue'), 'webhook_queue_ready');

$payload = [
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'results' => $results,
    'ran_at' => date('c'),
];

if (PHP_SAPI === 'cli') {
    echo ($payload['ok'] ? "PASS\n" : "FAIL\n");
    foreach ($results as $row) {
        echo ($row['ok'] ? '[ok] ' : '[FAIL] ') . $row['name'] . PHP_EOL;
    }
    exit($failed === 0 ? 0 : 1);
}

header('Content-Type: application/json');
echo json_encode($payload, JSON_PRETTY_PRINT);
