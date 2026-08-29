<?php
declare(strict_types=1);

/**
 * Timing-safe crypto comparisons — use for all signature / secret equality checks.
 * Never log $expected or $received values from these helpers.
 */

function cryptoTimingSafeEqual(string $expected, string $received): bool
{
    return hash_equals((string)$expected, (string)$received);
}

/** Strip optional sha256= prefix from partner signature headers. */
function cryptoStripSha256Prefix(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^sha256=(.+)$/i', $value, $m)) {
        return trim((string)$m[1]);
    }
    return $value;
}

/** HMAC-SHA256(rawBody, secret) compared as lowercase hex — timing-safe. */
function cryptoVerifyHmacSha256Hex(string $rawBody, string $secret, string $receivedHex): bool
{
    if ($secret === '' || $rawBody === '') {
        return false;
    }
    $receivedHex = cryptoStripSha256Prefix($receivedHex);
    if ($receivedHex === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return cryptoTimingSafeEqual(strtolower($expected), strtolower($receivedHex));
}

/**
 * Try multiple secrets (primary then previous during rotation grace).
 *
 * @param list<string> $secrets
 */
function cryptoVerifyHmacSha256HexAny(string $rawBody, array $secrets, string $receivedHex): bool
{
    foreach ($secrets as $secret) {
        if (!is_string($secret) || $secret === '') {
            continue;
        }
        if (cryptoVerifyHmacSha256Hex($rawBody, $secret, $receivedHex)) {
            return true;
        }
    }
    return false;
}

/** HMAC-SHA256(timestamp . rawBody, secret) → base64 — Cashfree-style. */
function cryptoVerifyHmacSha256B64TimestampBody(string $rawBody, string $secret, string $timestamp, string $receivedB64): bool
{
    if ($secret === '' || $rawBody === '' || $timestamp === '' || $receivedB64 === '') {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));
    return cryptoTimingSafeEqual($expected, $receivedB64);
}

/**
 * @param list<string> $secrets
 */
function cryptoVerifyHmacSha256B64TimestampBodyAny(string $rawBody, array $secrets, string $timestamp, string $receivedB64): bool
{
    foreach ($secrets as $secret) {
        if (!is_string($secret) || $secret === '') {
            continue;
        }
        if (cryptoVerifyHmacSha256B64TimestampBody($rawBody, $secret, $timestamp, $receivedB64)) {
            return true;
        }
    }
    return false;
}
