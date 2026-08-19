<?php
declare(strict_types=1);

/**
 * Route / Split workflow — Phase 11 parked by default (diagram audit #12).
 *
 * Today LIVE: standard settlement (collect → M/P ledger → wallet → bank).
 * Parked: Razorpay Route / Cashfree Easy Split / PayU Split capture-time transfer API.
 * Owner unlock: gateway_settings route_split_live_enabled + keys + commercial config.
 */

function routeSplitSettingKey(): string
{
    return 'route_split_live_enabled';
}

function routeSplitIsParked(): bool
{
    if (function_exists('routeSplitLiveEnabled')) {
        return !routeSplitLiveEnabled();
    }
    if (function_exists('getSetting')) {
        return trim((string)getSetting(routeSplitSettingKey(), '0')) !== '1';
    }
    return true;
}

function routeSplitParkedDisclaimer(): string
{
    return 'Route / Easy Split (Phase 11) is parked. Commission still applies on capture via M/P ledger. '
        . 'Money settles through standard wallet → bank (T+0/T+1/T+2). Owner enables live Route in Platform Settings when commercial is signed.';
}

/** Steps merchants/admins see for today's live path. */
function routeSplitTodaySettlementFlow(): array
{
    return [
        ['step' => 1, 'key' => 'collect', 'label' => 'Payment success', 'detail' => 'Checkout / UPI / card capture'],
        ['step' => 2, 'key' => 'ledger', 'label' => 'M/P commission', 'detail' => 'Admin + partner fee on ledger at capture'],
        ['step' => 3, 'key' => 'wallet', 'label' => 'Merchant wallet', 'detail' => 'Net balance after commission'],
        ['step' => 4, 'key' => 'settle', 'label' => 'Settlement batch', 'detail' => 'T+0 / T+1 / T+2 per merchant schedule'],
        ['step' => 5, 'key' => 'bank', 'label' => 'Bank transfer', 'detail' => 'Standard settlement to merchant bank account'],
    ];
}

/** Future Phase 11 path when Owner unlocks (not default live). */
function routeSplitFutureCaptureSplitFlow(): array
{
    return [
        ['step' => 1, 'key' => 'capture', 'label' => 'Payment capture', 'detail' => 'Same checkout success'],
        ['step' => 2, 'key' => 'transfer', 'label' => 'Partner transfer API', 'detail' => 'Razorpay Route / Cashfree Easy Split / PayU Split'],
        ['step' => 3, 'key' => 'vendor', 'label' => 'Linked accounts', 'detail' => 'Merchant vendor + platform linked account legs'],
        ['step' => 4, 'key' => 'webhook', 'label' => 'Transfer webhooks', 'detail' => 'Status sync to partner_transfers table'],
    ];
}

function routeSplitPeerProducts(): array
{
    return [
        ['partner' => 'razorpay', 'product' => 'Razorpay Route', 'split_at' => 'capture / transfer'],
        ['partner' => 'cashfree', 'product' => 'Easy Split', 'split_at' => 'order success'],
        ['partner' => 'payu', 'product' => 'PayU Split', 'split_at' => 'settlement file'],
    ];
}

function routeSplitPhase(): string
{
    if (routeSplitIsParked()) {
        return 'parked';
    }
    if (!function_exists('getRouteSplitReadinessChecklist')) {
        require_once __DIR__ . '/split_settlement.php';
    }
    $ready = getRouteSplitReadinessChecklist();
    return !empty($ready['ready']) ? 'live_ready' : 'owner_on';
}

function routeSplitParkedReason(?string $partnerKey = null): string
{
    if (routeSplitIsParked()) {
        return 'Route / Easy Split is parked (Phase 11). Commission still works via standard settlement (M/P on capture). '
            . 'Turn ON in Platform Settings → Live Money Switches when partner commercial is signed.';
    }
    if ($partnerKey !== null && $partnerKey !== '') {
        if (!function_exists('canUsePartnerRoute')) {
            require_once __DIR__ . '/split_settlement.php';
        }
        if (function_exists('canUsePartnerRoute') && canUsePartnerRoute($partnerKey)) {
            return 'Route live-ready for ' . $partnerKey . ' — capture may call partner transfer API when keys + linked IDs are set.';
        }
    }
    return 'Owner switch ON. Set Partner Commercial → route_mode + ready_for_api/live, paste linked account hints + merchant vendor IDs.';
}

/**
 * Gate before executePartnerRouteSplit calls partner API.
 *
 * @return array{ok:bool,mode:string,note?:string,error?:string,dispatch?:bool}
 */
function routeSplitExecuteGate(string $partnerKey, string $settlementMode): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $settlementMode = strtolower(trim($settlementMode));

    if ($settlementMode !== 'route_mode') {
        return [
            'ok' => true,
            'mode' => 'standard_settle',
            'note' => 'Standard settlement — transfer records are audit/pending until partner cycle.',
            'dispatch' => false,
        ];
    }

    if (routeSplitIsParked()) {
        return [
            'ok' => true,
            'mode' => 'route_mode_parked',
            'note' => 'Route/Split SDK parked until Owner enables Phase 11. Commission uses Admin-saved M/P on capture (standard ledger).',
            'dispatch' => false,
        ];
    }

    if (!function_exists('canUsePartnerRoute')) {
        require_once __DIR__ . '/split_settlement.php';
    }

    if (!function_exists('canUsePartnerRoute') || !canUsePartnerRoute($partnerKey)) {
        return [
            'ok' => true,
            'mode' => 'route_mode_parked',
            'note' => routeSplitParkedReason($partnerKey),
            'dispatch' => false,
        ];
    }

    return [
        'ok' => true,
        'mode' => 'route_mode_live',
        'note' => 'Owner gate open — partner transfer API may dispatch.',
        'dispatch' => true,
    ];
}

/** Merchant-facing copy (Collection Settings). */
function routeSplitMerchantEducation(): array
{
    return [
        'title' => 'Settlement vs Route / Split',
        'today' => 'UniWeb uses standard settlement today — money settles to your wallet / bank on T+0/T+1/T+2 after commission is cut.',
        'today_flow' => routeSplitTodaySettlementFlow(),
        'parked' => 'Route / Split (Phase 11) is parked — Admin prepares partner config only. No marketplace multi-vendor split yet.',
        'future_flow' => routeSplitFutureCaptureSplitFlow(),
        'merchant_action' => 'You do not paste Route or vendor IDs here — Admin manages partner programme in Registry.',
    ];
}

/**
 * @return array{
 *   parked:bool,
 *   phase:string,
 *   message:string,
 *   today_flow:list<array<string,mixed>>,
 *   future_flow:list<array<string,mixed>>,
 *   peers:list<array<string,string>>,
 *   readiness:array<string,mixed>
 * }
 */
function routeSplitReadinessReport(?string $partnerKey = null): array
{
    if (!function_exists('getRouteSplitReadinessChecklist')) {
        require_once __DIR__ . '/split_settlement.php';
    }
    $readiness = getRouteSplitReadinessChecklist($partnerKey);

    return [
        'parked' => routeSplitIsParked(),
        'phase' => routeSplitPhase(),
        'message' => routeSplitParkedReason($partnerKey),
        'disclaimer' => routeSplitParkedDisclaimer(),
        'today_flow' => routeSplitTodaySettlementFlow(),
        'future_flow' => routeSplitFutureCaptureSplitFlow(),
        'peers' => routeSplitPeerProducts(),
        'readiness' => $readiness,
    ];
}
