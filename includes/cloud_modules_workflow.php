<?php
declare(strict_types=1);

/**
 * Cloud modules + Auto KYC workflow — diagram audit #13.
 *
 * Old Cursor cloud agent runs missed auto_kyc (engine not loaded). Owner rule: local laptop only.
 * Live path: cloud_modules.php bridge + cron_auto_kyc.php every 10 min.
 */

function cloudModulesWorkPolicyMessage(): string
{
    return 'UniWeb work runs on the local laptop + Hostinger cron. Cursor Cloud Agents are OFF (owner rule). '
        . 'Auto KYC uses cron_auto_kyc.php — not cloud agent runs.';
}

/** Modules that must never be dropped from cloud_modules.php bridge. */
function cloudModulesBridgeCriticalModules(): array
{
    return [
        'auto_kyc.php' => 'Zero-Touch KYC engine',
        'partner_forward_queue.php' => 'Partner forward after verify',
        'kyc_workflow.php' => 'Canonical verify path (loaded via config / pages)',
        'notifications.php' => 'In-app alerts with dedup',
    ];
}

function cloudModulesBridgeFilePath(): string
{
    return __DIR__ . '/cloud_modules.php';
}

function cloudModulesBridgeListsModule(string $moduleFile): bool
{
    $moduleFile = trim($moduleFile);
    if ($moduleFile === '') {
        return false;
    }
    $path = cloudModulesBridgeFilePath();
    if (!is_file($path)) {
        return false;
    }
    $src = (string)file_get_contents($path);
    return str_contains($src, "'" . $moduleFile . "'") || str_contains($src, '"' . $moduleFile . '"');
}

function cloudModulesAutoKycCronScript(): string
{
    return 'cron_auto_kyc.php';
}

function cloudModulesAutoKycCronPath(): string
{
    return dirname(__DIR__) . '/' . cloudModulesAutoKycCronScript();
}

function cloudModulesAutoKycEngineLoaded(): bool
{
    return function_exists('runAutoKycEngine');
}

function cloudModulesEnsureAutoKycEngine(): bool
{
    if (cloudModulesAutoKycEngineLoaded()) {
        return true;
    }
    if (is_file(__DIR__ . '/auto_kyc.php')) {
        require_once __DIR__ . '/auto_kyc.php';
    }
    return cloudModulesAutoKycEngineLoaded();
}

function cloudModulesAutoKycDisclaimer(): string
{
    return cloudModulesWorkPolicyMessage()
        . ' Ensure auto_kyc.php stays in includes/cloud_modules.php and Hostinger cron hits cron_auto_kyc.php every 10 minutes.';
}

/**
 * Gate before cron / admin runs the engine.
 *
 * @return array{ok:bool,mode:string,error?:string,note?:string}
 */
function cloudModulesAutoKycCronGate(): array
{
    if (!is_file(cloudModulesBridgeFilePath())) {
        return [
            'ok' => false,
            'mode' => 'bridge_missing',
            'error' => 'includes/cloud_modules.php missing — live modules may not load auto_kyc.',
        ];
    }

    if (!cloudModulesBridgeListsModule('auto_kyc.php')) {
        return [
            'ok' => false,
            'mode' => 'auto_kyc_not_in_bridge',
            'error' => 'auto_kyc.php not listed in cloud_modules.php — Zero-Touch engine will fail on live.',
        ];
    }

    if (!is_file(__DIR__ . '/auto_kyc.php')) {
        return [
            'ok' => false,
            'mode' => 'auto_kyc_file_missing',
            'error' => 'includes/auto_kyc.php missing from deploy.',
        ];
    }

    if (!is_file(cloudModulesAutoKycCronPath())) {
        return [
            'ok' => false,
            'mode' => 'cron_script_missing',
            'error' => cloudModulesAutoKycCronScript() . ' missing from deploy.',
        ];
    }

    if (!cloudModulesEnsureAutoKycEngine()) {
        return [
            'ok' => false,
            'mode' => 'engine_not_loaded',
            'error' => 'runAutoKycEngine() not available — load auto_kyc.php via cloud_modules or config.',
        ];
    }

    if (!is_file(__DIR__ . '/kyc_workflow.php')) {
        return [
            'ok' => false,
            'mode' => 'kyc_workflow_missing',
            'error' => 'kyc_workflow.php missing — auto verify cannot call canonical path.',
        ];
    }

    return [
        'ok' => true,
        'mode' => 'ready',
        'note' => 'Local laptop + Hostinger cron — not Cursor Cloud Agents.',
    ];
}

