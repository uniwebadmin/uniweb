<?php
declare(strict_types=1);

/**
 * Recurring / AutoPay rail workflow — honest pending reasons (diagram audit #8).
 *
 * Mandates stay pending with a clear reason until: admin switch ON, partner keys,
 * merchant live mode, partner registration, and customer UPI approval.
 */

function recurringRailCheckLabels(): array
{
    return [
        'admin_approved' => 'Admin enabled Recurring / AutoPay',
        'partner_keys' => 'Partner keys (Razorpay / Cashfree / Decentro)',
        'merchant_live' => 'Merchant account in Live mode',
        'partner_registered' => 'Mandate registered with partner',
        'customer_approved' => 'Customer UPI / bank approval',
        'live_allowed' => 'Live debit gate open',
    ];
}

/**
 * Pure compute — does not read stale pending_reason from DB.
 */
function computeMandatePendingReason(array $mandate): string
{
    if (!function_exists('recurringAutopayApproved')) {
        require_once __DIR__ . '/mandates.php';
    }

    $status = strtolower((string)($mandate['status'] ?? ''));
    if (!in_array($status, ['pending', 'registered'], true)) {
        return '';
    }

    if (!recurringAutopayApproved()) {
        return 'Admin has not enabled Recurring / AutoPay yet (Platform Settings → Live Money Switches).';
    }

    $channel = (string)($mandate['channel'] ?? 'upi');
    if (empty($mandate['gateway_mandate_id'])) {
        if (!recurringAutopayPartnerKeysConfigured($channel)) {
            return 'Partner keys missing — paste Razorpay / Cashfree / Decentro keys in Partner Registry.';
        }
        return 'Not registered with partner yet — click Register.';
    }

    if (!empty($mandate['auth_url'])) {
        return 'Customer must approve in UPI / bank app — share the authorisation link.';
    }

    return 'Registered with partner — waiting for customer approval (webhook will update status).';
}

/**
 * @return array{ok:bool,checks:array<string,bool>,missing:list<string>,message:string}
 */
function recurringRailReadinessReport(): array
{
    if (!function_exists('getRecurringReadinessChecklist')) {
        require_once __DIR__ . '/mandates.php';
    }
    $checklist = getRecurringReadinessChecklist();

    return [
        'ok' => !empty($checklist['ready']),
        'checks' => array_column($checklist['items'], 'ok', 'id'),
        'missing' => array_values(array_map(
            static fn(array $i): string => (string)$i['id'],
            array_filter($checklist['items'], static fn(array $i): bool => empty($i['ok']))
        )),
        'message' => recurringActivationMessage(),
        'checklist' => $checklist,
    ];
}

function recurringActivationMessage(): string
{
    if (!function_exists('recurringAutopayApproved')) {
        require_once __DIR__ . '/mandates.php';
    }
    if (!recurringAutopayApproved()) {
        return 'Recurring / AutoPay is OFF — enable in Gateway Settings → Live Money Switches.';
    }
    if (!recurringAutopayPartnerKeysConfigured()) {
        return 'Paste Razorpay / Cashfree / Decentro keys in Partner Registry, then create or register mandates.';
    }
    if (!recurringAutopayLiveReady()) {
        return 'Partner keys or cron not ready — see Recurring readiness checklist.';
    }
    return 'Recurring live gate open — register mandates and share customer auth links.';
}

/** Persist fresh pending_reason on all incomplete mandates. */
function syncPendingMandateReasons(): int
{
    if (!function_exists('ensureMandateSchema')) {
        require_once __DIR__ . '/mandates.php';
    }
    ensureMandateSchema();
    $updated = 0;
    try {
        $st = getDB()->query("SELECT * FROM mandates WHERE status IN ('pending','registered')");
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $st->closeCursor();
    } catch (Throwable $e) {
        return 0;
    }

    $upd = getDB()->prepare('UPDATE mandates SET pending_reason=? WHERE id=?');
    foreach ($rows as $mandate) {
        $reason = computeMandatePendingReason($mandate);
        if ($reason === '') {
            continue;
        }
        if ($reason === (string)($mandate['pending_reason'] ?? '')) {
            continue;
        }
        try {
            $upd->execute([$reason, (int)$mandate['id']]);
            $updated++;
        } catch (Throwable $e) {
            error_log('syncPendingMandateReasons: ' . $e->getMessage());
        }
    }

    return $updated;
}

/**
 * Hook: recurring_autopay_approved ON or autopay partner keys saved.
 *
 * @return array{ok:bool,reasons_synced:int,message:string}
 */
function onRecurringRailUnlocked(): array
{
    $synced = syncPendingMandateReasons();

    return [
        'ok' => true,
        'reasons_synced' => $synced,
        'message' => recurringActivationMessage(),
    ];
}

/** Merchant-safe status hint for mandate list rows. */
function mandatePendingReasonDisplay(array $mandate): string
{
    if (function_exists('getMandatePendingReason')) {
        return getMandatePendingReason($mandate);
    }
    return computeMandatePendingReason($mandate);
}
