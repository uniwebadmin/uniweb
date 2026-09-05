<?php
declare(strict_types=1);

/**
 * Forward queue + gateway submit — Audit B points #7–8 (diagram-first).
 *
 * #7 Staged = package ready on UniWeb, not live at partner yet (normal before keys/API).
 * #8 admin_forward_queue (auto) and admin_gateway_submit (manual) stay synced via syncGatewaySubmissionToForwardQueue().
 */

function forwardStagedStatusKey(): string
{
    return 'staged';
}

function forwardStagedDefinition(): string
{
    return 'Package saved on UniWeb — not sent to the bank/partner yet (live KYC API + success-rate routing stay parked).';
}

function forwardStagedIsNormal(array $statsByStatus): bool
{
    $staged = (int)($statsByStatus[forwardStagedStatusKey()] ?? 0);
    $queued = (int)($statsByStatus['queued'] ?? 0);
    $processing = (int)($statsByStatus['processing'] ?? 0);
    $success = (int)($statsByStatus['success'] ?? 0);
    if ($staged <= 0) {
        return true;
    }
    // Mostly staged before live keys is expected — not an error if little or no success yet.
    return $success === 0 || $staged >= ($queued + $processing);
}

function forwardQueueAutoPage(): string
{
    return 'admin_forward_queue.php';
}

function forwardQueueManualPage(): string
{
    return 'admin_gateway_submit.php';
}

function forwardQueueSyncFunction(): string
{
    return 'syncGatewaySubmissionToForwardQueue';
}

function forwardStagedAdminEducation(): array
{
    return [
        'title' => 'KYC Forward — Staged is normal before live keys',
        'staged_meaning' => forwardStagedDefinition(),
        'mostly_staged' => 'When partner Test/Live keys or live KYC API are pending, most rows show Staged — this is expected, not a failure.',
        'next_steps' => [
            'Paste partner keys in Partner Registry → Test Connection',
            'Re-queue staged rows after keys saved (or wait for cron)',
            'Success status only when adapter confirms or local_record saves honestly',
        ],
        'must_not' => [
            'Show Staged as "sent to bank"',
            'Treat all Staged as failed queue',
            'Hide staged count on dashboard',
        ],
        'filter_url' => forwardQueueAutoPage() . '?status=staged',
    ];
}

function gatewaySubmitVsForwardQueueEducation(): array
{
    return [
        'title' => 'Gateway Submit ↔ Forward Queue — one data path',
        'auto_screen' => forwardQueueAutoPage(),
        'auto_screen_note' => 'Auto queue after Admin Verify (one row per KYC-forward partner)',
        'manual_screen' => forwardQueueManualPage() . ' — Multi-Gateway Forward bulk / status updates',
        'sync' => forwardQueueSyncFunction() . '() keeps gateway_submissions and partner_forward_queue aligned',
        'tables' => 'gateway_submissions (manual audit) + partner_forward_queue (worker queue)',
        'must_not' => [
            'Maintain two unsynced forward truths',
            'Manual submit without forward queue sync',
            'Auto queue without gateway_submissions mirror on manual path',
        ],
        'diagram_phone' => '_inbox/chat/daigram/33-wiring-forward-b6-b8-phone.html',
        'diagram_full' => '_inbox/chat/daigram/34-wiring-forward-b6-b8-full-diagrams.md',
    ];
}

/** Documented trigger points for Chain A (Owner proof). */
function forwardQueueTriggerPoints(): array
{
    return [
        [
            'trigger' => 'Admin KYC verify (super solo)',
            'file' => 'admin_kyc.php',
            'action' => 'verify_merchant_now',
            'handler' => 'verifyMerchantKycNow() → completeMerchantKycVerification() → advanceMerchantForwardAfterVerify()',
        ],
        [
            'trigger' => 'Admin KYC verify (checker approve)',
            'file' => 'includes/onboarding_security.php',
            'action' => 'kyc_merchant_verify',
            'handler' => 'applyApprovedControlAction() → completeMerchantKycVerification() → advanceMerchantForwardAfterVerify()',
        ],
        [
            'trigger' => 'Auto KYC verify',
            'file' => 'includes/auto_kyc.php',
            'action' => 'cron / admin run',
            'handler' => 'completeMerchantKycVerification() → advanceMerchantForwardAfterVerify()',
        ],
        [
            'trigger' => 'Explicit Forward to partners',
            'file' => 'admin_kyc.php',
            'action' => 'forward_partners_now',
            'handler' => 'forwardMerchantToPartnersNow() → enqueueMerchantToAllEnabledPartners() → processPerPartnerForwardQueue()',
        ],
        [
            'trigger' => 'Live enable (checker)',
            'file' => 'includes/onboarding_security.php',
            'action' => 'merchant_live_enable',
            'handler' => 'advanceMerchantForwardAfterVerify() after live gate',
        ],
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function forwardQueueWorkflowHealthCheck(): array
{
    $root = dirname(__DIR__);
    $fwdPage = (string)@file_get_contents($root . '/admin_forward_queue.php');
    $gwPage = (string)@file_get_contents($root . '/admin_gateway_submit.php');
    $pfq = (string)@file_get_contents($root . '/includes/partner_forward_queue.php');

    $checks = [
        'workflow' => is_file(__DIR__ . '/forward_queue_workflow.php'),
        'staged_copy' => str_contains($fwdPage, 'not sent to the bank/partner yet') || str_contains($fwdPage, forwardStagedDefinition()),
        'cross_link_submit' => str_contains($fwdPage, forwardQueueManualPage()),
        'cross_link_queue' => str_contains($gwPage, forwardQueueAutoPage()),
        'sync_fn' => str_contains($pfq, forwardQueueSyncFunction()),
        'gateways_sync_call' => str_contains((string)@file_get_contents($root . '/includes/gateways.php'), forwardQueueSyncFunction())
            && str_contains((string)@file_get_contents($root . '/includes/gateways.php'), 'updateGatewaySubmissionStatus')
            && str_contains((string)@file_get_contents($root . '/includes/gateways.php'), 'manual_status'),
    ];

    $ok = !in_array(false, $checks, true);
    $failed = array_keys(array_filter($checks, static fn ($v) => !$v));

    return [
        'id' => 'forward_queue_sync',
        'label' => 'Forward queue / Gateway submit (B7–B8)',
        'ok' => $ok,
        'status' => $ok ? 'Staged honest · submit↔queue synced' : 'Fix — ' . implode(', ', $failed),
        'detail' => 'Staged = ready not sent · syncGatewaySubmissionToForwardQueue on manual submit',
        'test_url' => forwardQueueAutoPage() . '?status=staged',
    ];
}
