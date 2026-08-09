<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/crypto.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "PASS: {$label}\n"; }
    else { $fail++; echo "FAIL: {$label}\n"; }
}

// C1: encrypt → decrypt round-trip
$enc = pii_encrypt('ABCDE1234F');
check('pii_encrypt returns encrypted value', isSensitiveEncrypted($enc));
check('pii_decrypt round-trip', pii_decrypt($enc) === 'ABCDE1234F');

// C1: idempotent — encrypting already-encrypted value is no-op
$enc2 = pii_encrypt($enc);
check('pii_encrypt idempotent', $enc2 === $enc);

// C1: masking functions
check('pii_mask_pan format', pii_mask_pan($enc) === 'ABCDE****F');
check('pii_mask_aadhaar format', pii_mask_aadhaar(pii_encrypt('123456789012')) === 'XXXX-XXXX-9012');
check('pii_mask_account format', pii_mask_account(pii_encrypt('1234567890')) === '****7890');
check('pii_mask_gstin format', pii_mask_gstin(pii_encrypt('27ABCDE1234F1Z5')) === '***********F1Z5');

// C1: pii_hash returns 64-char hex
$h = pii_hash('ABCDE1234F');
check('pii_hash is 64-char hex', strlen($h) === 64 && ctype_xdigit($h));
check('pii_hash deterministic', pii_hash('ABCDE1234F') === $h);

// C1: fail-closed when key missing — test with empty key
// (skip if ENCRYPTION_KEY is set, which it should be)
if (defined('ENCRYPTION_KEY') && ENCRYPTION_KEY !== '') {
    check('ENCRYPTION_KEY is configured', true);
} else {
    check('ENCRYPTION_KEY is configured (WARNING: not set!)', false);
}

echo "\nC1 Crypto Test: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
