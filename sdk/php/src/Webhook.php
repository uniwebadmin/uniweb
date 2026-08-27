<?php
declare(strict_types=1);

namespace UniWeb\Client;

/** Verify outbound UniWeb webhook deliveries (X-UniWeb-Signature). */
final class Webhook
{
    public static function verifySignature(string $rawBody, string $signatureHeader, string $signingSecret): bool
    {
        if ($rawBody === '' || $signatureHeader === '' || $signingSecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $signingSecret);
        return hash_equals($expected, $signatureHeader);
    }

    /** @return array<string,mixed>|null Decoded event or null when signature invalid. */
    public static function parseEvent(string $rawBody, string $signatureHeader, string $signingSecret): ?array
    {
        if (!self::verifySignature($rawBody, $signatureHeader, $signingSecret)) {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }
}
