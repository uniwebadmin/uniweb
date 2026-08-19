<?php
declare(strict_types=1);

/**
 * gateway_submissions.gateway VARCHAR workflow — diagram audit #16, migration 067.
 *
 * ENUM drift blocked new partners (worldline, digio). Column is VARCHAR(40); keys from getPartnerRegistry().
 */

function gatewaySubmissionsGatewayMaxLength(): int
{
    return 40;
}

function gatewaySubmissionsMigrationFile(): string
{
    return '067_gateway_submissions_varchar.sql';
}

function gatewaySubmissionsDisclaimer(): string
{
    return 'gateway_submissions.gateway is VARCHAR(40) — new partners from getPartnerRegistry() insert without ENUM ALTER. '
        . 'Apply migration 067 on live if column is still ENUM.';
}

function gatewaySubmissionsSchemaExpectsVarchar(): bool
{
    $path = __DIR__ . '/gateways.php';
    if (!is_file($path)) {
        return false;
    }
    $src = (string)file_get_contents($path);
    return str_contains($src, 'MODIFY gateway VARCHAR(40)')
        || str_contains($src, "gateway VARCHAR(40)");
}

/** @return list<string> */
function gatewaySubmissionsAllowedPartnerKeys(): array
{
    if (!function_exists('getGatewaySubmissionPartnerKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return getGatewaySubmissionPartnerKeys();
}

/**
 * @return array{ok:bool,error?:string,key?:string}
 */
function gatewaySubmissionsPartnerKeyGate(string $gatewayKey): array
{
    $key = strtolower(trim($gatewayKey));
    if ($key === '') {
        return ['ok' => false, 'error' => 'Partner key is required.'];
    }
    if (strlen($key) > gatewaySubmissionsGatewayMaxLength()) {
        return ['ok' => false, 'error' => 'Partner key longer than VARCHAR(' . gatewaySubmissionsGatewayMaxLength() . ').'];
    }
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) {
        return ['ok' => false, 'error' => 'Partner key must be lowercase slug (a-z, 0-9, underscore).'];
    }
    if (!function_exists('isPaymentMethodRegistryKey')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (function_exists('isPaymentMethodRegistryKey') && isPaymentMethodRegistryKey($key)) {
        return ['ok' => false, 'error' => "'{$key}' is a payment method rail — not a gateway_submissions partner."];
    }
    $allowed = gatewaySubmissionsAllowedPartnerKeys();
    if ($allowed !== [] && !in_array($key, $allowed, true)) {
        return [
            'ok' => false,
            'error' => "'{$key}' is not in getGatewaySubmissionPartnerKeys() — register partner in Partner Registry first.",
        ];
    }
    return ['ok' => true, 'key' => $key];
}

/**
 * @return array{ok:bool,error?:string,key?:string}
 */
function gatewaySubmissionsInsertGate(int $merchantId, string $gatewayKey): array
{
    if ($merchantId < 1) {
        return ['ok' => false, 'error' => 'Invalid merchant.'];
    }
    $keyGate = gatewaySubmissionsPartnerKeyGate($gatewayKey);
    if (empty($keyGate['ok'])) {
        return $keyGate;
    }
    if (!gatewaySubmissionsSchemaExpectsVarchar()) {
        return ['ok' => false, 'error' => 'gateways.php missing VARCHAR(40) schema guard — risk of ENUM drift.'];
    }
    return ['ok' => true, 'key' => (string)($keyGate['key'] ?? $gatewayKey)];
}

function gatewaySubmissionsAdminEducation(): array
{
    return [
        'title' => 'Gateway submissions — VARCHAR partners (not ENUM)',
        'summary' => gatewaySubmissionsDisclaimer(),
        'migration' => gatewaySubmissionsMigrationFile(),
        'max_length' => gatewaySubmissionsGatewayMaxLength(),
        'allowed_source' => 'getGatewaySubmissionPartnerKeys()',
        'pages' => [
            'admin_gateway_submit.php' => 'Manual Multi-Gateway Forward',
            'admin_forward_queue.php' => 'Auto queue after verify (synced)',
        ],
    ];
}

/**
 * @return array{ok:bool,message:string,checks:array<string,bool>,missing:list<string>,allowed_keys:list<string>}
 */
function gatewaySubmissionsReadinessReport(): array
{
    $allowed = [];
    try {
        $allowed = gatewaySubmissionsAllowedPartnerKeys();
    } catch (Throwable $e) {
        $allowed = [];
    }

    $checks = [
        'migration_file' => is_file(dirname(__DIR__) . '/migrations/' . gatewaySubmissionsMigrationFile()),
        'schema_varchar_guard' => gatewaySubmissionsSchemaExpectsVarchar(),
        'ensure_table_helper' => function_exists('ensureGatewaySubmissionsTable'),
        'submit_helper' => function_exists('submitMerchantToGateway'),
        'sync_forward_queue' => function_exists('syncGatewaySubmissionToForwardQueue')
            || str_contains((string)@file_get_contents(__DIR__ . '/partner_forward_queue.php'), 'syncGatewaySubmissionToForwardQueue'),
        'allowed_keys_from_registry' => $allowed !== [],
    ];

    $missing = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

    return [
        'ok' => empty($missing),
        'message' => empty($missing)
            ? 'gateway_submissions uses VARCHAR(40) — new registry partners can submit without ENUM ALTER.'
            : 'Fix gateway_submissions VARCHAR wiring: ' . implode(', ', $missing),
        'checks' => $checks,
        'missing' => $missing,
        'allowed_keys' => $allowed,
    ];
}

/** @return array{id:string,label:string,ok:bool,status:string,detail:string,test_url?:string} */
function gatewaySubmissionsHealthCheck(): array
{
    $report = gatewaySubmissionsReadinessReport();

    if (empty($report['checks']['schema_varchar_guard'])) {
        return [
            'id' => 'gateway_submissions_varchar',
            'label' => 'Gateway submissions (VARCHAR)',
            'ok' => false,
            'status' => 'ENUM drift risk',
            'detail' => 'Apply migration 067 — gateway column should be VARCHAR(40)',
            'test_url' => 'admin_gateway_submit.php',
        ];
    }

    if (!empty($report['missing'])) {
        return [
            'id' => 'gateway_submissions_varchar',
            'label' => 'Gateway submissions (VARCHAR)',
            'ok' => false,
            'status' => 'Incomplete',
            'detail' => $report['message'],
            'test_url' => 'admin_gateway_submit.php',
        ];
    }

    return [
        'id' => 'gateway_submissions_varchar',
        'label' => 'Gateway submissions (VARCHAR)',
        'ok' => true,
        'status' => 'VARCHAR OK · ' . count($report['allowed_keys']) . ' submission partners',
        'detail' => gatewaySubmissionsDisclaimer(),
        'test_url' => 'admin_gateway_submit.php',
    ];
}
