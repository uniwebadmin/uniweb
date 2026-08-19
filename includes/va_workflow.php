<?php
declare(strict_types=1);

/**
 * VA rail workflow — supported vs unsupported gateway list (diagram audit #9).
 *
 * Partner Registry ≠ VA adapter. Only bank rails with a live create adapter may
 * call createAdditionalVirtualAccount(). Checkout PGs fail fast with an honest message.
 */

/** Partners that must never receive VA create API calls from va_manager. */
function vaKnownUnsupportedCreationGateways(): array
{
    return [
        'razorpay',
        'cashfree',
        'payu',
        'phonepe',
        'pinelabs',
        'decentro',
        'worldline',
        'digio',
    ];
}

/** @return array<string, string> gateway key => what to use instead */
function vaPartnerUseInsteadMap(): array
{
    return [
        'razorpay' => 'Razorpay checkout / payment links',
        'cashfree' => 'Cashfree checkout / payment links',
        'payu' => 'PayU checkout',
        'phonepe' => 'PhonePe UPI checkout',
        'pinelabs' => 'Pine Labs checkout (roadmap)',
        'decentro' => 'Decentro KYC / payout / UPI collect APIs',
        'worldline' => 'Worldline POS',
        'digio' => 'Digio KYC',
    ];
}

function vaPartnerUseInsteadHint(string $gateway): string
{
    $gateway = strtolower(trim($gateway));
    return vaPartnerUseInsteadMap()[$gateway] ?? 'checkout PG or the correct partner screen';
}

/** Dynamic supported list (axis always; rbl when operational). */
function vaSupportedCreationGatewaysList(): array
{
    if (!function_exists('vaSupportedCreationGateways')) {
        require_once __DIR__ . '/va_manager.php';
    }
    return vaSupportedCreationGateways();
}

/**
 * Unsupported partners that also exist in Partner Registry (for admin UI).
 *
 * @return list<string>
 */
function vaUnsupportedCreationGateways(): array
{
    $known = vaKnownUnsupportedCreationGateways();
    if (!function_exists('getPartnerRegistryKeys')) {
        if (is_file(__DIR__ . '/partner_engine.php')) {
            require_once __DIR__ . '/partner_engine.php';
        }
    }
    if (!function_exists('getPartnerRegistryKeys')) {
        return $known;
    }
    return array_values(array_intersect($known, getPartnerRegistryKeys()));
}

function vaSupportedListLabel(): string
{
    return implode(', ', vaSupportedCreationGatewaysList());
}

/** Error messages — safe when config/DB not bootstrapped (smoke tests). */
function vaSupportedListLabelSafe(): string
{
    if (!function_exists('getDB')) {
        return 'axis (+ rbl when RBL keys ready)';
    }
    try {
        return vaSupportedListLabel();
    } catch (Throwable $e) {
        return 'axis (+ rbl when RBL keys ready)';
    }
}

function vaUnsupportedCreationReason(string $gateway): string
{
    $gateway = strtolower(trim($gateway));
    $supportedLabel = vaSupportedListLabelSafe();

    if (in_array($gateway, vaKnownUnsupportedCreationGateways(), true)) {
        return 'Gateway "' . $gateway . '" VA creation is not live yet. Supported: '
            . $supportedLabel . '. Use ' . vaPartnerUseInsteadHint($gateway) . ' instead.';
    }

    return 'Gateway "' . $gateway . '" VA creation is not live yet. Supported: ' . $supportedLabel . '.';
}

/**
 * Gate before any VA create — unsupported partners never reach bank API.
 *
 * @return array{ok:bool,gateway:string,supported:list<string>,error?:string,use_instead?:string}
 */
function vaCreationGateCheck(string $gateway): array
{
    $gateway = strtolower(trim($gateway));

    if (in_array($gateway, vaKnownUnsupportedCreationGateways(), true)) {
        return [
            'ok' => false,
            'gateway' => $gateway,
            'supported' => ['axis'],
            'unsupported' => vaKnownUnsupportedCreationGateways(),
            'use_instead' => vaPartnerUseInsteadHint($gateway),
            'error' => vaUnsupportedCreationReason($gateway),
        ];
    }

    $supported = vaSupportedCreationGatewaysList();

    if (in_array($gateway, $supported, true)) {
        return [
            'ok' => true,
            'gateway' => $gateway,
            'supported' => $supported,
        ];
    }

    return [
        'ok' => false,
        'gateway' => $gateway,
        'supported' => $supported,
        'unsupported' => vaKnownUnsupportedCreationGateways(),
        'use_instead' => vaPartnerUseInsteadHint($gateway),
        'error' => vaUnsupportedCreationReason($gateway),
    ];
}

