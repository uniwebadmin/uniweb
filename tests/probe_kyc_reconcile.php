<?php
declare(strict_types=1);

/**
 * KYC + reconcile runtime probes — no live partner keys or ₹1 payment required.
 * Usage: php tests/probe_kyc_reconcile.php
 */

$root = dirname(__DIR__);
$failures = [];

$fail = static function (string $name, string $detail = '') use (&$failures): void {
    $failures[] = $detail !== '' ? "{$name}: {$detail}" : $name;
};

require_once $root . '/includes/kyc_verify.php';
require_once $root . '/includes/kyc_submit_guard.php';
require_once $root . '/includes/kyc_reconcile_workflow.php';

$score = nameMatchScore('Rajesh Kumar Traders', 'Rajesh Kumar');
if ($score < 0.5) {
    $fail('name_match_score_sanity', (string)$score);
}

$mismatch = evaluateMerchantNameAgainstRegistry(0, 'Acme Corp', 'pan');
if (!empty($mismatch['ok'])) {
    $fail('name_eval_missing_merchant');
}

$fp1 = kycSubmitFingerprint('verify', [1, 'pan', 'ABCDE1234F']);
$fp2 = kycSubmitFingerprint('verify', [1, 'pan', 'ABCDE1234F']);
if ($fp1 !== $fp2 || strlen($fp1) !== 64) {
    $fail('kyc_submit_fingerprint_stable');
}

$errorCatcher = (string)file_get_contents($root . '/includes/error_catcher.php');
if (!str_contains($errorCatcher, '$message = maskPiiRegex($message)')) {
    $fail('error_log_masks_message');
}
if (!str_contains($errorCatcher, '$requestUri')) {
    $fail('error_log_masks_url');
}

foreach (['kyc.php', 'verify_api.php', 'kyc_media_receiver.php', 'admin_kyc.php'] as $entry) {
    $body = (string)file_get_contents($root . '/' . $entry);
    if (!str_contains($body, 'claimKycSubmitLock')) {
        $fail('kyc_idempotent_wired_' . $entry);
    }
}

$probe = runReconcileLiveProveProbes();
if (empty($probe['ok'])) {
    $fail('reconcile_live_prove_probes', 'failed=' . (int)($probe['failed'] ?? 0));
}

echo 'KYC_RECON_PROBE failures=' . count($failures) . PHP_EOL;
foreach ($failures as $f) {
    echo '  FAIL ' . $f . PHP_EOL;
}
exit(count($failures) > 0 ? 1 : 0);
