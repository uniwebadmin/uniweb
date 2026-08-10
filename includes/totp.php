<?php
declare(strict_types=1);

/**
 * VIP Feature — Optional 2FA (TOTP / Google Authenticator compatible).
 * Self-contained RFC 6238 implementation — no external dependency.
 */

function totpBase32Encode(string $data): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($data) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        if (strlen($chunk) < 5) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        }
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}

function totpBase32Decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
    $bits = '';
    foreach (str_split($secret) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) {
            continue;
        }
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $bytes .= chr(bindec($byte));
        }
    }
    return $bytes;
}

function totpGenerateSecret(int $length = 20): string
{
    return totpBase32Encode(random_bytes($length));
}

function totpCode(string $base32Secret, ?int $timestamp = null, int $period = 30, int $digits = 6): string
{
    $timestamp = $timestamp ?? time();
    $counter = (int)floor($timestamp / $period);
    $binCounter = pack('N*', 0) . pack('N*', $counter); // 64-bit big-endian counter
    $key = totpBase32Decode($base32Secret);
    $hash = hash_hmac('sha1', $binCounter, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $truncated = ((ord($hash[$offset]) & 0x7F) << 24)
        | ((ord($hash[$offset + 1]) & 0xFF) << 16)
        | ((ord($hash[$offset + 2]) & 0xFF) << 8)
        | (ord($hash[$offset + 3]) & 0xFF);
    $code = $truncated % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/** Accepts a code from the current step or one step before/after to allow for clock drift. */
function totpVerify(string $base32Secret, string $code, int $window = 1, int $period = 30): bool
{
    $code = preg_replace('/\D/', '', $code);
    if ($code === '' || strlen($code) !== 6) {
        return false;
    }
    $now = time();
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totpCode($base32Secret, $now + ($i * $period)), $code)) {
            return true;
        }
    }
    return false;
}

