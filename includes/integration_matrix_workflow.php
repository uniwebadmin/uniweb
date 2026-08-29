<?php
declare(strict_types=1);

/**
 * Integration matrix workflow — honest scaffold board (diagram audit #11).
 *
 * Admin page shows gateway × operation status from code registry + key gates.
 * This module NEVER calls partner APIs — reference / checklist only.
 * Live verify = Partner Registry → partner detail → Test Connection.
 */

function integrationMatrixScaffoldDisclaimer(): string
{
    return 'Integration Status Board is a read-only scaffold — it does not run live partner API calls or paste keys. '
        . 'Paste keys in Partner Registry, then verify on each partner\'s Test Connection tab.';
}

/** Hard rule: matrix UI must not trigger outbound partner API calls. */
function integrationMatrixRunsLiveApiCalls(): bool
{
    return false;
}

function integrationMatrixStatusDefinitions(): array
{
    return [
        'scaffold' => [
            'label' => 'STUB',
            'class' => 'ui-pill-stub',
            'hint' => 'Code path or checklist row exists — verify manually after keys',
        ],
        'blocked_owner' => [
            'label' => 'Blocked (keys)',
            'class' => 'bg-amber-500/20 text-amber-300',
            'hint' => 'Paste partner keys in Partner Registry → Keys',
        ],
        'blocked_axis_uat' => [
            'label' => 'Blocked (Axis UAT)',
            'class' => 'bg-red-500/20 text-red-300',
            'hint' => 'Axis live/UAT package pending from bank RM',
        ],
        'pending' => [
            'label' => 'Pending',
            'class' => 'bg-gray-700 text-gray-400',
            'hint' => 'Not wired for this gateway × operation',
        ],
        'pass' => [
            'label' => 'Pass',
            'class' => 'bg-emerald-500/20 text-emerald-300',
            'hint' => 'Reserved — matrix does not auto-pass; use Test Connection',
        ],
    ];
}

function integrationMatrixOperationDefinitions(): array
{
    return [
        'test_mode_call' => 'Test mode call',
        'live_mode_call' => 'Live mode call',
        'webhook_receive' => 'Webhook receive',
        'webhook_idempotency' => 'Webhook idempotency',
        'failure_reason_map' => 'Failure reason map',
        'success_capture' => 'Success capture',
        'refund_path' => 'Refund path',
        'settlement_path' => 'Settlement path',
        'mdr_apply' => 'MDR apply',
        'recon_match' => 'Recon match',
        'merchant_visibility' => 'Merchant visibility',
    ];
}

function integrationMatrixPartnerLabels(): array
{
    if (!function_exists('getIntegrationMatrixPartnerLabels')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return getIntegrationMatrixPartnerLabels();
}

function integrationMatrixOperationApplies(string $gateway, string $operation): bool
{
    if (!function_exists('partnerHasRegistryFlag')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $pgOnly = ['refund_path', 'settlement_path'];
    $noRefund = ['pinelabs', 'phonepe', 'worldline'];
    if (in_array($operation, $pgOnly, true) && in_array($gateway, $noRefund, true)) {
        return false;
    }
    if ($operation === 'webhook_receive' && !in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true)) {
        return false;
    }
    if ($operation === 'merchant_visibility' && function_exists('partnerHasRegistryFlag')) {
        return partnerHasRegistryFlag($gateway, 'merchant_visibility');
    }
    if ($operation === 'merchant_visibility' && !in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true)) {
        return false;
    }
    return true;
}

/** Code-scaffold exists for gateway × operation (no API call). */
function integrationMatrixCodeScaffoldExists(string $gateway, string $operation): bool
{
    if (!function_exists('partnerHasRegistryFlag')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return match ($operation) {
        'failure_reason_map' => function_exists('mapGatewayFailureReason'),
        'webhook_idempotency', 'webhook_receive' => in_array($gateway, ['razorpay', 'cashfree', 'payu', 'decentro', 'axis', 'digio'], true),
        'refund_path' => in_array($gateway, ['razorpay', 'cashfree', 'payu', 'decentro'], true) && function_exists('createRazorpayRefund'),
        'settlement_path' => in_array($gateway, ['razorpay', 'cashfree', 'payu'], true),
        'mdr_apply' => true,
        'recon_match' => function_exists('reconcileBankStatementRows'),
        'merchant_visibility' => function_exists('partnerHasRegistryFlag')
            ? partnerHasRegistryFlag($gateway, 'merchant_visibility')
            : in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true),
        'success_capture' => in_array($gateway, ['razorpay', 'cashfree', 'payu', 'decentro', 'axis', 'phonepe', 'pinelabs'], true),
        default => false,
    };
}

