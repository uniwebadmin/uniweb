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
        'corp_and_master' => 'RBL Corp ID + Master Account (sandbox zip fixtures or Owner paste)',
        'operational' => 'Sandbox: Key/Secret + zip fixtures; Live: all Owner-pasted fields',
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
    if (!isRblPartiallyConfigured()) {
        return false;
    }
    if (function_exists('rblIsSandboxEnvironment') && rblIsSandboxEnvironment()) {
        $c = function_exists('rblCredentials') ? rblCredentials() : [];
        return trim((string)($c['corp_id'] ?? '')) !== '' && trim((string)($c['master_account'] ?? '')) !== '';
    }
    foreach (rblRequiredCredentialFields() as $field) {
        if (trim(rblPartnerCredential($field, '')) === '') {
            return false;
        }
    }
    return true;
}

/** Live money: production env + Owner-pasted Corp/Master (never sandbox zip fixtures). */
function rblLiveMoneyAllowed(): bool
{
    if (rblPartnerCredential('rbl_environment', 'sandbox') !== 'production') {
        return false;
    }
    foreach (rblRequiredCredentialFields() as $field) {
        if (trim(rblPartnerCredential($field, '')) === '') {
            return false;
        }
    }
    return true;
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
    $pastedCorpMaster = trim(rblPartnerCredential('rbl_corp_id', '')) !== ''
        && trim(rblPartnerCredential('rbl_master_account', '')) !== '';
    $sandboxFx = function_exists('rblIsSandboxEnvironment') && rblIsSandboxEnvironment();
    $hasCorpMaster = $pastedCorpMaster || ($sandboxFx && $hasClient);
    $operational = $hasClient && $hasCorpMaster;
    $liveEnv = rblPartnerCredential('rbl_environment', 'sandbox') === 'production';
    $liveOk = $liveEnv && $hasClient && $pastedCorpMaster;

    $checks = [
        'client_credentials' => $hasClient,
        'corp_and_master' => $hasCorpMaster,
        'operational' => $operational,
        'live_environment' => $liveEnv || !$operational,
        'live_allowed' => $liveOk,
    ];

    $message = 'Paste RBL Sandbox Key + Secret in Partner Registry → RBL Bank → Keys (Environment = Sandbox).';
    if ($hasClient && $sandboxFx && !$pastedCorpMaster) {
        $message = 'Sandbox Key/Secret saved. Corp ID + Master Account from RBL zip TestCase 1 (VAOPENBANK / 409000832853). Live keys later.';
    } elseif ($hasClient && !$hasCorpMaster) {
        $message = 'RBL Corp ID and Master Account required for production (sandbox zip fixtures do not apply).';
    } elseif ($operational && !$liveEnv) {
        $message = 'RBL sandbox operational — VA/UPI probes allowed. Live collect/settle waits for production keys.';
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
        return 'RBL Corp ID and Master Account required for this environment.';
    }
    return '';
}
