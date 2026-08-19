<?php
declare(strict_types=1);

/**
 * Canonical payout rail workflow — gated stub vs live dispatch (diagram #3).
 *
 * Live money only when payoutLiveMoneyAllowed() (partner keys + payout_live_enabled).
 * Mock adapter uses UNIWEB_TEST_ UTR prefix — never presented as bank money when gated.
 */

function payoutRailCheckLabels(): array
{
    return [
        'partner_keys' => 'Payout partner keys (RazorpayX or Cashfree Payout)',
        'live_switch' => 'Admin payout live switch (payout_live_enabled)',
        'default_adapter' => 'Configured payout adapter',
        'live_allowed' => 'Live money gate open',
    ];
}

/**
 * @return array{ok:bool,checks:array<string,bool>,missing:list<string>}
 */
function payoutRailReadinessReport(): array
{
    if (!function_exists('payoutPartnerKeysConfigured')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!function_exists('resolveDefaultPayoutAdapterName')) {
        require_once __DIR__ . '/payout_partner_api.php';
    }

    $keysOk = payoutPartnerKeysConfigured();
    $switchOn = trim((string)getSetting('payout_live_enabled', '0')) === '1';
    $adapter = resolveDefaultPayoutAdapterName();
    $liveOk = function_exists('payoutLiveMoneyAllowed') && payoutLiveMoneyAllowed();

    $checks = [
        'partner_keys' => $keysOk,
        'live_switch' => $switchOn,
        'default_adapter' => $adapter !== null && $adapter !== '',
        'live_allowed' => $liveOk,
    ];

    return [
        'ok' => $liveOk,
        'checks' => $checks,
        'missing' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
    ];
}

function payoutRailReadinessMissingLabels(array $report): array
{
    $labels = payoutRailCheckLabels();
    $out = [];
    foreach ($report['missing'] ?? [] as $key) {
        $out[] = $labels[(string)$key] ?? str_replace('_', ' ', (string)$key);
    }
    return $out;
}

function payoutUtrIsTest(?string $utr): bool
{
    if (!function_exists('uniwebTestReferenceIsMock') && is_file(__DIR__ . '/partner_keys_workflow.php')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
    }
    if (function_exists('uniwebTestReferenceIsMock')) {
        return uniwebTestReferenceIsMock($utr);
    }
    $utr = strtoupper(trim((string)$utr));
    return $utr !== '' && str_starts_with($utr, 'UNIWEB_TEST_');
}

/** Merchant-safe UTR label — honest about mock vs partner reference. */
function payoutUtrDisplayLabel(?string $utr): string
{
    if (!function_exists('uniwebTestReferenceDisplayLabel') && is_file(__DIR__ . '/partner_keys_workflow.php')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
    }
    if (function_exists('uniwebTestReferenceDisplayLabel')) {
        return uniwebTestReferenceDisplayLabel($utr);
    }
    $utr = trim((string)$utr);
    if ($utr === '') {
        return '—';
    }
    if (payoutUtrIsTest($utr)) {
        return $utr . ' (test — no bank transfer)';
    }
    return $utr . ' (partner reference)';
}

/**
 * Promote gated drafts / stale maker rows to queued when live rail opens.
 */
function promoteGatedPayoutOrdersToQueue(): int
{
    if (!function_exists('payoutLiveMoneyAllowed')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!payoutLiveMoneyAllowed()) {
        return 0;
    }
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare(
            "UPDATE payout_orders SET status='queued', failure_reason=NULL
             WHERE status IN ('draft','pending_maker')"
        );
        $st->execute();
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Keys pasted or live switch ON — promote waiting orders and dispatch queue.
 *
 * @return array{ok:bool,gated?:bool,promoted?:int,dispatch?:array<string,mixed>}
 */
function advancePayoutDispatchPipeline(int $limit = 20): array
{
    if (!function_exists('payoutLiveMoneyAllowed')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!payoutLiveMoneyAllowed()) {
        return [
            'ok' => true,
            'gated' => true,
            'promoted' => 0,
            'message' => payoutActivationMessage(),
        ];
    }

    $promoted = promoteGatedPayoutOrdersToQueue();
    $dispatch = dispatchQueuedPayouts($limit);

    return [
        'ok' => true,
        'gated' => false,
        'promoted' => $promoted,
        'dispatch' => $dispatch,
    ];
}

/** Hook: partner payout keys saved or payout_live_enabled turned ON. */
function onPayoutRailUnlocked(): array
{
    return advancePayoutDispatchPipeline(30);
}

/**
 * Dispatch one order after checker approval (live rail only).
 */
function dispatchPayoutOrderIfLive(int $orderId): array
{
    if (!function_exists('payoutLiveMoneyAllowed')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!payoutLiveMoneyAllowed()) {
        return ['ok' => false, 'gated' => true, 'error' => payoutActivationMessage()];
    }
    return dispatchPayoutOrder($orderId);
}