/**
 * Evaluate one matrix cell — never returns pass from live API probe.
 *
 * @return array{status:string,note:string}
 */
function integrationMatrixEvaluateCell(string $gateway, string $operation): array
{
    $gateway = strtolower(trim($gateway));
    $configured = function_exists('isGatewayConfigured') && isGatewayConfigured($gateway);

    if (in_array($operation, ['live_mode_call', 'test_mode_call'], true)) {
        if (!$configured) {
            return [
                'status' => 'blocked_owner',
                'note' => 'Partner keys not configured — paste in Partner Registry → Partner Detail → Keys',
            ];
        }
        if ($operation === 'live_mode_call' && $gateway === 'axis') {
            return [
                'status' => 'blocked_axis_uat',
                'note' => 'Axis live blocked until RM/UAT commercial package (bucket 6)',
            ];
        }
        return [
            'status' => 'scaffold',
            'note' => 'Keys saved — run Test Connection on Partner Registry; this board does not call partner APIs',
        ];
    }

    if (integrationMatrixCodeScaffoldExists($gateway, $operation)) {
        return [
            'status' => 'scaffold',
            'note' => $configured
                ? 'Code path exists — verify with Test Connection / webhook when ready'
                : 'Code path exists — paste keys in Partner Registry to verify',
        ];
    }

    return [
        'status' => 'pending',
        'note' => 'Scaffold pending or N/A for this gateway',
    ];
}

/** @return list<array{gateway:string,gateway_label:string,operation:string,operation_label:string,status:string,note:string}> */
function integrationMatrixBuildSummary(): array
{
    $rows = [];
    foreach (integrationMatrixPartnerLabels() as $gw => $label) {
        foreach (integrationMatrixOperationDefinitions() as $op => $opLabel) {
            if (!integrationMatrixOperationApplies($gw, $op)) {
                continue;
            }
            $cell = integrationMatrixEvaluateCell($gw, $op);
            $rows[] = [
                'gateway' => $gw,
                'gateway_label' => $label,
                'operation' => $op,
                'operation_label' => $opLabel,
                'status' => $cell['status'],
                'note' => $cell['note'],
            ];
        }
    }
    return $rows;
}

/**
 * @return array{
 *   rows:list<array<string,mixed>>,
 *   counts:array<string,int>,
 *   partners:int,
 *   operations:int,
 *   scaffold_only:bool,
 *   message:string,
 *   legend:array<string,array{label:string,class:string,hint:string}>
 * }
 */
function integrationMatrixReadinessReport(): array
{
    $rows = integrationMatrixBuildSummary();
    $counts = ['scaffold' => 0, 'blocked_owner' => 0, 'blocked_axis_uat' => 0, 'pending' => 0, 'pass' => 0];
    foreach ($rows as $row) {
        $st = (string)($row['status'] ?? 'pending');
        $counts[$st] = ($counts[$st] ?? 0) + 1;
    }

    return [
        'rows' => $rows,
        'counts' => $counts,
        'partners' => count(integrationMatrixPartnerLabels()),
        'operations' => count(integrationMatrixOperationDefinitions()),
        'scaffold_only' => !integrationMatrixRunsLiveApiCalls(),
        'message' => integrationMatrixScaffoldDisclaimer(),
        'legend' => integrationMatrixStatusDefinitions(),
    ];
}

function integrationMatrixStatusBadgeHtml(string $status): string
{
    $defs = integrationMatrixStatusDefinitions();
    $meta = $defs[$status] ?? ['label' => 'Unknown', 'class' => 'bg-gray-700 text-gray-400', 'hint' => ''];
    if (!function_exists('uiStatusPill') && is_file(__DIR__ . '/ui/ui_components.php')) {
        require_once __DIR__ . '/ui/ui_components.php';
    }
    if (function_exists('uiStatusPill')) {
        $variant = match ($status) {
            'scaffold' => 'stub',
            'blocked_owner', 'blocked_axis_uat', 'pending' => 'parked',
            'pass' => 'live',
            default => 'neutral',
        };
        return uiStatusPill($variant, (string)($meta['label'] ?? 'Unknown'), (string)($meta['hint'] ?? ''));
    }
    $title = ($meta['hint'] ?? '') !== '' ? ' title="' . htmlspecialchars((string)$meta['hint'], ENT_QUOTES, 'UTF-8') . '"' : '';
    return '<span class="text-[10px] px-2 py-0.5 rounded ' . e($meta['class']) . '"' . $title . '>'
        . e($meta['label']) . '</span>';
}
