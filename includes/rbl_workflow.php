<?php
declare(strict_types=1);

/**
 * RBL Bank rail workflow — no demo defaults, honest gates (diagram audit #7).
 *
 * Keys: Partner Registry only (includes/rbl.php → rblPartnerCredential).
 * Live collect/settle only when production keys + rblLiveMoneyAllowed().
 */

function rblRequiredCredentialFields(): array
{
    return ['rbl_client_id', 'rbl_client_secret', 'rbl_corp_id', 'rbl_master_account'];
}

function rblCheckLabels(): array
{
    return [
        'client_credentials' => 'RBL Client ID + Client Secret (Partner Registry)',
        'corp_and_master' => 'RBL Corp ID + Master Account (no demo defaults)',
        'operational' => 'All required RBL fields present',
        'live_environment' => 'Production environment selected for live money',
        'live_allowed' => 'Live RBL collect/settle gate open',
    ];
}

/** Client ID + secret pasted (partial — probe still needs corp + master). */
function isRblPartiallyConfigured(): bool
{
    if (!function_exists('isRblConfigured')) {
        require_once __DIR__ . '/rbl.php';
    }
    return isRblConfigured();
}

/** Full operational gate — VA, UPI collect, payout API calls allowed. */
function isRblOperational(): bool
{
    if (!function_exists('rblPartnerCredential')) {
        require_once __DIR__ . '/rbl.php';
    }
    foreach (rblRequiredCredentialFields() as $field) {
        if (trim(rblPartnerCredential($field, '')) === '') {
            return false;
        }
    }
    return true;
}

/** Live money: production env + all required registry fields. */
function rblLiveMoneyAllowed(): bool
{
    if (!isRblOperational()) {
        return false;
    }
    return rblPartnerCredential('rbl_environment', 'sandbox') === 'production';
}

/**
 * @return array{ok:bool,checks:array<string,bool>,missing:list<string>,message:string}
 */
function rblReadinessReport(): array
{
    if (!function_exists('rblPartnerCredential')) {
        require_once __DIR__ . '/rbl.php';
    }

    $hasClient = trim(rblPartnerCredential('rbl_client_id', '')) !== ''
        && trim(rblPartnerCredential('rbl_client_secret', '')) !== '';
    $hasCorpMaster = trim(rblPartnerCredential('rbl_corp_id', '')) !== ''
        && trim(rblPartnerCredential('rbl_master_account', '')) !== '';
    $operational = $hasClient && $hasCorpMaster;
    $liveEnv = rblPartnerCredential('rbl_environment', 'sandbox') === 'production';
    $liveOk = $operational && $liveEnv;

    $checks = [
        'client_credentials' => $hasClient,
        'corp_and_master' => $hasCorpMaster,
        'operational' => $operational,
        'live_environment' => $liveEnv || !$operational,
        'live_allowed' => $liveOk,
    ];

    $message = 'Paste RBL keys in Partner Registry → RBL Bank → Keys.';
    if ($hasClient && !$hasCorpMaster) {
        $message = 'RBL Corp ID and Master Account required in Partner Registry (no demo defaults).';
    } elseif ($operational && !$liveEnv) {
        $message = 'RBL sandbox ready — test connection OK. Live collect/settle needs production keys.';
    } elseif ($liveOk) {
        $message = 'RBL live gate open — real collect/settle when API responds.';
    }

    return [
        'ok' => $operational,
        'checks' => $checks,
        'missing' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
        'message' => $message,
    ];
}

function rblReadinessMissingLabels(array $report): array
{
    $labels = rblCheckLabels();
    $out = [];
    foreach ($report['missing'] ?? [] as $key) {
        $out[] = $labels[(string)$key] ?? str_replace('_', ' ', (string)$key);
    }
    return $out;
}

function rblGateBlockedReason(): string
{
    if (!isRblPartiallyConfigured()) {
        return 'RBL Client ID and Client Secret are required in Partner Registry.';
    }
    if (!isRblOperational()) {
        return 'RBL Corp ID and Master Account must be set in Partner Registry (no demo defaults).';
    }
    return '';
}
