<?php
declare(strict_types=1);

/**
 * Auto-KYC risk fail-closed workflow — diagram audit #18.
 *
 * Fail-closed: risk/AML unknown or flagged → skip auto-verify.
 * Quirks: test video optional, missing video column lenient, PAN API soft-fail.
 * Manual assist: 3 verify_failed → clarification lane.
 */

function autoKycRiskFailClosedDisclaimer(): string
{
    return 'Auto-KYC fails closed on risk: blocked/suspended merchants, rejected/clarification KYC, '
        . 'open AML high/critical flags, or when risk state cannot be read. Name mismatch and repeated '
        . 'verify failures route to manual assist after the configured threshold (default 3).';
}

function autoKycRiskManualAssistThreshold(): int
{
    if (function_exists('getSetting')) {
        return max(1, (int)getSetting('kyc_max_failures_before_manual', '3'));
    }
    return 3;
}

function autoKycRiskVideoSettingKey(): string
{
    return 'video_kyc_required_for_auto';
}

/** @return list<array{key:string,label:string,behavior:string}> */
function autoKycRiskQuirksCatalog(): array
{
    return [
        [
            'key' => 'risk_db_fail_closed',
            'label' => 'Risk DB read error',
            'behavior' => 'merchantHasRiskFlags returns true — auto-verify blocked (fail-closed)',
        ],
        [
            'key' => 'video_column_lenient',
            'label' => 'video_kyc_status column missing',
            'behavior' => 'checkVideoKycCompleted returns true — video not enforced (legacy DB quirk)',
        ],
        [
            'key' => 'test_video_optional',
            'label' => 'Test mode + video_kyc_required_for_auto OFF',
            'behavior' => 'Video skipped — logged as video_skipped_not_required',
        ],
        [
            'key' => 'pan_api_soft_fail',
            'label' => 'Decentro PAN API down',
            'behavior' => 'runKycVerificationChecks soft_fail — does not alone block auto-verify',
        ],
        [
            'key' => 'manual_escape',
            'label' => 'Admin manual verify',
            'behavior' => 'admin_kyc.php Verify always available — bypasses auto engine',
        ],
    ];
}

/** @return list<array{order:int,key:string,label:string,fail_mode:string}> */
function autoKycRiskGateOrder(): array
{
    return [
        ['order' => 1, 'key' => 'risk', 'label' => 'Risk / AML / blocked status', 'fail_mode' => 'fail-closed skip'],
        ['order' => 2, 'key' => 'video', 'label' => 'Video KYC when required', 'fail_mode' => 'skip'],
        ['order' => 3, 'key' => 'docs', 'label' => 'Required docs clean + complete', 'fail_mode' => 'skip'],
        ['order' => 4, 'key' => 'name', 'label' => 'Name consistency PAN vs business', 'fail_mode' => 'verify_failed + manual assist threshold'],
        ['order' => 5, 'key' => 'verify', 'label' => 'completeMerchantKycVerification', 'fail_mode' => 'verify_failed on error'],
    ];
}

function autoKycRiskEnsureEngine(): void
{
    if (!function_exists('merchantHasRiskFlags') && is_file(__DIR__ . '/auto_kyc.php')) {
        require_once __DIR__ . '/auto_kyc.php';
    }
}

/**
 * Early gate: risk + video (steps 1–2 of engine).
 *
 * @param array<string,mixed> $merchantRow
 * @return array{continue:bool,summary_key?:string,log_action?:string,log_detail?:string,side_log?:array{action:string,detail:string}}
 */
function autoKycRiskEarlyGate(int $merchantId, array $merchantRow = []): array
{
    autoKycRiskEnsureEngine();

    if (!function_exists('merchantHasRiskFlags') || merchantHasRiskFlags($merchantId)) {
        return [
            'continue' => false,
            'summary_key' => 'skipped_risk',
            'log_action' => 'skipped',
            'log_detail' => 'Risk flags present',
        ];
    }

    if (!function_exists('checkVideoKycCompleted') || !checkVideoKycCompleted($merchantId)) {
        return [
            'continue' => false,
            'summary_key' => 'skipped_video',
            'log_action' => 'skipped',
            'log_detail' => 'Video KYC not verified (required by setting)',
        ];
    }

    $sideLog = null;
    if (function_exists('getSetting')) {
        $acctMode = (string)($merchantRow['account_mode'] ?? 'test');
        $vidRequired = getSetting(autoKycRiskVideoSettingKey(), ($acctMode === 'live') ? '1' : '0');
        if ($vidRequired !== '1') {
            $sideLog = [
                'action' => 'video_skipped_not_required',
                'detail' => 'Video KYC not required for auto-KYC (setting off, mode=' . $acctMode . ')',
            ];
        }
    }

    return ['continue' => true, 'side_log' => $sideLog];
}

