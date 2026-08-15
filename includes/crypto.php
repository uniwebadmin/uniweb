<?php
declare(strict_types=1);

/**
 * Sensitive data encryption helpers.
 *
 * Uses AES-256-GCM with a 256-bit key.
 * Encrypted values are prefixed with `enc:v1:` so they can be distinguished
 * from legacy plaintext values while the database is being migrated.
 *
 * The key is read from the ENCRYPTION_KEY constant or the ENCRYPTION_KEY
 * environment variable. It must be 32 raw bytes or a base64-encoded 32-byte
 * string.
 */

const SENSITIVE_ENC_PREFIX = 'enc:v1:';

function _sensitiveKey(): string
{
    if (!defined('ENCRYPTION_KEY')) {
        throw new RuntimeException('ENCRYPTION_KEY is not configured.');
    }
    $key = constant('ENCRYPTION_KEY') ?? '';
    if ($key === '') {
        throw new RuntimeException('ENCRYPTION_KEY is not configured.');
    }
    if (strlen($key) === 32) {
        return $key;
    }
    $decoded = base64_decode($key, true);
    if ($decoded !== false && strlen($decoded) === 32) {
        return $decoded;
    }
    throw new RuntimeException('ENCRYPTION_KEY must be 32 bytes or base64 of 32 bytes.');
}

function sensitiveEncrypt(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return $plain;
    }
    // Idempotent: do not double-encrypt.
    if (isSensitiveEncrypted($plain)) {
        return $plain;
    }
    $key = _sensitiveKey();
    $iv = random_bytes(12);
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($ciphertext === false) {
        throw new RuntimeException('sensitiveEncrypt failed: ' . openssl_error_string());
    }
    return SENSITIVE_ENC_PREFIX . base64_encode($tag . $iv . $ciphertext);
}

function sensitiveDecrypt(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    if (!isSensitiveEncrypted($value)) {
        return $value;
    }
    try {
        $key = _sensitiveKey();
        $raw = base64_decode(substr($value, strlen(SENSITIVE_ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $tag = substr($raw, 0, 16);
        $iv = substr($raw, 16, 12);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            return null;
        }
        return $plain;
    } catch (Throwable $e) {
        return null;
    }
}

function isSensitiveEncrypted(?string $value): bool
{
    return $value !== null && $value !== '' && str_starts_with($value, SENSITIVE_ENC_PREFIX);
}

function sensitiveLast4(?string $value): string
{
    return '****' . sensitiveLast4Raw($value);
}

function sensitiveLast4Raw(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $plain = isSensitiveEncrypted($value) ? sensitiveDecrypt($value) : $value;
    $plain = preg_replace('/\D/', '', (string)$plain) ?: (string)$plain;
    $len = strlen($plain);
    if ($len === 0) {
        return '';
    }
    return substr($plain, -min(4, $len));
}

function sensitiveMask(?string $value, string $type = ''): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    $plain = isSensitiveEncrypted($value) ? sensitiveDecrypt($value) : $value;
    $plain = (string)$plain;
    $len = strlen($plain);
    if ($len <= 4) {
        return '****' . $plain;
    }
    $type = strtolower($type);
    if ($type === 'pan' || $type === 'gst') {
        return str_repeat('*', max(0, $len - 4)) . substr($plain, -4);
    }
    if ($type === 'aadhaar') {
        $clean = preg_replace('/\D/', '', $plain) ?: $plain;
        if (strlen($clean) === 12) {
            return 'XXXX-XXXX-' . substr($clean, -4);
        }
    }
    return '****' . substr($plain, -4);
}

/**
 * Decrypt a sensitive value AND log the access to staff audit trail (C5).
 * Use this instead of sensitiveDecrypt() when admin/staff views full PII.
 */
function sensitiveDecryptWithAudit(?string $value, string $fieldType, ?int $merchantId = null): ?string
{
    if ($value === null || $value === '' || !isSensitiveEncrypted($value)) {
        return $value;
    }
    $plain = sensitiveDecrypt($value);
    if (function_exists('logStaffActivity') && session_status() === PHP_SESSION_ACTIVE) {
        try {
            logStaffActivity('pii_view', 'Viewed full ' . $fieldType, $merchantId, 'merchant', (string)$merchantId);
        } catch (Throwable $e) { /* non-fatal */ }
    }
    return $plain;
}

/* ------------------------------------------------------------------ *
 *  PII helper wrappers (Block C — single shared crypto surface)
 *  Algorithm: AES-256-GCM via sensitiveEncrypt/sensitiveDecrypt.
 *  Key from ENCRYPTION_KEY env constant only — never hardcoded.
 *  All pii_* functions are thin wrappers over the core primitives
 *  so there is exactly one encrypt/decrypt implementation.
 * ------------------------------------------------------------------ */

function pii_encrypt(string $plain): string
{
    return sensitiveEncrypt($plain) ?? '';
}

function pii_decrypt(string $cipher): string
{
    return (string)sensitiveDecrypt($cipher);
}

function pii_mask_aadhaar(?string $value): string
{
    return sensitiveMask($value, 'aadhaar');
}

function pii_mask_pan(?string $value): string
{
    if ($value === null || $value === '') return '—';
    $plain = isSensitiveEncrypted($value) ? sensitiveDecrypt($value) : $value;
    $plain = (string)$plain;
    if (strlen($plain) < 6) return '****';
    return substr($plain, 0, 5) . str_repeat('*', max(0, strlen($plain) - 6)) . substr($plain, -1);
}

function pii_mask_account(?string $value): string
{
    return sensitiveLast4($value);
}

function pii_mask_gstin(?string $value): string
{
    return sensitiveMask($value, 'gst');
}

function pii_hash(string $normalizedValue): string
{
    if (!defined('ENCRYPTION_KEY') || constant('ENCRYPTION_KEY') === '') {
        throw new RuntimeException('ENCRYPTION_KEY is not configured.');
    }
    return hash_hmac('sha256', $normalizedValue, _sensitiveKey());
}

/** PDF audit aliases — one encrypt/decrypt API for the whole app (B-06). */
function encryptSensitive(?string $plain): ?string
{
    return sensitiveEncrypt($plain);
}

function decryptSensitive(?string $value): ?string
{
    return sensitiveDecrypt($value);
}

/**
 * UI plaintext for authorized viewers (merchant own data, admin KYC/detail).
 * Never returns enc:v1: blobs — empty string if decrypt fails.
 */
function sensitiveUiPlain(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    $plain = sensitiveDecrypt($stored);
    if ($plain === null || $plain === '') {
        return isSensitiveEncrypted($stored) ? '' : (string)$stored;
    }
    return (string)$plain;
}

/**
 * Persist a submitted PII field: encrypt new plaintext; keep existing ciphertext if masked/unchanged empty keep.
 */
function sensitiveUiSave(?string $submitted, ?string $existingStored): ?string
{
    $submitted = trim((string)$submitted);
    if ($submitted === '') {
        return $existingStored !== null && $existingStored !== '' ? $existingStored : null;
    }
    if (str_starts_with($submitted, '*') || str_starts_with($submitted, 'X')) {
        return $existingStored;
    }
    if (isSensitiveEncrypted($submitted)) {
        return $submitted;
    }
    return sensitiveEncrypt($submitted);
}