function totpAuthUrl(string $base32Secret, string $accountLabel, string $issuer = 'UniWeb'): string
{
    $label = rawurlencode($issuer . ':' . $accountLabel);
    $params = http_build_query([
        'secret' => $base32Secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);
    return "otpauth://totp/{$label}?{$params}";
}

/**
 * Encrypt a TOTP secret for storage at rest.
 * Uses sensitiveEncrypt (AES-256-GCM) if available.
 */
function encryptTotpSecret(string $plainSecret): string
{
    if (function_exists('sensitiveEncrypt')) {
        $enc = sensitiveEncrypt($plainSecret);
        if ($enc !== null && $enc !== '') {
            return $enc;
        }
    }
    return $plainSecret;
}

/**
 * Decrypt a TOTP secret from storage.
 * Returns the plaintext base32 secret, or empty string on failure.
 * Backward compatible: if not encrypted (legacy plaintext), returns as-is.
 */
function decryptTotpSecret(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (function_exists('isSensitiveEncrypted') && isSensitiveEncrypted($stored)) {
        if (function_exists('sensitiveDecrypt')) {
            $dec = sensitiveDecrypt($stored);
            return $dec ?? '';
        }
    }
    // Legacy plaintext — return as-is
    return $stored;
}

/**
 * One-time migration: encrypt plaintext totp_secret values in merchants + admins tables.
 * Idempotent: rows already encrypted (enc:v1: prefix) are skipped.
 * Returns count of rows migrated.
 */
function migrateTotpSecretsEncryption(): array
{
    $db = getDB();
    $migrated = ['merchants' => 0, 'admins' => 0];

    // 1. Migrate merchants.totp_secret
    try {
        $rows = $db->query("SELECT id, totp_secret FROM merchants WHERE totp_secret IS NOT NULL AND totp_secret != ''")->fetchAll();
        foreach ($rows as $row) {
            $stored = (string)$row['totp_secret'];
            if (function_exists('isSensitiveEncrypted') && isSensitiveEncrypted($stored)) {
                continue; // already encrypted
            }
            $encrypted = encryptTotpSecret($stored);
            if ($encrypted !== $stored) {
                $db->prepare("UPDATE merchants SET totp_secret=? WHERE id=?")
                    ->execute([$encrypted, (int)$row['id']]);
                $migrated['merchants']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    // 2. Migrate admins.totp_secret
    try {
        $rows = $db->query("SELECT id, totp_secret FROM admins WHERE totp_secret IS NOT NULL AND totp_secret != ''")->fetchAll();
        foreach ($rows as $row) {
            $stored = (string)$row['totp_secret'];
            if (function_exists('isSensitiveEncrypted') && isSensitiveEncrypted($stored)) {
                continue; // already encrypted
            }
            $encrypted = encryptTotpSecret($stored);
            if ($encrypted !== $stored) {
                $db->prepare("UPDATE admins SET totp_secret=? WHERE id=?")
                    ->execute([$encrypted, (int)$row['id']]);
                $migrated['admins']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    return $migrated;
}

function ensureMerchant2FA(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("ALTER TABLE merchants ADD COLUMN totp_secret VARCHAR(256) NULL");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec("ALTER TABLE merchants MODIFY totp_secret VARCHAR(256) NULL");
    } catch (Throwable $e) { /* ok if already wide enough */ }
    try {
        getDB()->exec("ALTER TABLE merchants ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) { /* ok */ }
}

/** Universal MFA policy: admin/staff = mandatory, merchant = optional. */
function mfaPolicy(string $audience = 'merchant'): array
{
    $audience = strtolower($audience);
    if (in_array($audience, ['admin', 'staff', 'ops'], true)) {
        return [
            'audience' => $audience,
            'required' => true,
            'label' => 'Mandatory',
            'summary' => 'Authenticator MFA is required for all admin and staff logins. First login enrolls your authenticator — you are never locked out without a setup prompt.',
            'setup_hint' => 'Scan the secret with Google Authenticator or Authy, then enter the 6-digit code to finish enrollment.',
        ];
    }
    return [
        'audience' => 'merchant',
        'required' => false,
        'label' => 'Optional',
        'summary' => 'Two-factor authentication is optional for merchants. Enable it anytime from Settings for stronger login protection.',
        'setup_hint' => 'Recommended for accounts that move money or manage team access. You can turn it off later with your password.',
    ];
}

function merchantHasMfaEnabled(?array $merchant): bool
{
    return !empty($merchant['totp_enabled']) && !empty($merchant['totp_secret']);
}

/**
 * Soft setup banner for merchants without 2FA. Never blocks access.
 * Returns HTML string (already escaped) or empty when not applicable.
 */
function renderMerchantMfaSetupPrompt(?array $merchant, string $context = 'dashboard'): string
{
    if (!$merchant || merchantHasMfaEnabled($merchant)) {
        return '';
    }
    // Avoid nagging on the 2FA page itself or during setup wizard.
    if ($context === '2fa_page') {
        return '';
    }
    $policy = mfaPolicy('merchant');
    $dismissKey = 'mfa_prompt_dismissed_at';
    if ($context === 'dashboard' && !empty($_SESSION[$dismissKey]) && (time() - (int)$_SESSION[$dismissKey]) < 86400) {
        return '';
    }
    return '<div class="glass rounded-xl p-4 mb-6 border border-sky-500/25 flex flex-wrap items-center justify-between gap-3">'
        . '<div class="min-w-0">'
        . '<p class="text-sm font-semibold text-sky-300">Optional: enable Two-Factor Authentication</p>'
        . '<p class="text-xs text-gray-500 mt-1">' . htmlspecialchars($policy['setup_hint'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '</div>'
        . '<div class="flex flex-wrap gap-2">'
        . '<a href="merchant_2fa.php" class="text-xs bg-sky-600 text-white px-3 py-2 rounded-lg hover:bg-sky-500">Set up 2FA</a>'
        . ($context === 'dashboard'
            ? '<a href="?dismiss_mfa_prompt=1" class="text-xs text-gray-500 px-3 py-2 hover:text-gray-300">Remind me later</a>'
            : '')
        . '</div></div>';
}
