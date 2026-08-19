<?php
declare(strict_types=1);

/**
 * Partner keys Plane B workflow — canonical field names, legacy alias migrate/wipe, mock reference labels.
 * Diagrams audit #4 Pine Labs · #5 Decentro/getSetting · #6 Mock UTR.
 */

/** Legacy gateway_settings keys that must never store new partner secrets (Plane A blocklist). */
function partnerLegacyPlaintextSettingKeys(): array
{
    return [
        'decentro_api_key',
        'decentro_api_secret',
        'pinelabs_api_key',
        'pinelabs_api_secret',
        'axis_api_key',
        'axis_api_secret',
        'razorpay_key_id',
        'razorpay_key_secret',
        'cashfree_app_id',
        'cashfree_secret_key',
        'payu_merchant_key',
        'payu_merchant_salt',
        'rbl_client_id',
        'rbl_client_secret',
        'rbl_corp_id',
        'rbl_master_account',
        'rbl_app_name',
    ];
}

/**
 * Canonical credential key aliases stored inside encrypted payload (old name → still readable).
 *
 * @return array<string, array<string, list<string>>>
 */
function partnerCredentialLegacyAliases(): array
{
    return [
        'decentro' => [
            'decentro_client_id' => ['decentro_api_key'],
            'decentro_client_secret' => ['decentro_api_secret'],
        ],
        'pinelabs' => [
            'pinelabs_merchant_id' => ['pinelabs_merchant_code'],
            'pinelabs_access_code' => ['pinelabs_api_key'],
            'pinelabs_secure_key' => ['pinelabs_api_secret'],
        ],
        'axis' => [
            'axis_client_id' => ['axis_api_key'],
            'axis_client_secret' => ['axis_api_secret'],
        ],
    ];
}

/**
 * Normalize payload to canonical registry key names before encrypt/save.
 *
 * @param array<string, mixed> $payload
 * @return array<string, string>
 */
function normalizePartnerCredentialPayload(string $partnerKey, array $payload): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $out = [];
    foreach ($payload as $k => $v) {
        if (!is_string($k) || !is_scalar($v)) {
            continue;
        }
        $val = trim((string)$v);
        if ($val === '') {
            continue;
        }
        $out[$k] = $val;
    }

    $aliases = partnerCredentialLegacyAliases();
    foreach ($aliases[$partnerKey] ?? [] as $canonical => $legacyNames) {
        if (!empty($out[$canonical])) {
            continue;
        }
        foreach ($legacyNames as $legacy) {
            if (!empty($out[$legacy])) {
                $out[$canonical] = $out[$legacy];
                unset($out[$legacy]);
                break;
            }
        }
    }

    if ($partnerKey === 'pinelabs') {
        // Required trio for isGatewayConfigured('pinelabs') — surface missing in admin report.
        foreach (['pinelabs_merchant_id', 'pinelabs_access_code', 'pinelabs_secure_key'] as $req) {
            if (empty($out[$req])) {
                continue;
            }
        }
    }

    return $out;
}

/**
 * Wipe leftover plaintext partner secrets from gateway_settings after migration to partner_credentials.
 */
function wipeLegacyPartnerPlaintextFromGatewaySettings(): int
{
    if (!function_exists('getSetting')) {
        return 0;
    }
    $wiped = 0;
    $db = function_exists('getDB') ? getDB() : null;
    foreach (partnerLegacyPlaintextSettingKeys() as $key) {
        $plain = trim((string)getSetting($key, ''));
        if ($plain === '') {
            continue;
        }
        try {
            if (function_exists('saveSetting')) {
                saveSetting($key, '');
            } elseif ($db) {
                $db->prepare('UPDATE gateway_settings SET setting_value=? WHERE setting_key=?')->execute(['', $key]);
            }
            $wiped++;
        } catch (Throwable $e) {
            error_log('wipeLegacyPartnerPlaintext: ' . $key . ' — ' . $e->getMessage());
        }
    }
    if ($wiped > 0 && function_exists('clearSettingCache')) {
        clearSettingCache();
    }
    return $wiped;
}

/**
 * Detect UniWeb synthetic test references (payout UTR, route split, etc.).
 */
function uniwebTestReferenceIsMock(?string $reference): bool
{
    $reference = strtoupper(trim((string)$reference));
    return $reference !== '' && str_starts_with($reference, 'UNIWEB_TEST_');
}

function uniwebTestReferenceDisplayLabel(?string $reference, string $liveSuffix = 'partner reference'): string
{
    $reference = trim((string)$reference);
    if ($reference === '') {
        return '—';
    }
    if (uniwebTestReferenceIsMock($reference)) {
        return $reference . ' (test — no bank transfer)';
    }
    return $reference . ' (' . $liveSuffix . ')';
}

/**
 * @return array{ok:bool,checks:array<string,bool>,missing:list<string>,legacy_plaintext:list<string>}
 */
function partnerKeysPlaneReport(): array
{
    if (!function_exists('payoutPartnerKeysConfigured')) {
        require_once __DIR__ . '/payout.php';
    }
    if (!function_exists('getPartnerSetting')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (!function_exists('payoutLiveMoneyAllowed') && is_file(__DIR__ . '/payout_workflow.php')) {
        require_once __DIR__ . '/payout_workflow.php';
    }

    $legacyFound = [];
    foreach (partnerLegacyPlaintextSettingKeys() as $key) {
        if (trim((string)getSetting($key, '')) !== '') {
            $legacyFound[] = $key;
        }
    }

    $pineOk = (bool)getPartnerSetting('pinelabs', 'pinelabs_merchant_id', '')
        && (bool)getPartnerSetting('pinelabs', 'pinelabs_access_code', '')
        && (bool)getPartnerSetting('pinelabs', 'pinelabs_secure_key', '');

    $decentroOk = trim(getPartnerSetting('decentro', 'decentro_client_id', '')) !== ''
        && trim(getPartnerSetting('decentro', 'decentro_client_secret', '')) !== '';

    if (!function_exists('isRblOperational') && is_file(__DIR__ . '/rbl_workflow.php')) {
        require_once __DIR__ . '/rbl_workflow.php';
    }
    $rblPartial = function_exists('isRblPartiallyConfigured') && isRblPartiallyConfigured();
    $rblOk = function_exists('isRblOperational') && isRblOperational();

    $checks = [
        'plane_b_only' => $legacyFound === [],
        'decentro_registry' => $decentroOk,
        'pinelabs_fields_aligned' => $pineOk || !function_exists('partnerIsConfigured') || !partnerIsConfigured('pinelabs'),
        'payout_keys_or_gated' => payoutPartnerKeysConfigured() || !payoutLiveMoneyAllowed(),
        'rbl_no_demo_defaults' => !$rblPartial || $rblOk,
    ];

    return [
        'ok' => !in_array(false, $checks, true),
        'checks' => $checks,
        'missing' => array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok)),
        'legacy_plaintext' => $legacyFound,
    ];
}

function partnerKeysPlaneMissingLabels(array $report): array
{
    $labels = [
        'plane_b_only' => 'No legacy plaintext partner keys in gateway_settings',
        'decentro_registry' => 'Decentro keys in Partner Registry',
        'pinelabs_fields_aligned' => 'Pine Labs canonical fields (when configured)',
        'payout_keys_or_gated' => 'Payout keys present or payout live gated off',
        'rbl_no_demo_defaults' => 'RBL Corp ID + Master Account when RBL keys partial',
    ];
    $out = [];
    foreach ($report['missing'] ?? [] as $key) {
        $out[] = $labels[(string)$key] ?? str_replace('_', ' ', (string)$key);
    }
    return $out;
}
