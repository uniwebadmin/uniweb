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
    return 'Phase 11 is OFF (default). Checkout uses a fixed collect partner. Commission still posts via standard M/P settlement — not live Route/Easy Split transfers.';
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
        return 'Phase 11 is OFF (default). Checkout uses a fixed collect partner — no smart routing, no live Route/Easy Split transfers.';
    }
    if ($partnerKey !== null && $partnerKey !== '') {
        if (!function_exists('canUsePartnerRoute')) {
            require_once __DIR__ . '/split_settlement.php';
        }
        if (function_exists('canUsePartnerRoute') && canUsePartnerRoute($partnerKey)) {
            return 'Partner config allows Route intent — capture-time transfer still requires Platform switch ON and live transfer SDK.';
        }
    }
    return 'Platform switch ON. Complete Partner Commercial → route status ready_for_api or live, linked account hint, and merchant vendor IDs.';
}

/**
 * Amber warning for Platform Settings when Phase 11 switch ON but partner config incomplete.
 *
 * @return array{title:string,body:string,readiness:string}|null
 */
function phase11SwitchInlineWarning(): ?array
{
    if (routeSplitIsParked()) {
        return null;
    }
    if (!function_exists('getRouteSplitReadinessChecklist')) {
        require_once __DIR__ . '/split_settlement.php';
    }
    $ready = getRouteSplitReadinessChecklist();
    if (!empty($ready['ready'])) {
        return null;
    }
    return [
        'title' => 'Phase 11 ON — Route config incomplete',
        'body' => 'Smart checkout routing may run when collect keys exist. Live Route / Easy Split money movement is not active until Partner Detail → Commercial is ready_for_api or live, linked account hints are saved, and the transfer SDK is enabled.',
        'readiness' => (int)($ready['done'] ?? 0) . '/' . (int)($ready['total'] ?? 0),
    ];
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
        'parked' => 'Route / Split is not a live marketplace product on your account. UniWeb uses standard settlement today.',
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
