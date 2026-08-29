<?php
declare(strict_types=1);

namespace UniWeb\Client;

/** Verify outbound UniWeb webhook deliveries (X-UniWeb-Signature). */
final class Webhook
{
    public static function verifySignature(string $rawBody, string $signatureHeader, string $signingSecret, ?string $previousSecret = null): bool
    {
        if ($rawBody === '' || $signatureHeader === '' || $signingSecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $signingSecret);
        if (hash_equals($expected, $signatureHeader)) {
            return true;
        }
        if ($previousSecret !== null && $previousSecret !== '' && $previousSecret !== $signingSecret) {
            $expectedPrev = hash_hmac('sha256', $rawBody, $previousSecret);
            return hash_equals($expectedPrev, $signatureHeader);
        }
        return false;
    }

    /** @return array<string,mixed>|null Decoded event or null when signature invalid. */
    public static function parseEvent(string $rawBody, string $signatureHeader, string $signingSecret, ?string $previousSecret = null): ?array
    {
        if (!self::verifySignature($rawBody, $signatureHeader, $signingSecret, $previousSecret)) {
            return null;
        }
        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : null;
    }
}
