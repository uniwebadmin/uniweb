<?php
declare(strict_types=1);

/**
 * Gateway × operation integration matrix — scaffold registry only.
 * Live/test API calls require owner-pasted partner keys (BLOCKED_OWNER overnight).
 */

function integrationMatrixGateways(): array
{
    return [
        'razorpay' => 'Razorpay',
        'cashfree' => 'Cashfree',
        'payu' => 'PayU',
        'decentro' => 'Decentro',
        'axis' => 'Axis Bank VA',
        'pinelabs' => 'PineLabs',
        'phonepe' => 'PhonePe',
        'worldline' => 'Worldline',
        'digio' => 'Digio',
    ];
}

function integrationMatrixOperations(): array
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

/** @return array{status:string,note:string} */
function integrationMatrixCellStatus(string $gateway, string $operation): array
{
    $configured = isGatewayConfigured($gateway);
    $codeScaffold = match ($operation) {
        'failure_reason_map' => function_exists('mapGatewayFailureReason'),
        'webhook_idempotency', 'webhook_receive' => in_array($gateway, ['razorpay', 'cashfree', 'payu', 'decentro', 'axis'], true),
        'refund_path' => in_array($gateway, ['razorpay', 'cashfree', 'payu', 'decentro'], true) && function_exists('createRazorpayRefund'),
        'settlement_path' => in_array($gateway, ['razorpay', 'cashfree', 'payu'], true),
        'mdr_apply' => true,
        'recon_match' => function_exists('reconcileBankStatementRows'),
        'merchant_visibility' => in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true),
        default => false,
    };

    if (in_array($operation, ['live_mode_call', 'test_mode_call'], true)) {
        if (!$configured) {
            return ['status' => 'blocked_owner', 'note' => 'Partner keys not configured — owner paste in Partner Registry → Partner Detail → Keys'];
        }
        if ($operation === 'live_mode_call' && $gateway === 'axis') {
            return ['status' => 'blocked_axis_uat', 'note' => 'Axis live blocked until RM/UAT package (bucket 6)'];
        }
        return ['status' => 'blocked_owner', 'note' => 'Overnight: no live partner API calls without owner keys'];
    }

    if ($codeScaffold) {
        return ['status' => 'scaffold', 'note' => 'Code path exists; verify with keys when owner enables'];
    }

    return ['status' => 'pending', 'note' => 'Scaffold pending or N/A for this gateway'];
}

function integrationMatrixSummary(): array
{
    $rows = [];
    foreach (integrationMatrixGateways() as $gw => $label) {
        foreach (integrationMatrixOperations() as $op => $opLabel) {
            if ($gw === 'decentro' && $op === 'webhook_receive') {
                /* included in matrix */
            }
            if (!integrationMatrixOpApplies($gw, $op)) {
                continue;
            }
            $cell = integrationMatrixCellStatus($gw, $op);
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

function integrationMatrixOpApplies(string $gateway, string $operation): bool
{
    $pgOnly = ['refund_path', 'settlement_path'];
    $noRefund = ['pinelabs', 'phonepe', 'worldline'];
    if (in_array($operation, $pgOnly, true) && in_array($gateway, $noRefund, true)) {
        return false;
    }
    if ($operation === 'webhook_receive' && !in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true)) {
        return false;
    }
    if ($operation === 'merchant_visibility' && !in_array($gateway, ['decentro', 'axis', 'pinelabs', 'phonepe', 'worldline', 'digio'], true)) {
        return false;
    }
    if ($gateway === 'digio' && $operation === 'webhook_receive') {
        return true;
    }
    return true;
}

function integrationMatrixStatusBadge(string $status): string
{
    $map = [
        'scaffold' => ['Scaffold', 'bg-sky-500/20 text-sky-300'],
        'blocked_owner' => ['Blocked (keys)', 'bg-amber-500/20 text-amber-300'],
        'blocked_axis_uat' => ['Blocked (Axis UAT)', 'bg-red-500/20 text-red-300'],
        'pending' => ['Pending', 'bg-gray-700 text-gray-400'],
        'pass' => ['Pass', 'bg-emerald-500/20 text-emerald-300'],
    ];
    [$label, $cls] = $map[$status] ?? ['Unknown', 'bg-gray-700 text-gray-400'];
    return '<span class="text-[10px] px-2 py-0.5 rounded ' . $cls . '">' . e($label) . '</span>';
}