/**
 * Name mismatch gate (step 4) — may trigger manual assist.
 *
 * @return array{continue:bool,summary_key?:string,log_action?:string,log_detail?:string,manual_assist?:bool,routed_manual?:bool}
 */
function autoKycRiskNameGate(int $merchantId): array
{
    autoKycRiskEnsureEngine();

    if (!function_exists('checkNameConsistency')) {
        return ['continue' => true];
    }

    $nameCheck = checkNameConsistency($merchantId);
    if (!empty($nameCheck['ok'])) {
        return ['continue' => true];
    }

    $mismatch = (string)($nameCheck['mismatch'] ?? 'Name mismatch');
    $manualAssist = false;
    $routed = false;

    if (function_exists('logAutoKycRun')) {
        logAutoKycRun($merchantId, 'verify_failed', $mismatch);
    }
    if (function_exists('shouldRouteToManualAssist') && shouldRouteToManualAssist($merchantId)) {
        $manualAssist = true;
        if (function_exists('routeToManualAssist')) {
            routeToManualAssist($merchantId, $mismatch);
            $routed = true;
        }
    }

    return [
        'continue' => false,
        'summary_key' => 'skipped_name_mismatch',
        'log_action' => 'skipped',
        'log_detail' => 'Name mismatch: ' . $mismatch,
        'manual_assist' => $manualAssist,
        'routed_manual' => $routed,
    ];
}

/** After autoVerifyMerchantKyc returned false. */
function autoKycRiskVerifyFailureGate(int $merchantId): array
{
    autoKycRiskEnsureEngine();
    $routed = false;
    if (function_exists('shouldRouteToManualAssist') && shouldRouteToManualAssist($merchantId)) {
        if (function_exists('routeToManualAssist')) {
            routeToManualAssist($merchantId, 'Repeated auto-KYC verification failures');
            $routed = true;
        }
    }
    return ['routed_manual' => $routed];
}

function autoKycRiskAdminEducation(): array
{
    return [
        'title' => 'Auto-KYC fail-closed + quirks',
        'policy' => autoKycRiskFailClosedDisclaimer(),
        'threshold' => autoKycRiskManualAssistThreshold(),
        'gates' => autoKycRiskGateOrder(),
        'quirks' => autoKycRiskQuirksCatalog(),
        'manual_page' => 'admin_kyc.php',
    ];
}

/**
 * @return array{ok:bool,message:string,checks:array<string,bool>,missing:list<string>,threshold:int}
 */
function autoKycRiskReadinessReport(): array
{
    autoKycRiskEnsureEngine();

    $checks = [
        'engine_function' => function_exists('runAutoKycEngine'),
        'risk_fail_closed' => str_contains((string)@file_get_contents(__DIR__ . '/auto_kyc.php'), 'fail-closed'),
        'aml_high_critical' => str_contains((string)@file_get_contents(__DIR__ . '/auto_kyc.php'), "severity IN ('high','critical')"),
        'early_gate' => function_exists('autoKycRiskEarlyGate'),
        'name_gate' => function_exists('autoKycRiskNameGate'),
        'manual_assist' => function_exists('routeToManualAssist') && function_exists('shouldRouteToManualAssist'),
        'canonical_verify' => str_contains((string)@file_get_contents(__DIR__ . '/auto_kyc.php'), 'completeMerchantKycVerification'),
    ];

    $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

    return [
        'ok' => empty($missing),
        'message' => empty($missing)
            ? autoKycRiskFailClosedDisclaimer()
            : 'Auto-KYC risk gates incomplete: ' . implode(', ', $missing),
        'checks' => $checks,
        'missing' => $missing,
        'threshold' => autoKycRiskManualAssistThreshold(),
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function autoKycRiskHealthCheck(): array
{
    $report = autoKycRiskReadinessReport();

    if (!empty($report['missing'])) {
        return [
            'id' => 'auto_kyc_risk',
            'label' => 'Auto-KYC risk (fail-closed)',
            'ok' => false,
            'status' => 'Gates incomplete',
            'detail' => $report['message'],
            'test_url' => 'admin_auto_kyc.php',
        ];
    }

    return [
        'id' => 'auto_kyc_risk',
        'label' => 'Auto-KYC risk (fail-closed)',
        'ok' => true,
        'status' => 'Fail-closed ON · manual assist after ' . (int)$report['threshold'] . ' failures',
        'detail' => autoKycRiskFailClosedDisclaimer(),
        'test_url' => 'admin_auto_kyc.php',
    ];
}
