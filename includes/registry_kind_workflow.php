<?php
declare(strict_types=1);

/**
 * gateway_registry kind split — methods vs partners (diagram audit #15, migration 066).
 *
 * Same DB table; registry_kind = method | partner. Partner Registry UI = partners only.
 * Merchant Payment Methods = method rails only. Code truth: getPartnerRegistry() + paymentMethodRegistryKeys().
 */

function registryKindDisclaimer(): string
{
    return 'gateway_registry holds both payment methods (UPI, Card, QR) and tech partners (Razorpay, Cashfree). '
        . 'registry_kind splits them — Partner Registry shows partners only; merchant toggles use method rows.';
}

/** @return list<string> */
function registryKindMethodKeys(): array
{
    if (!function_exists('paymentMethodRegistryKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return paymentMethodRegistryKeys();
}

/** @return list<string> */
function registryKindPartnerKeys(): array
{
    if (!function_exists('getPartnerRegistryKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return getPartnerRegistryKeys();
}

function registryKindResolve(string $gatewayKey): string
{
    $key = strtolower(trim($gatewayKey));
    if ($key === '') {
        return 'unknown';
    }
    if (!function_exists('paymentMethodRegistryKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (in_array($key, paymentMethodRegistryKeys(), true)) {
        return 'method';
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (isset(getPartnerRegistry()[$key])) {
        return 'partner';
    }
    return 'unknown';
}

/**
 * Block registering a payment-method rail as a partner.
 *
 * @return array{ok:bool,error?:string,kind?:string}
 */
function registryKindPartnerRegistrationGate(string $gatewayKey): array
{
    $key = strtolower(trim($gatewayKey));
    if ($key === '') {
        return ['ok' => false, 'error' => 'Partner key is required.'];
    }
    $kind = registryKindResolve($key);
    if ($kind === 'method') {
        return [
            'ok' => false,
            'kind' => 'method',
            'error' => "'{$key}' is a payment method rail (UPI/Card/QR), not a partner. "
                . 'Toggle it on the merchant Payment Methods page — do not register it in Partner Registry.',
        ];
    }
    return ['ok' => true, 'kind' => $kind === 'partner' ? 'partner' : 'custom'];
}

/**
 * Ensure admin partner lists never include method rows.
 *
 * @return array{ok:bool,mode:string,note?:string}
 */
function registryKindPartnerListGate(): array
{
    if (!function_exists('gatewayRegistryKindClause')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $clause = gatewayRegistryKindClause('partner');
    if (!str_contains($clause, 'registry_kind') && !str_contains($clause, 'NOT IN')) {
        return [
            'ok' => false,
            'mode' => 'filter_missing',
            'note' => 'Partner list filter could not resolve registry_kind — apply migration 066.',
        ];
    }
    return [
        'ok' => true,
        'mode' => 'partner_only',
        'note' => 'getRegisteredGateways() filters registry_kind=partner.',
    ];
}

function registryKindAdminEducation(): array
{
    return [
        'title' => 'Partner Registry vs Payment Methods',
        'summary' => registryKindDisclaimer(),
        'partner_screen' => 'admin_gateway_registry.php — bank/PG partners (keys, commercial, activate)',
        'method_screen' => 'payment_methods.php — UPI, Card, QR toggles per merchant',
        'migration' => '066_registry_kind.sql',
        'method_examples' => array_slice(registryKindMethodKeys(), 0, 6),
        'partner_count' => count(registryKindPartnerKeys()),
    ];
}

/**
 * @return array{
 *   ok:bool,
 *   message:string,
 *   checks:array<string,bool>,
 *   missing:list<string>,
 *   method_keys:list<string>,
 *   partner_count:int,
 *   column_present:bool
 * }
 */
function registryKindReadinessReport(): array
{
    if (!function_exists('gatewayRegistryHasKindColumn')) {
        require_once __DIR__ . '/payment_methods.php';
    }

    $columnPresent = gatewayRegistryHasKindColumn();
    $methodKeys = registryKindMethodKeys();
    $partnerKeys = registryKindPartnerKeys();
    $listGate = registryKindPartnerListGate();

    $checks = [
        'migration_column' => $columnPresent,
        'method_keys_defined' => $methodKeys !== [],
        'partner_registry_defined' => $partnerKeys !== [],
        'partner_list_filter' => !empty($listGate['ok']),
        'backfill_helper' => function_exists('backfillGatewayRegistryKinds'),
        'kind_clause_helper' => function_exists('gatewayRegistryKindClause'),
        'no_overlap' => count(array_intersect($methodKeys, $partnerKeys)) === 0,
    ];

    $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

    return [
        'ok' => empty($missing),
        'message' => empty($missing)
            ? 'registry_kind split OK — Partner Registry = partners, Payment Methods = method rails.'
            : 'Apply migration 066 or fix registry_kind backfill: ' . implode(', ', $missing),
        'checks' => $checks,
        'missing' => $missing,
        'method_keys' => $methodKeys,
        'partner_count' => count($partnerKeys),
        'column_present' => $columnPresent,
        'list_gate' => $listGate,
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function registryKindHealthCheck(): array
{
    $report = registryKindReadinessReport();

    if (empty($report['column_present'])) {
        return [
            'id' => 'registry_kind',
            'label' => 'Registry kind (method vs partner)',
            'ok' => false,
            'status' => 'Migration 066 pending',
            'detail' => 'gateway_registry.registry_kind column missing — Partner Registry may show mixed rows.',
            'test_url' => 'gateway_settings.php',
        ];
    }

    if (!empty($report['missing'])) {
        return [
            'id' => 'registry_kind',
            'label' => 'Registry kind (method vs partner)',
            'ok' => false,
            'status' => 'Split incomplete',
            'detail' => $report['message'],
            'test_url' => 'admin_gateway_registry.php',
        ];
    }

    return [
        'id' => 'registry_kind',
        'label' => 'Registry kind (method vs partner)',
        'ok' => true,
        'status' => 'Split OK · ' . (int)$report['partner_count'] . ' partners · ' . count($report['method_keys']) . ' method rails',
        'detail' => registryKindDisclaimer(),
        'test_url' => 'admin_gateway_registry.php',
    ];
}
