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

function ensureMerchant2FA(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("ALTER TABLE merchants ADD COLUMN totp_secret VARCHAR(64) NULL");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec("ALTER TABLE merchants ADD COLUMN totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    } catch (Throwable $e) { /* ok */ }
}
