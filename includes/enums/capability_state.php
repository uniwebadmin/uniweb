<?php
declare(strict_types=1);

/**
 * Capability / integration honesty labels — single source of truth.
 * LIVE | STUB | PARKED | NEVER (NEVER products must not appear as sellable UI).
 */
final class CapabilityState
{
    public const LIVE = 'LIVE';
    public const STUB = 'STUB';
    public const PARKED = 'PARKED';
    public const NEVER = 'NEVER';

    /** @return list<string> */
    public static function cases(): array
    {
        return [self::LIVE, self::STUB, self::PARKED, self::NEVER];
    }

    public static function label(string $state): string
    {
        $state = strtoupper(trim($state));
        return match ($state) {
            self::LIVE => 'LIVE',
            self::STUB => 'STUB',
            self::PARKED => 'PARKED',
            self::NEVER => 'NEVER',
            default => 'UNKNOWN',
        };
    }

    public static function defaultHint(string $state): string
    {
        $state = strtoupper(trim($state));
        return match ($state) {
            self::LIVE => 'Real path works when keys and config are complete.',
            self::STUB => 'UI or registry exists — checkout or API path incomplete.',
            self::PARKED => 'Intentionally off or later — Owner switch or design default.',
            self::NEVER => 'Must not ship as product (blocked by policy).',
            default => '',
        };
    }

    /** Maps to ui-pill CSS variant slug. */
    public static function badgeVariant(string $state): string
    {
        $state = strtoupper(trim($state));
        return match ($state) {
            self::LIVE => 'live',
            self::STUB => 'stub',
            self::PARKED => 'parked',
            self::NEVER => 'never',
            default => 'neutral',
        };
    }
}

/** @return array{live:int,stub:int,parked:int,never:int,total:int} */
function platformPartnerCapabilityCounts(): array
{
    $counts = ['live' => 0, 'stub' => 0, 'parked' => 0, 'never' => 0, 'total' => 0];
    if (!function_exists('getPartnerRegistry') || !function_exists('partnerIntegrationState')) {
        return $counts;
    }
    foreach (array_keys(getPartnerRegistry()) as $key) {
        $state = partnerIntegrationState((string)$key)['state'] ?? CapabilityState::PARKED;
        $counts['total']++;
        match (strtoupper((string)$state)) {
            CapabilityState::LIVE => $counts['live']++,
            CapabilityState::STUB => $counts['stub']++,
            CapabilityState::NEVER => $counts['never']++,
            default => $counts['parked']++,
        };
    }
    return $counts;
}
