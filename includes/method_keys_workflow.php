<?php
declare(strict_types=1);

/**
 * Payment method key aliases workflow — canonical catalog vs registry names (diagram audit #10).
 *
 * Problem: checkout read `upi_p2m` in enabled_methods JSON while toggles wrote `net_banking`
 * — same method looked OFF on checkout. Fix: normalize every read/write via one map.
 */

/** Alias → canonical catalog key (enabled_methods JSON + checkout allow()). */
function methodKeyCheckoutAliasMap(): array
{
    return [
        'upi' => 'upi_p2m',
        'upi_p2m' => 'upi_p2m',
        'dc' => 'debit_card',
        'debit_card' => 'debit_card',
        'cc' => 'credit_card',
        'credit_card' => 'credit_card',
        'net_banking' => 'netbanking',
        'netbanking' => 'netbanking',
        'nb' => 'netbanking',
        'payu_upi' => 'payu_upi',
        'axis_va' => 'axis_va',
        'wallet' => 'wallet',
        'emi' => 'emi',
        'qr_code' => 'qr_code',
        'payout' => 'payout',
        'recurring' => 'recurring',
        'razorpay' => 'razorpay',
        'cashfree' => 'cashfree',
        'instant_settlement' => 'instant_settlement',
    ];
}

/** Canonical catalog key → gateway_registry.gateway_key (DB row name). */
function methodKeyRegistryOverrides(): array
{
    return [
        'netbanking' => 'net_banking',
    ];
}

/** Single canonical catalog key for any alias (checkout tab key, JSON, allow()). */
function methodKeyNormalize(string $methodKey): string
{
    $key = strtolower(trim($methodKey));
    return methodKeyCheckoutAliasMap()[$key] ?? $key;
}

/**
 * @param list<string|int|float> $keys
 * @return list<string>
 */
function methodKeyNormalizeList(array $keys): array
{
    $out = [];
    foreach ($keys as $k) {
        $n = methodKeyNormalize((string)$k);
        if ($n !== '') {
            $out[$n] = true;
        }
    }
    return array_keys($out);
}

/** gateway_registry row key for merchant_payment_methods.method_key. */
function methodKeyRegistryResolve(string $methodKey): string
{
    $canonical = methodKeyNormalize($methodKey);
    return methodKeyRegistryOverrides()[$canonical] ?? $canonical;
}

function methodKeysAreEquivalent(string $a, string $b): bool
{
    return methodKeyNormalize($a) === methodKeyNormalize($b);
}

function methodKeysMismatchExplain(): string
{
    return 'Checkout reads merchants.enabled_methods (catalog keys like upi_p2m, netbanking). '
        . 'If toggles or legacy JSON used aliases (upi, net_banking), the same method looked OFF at checkout. '
        . 'normalizeCheckoutMethodKey() maps all aliases before every compare and write.';
}

/**
 * @return array{ok:bool,alias_map:array<string,string>,registry_overrides:array<string,string>,examples:list<array{alias:string,canonical:string,registry:string}>,writers:list<string>,message:string}
 */
function methodKeysReadinessReport(): array
{
    $examples = [
        ['alias' => 'upi', 'canonical' => 'upi_p2m', 'registry' => 'upi_p2m'],
        ['alias' => 'net_banking', 'canonical' => 'netbanking', 'registry' => 'net_banking'],
        ['alias' => 'nb', 'canonical' => 'netbanking', 'registry' => 'net_banking'],
        ['alias' => 'dc', 'canonical' => 'debit_card', 'registry' => 'debit_card'],
    ];

    return [
        'ok' => true,
        'alias_map' => methodKeyCheckoutAliasMap(),
        'registry_overrides' => methodKeyRegistryOverrides(),
        'examples' => $examples,
        'writers' => [
            'persistMerchantEnabledMethodsJson',
            'syncMerchantEnabledMethodsFromToggles',
            'backfillMerchantEnabledMethodsJson',
            'toggleMerchantPaymentMethod',
            'setMerchantPaymentMethods',
        ],
        'message' => methodKeysMismatchExplain(),
    ];
}