/** Admin / Gateway Settings education copy. */
function cloudModulesAutoKycAdminEducation(): array
{
    return [
        'title' => 'Auto KYC — local + cron (not cloud agents)',
        'policy' => cloudModulesWorkPolicyMessage(),
        'cron_script' => cloudModulesAutoKycCronScript(),
        'cron_schedule' => 'Every 10 minutes (Hostinger cron)',
        'bridge_module' => 'auto_kyc.php in cloud_modules.php',
        'admin_page' => 'admin_auto_kyc.php',
        'steps' => [
            ['step' => 1, 'label' => 'Docs uploaded', 'detail' => 'Merchant submits KYC documents'],
            ['step' => 2, 'label' => 'Cron runs', 'detail' => 'cron_auto_kyc.php → runAutoKycEngine()'],
            ['step' => 3, 'label' => 'Auto verify', 'detail' => 'Same path as Admin Verify (kyc_workflow)'],
            ['step' => 4, 'label' => 'Forward queue', 'detail' => 'Partner forward when keys + commercial ready'],
        ],
    ];
}

/**
 * @return array{
 *   ok:bool,
 *   policy:string,
 *   checks:array<string,bool>,
 *   missing:list<string>,
 *   message:string,
 *   bridge_critical:array<string,string>
 * }
 */
function cloudModulesAutoKycReadinessReport(): array
{
    $checks = [
        'work_policy_local' => true,
        'cloud_bridge_file' => is_file(cloudModulesBridgeFilePath()),
        'auto_kyc_in_bridge' => cloudModulesBridgeListsModule('auto_kyc.php'),
        'forward_queue_in_bridge' => cloudModulesBridgeListsModule('partner_forward_queue.php'),
        'auto_kyc_file' => is_file(__DIR__ . '/auto_kyc.php'),
        'kyc_workflow_file' => is_file(__DIR__ . '/kyc_workflow.php'),
        'cron_script' => is_file(cloudModulesAutoKycCronPath()),
        'engine_loadable' => cloudModulesAutoKycEngineLoaded() || cloudModulesEnsureAutoKycEngine(),
    ];

    $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
    $gate = cloudModulesAutoKycCronGate();

    return [
        'ok' => empty($missing) && !empty($gate['ok']),
        'policy' => cloudModulesWorkPolicyMessage(),
        'checks' => $checks,
        'missing' => $missing,
        'message' => !empty($gate['ok'])
            ? 'Auto KYC engine ready — use Hostinger cron + Admin Run Now. Do not use Cursor Cloud Agents.'
            : (string)($gate['error'] ?? 'Auto KYC not ready'),
        'bridge_critical' => cloudModulesBridgeCriticalModules(),
        'gate' => $gate,
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function autoKycEngineHealthCheck(): array
{
    $report = cloudModulesAutoKycReadinessReport();
    $gate = $report['gate'] ?? [];

    if (empty($report['checks']['cloud_bridge_file'])) {
        return [
            'id' => 'auto_kyc_engine',
            'label' => 'Auto KYC Engine',
            'ok' => false,
            'status' => 'Bridge missing',
            'detail' => 'includes/cloud_modules.php not found — live may miss auto_kyc',
            'test_url' => 'admin_auto_kyc.php',
        ];
    }

    if (empty($report['checks']['auto_kyc_in_bridge'])) {
        return [
            'id' => 'auto_kyc_engine',
            'label' => 'Auto KYC Engine',
            'ok' => false,
            'status' => 'auto_kyc not in cloud_modules',
            'detail' => 'Add auto_kyc.php to cloud_modules bridge (audit #13)',
            'test_url' => 'admin_auto_kyc.php',
        ];
    }

    if (empty($gate['ok'])) {
        return [
            'id' => 'auto_kyc_engine',
            'label' => 'Auto KYC Engine',
            'ok' => false,
            'status' => (string)($gate['mode'] ?? 'blocked'),
            'detail' => (string)($gate['error'] ?? 'Engine not loadable'),
            'test_url' => 'admin_auto_kyc.php',
        ];
    }

    $lastRun = null;
    if (function_exists('getLastAutoKycRun')) {
        $lastRun = getLastAutoKycRun();
    }

    $ranAt = is_array($lastRun) ? (string)($lastRun['ran_at'] ?? '') : '';
    $verified = is_array($lastRun) ? (int)($lastRun['summary']['merchants_verified'] ?? 0) : 0;

    return [
        'id' => 'auto_kyc_engine',
        'label' => 'Auto KYC Engine',
        'ok' => true,
        'status' => $ranAt !== '' ? 'Engine ready · last run ' . $ranAt : 'Engine ready · awaiting first cron run',
        'detail' => cloudModulesWorkPolicyMessage()
            . ($verified > 0 ? ' · Last run verified ' . $verified . ' merchant(s).' : ''),
        'test_url' => 'admin_auto_kyc.php',
    ];
}
