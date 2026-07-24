<?php
declare(strict_types=1);

/**
 * Delayed settlement spec — per-vertical delay minutes and lifecycle (scaffold).
 * Transfer API calls remain BLOCKED_OWNER until payout/settlement partner keys exist.
 */

function settlementDelayVerticals(): array
{
    return [
        'normal' => ['label' => 'Normal (default merchant)', 'default_minutes' => 120],
        'plate_roadside' => ['label' => 'Plate / roadside vendor', 'default_minutes' => 60],
        'ecommerce' => ['label' => 'E-commerce', 'default_minutes' => 1440],
        'education' => ['label' => 'Education', 'default_minutes' => 2880],
        'healthcare' => ['label' => 'Healthcare', 'default_minutes' => 1440],
        'default' => ['label' => 'Platform default', 'default_minutes' => 120],
    ];
}

function settlementDelayLifecycleSteps(): array
{
    return [
        'delay_minutes_config' => 'Admin/merchant delay minutes config',
        'cron_picks_due_rows' => 'Cron picks due settlement rows',
        'transfer_api_call' => 'Partner transfer API call (BLOCKED_OWNER without keys)',
        'on_hold' => 'On hold — compliance / dispute',
        'release' => 'Release from hold',
        'merchant_report' => 'Merchant settlement delay report',
        'admin_override' => 'Admin override delay or force release',
    ];
}

/** @return array<string, array<string, string>> */
function settlementDelayMatrix(): array
{
    $matrix = [];
    foreach (settlementDelayVerticals() as $key => $meta) {
        foreach (settlementDelayLifecycleSteps() as $step => $label) {
            $status = match ($step) {
                'delay_minutes_config' => 'scaffold',
                'cron_picks_due_rows' => function_exists('runScheduledSettlementBatches') ? 'scaffold' : 'pending',
                'transfer_api_call' => 'blocked_owner',
                'on_hold', 'release' => 'scaffold',
                'merchant_report' => 'pending',
                'admin_override' => 'scaffold',
                default => 'pending',
            };
            $matrix[$key][$step] = $status;
        }
    }
    return $matrix;
}

function merchantSettlementDelayMinutes(array $merchant): int
{
    $vertical = (string)($merchant['business_vertical'] ?? 'default');
    $verticals = settlementDelayVerticals();
    if (!isset($verticals[$vertical])) {
        $vertical = 'default';
    }
    $custom = (int)($merchant['settlement_delay_minutes'] ?? 0);
    if ($custom > 0) {
        return $custom;
    }
    return (int)$verticals[$vertical]['default_minutes'];
}