function vaPartnerDisplayLabel(string $gateway): string
{
    if (function_exists('vaGatewayDisplayName')) {
        return vaGatewayDisplayName($gateway);
    }
    if (!function_exists('getPartnerRegistry')) {
        if (is_file(__DIR__ . '/partner_engine.php')) {
            require_once __DIR__ . '/partner_engine.php';
        }
    }
    $reg = function_exists('getPartnerRegistry') ? getPartnerRegistry() : [];
    $key = strtolower(trim($gateway));
    if (isset($reg[$key]['name'])) {
        return (string)$reg[$key]['name'];
    }
    return ucfirst($key);
}

/** Readiness for one supported VA rail (keys/mock — not merchant-specific). */
function vaSupportedRailReadiness(string $gateway): array
{
    $gateway = strtolower(trim($gateway));

    if ($gateway === 'axis') {
        if (!function_exists('axisCredentials')) {
            require_once __DIR__ . '/axis.php';
        }
        $creds = axisCredentials();
        $mockOk = function_exists('axisAllowMock') && axisAllowMock();
        $hasKeys = trim((string)($creds['client_id'] ?? '')) !== '';
        $ready = $hasKeys || $mockOk;

        return [
            'gateway' => 'axis',
            'label' => vaPartnerDisplayLabel('axis'),
            'listed' => true,
            'ready' => $ready,
            'message' => $ready
                ? ($mockOk && !$hasKeys ? 'Dev mock ON — UAT VA rows OK.' : 'Axis keys saved — VA create allowed.')
                : 'Paste Axis keys in Partner Registry → Axis Bank → Keys.',
        ];
    }

    if ($gateway === 'rbl') {
        if (!function_exists('rblReadinessReport')) {
            require_once __DIR__ . '/rbl_workflow.php';
        }
        $rbl = rblReadinessReport();
        $listed = in_array('rbl', vaSupportedCreationGatewaysList(), true);

        return [
            'gateway' => 'rbl',
            'label' => vaPartnerDisplayLabel('rbl'),
            'listed' => $listed,
            'ready' => !empty($rbl['ok']),
            'message' => $listed
                ? (string)($rbl['message'] ?? 'RBL keys incomplete.')
                : 'RBL appears after Corp ID + Master Account are saved (no demo defaults).',
        ];
    }

    return [
        'gateway' => $gateway,
        'label' => vaPartnerDisplayLabel($gateway),
        'listed' => false,
        'ready' => false,
        'message' => 'Not a VA bank rail.',
    ];
}

/**
 * Admin / status panel — supported vs unsupported partners for VA create.
 *
 * @return array{
 *   supported:list<array{gateway:string,label:string,listed:bool,ready:bool,message:string}>,
 *   unsupported:list<array{gateway:string,label:string,use_instead:string}>,
 *   supported_keys:list<string>,
 *   unsupported_keys:list<string>,
 *   message:string
 * }
 */
function vaRailReadinessReport(): array
{
    $supportedKeys = vaSupportedCreationGatewaysList();
    $supported = [];
    foreach (array_unique(array_merge(['axis', 'rbl'], $supportedKeys)) as $gw) {
        if ($gw === 'rbl' && !in_array('rbl', $supportedKeys, true)) {
            $supported[] = vaSupportedRailReadiness('rbl');
            continue;
        }
        if ($gw === 'axis' || in_array($gw, $supportedKeys, true)) {
            $supported[] = vaSupportedRailReadiness($gw);
        }
    }

    $unsupported = [];
    foreach (vaKnownUnsupportedCreationGateways() as $gw) {
        $unsupported[] = [
            'gateway' => $gw,
            'label' => vaPartnerDisplayLabel($gw),
            'use_instead' => vaPartnerUseInsteadHint($gw),
        ];
    }

    $readyCount = count(array_filter($supported, static fn(array $row): bool => !empty($row['ready'])));
    $message = $readyCount > 0
        ? 'VA create is wired for bank rails only. Checkout PG partners cannot mint a VA number here.'
        : 'Paste Axis keys (or enable dev mock) before creating virtual accounts.';

    return [
        'supported' => $supported,
        'unsupported' => $unsupported,
        'supported_keys' => $supportedKeys,
        'unsupported_keys' => vaKnownUnsupportedCreationGateways(),
        'message' => $message,
    ];
}
