<?php
declare(strict_types=1);

/**
 * Money-safe runtime probes — no live partner keys or network required.
 * Usage: php tests/probe_money_rails.php
 */

$root = dirname(__DIR__);
$failures = [];

$fail = static function (string $name, string $detail = '') use (&$failures): void {
    $failures[] = $detail !== '' ? "{$name}: {$detail}" : $name;
};

$sanitizeDefaultCollectionMode = static function (string $mode): string {
    $liveSafe = ['direct_upi', 'platform_pg', 'axis_va'];
    $mode = trim($mode);
    return in_array($mode, $liveSafe, true) ? $mode : 'platform_pg';
};

require_once $root . '/includes/merchant_api_errors.php';
require_once $root . '/includes/crypto_compare.php';
require_once $root . '/includes/pg_webhooks.php';
require_once $root . '/includes/refund_webhooks.php';

// New-merchant defaults — never parked Route / Easy Split rails
foreach (['route_split', 'easy_split', 'razorpay_route', 'cashfree_route', 'payu_split'] as $bad) {
    $safe = $sanitizeDefaultCollectionMode($bad);
    if ($safe !== 'platform_pg') {
        $fail('collection_default_live_safe', $bad . ' => ' . $safe);
    }
}

// Platform live-money switches default OFF (migration + code paths)
$m077 = $root . '/migrations/077_live_money_switches_intelligent_routing_defaults.sql';
if (!is_file($m077)) {
    $fail('m077_live_money_defaults_migration');
} else {
    $m077Body = (string)file_get_contents($m077);
    foreach (['payout_live_enabled', 'recurring_autopay_approved', 'route_split_live_enabled', 'intelligent_routing_enabled'] as $key) {
        if (!str_contains($m077Body, $key)) {
            $fail('m077_switch_key_' . $key);
        }
    }
}

// Webhook signature — reject missing / empty body (no DB, no secrets)
$body = '{"event":"payment.captured","payload":{}}';
$rzMissing = pgWebhookVerifyPartner('razorpay', $body, null, []);
if (!empty($rzMissing['ok']) || ($rzMissing['reason'] ?? '') !== 'missing_signature') {
    $fail('wh_razorpay_missing_signature', (string)($rzMissing['reason'] ?? ''));
}
$rzEmpty = pgWebhookVerifyPartner('razorpay', '', null, ['x-razorpay-signature' => 'abc']);
if (!empty($rzEmpty['ok']) || ($rzEmpty['reason'] ?? '') !== 'empty_body') {
    $fail('wh_razorpay_empty_body', (string)($rzEmpty['reason'] ?? ''));
}

$cfMissing = pgWebhookVerifyPartner('cashfree', $body, null, []);
if (!empty($cfMissing['ok']) || !in_array($cfMissing['reason'] ?? '', ['missing_signature', 'invalid_signature_or_stale_timestamp'], true)) {
    $fail('wh_cashfree_missing_signature', (string)($cfMissing['reason'] ?? ''));
}
$cfEmpty = pgWebhookVerifyPartner('cashfree', '', null, ['x-webhook-signature' => 'x', 'x-webhook-timestamp' => (string)time()]);
if (!empty($cfEmpty['ok']) || ($cfEmpty['reason'] ?? '') !== 'empty_body') {
    $fail('wh_cashfree_empty_body');
}

$payuEmpty = pgWebhookVerifyPartner('payu', '', [], null);
if (!empty($payuEmpty['ok'])) {
    $fail('wh_payu_empty_form');
}

if (!cryptoVerifyHmacSha256Hex($body, 'smoke_secret', hash_hmac('sha256', $body, 'smoke_secret'))) {
    $fail('wh_crypto_self_test');
}
if (cryptoVerifyHmacSha256Hex($body, 'smoke_secret', '')) {
    $fail('wh_crypto_empty_sig_must_fail');
}

// Merchant API — stable JSON error_code (no HTML)
$authPayload = merchantApiBuildErrorPayload('auth_failed');
if (($authPayload['error_code'] ?? '') !== 'auth_failed' || ($authPayload['success'] ?? true) !== false) {
    $fail('api_auth_failed_payload');
}
if (merchantApiErrorHttpStatus('auth_failed') !== 401) {
    $fail('api_auth_failed_http');
}
$missingKeyPayload = merchantApiBuildErrorPayload('missing_idempotency_key');
if (($missingKeyPayload['error_code'] ?? '') !== 'missing_idempotency_key') {
    $fail('api_missing_idempotency_payload');
}

// Refund / forward honesty — staged ≠ paid/sent
$pendingRefund = refundDisplayStatus(['status' => 'pending', 'provider_status' => '']);
if ($pendingRefund === 'processed' || $pendingRefund === 'paid') {
    $fail('refund_pending_not_processed', $pendingRefund);
}
$stagedLabelSrc = (string)file_get_contents($root . '/includes/partner_forward_queue.php');
if (!str_contains($stagedLabelSrc, "if (\$status === 'staged' && \$adapter === 'local_record')")) {
    $fail('forward_staged_local_record_label_guard');
}
if (!str_contains($stagedLabelSrc, 'not sent to partner') && !str_contains($stagedLabelSrc, 'not sent to network')) {
    $fail('forward_staged_honest_copy');
}

// Idempotency + ledger — static schema markers
$finSql = (string)@file_get_contents($root . '/migrations/001_financial_integrity.sql');
if (!str_contains($finSql, 'uniq_api_idempotency') || !str_contains($finSql, 'ledger_journals')) {
    $fail('ledger_idempotency_schema');
}
$finPhp = (string)file_get_contents($root . '/includes/financial_integrity.php');
foreach (['claimApiIdempotency', 'captureVerifiedPaymentOrder', 'finalizeSuccessfulPaymentTransaction', 'postPrimaryPaymentCaptureLedger'] as $fn) {
    if (!str_contains($finPhp, 'function ' . $fn)) {
        $fail('financial_integrity_' . $fn);
    }
}

// Error shell — no stack traces in source
$errorShell = (string)file_get_contents($root . '/includes/error_page.php');
if (str_contains($errorShell, 'getMessage') || str_contains($errorShell, 'getTrace')) {
    $fail('error_shell_no_trace_helpers');
}

echo 'MONEY_RAILS_PROBE failures=' . count($failures) . PHP_EOL;
foreach ($failures as $f) {
    echo '  FAIL ' . $f . PHP_EOL;
}
exit(count($failures) > 0 ? 1 : 0);
