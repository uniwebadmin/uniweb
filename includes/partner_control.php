<?php
declare(strict_types=1);

/**
 * Partner Control Plane — credentials, methods, merchant links, reason maps.
 * Block B: data model + runtime helpers.
 *
 * Supported partner keys: razorpay, cashfree, payu, phonepe, pinelabs,
 * worldline, axis, rbl, decentro, digio, razorpayx.
 */

function ensurePartnerControlTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        // sort_order on gateway_registry (partners table)
        try { $db->exec("ALTER TABLE gateway_registry ADD COLUMN sort_order INT NOT NULL DEFAULT 99"); } catch (Throwable $e) { /* already exists */ }

        $db->exec("CREATE TABLE IF NOT EXISTS partner_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_key VARCHAR(40) NOT NULL,
            env ENUM('test','live') NOT NULL DEFAULT 'test',
            encrypted_payload TEXT NOT NULL,
            last4 VARCHAR(8) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_partner_env (partner_key, env),
            INDEX idx_partner (partner_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS partner_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_key VARCHAR(40) NOT NULL,
            method VARCHAR(40) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 50,
            min_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
            max_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
            base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_partner_method (partner_key, method),
            INDEX idx_partner_enabled (partner_key, is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        try { $db->exec("ALTER TABLE partner_methods ADD COLUMN base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already exists */ }

        $db->exec("CREATE TABLE IF NOT EXISTS partner_merchant_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            partner_key VARCHAR(40) NOT NULL,
            external_id VARCHAR(120) DEFAULT NULL,
            kyc_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            live_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_merchant_partner (merchant_id, partner_key),
            INDEX idx_partner (partner_key, kyc_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $linkAlters = [
            "ALTER TABLE partner_merchant_links ADD COLUMN account_source VARCHAR(20) NOT NULL DEFAULT 'platform'",
            'ALTER TABLE partner_merchant_links ADD COLUMN partner_mid VARCHAR(120) DEFAULT NULL',
            "ALTER TABLE partner_merchant_links ADD COLUMN credential_status VARCHAR(20) NOT NULL DEFAULT 'missing'",
            "ALTER TABLE partner_merchant_links ADD COLUMN env VARCHAR(10) NOT NULL DEFAULT 'test'",
            'ALTER TABLE partner_merchant_links ADD COLUMN encrypted_payload TEXT DEFAULT NULL',
            "ALTER TABLE partner_merchant_links ADD COLUMN last4 VARCHAR(8) NOT NULL DEFAULT ''",
            'ALTER TABLE partner_merchant_links ADD COLUMN checkout_enabled TINYINT(1) NOT NULL DEFAULT 0',
            "ALTER TABLE partner_merchant_links ADD COLUMN linked_by VARCHAR(20) NOT NULL DEFAULT 'merchant'",
            'ALTER TABLE partner_merchant_links ADD COLUMN linked_by_id INT DEFAULT NULL',
            'ALTER TABLE partner_merchant_links ADD COLUMN owner_override TINYINT(1) NOT NULL DEFAULT 0',
        ];
        foreach ($linkAlters as $sql) {
            try { $db->exec($sql); } catch (Throwable $e) { /* exists */ }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS gateway_reason_maps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_key VARCHAR(40) NOT NULL,
            raw_code VARCHAR(120) NOT NULL,
            msg_en VARCHAR(500) NOT NULL DEFAULT '',
            msg_hi VARCHAR(500) NOT NULL DEFAULT '',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_partner_code (partner_key, raw_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        seedPartnerMethods();
        migrateGatewaySettingsToPartnerCredentials();
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Seed default partner_methods rows for every registered partner.
 * Methods: upi, credit_card, debit_card, netbanking, emi, emandate_upi, emandate_card, emandate_nb
 */
function seedPartnerMethods(): void
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $db = getDB();
    $registry = getPartnerRegistry();
    $defaultMethods = [
        ['upi', 10],
        ['debit_card', 30],
        ['credit_card', 31],
        ['netbanking', 40],
        ['emi', 50],
        ['emandate_upi', 60],
        ['emandate_card', 61],
        ['emandate_nb', 62],
    ];

    foreach ($registry as $key => $p) {
        foreach ($defaultMethods as [$method, $priority]) {
            try {
                $db->prepare("INSERT IGNORE INTO partner_methods (partner_key, method, is_enabled, priority) VALUES (?,?,0,?)")
                    ->execute([$key, $method, $priority]);
            } catch (Throwable $e) { /* ok */ }
        }
    }
}

/**
 * Save encrypted credentials for a partner + env.
 */
function savePartnerCredentials(string $partnerKey, string $env, array $keys, array $configKeys): string
{
    ensurePartnerControlTables();
    $db = getDB();

    $existing = getPartnerCredentials($partnerKey, $env);
    unset($existing['_last4']);
    $payload = is_array($existing) ? $existing : [];

    $submitted = 0;
    foreach ($configKeys as $key => $meta) {
        if (!isset($keys[$key])) continue;
        $val = trim((string)$keys[$key]);
        if ($val === '') continue;
        $payload[$key] = $val;
        $submitted++;
    }

    if ($submitted === 0 || $payload === []) {
        return 'no_keys';
    }

    if (!function_exists('normalizePartnerCredentialPayload')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
    }
    if (function_exists('normalizePartnerCredentialPayload')) {
        $payload = normalizePartnerCredentialPayload($partnerKey, $payload);
    }
    if ($payload === []) {
        return 'no_keys';
    }

    $last4 = '';
    foreach ($payload as $key => $val) {
        if (!is_string($val) || $val === '') {
            continue;
        }
        $k = (string)$key;
        if (str_contains($k, 'secret') || str_contains($k, 'salt') || str_contains($k, 'pass')) {
            $last4 = substr($val, -4);
            break;
        }
    }
    if ($last4 === '') {
        foreach ($payload as $key => $val) {
            if (!is_string($val) || $val === '' || str_contains((string)$key, 'environment')) {
                continue;
            }
            $last4 = substr($val, -4);
            break;
        }
    }

    $encrypted = function_exists('sensitiveEncrypt') ? sensitiveEncrypt(json_encode($payload)) : base64_encode(json_encode($payload));

    $db->prepare(
        "INSERT INTO partner_credentials (partner_key, env, encrypted_payload, last4) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), last4=VALUES(last4)"
    )->execute([$partnerKey, $env, $encrypted, $last4]);

    return $last4;
}

/**
 * Get decrypted credentials for a partner + env.
 */
function getPartnerCredentials(string $partnerKey, string $env = 'test'): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT encrypted_payload, last4 FROM partner_credentials WHERE partner_key=? AND env=?");
        $st->execute([$partnerKey, $env]);
        $row = $st->fetch();
        if (!$row) return [];
        $decrypted = function_exists('sensitiveDecrypt') ? sensitiveDecrypt($row['encrypted_payload']) : base64_decode($row['encrypted_payload']);
        $data = json_decode($decrypted, true) ?: [];
        $data['_last4'] = $row['last4'];
        return $data;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Partner PG credential setting keys that must never live in gateway_settings.
 * Platform Settings (SMTP / cron / templates) cannot save these.
 *
 * @return array<string, list<string>>
 */
function uniwebPartnerCredentialSettingMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $map = [];
    foreach (getPartnerRegistry() as $partnerKey => $meta) {
        $keys = array_keys($meta['config_keys'] ?? []);
        if ($keys !== []) {
            $map[$partnerKey] = $keys;
        }
    }
    return $map;
}

function isPartnerCredentialSettingKey(string $key): bool
{
    if (function_exists('partnerLegacyPlaintextSettingKeys')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
    }
    if (function_exists('partnerLegacyPlaintextSettingKeys') && in_array($key, partnerLegacyPlaintextSettingKeys(), true)) {
        return true;
    }
    foreach (uniwebPartnerCredentialSettingMap() as $keys) {
        if (in_array($key, $keys, true)) {
            return true;
        }
    }
    return false;
}

function partnerDetailKeysUrl(string $partnerKey, string $env = 'live'): string
{
    return 'admin_gateway_detail.php?partner=' . rawurlencode($partnerKey) . '&tab=keys&env=' . rawurlencode($env);
}

/**
 * Resolve canonical partner credential from encrypted payload (handles legacy key names).
 */
function resolvePartnerCredentialValue(array $creds, string $partnerKey, string $keyName): string
{
    if (!empty($creds[$keyName])) {
        return (string)$creds[$keyName];
    }
    if (function_exists('partnerCredentialLegacyAliases')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
        $aliases = partnerCredentialLegacyAliases();
    } else {
        $aliases = [
            'decentro' => [
                'decentro_client_id' => ['decentro_api_key'],
                'decentro_client_secret' => ['decentro_api_secret'],
            ],
            'pinelabs' => [
                'pinelabs_access_code' => ['pinelabs_api_key'],
                'pinelabs_secure_key' => ['pinelabs_api_secret'],
            ],
            'axis' => [
                'axis_client_id' => ['axis_api_key'],
                'axis_client_secret' => ['axis_api_secret'],
            ],
        ];
    }
    foreach ($aliases[$partnerKey][$keyName] ?? [] as $alt) {
        if (!empty($creds[$alt])) {
            return (string)$creds[$alt];
        }
    }
    return '';
}

/** Partner environment (test/live/sandbox) — credentials first, then platform template row. */
function getPartnerEnvironment(string $partnerKey, string $default = 'test'): string
{
    $envKey = $partnerKey . '_environment';
    $fromCreds = trim(getPartnerSetting($partnerKey, $envKey, ''));
    if ($fromCreds !== '') {
        return $fromCreds;
    }
    if (function_exists('getSetting')) {
        return trim((string)getSetting($envKey, $default));
    }
    return $default;
}

function decentroClientId(): string
{
    return getPartnerSetting('decentro', 'decentro_client_id', '');
}

function decentroClientSecret(): string
{
    return getPartnerSetting('decentro', 'decentro_client_secret', '');
}

/** Active partner env label (sandbox/uat/test vs live/production) — Registry first, never plaintext PG secrets from Plane A. */
function partnerActiveEnvironment(string $partnerKey, string $default = 'test'): string
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $reg = getPartnerRegistry()[$partnerKey] ?? null;
    $envKey = (string)($reg['env_key'] ?? $partnerKey . '_environment');
    foreach (['live', 'test', 'production', 'sandbox'] as $bucket) {
        $creds = getPartnerCredentials($partnerKey, $bucket);
        if (!empty($creds[$envKey])) {
            return strtolower(trim((string)$creds[$envKey]));
        }
    }
    return strtolower(trim($default));
}

/** Which encrypted credential bucket to load (test vs live). */
function partnerCredentialEnvBucket(string $partnerKey): string
{
    $env = partnerActiveEnvironment($partnerKey, 'test');
    if (in_array($env, ['live', 'production'], true)) {
        return 'live';
    }
    return 'test';
}

function isDecentroConfigured(): bool
{
    return decentroClientId() !== '' && decentroClientSecret() !== '';
}

function isDecentroSandboxEnvironment(): bool
{
    return in_array(partnerActiveEnvironment('decentro', 'sandbox'), ['sandbox', 'test', 'uat'], true);
}

function decentroBaseUrl(): string
{
    $custom = trim(getPartnerSetting('decentro', 'decentro_base_url', ''));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return isDecentroSandboxEnvironment()
        ? 'https://in.staging.decentro.tech'
        : 'https://api.decentro.tech';
}

function decentroConsumerUrn(): string
{
    return getPartnerSetting('decentro', 'decentro_consumer_urn', '');
}

function decentroModuleSecret(): string
{
    return getPartnerSetting('decentro', 'decentro_module_secret', '');
}

function decentroProviderSecret(): string
{
    return getPartnerSetting('decentro', 'decentro_provider_secret', '');
}

function cashfreePayoutClientId(): string
{
    return getPartnerSetting('cashfree', 'cashfree_payout_client_id', '');
}

function cashfreePayoutClientSecret(): string
{
    return getPartnerSetting('cashfree', 'cashfree_payout_client_secret', '');
}

function cashfreePayoutBaseUrl(): string
{
    $custom = trim(getPartnerSetting('cashfree', 'cashfree_payout_base_url', ''));
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return in_array(partnerActiveEnvironment('cashfree', 'production'), ['sandbox', 'test'], true)
        ? 'https://payout-gamma.cashfree.com'
        : 'https://payout-api.cashfree.com';
}

function cashfreeActiveCredentialMode(): string
{
    return in_array(partnerActiveEnvironment('cashfree', 'production'), ['sandbox', 'test'], true) ? 'test' : 'live';
}

function axisPartnerSetting(string $keyName, string $default = ''): string
{
    return getPartnerSetting('axis', $keyName, $default);
}

function razorpayxAccountNumber(): string
{
    $acct = trim(getPartnerSetting('razorpayx', 'razorpayx_account_number', ''));
    if ($acct !== '') {
        return $acct;
    }
    return trim(getPartnerSetting('razorpay', 'razorpayx_account_number', ''));
}

function razorpayxKeyId(): string
{
    $key = trim(getPartnerSetting('razorpayx', 'razorpayx_key_id', ''));
    if ($key !== '') {
        return $key;
    }
    return trim(getPartnerSetting('razorpay', 'razorpay_key_id', ''));
}

/**
 * Bridge: read a single partner key from encrypted partner_credentials only (P1-01).
 * Does not read plaintext gateway_settings. Migrate-then-wipe runs first via ensurePartnerControlTables().
 */
function getPartnerSetting(string $partnerKey, string $keyName, string $default = ''): string
{
    static $cache = [];
    $ck = $partnerKey . ':' . $keyName;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $env = partnerCredentialEnvBucket($partnerKey);

    $creds = getPartnerCredentials($partnerKey, $env);
    if (empty($creds) && $env === 'live') {
        $creds = getPartnerCredentials($partnerKey, 'production');
    }
    if (empty($creds)) {
        $creds = getPartnerCredentials($partnerKey, 'test');
    }

    if (!empty($creds[$keyName])) {
        $cache[$ck] = (string)$creds[$keyName];
        return $cache[$ck];
    }

    $resolved = resolvePartnerCredentialValue($creds, $partnerKey, $keyName);
    if ($resolved !== '') {
        $cache[$ck] = $resolved;
        return $cache[$ck];
    }

    $cache[$ck] = $default;
    return $default;
}

function cashfreeAppId(): string
{
    return getPartnerSetting('cashfree', 'cashfree_app_id', '');
}

function cashfreeSecretKey(): string
{
    return getPartnerSetting('cashfree', 'cashfree_secret_key', '');
}

/**
 * Copy leftover plaintext PG keys from gateway_settings into encrypted partner_credentials,
 * then blank those gateway_settings rows (P1-01 — single keys plane).
 * Called once from ensurePartnerControlTables().
 */
function migrateGatewaySettingsToPartnerCredentials(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!function_exists('getSetting') || !function_exists('sensitiveEncrypt')) {
        return;
    }

    $partners = uniwebPartnerCredentialSettingMap();
    $db = getDB();
    $wiped = false;

    foreach ($partners as $partnerKey => $keys) {
        try {
            $envHint = function_exists('getSetting')
                ? strtolower(trim((string)getSetting($partnerKey . '_environment', '')))
                : '';
            $targetEnv = in_array($envHint, ['live', 'production'], true) ? 'live' : 'test';
            $firstId = trim((string)getSetting($keys[0], ''));
            if ($partnerKey === 'razorpay' && str_starts_with($firstId, 'rzp_live_')) {
                $targetEnv = 'live';
            }

            $existing = getPartnerCredentials($partnerKey, $targetEnv);
            unset($existing['_last4']);
            $payload = is_array($existing) ? $existing : [];
            $last4 = '';
            foreach ($keys as $k) {
                $val = trim((string)getSetting($k, ''));
                if ($val === '') {
                    continue;
                }
                if (empty($payload[$k])) {
                    $payload[$k] = $val;
                }
                if ($last4 === '' && (str_contains($k, 'secret') || str_contains($k, 'salt') || str_contains($k, 'pass'))) {
                    $last4 = substr((string)$payload[$k], -4);
                }
            }
            if ($partnerKey === 'axis') {
                if (empty($payload['axis_client_id']) && !empty($payload['axis_api_key'])) {
                    $payload['axis_client_id'] = (string)$payload['axis_api_key'];
                }
                if (empty($payload['axis_client_secret']) && !empty($payload['axis_api_secret'])) {
                    $payload['axis_client_secret'] = (string)$payload['axis_api_secret'];
                }
            }
            if ($partnerKey === 'decentro') {
                if (empty($payload['decentro_client_id'])) {
                    $legacy = trim((string)getSetting('decentro_api_key', ''));
                    if ($legacy !== '') {
                        $payload['decentro_client_id'] = $legacy;
                    }
                }
                if (empty($payload['decentro_client_secret'])) {
                    $legacy = trim((string)getSetting('decentro_api_secret', ''));
                    if ($legacy !== '') {
                        $payload['decentro_client_secret'] = $legacy;
                    }
                }
            }
            if ($partnerKey === 'pinelabs') {
                if (empty($payload['pinelabs_access_code'])) {
                    $legacy = trim((string)getSetting('pinelabs_api_key', ''));
                    if ($legacy !== '') {
                        $payload['pinelabs_access_code'] = $legacy;
                    }
                }
                if (empty($payload['pinelabs_secure_key'])) {
                    $legacy = trim((string)getSetting('pinelabs_api_secret', ''));
                    if ($legacy !== '') {
                        $payload['pinelabs_secure_key'] = $legacy;
                    }
                }
            }

            if ($payload !== []) {
                if ($last4 === '') {
                    foreach ($payload as $pv) {
                        if (!is_string($pv) || $pv === '') {
                            continue;
                        }
                        $last4 = substr($pv, -4);
                        break;
                    }
                }
                $encrypted = sensitiveEncrypt(json_encode($payload));
                $db->prepare(
                    "INSERT INTO partner_credentials (partner_key, env, encrypted_payload, last4) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), last4=VALUES(last4)"
                )->execute([$partnerKey, $targetEnv, $encrypted, $last4]);
            }

            $credsNow = getPartnerCredentials($partnerKey, $targetEnv);
            if ($credsNow === []) {
                $credsNow = getPartnerCredentials($partnerKey, $targetEnv === 'live' ? 'test' : 'live');
            }
            foreach ($keys as $k) {
                $plain = trim((string)getSetting($k, ''));
                if ($plain === '') {
                    continue;
                }
                $inCreds = !empty($credsNow[$k])
                    || ($k === 'axis_api_key' && !empty($credsNow['axis_client_id']))
                    || ($k === 'axis_api_secret' && !empty($credsNow['axis_client_secret']));
                if (!$inCreds) {
                    continue;
                }
                if (function_exists('saveSetting')) {
                    saveSetting($k, '');
                } else {
                    $db->prepare('UPDATE gateway_settings SET setting_value=? WHERE setting_key=?')->execute(['', $k]);
                }
                $wiped = true;
            }
        } catch (Throwable $e) {
            error_log('UniWeb: key migration failed for ' . $partnerKey . ': ' . $e->getMessage());
        }
    }

    if ($wiped && function_exists('clearSettingCache')) {
        clearSettingCache();
    }

    if (!function_exists('wipeLegacyPartnerPlaintextFromGatewaySettings')) {
        require_once __DIR__ . '/partner_keys_workflow.php';
    }
    if (function_exists('wipeLegacyPartnerPlaintextFromGatewaySettings')) {
        wipeLegacyPartnerPlaintextFromGatewaySettings();
    }
}

/**
 * Get credential status (last4 + has_keys) for a partner.
 */
function getPartnerCredentialStatus(string $partnerKey): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT env, last4 FROM partner_credentials WHERE partner_key=?");
        $st->execute([$partnerKey]);
        $rows = $st->fetchAll();
        $result = ['test' => false, 'live' => false, 'test_last4' => '', 'live_last4' => ''];
        foreach ($rows as $r) {
            $envName = (string)$r['env'];
            if ($envName === 'production') {
                $envName = 'live';
            }
            if ($envName !== 'test' && $envName !== 'live') {
                continue;
            }
            $result[$envName] = true;
            $result[$envName . '_last4'] = $r['last4'];
        }
        return $result;
    } catch (Throwable $e) {
        return ['test' => false, 'live' => false, 'test_last4' => '', 'live_last4' => ''];
    }
}

function partnerLiveEnvironmentValue(string $partnerKey): string
{
    return $partnerKey === 'cashfree' ? 'production' : 'live';
}

function partnerTestEnvironmentValue(string $partnerKey): string
{
    return $partnerKey === 'cashfree' ? 'sandbox' : 'test';
}

/**
 * Copy Test-slot credentials into Live slot (owner pasted live keys on the Test tab).
 */
function copyPartnerCredentialsToLive(string $partnerKey): string
{
    ensurePartnerControlTables();
    $creds = getPartnerCredentials($partnerKey, 'test');
    unset($creds['_last4']);
    if ($creds === []) {
        return 'no_keys';
    }
    foreach ($creds as $k => $v) {
        if (is_string($k) && str_ends_with($k, '_environment')) {
            $creds[$k] = str_contains($k, 'cashfree') ? 'production' : 'live';
        }
    }
    $last4 = '';
    foreach ($creds as $key => $val) {
        if (!is_string($val) || $val === '') {
            continue;
        }
        $k = (string)$key;
        if (str_contains($k, 'secret') || str_contains($k, 'salt') || str_contains($k, 'pass')) {
            $last4 = substr($val, -4);
            break;
        }
    }
    if ($last4 === '') {
        foreach ($creds as $val) {
            if (is_string($val) && $val !== '') {
                $last4 = substr($val, -4);
                break;
            }
        }
    }
    $encrypted = function_exists('sensitiveEncrypt') ? sensitiveEncrypt(json_encode($creds)) : base64_encode(json_encode($creds));
    getDB()->prepare(
        "INSERT INTO partner_credentials (partner_key, env, encrypted_payload, last4) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), last4=VALUES(last4)"
    )->execute([$partnerKey, 'live', $encrypted, $last4]);
    if (function_exists('saveSetting')) {
        $envKey = $partnerKey . '_environment';
        saveSetting($envKey, partnerLiveEnvironmentValue($partnerKey));
    }
    return $last4 !== '' ? $last4 : 'saved';
}

/**
 * Get all partner_methods for a partner.
 */
function getPartnerMethods(string $partnerKey): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT * FROM partner_methods WHERE partner_key=? ORDER BY priority ASC, method ASC");
        $st->execute([$partnerKey]);
        $rows = $st->fetchAll();
        if (function_exists('sortPaymentMethodsUpiFirst')) {
            return sortPaymentMethodsUpiFirst($rows, 'method');
        }
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Toggle a method on/off for a partner.
 */
function togglePartnerMethod(string $partnerKey, string $method, bool $enabled, int $priority = 50, float $minAmt = 0, float $maxAmt = 0): bool
{
    ensurePartnerControlTables();
    try {
        getDB()->prepare(
            "INSERT INTO partner_methods (partner_key, method, is_enabled, priority, min_amt, max_amt)
             VALUES (?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled), priority=VALUES(priority), min_amt=VALUES(min_amt), max_amt=VALUES(max_amt)"
        )->execute([$partnerKey, $method, $enabled ? 1 : 0, $priority, $minAmt, $maxAmt]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get enabled methods for a partner (only is_enabled=1).
 */
function getEnabledPartnerMethods(string $partnerKey): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT * FROM partner_methods WHERE partner_key=? AND is_enabled=1 ORDER BY priority ASC");
        $st->execute([$partnerKey]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Check if a specific method is enabled for a partner.
 */
function isPartnerMethodEnabled(string $partnerKey, string $method): bool
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT is_enabled FROM partner_methods WHERE partner_key=? AND method=?");
        $st->execute([$partnerKey, $method]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get all partners with enabled methods for a given method key.
 * Returns array of partner_keys that have this method enabled AND gateway active.
 */
function getPartnersForMethod(string $method): array
{
    ensurePartnerControlTables();
    if (!function_exists('ensurePaymentMethodsTable')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    try {
        $st = getDB()->prepare(
            "SELECT pm.partner_key FROM partner_methods pm
             INNER JOIN gateway_registry gr ON gr.gateway_key = pm.partner_key
             WHERE pm.method = ? AND pm.is_enabled = 1 AND gr.is_active = 1
             ORDER BY pm.priority ASC, gr.sort_order ASC"
        );
        $st->execute([$method]);
        return array_column($st->fetchAll(), 'partner_key');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get all enabled methods across all active partners.
 * Returns assoc: method => [partner_keys...]
 */
function getAllEnabledMethods(): array
{
    ensurePartnerControlTables();
    if (!function_exists('ensurePaymentMethodsTable')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    try {
        $retiredSql = '';
        try {
            if (function_exists('partnerRegistryHasRetiredColumn') && partnerRegistryHasRetiredColumn()) {
                $retiredSql = ' AND gr.retired_at IS NULL';
            }
        } catch (Throwable $e) { /* ok */ }
        $st = getDB()->query(
            "SELECT pm.method, pm.partner_key, pm.priority, pm.min_amt, pm.max_amt
             FROM partner_methods pm
             INNER JOIN gateway_registry gr ON gr.gateway_key = pm.partner_key
             WHERE pm.is_enabled = 1 AND gr.is_active = 1{$retiredSql}
             ORDER BY pm.method, pm.priority ASC, gr.sort_order ASC"
        );
        $rows = $st->fetchAll();
        $result = [];
        foreach ($rows as $r) {
            $result[$r['method']][] = $r;
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get merchant-partner links.
 */
function getMerchantPartnerLinks(int $merchantId): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT * FROM partner_merchant_links WHERE merchant_id=? ORDER BY created_at DESC");
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Create or update a merchant-partner link.
 */
function upsertMerchantPartnerLink(int $merchantId, string $partnerKey, ?string $externalId = null, string $kycStatus = 'pending'): bool
{
    ensurePartnerControlTables();
    try {
        getDB()->prepare(
            "INSERT INTO partner_merchant_links (merchant_id, partner_key, external_id, kyc_status)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE external_id=VALUES(external_id), kyc_status=VALUES(kyc_status),
             live_at=IF(VALUES(kyc_status)='live', NOW(), live_at)"
        )->execute([$merchantId, $partnerKey, $externalId, $kycStatus]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getMerchantPartnerLinkRow(int $merchantId, string $partnerKey): ?array
{
    ensurePartnerControlTables();
    $partnerKey = strtolower(trim($partnerKey));
    if ($merchantId < 1 || $partnerKey === '') {
        return null;
    }
    try {
        $st = getDB()->prepare('SELECT * FROM partner_merchant_links WHERE merchant_id=? AND partner_key=? LIMIT 1');
        $st->execute([$merchantId, $partnerKey]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Partners the merchant may already-live link (registry flag / commercial mode). Retired excluded.
 *
 * @return list<array<string,mixed>>
 */
function listAlreadyLiveLinkablePartners(): array
{
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $out = [];
    foreach (getRegisteredGateways(false) as $g) {
        if (function_exists('partnerAllowsAlreadyLiveLink') && partnerAllowsAlreadyLiveLink($g)) {
            $out[] = $g;
        }
    }
    return $out;
}

function merchantAlreadyLivePublicState(?array $link): string
{
    if (!$link) {
        return 'not_linked';
    }
    $status = strtolower(trim((string)($link['credential_status'] ?? 'missing')));
    if ($status === 'invalid') {
        return 'keys_invalid';
    }
    if ((int)($link['checkout_enabled'] ?? 0) === 1) {
        return 'enabled_checkout';
    }
    if ($status === 'valid' || (int)($link['owner_override'] ?? 0) === 1) {
        return 'linked';
    }
    if ($status === 'missing' && trim((string)($link['encrypted_payload'] ?? '')) === '') {
        return 'not_linked';
    }
    return 'keys_invalid';
}

function merchantAlreadyLiveStateLabel(string $state): string
{
    return match ($state) {
        'keys_invalid' => 'Keys invalid',
        'linked' => 'Linked',
        'enabled_checkout' => 'Enabled for checkout',
        default => 'Not linked',
    };
}

/**
 * Probe merchant-owned keys in memory. Never logs secrets. Honest VALID only on HTTP 2xx.
 *
 * @param array<string,string> $keys
 * @return array{status:string,message:string}
 */
function probeMerchantOwnedPartnerKeys(string $partnerKey, array $keys, string $env = 'test'): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $env = $env === 'live' ? 'live' : 'test';
    $keyId = '';
    $secret = '';
    foreach ($keys as $k => $v) {
        $lk = strtolower((string)$k);
        $val = trim((string)$v);
        if ($val === '') {
            continue;
        }
        if ($keyId === '' && (str_contains($lk, 'key_id') || str_contains($lk, 'app_id') || str_contains($lk, 'merchant_key') || str_contains($lk, 'api_key') || str_contains($lk, 'client_id') || $lk === 'key')) {
            $keyId = $val;
        }
        if ($secret === '' && (str_contains($lk, 'secret') || str_contains($lk, 'salt') || str_contains($lk, 'secure'))) {
            $secret = $val;
        }
    }
    if ($keyId === '' && $secret === '') {
        return ['status' => 'missing', 'message' => 'No keys submitted.'];
    }
    if ($keyId === '' || $secret === '') {
        return ['status' => 'invalid', 'message' => 'Both key and secret are required.'];
    }
    if (!function_exists('curl_init')) {
        return ['status' => 'invalid', 'message' => 'Server cannot run Test Connection (cURL missing).'];
    }

    if ($partnerKey === 'razorpay') {
        $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $keyId . ':' . $secret,
            CURLOPT_TIMEOUT => 12,
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['status' => 'invalid', 'message' => 'Partner did not accept the keys (connection failed).'];
        }
        if ($http >= 200 && $http < 300) {
            return ['status' => 'valid', 'message' => 'Keys accepted by Razorpay.'];
        }
        return ['status' => 'invalid', 'message' => 'Partner rejected the keys (HTTP ' . $http . ').'];
    }

    if ($partnerKey === 'cashfree') {
        $base = $env === 'live' ? 'https://api.cashfree.com' : 'https://sandbox.cashfree.com';
        $ch = curl_init($base . '/pg/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'x-client-id: ' . $keyId,
                'x-client-secret: ' . $secret,
                'x-api-version: 2023-08-01',
            ],
            CURLOPT_TIMEOUT => 12,
        ]);
        curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string)curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return ['status' => 'invalid', 'message' => 'Partner did not accept the keys (connection failed).'];
        }
        if (in_array($http, [200, 400, 404, 422], true)) {
            return ['status' => 'valid', 'message' => 'Cashfree accepted the client credentials.'];
        }
        if ($http === 401 || $http === 403) {
            return ['status' => 'invalid', 'message' => 'Partner rejected the keys.'];
        }
        return ['status' => 'invalid', 'message' => 'Could not verify Cashfree keys (HTTP ' . $http . ').'];
    }

    return ['status' => 'invalid', 'message' => 'No live Test Connection for this partner. Keys are stored encrypted; status stays Invalid until a probe exists or Admin uses Owner override.'];
}

/**
 * Map generic already-live form fields onto partner-specific vault keys.
 *
 * @return array<string,string>
 */
function merchantAlreadyLivePostedKeys(string $partnerKey, array $post): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $id = trim((string)($post['already_live_key'] ?? ''));
    $sec = trim((string)($post['already_live_secret'] ?? ''));
    $out = [];
    if ($partnerKey === 'razorpay') {
        if ($id !== '') {
            $out['razorpay_key_id'] = $id;
        }
        if ($sec !== '') {
            $out['razorpay_key_secret'] = $sec;
        }
        return $out;
    }
    if ($partnerKey === 'cashfree') {
        if ($id !== '') {
            $out['cashfree_app_id'] = $id;
        }
        if ($sec !== '') {
            $out['cashfree_secret_key'] = $sec;
        }
        return $out;
    }
    if ($partnerKey === 'payu') {
        if ($id !== '') {
            $out['payu_merchant_key'] = $id;
        }
        if ($sec !== '') {
            $out['payu_merchant_salt'] = $sec;
        }
        return $out;
    }
    $prefix = $partnerKey !== '' ? $partnerKey : 'partner';
    if ($id !== '') {
        $out[$prefix . '_api_key'] = $id;
    }
    if ($sec !== '') {
        $out[$prefix . '_api_secret'] = $sec;
    }
    return $out;
}

/**
 * Already-live LINK: store merchant-owned keys in partner_merchant_links. Does not create a sub-merchant.
 *
 * @param array{partner_mid?:string,env?:string,keys?:array,owner_override?:bool,actor_role?:string,actor_id?:int,actor_email?:string} $input
 * @return array{ok:bool,error?:string,credential_status?:string,last4?:string,message?:string}
 */
function saveMerchantAlreadyLiveLink(int $merchantId, string $partnerKey, array $input): array
{
    ensurePartnerControlTables();
    $partnerKey = strtolower(trim($partnerKey));
    if ($merchantId < 1 || $partnerKey === '') {
        return ['ok' => false, 'error' => 'Invalid merchant or partner.'];
    }
    if (!function_exists('checkRateLimit') && is_file(__DIR__ . '/rate_limiter.php')) {
        require_once __DIR__ . '/rate_limiter.php';
    }
    if (function_exists('checkRateLimit') && !checkRateLimit('m' . $merchantId, 'already_live_link', 8)) {
        return ['ok' => false, 'error' => 'Too many link attempts. Wait a minute and try again.'];
    }

    $gw = null;
    if (function_exists('getRegisteredGateways')) {
        foreach (getRegisteredGateways(false) as $row) {
            if (strtolower((string)$row['gateway_key']) === $partnerKey) {
                $gw = $row;
                break;
            }
        }
    }
    if (!$gw || (function_exists('partnerAllowsAlreadyLiveLink') && !partnerAllowsAlreadyLiveLink($gw))) {
        return ['ok' => false, 'error' => 'Already-live link is not available for this partner.'];
    }

    $env = ((string)($input['env'] ?? 'test')) === 'live' ? 'live' : 'test';
    $submitted = [];
    foreach ((array)($input['keys'] ?? []) as $k => $v) {
        $val = trim((string)$v);
        if ($val === '') {
            continue;
        }
        $submitted[(string)$k] = $val;
    }
    $existing = getMerchantPartnerLinkRow($merchantId, $partnerKey);
    $payload = [];
    if ($existing && !empty($existing['encrypted_payload'])) {
        $raw = function_exists('sensitiveDecrypt')
            ? (string)sensitiveDecrypt((string)$existing['encrypted_payload'])
            : (string)base64_decode((string)$existing['encrypted_payload']);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }
    foreach ($submitted as $k => $v) {
        $payload[$k] = $v;
    }

    $probeKeys = $payload;
    foreach ($submitted as $k => $v) {
        $probeKeys[$k] = $v;
    }
    $probe = probeMerchantOwnedPartnerKeys($partnerKey, $probeKeys, $env);
    $status = $probe['status'];
    $actorRole = strtolower(trim((string)($input['actor_role'] ?? 'merchant')));
    if ($actorRole !== 'admin') {
        $actorRole = 'merchant';
    }
    $ownerOverride = $actorRole === 'admin' && !empty($input['owner_override']);
    if ($ownerOverride && $status !== 'valid') {
        $status = 'valid';
        $probe['message'] = 'Owner override: treated as Linked without a passing Test Connection.';
    }

    $last4 = '';
    foreach ($payload as $k => $val) {
        if (!is_string($val) || $val === '') {
            continue;
        }
        $lk = strtolower((string)$k);
        if (str_contains($lk, 'secret') || str_contains($lk, 'salt') || str_contains($lk, 'pass')) {
            $last4 = substr($val, -4);
            break;
        }
    }
    if ($last4 === '' && $payload !== []) {
        $first = (string)reset($payload);
        $last4 = substr($first, -4);
    }

    $mid = mb_substr(trim((string)($input['partner_mid'] ?? '')), 0, 120);
    $encrypted = function_exists('sensitiveEncrypt')
        ? sensitiveEncrypt(json_encode($payload, JSON_UNESCAPED_UNICODE))
        : base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $actorId = (int)($input['actor_id'] ?? 0);
    $checkout = 0;
    if ($existing && (int)($existing['checkout_enabled'] ?? 0) === 1 && ($status === 'valid' || $ownerOverride)) {
        $checkout = 1;
    }

    try {
        getDB()->prepare(
            "INSERT INTO partner_merchant_links
                (merchant_id, partner_key, external_id, kyc_status, account_source, partner_mid, credential_status, env, encrypted_payload, last4, checkout_enabled, linked_by, linked_by_id, owner_override)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                external_id=VALUES(external_id),
                kyc_status='linked',
                account_source=VALUES(account_source),
                partner_mid=VALUES(partner_mid),
                credential_status=VALUES(credential_status),
                env=VALUES(env),
                encrypted_payload=VALUES(encrypted_payload),
                last4=VALUES(last4),
                checkout_enabled=VALUES(checkout_enabled),
                linked_by=VALUES(linked_by),
                linked_by_id=VALUES(linked_by_id),
                owner_override=VALUES(owner_override)"
        )->execute([
            $merchantId,
            $partnerKey,
            $mid !== '' ? $mid : null,
            'linked',
            'linked',
            $mid !== '' ? $mid : null,
            $status,
            $env,
            $encrypted,
            $last4,
            $checkout,
            $actorRole,
            $actorId > 0 ? $actorId : null,
            $ownerOverride ? 1 : 0,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save link.'];
    }

    $who = mb_substr(trim((string)($input['actor_email'] ?? $actorRole)), 0, 120);
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'merchant_already_live_linked',
            $merchantId,
            'partner',
            $partnerKey,
            'account_source=linked status=' . $status . ' by ' . ($who !== '' ? $who : $actorRole) . ($ownerOverride ? ' override=1' : '')
        );
    }
    if (function_exists('logStaffActivity') && $actorRole === 'admin') {
        logStaffActivity('merchant_already_live_linked', 'Linked existing ' . $partnerKey . ' for merchant #' . $merchantId . ' status=' . $status, $merchantId, 'partner', $partnerKey);
    }

    return [
        'ok' => true,
        'credential_status' => $status,
        'last4' => $last4,
        'message' => (string)($probe['message'] ?? ''),
    ];
}

/**
 * Enable already-live partner for this merchant's checkout flag. Requires VALID or admin override.
 *
 * @param array{actor_role?:string,actor_id?:int,actor_email?:string} $actor
 */
function setMerchantAlreadyLiveCheckoutEnabled(int $merchantId, string $partnerKey, bool $enabled, array $actor = []): array
{
    ensurePartnerControlTables();
    $partnerKey = strtolower(trim($partnerKey));
    $row = getMerchantPartnerLinkRow($merchantId, $partnerKey);
    if (!$row) {
        return ['ok' => false, 'error' => 'Not linked.'];
    }
    $status = strtolower((string)($row['credential_status'] ?? ''));
    $override = (int)($row['owner_override'] ?? 0) === 1;
    if ($enabled && $status !== 'valid' && !$override) {
        return ['ok' => false, 'error' => 'Enable is not available until keys are Valid (or Admin override).'];
    }
    try {
        getDB()->prepare('UPDATE partner_merchant_links SET checkout_enabled=? WHERE merchant_id=? AND partner_key=?')
            ->execute([$enabled ? 1 : 0, $merchantId, $partnerKey]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update checkout flag.'];
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            $enabled ? 'merchant_already_live_checkout_on' : 'merchant_already_live_checkout_off',
            $merchantId,
            'partner',
            $partnerKey,
            'checkout_enabled=' . ($enabled ? '1' : '0')
        );
    }
    return ['ok' => true];
}

/**
 * Get reason map for a partner + error code.
 */
function getReasonMap(string $partnerKey, string $rawCode): ?array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT * FROM gateway_reason_maps WHERE partner_key=? AND raw_code=? AND is_active=1");
        $st->execute([$partnerKey, $rawCode]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get all reason maps for a partner.
 */
function getPartnerReasonMaps(string $partnerKey): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare("SELECT * FROM gateway_reason_maps WHERE partner_key=? ORDER BY raw_code ASC");
        $st->execute([$partnerKey]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Save a reason map entry.
 */
function saveReasonMap(string $partnerKey, string $rawCode, string $msgEn, string $msgHi): bool
{
    ensurePartnerControlTables();
    try {
        getDB()->prepare(
            "INSERT INTO gateway_reason_maps (partner_key, raw_code, msg_en, msg_hi) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE msg_en=VALUES(msg_en), msg_hi=VALUES(msg_hi)"
        )->execute([$partnerKey, $rawCode, $msgEn, $msgHi]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Map a gateway error code to a human message using gateway_reason_maps.
 */
function mapPartnerError(string $partnerKey, string $rawCode, string $lang = 'en'): string
{
    $map = getReasonMap($partnerKey, $rawCode);
    if ($map) {
        return $lang === 'hi' ? ($map['msg_hi'] ?: $map['msg_en']) : $map['msg_en'];
    }
    return '';
}

/**
 * Check if a partner is fully chargeable: active in registry + has credentials + has enabled methods.
 */
function isPartnerChargeable(string $partnerKey): bool
{
    if (!function_exists('isGatewayActive')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (!isGatewayActive($partnerKey)) return false;
    if (!function_exists('partnerIsConfigured')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (!partnerIsConfigured($partnerKey)) return false;
    $methods = getEnabledPartnerMethods($partnerKey);
    return !empty($methods);
}

/**
 * Get partners that are explicitly Go Live for public website display.
 * Conditions: is_active=1, public_go_live=1, live credentials present, >=1 enabled method.
 * Returns array of ['key','name','icon'] for public HTML.
 */
function getPublicLivePartners(): array
{
    if (!function_exists('ensurePaymentMethodsTable')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    ensurePaymentMethodsTable();
    ensurePartnerControlTables();
    $result = [];
    try {
        $db = getDB();
        $rows = $db->query("SELECT gateway_key, gateway_name FROM gateway_registry WHERE is_active=1 AND public_go_live=1 ORDER BY sort_order ASC, gateway_name ASC")->fetchAll();
        $registry = getPartnerRegistry();
        foreach ($rows as $row) {
            $pk = $row['gateway_key'];
            $cred = getPartnerCredentialStatus($pk);
            if (!$cred['live']) continue;
            $methods = getEnabledPartnerMethods($pk);
            if (empty($methods)) continue;
            $result[] = [
                'key' => $pk,
                'name' => $row['gateway_name'],
                'icon' => $registry[$pk]['icon'] ?? '',
            ];
        }
    } catch (Throwable $e) { /* ok */ }
    return $result;
}

/**
 * Set partner Go Live flag (public website visibility).
 * Validates: active + live credentials + >=1 enabled method before allowing ON.
 */
function setPartnerGoLive(int $gatewayId, bool $goLive, string $adminEmail): array
{
    if (!function_exists('ensurePaymentMethodsTable')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    ensurePaymentMethodsTable();
    ensurePartnerControlTables();
    try {
        $gw = getGatewayById($gatewayId);
        if (!$gw) return ['ok' => false, 'error' => 'Gateway not found.'];
        $pk = $gw['gateway_key'];
        if ($goLive) {
            if ((int)$gw['is_active'] !== 1) return ['ok' => false, 'error' => 'Partner must be Active first.'];
            $cred = getPartnerCredentialStatus($pk);
            if (!$cred['live']) return ['ok' => false, 'error' => 'Live credentials required. Save live keys first.'];
            $methods = getEnabledPartnerMethods($pk);
            if (empty($methods)) return ['ok' => false, 'error' => 'At least one payment method must be enabled.'];
            try {
                $mdr = getDB()->prepare('SELECT id FROM partner_commercial WHERE partner_key=? LIMIT 1');
                $mdr->execute([$pk]);
                if (!$mdr->fetchColumn()) {
                    return ['ok' => false, 'error' => 'Save commercial MDR first (Commercial tab).'];
                }
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'Save commercial MDR first (Commercial tab).'];
            }
        }
        $db = getDB();
        $db->prepare("UPDATE gateway_registry SET public_go_live=?, public_go_live_at=?, public_go_live_by=? WHERE id=?")
            ->execute([$goLive ? 1 : 0, $goLive ? date('Y-m-d H:i:s') : null, $goLive ? $adminEmail : null, $gatewayId]);
        if (function_exists('logAudit')) {
            logAudit('partner_go_live', $adminEmail, "partner={$pk} go_live=" . ($goLive ? 'ON' : 'OFF'));
        }
        if (function_exists('logStaffActivity')) {
            logStaffActivity('partner_go_live', $pk . ' ' . ($goLive ? 'ON' : 'OFF'), null, 'partner', $pk);
        }
        return ['ok' => true, 'go_live' => $goLive];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * UI checklist for Partner Registry Go-live (keys, methods, MDR, webhook).
 * @return array{items:array,ready:bool}
 */
function partnerGoLiveChecklist(string $partnerKey, array $gateway = [], string $webhookUrl = ''): array
{
    $active = (int)($gateway['is_active'] ?? 0) === 1;
    $cred = function_exists('getPartnerCredentialStatus') ? getPartnerCredentialStatus($partnerKey) : ['live' => false];
    $liveKeys = !empty($cred['live']);
    $methodsOn = false;
    try {
        $enabled = function_exists('getEnabledPartnerMethods') ? getEnabledPartnerMethods($partnerKey) : [];
        $methodsOn = !empty($enabled);
    } catch (Throwable $e) {
        $methodsOn = false;
    }
    $mdr = false;
    try {
        $st = getDB()->prepare('SELECT id FROM partner_commercial WHERE partner_key=? LIMIT 1');
        $st->execute([$partnerKey]);
        $mdr = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        $mdr = false;
    }
    $webhook = trim($webhookUrl) !== '';
    $items = [
        ['key' => 'active', 'label' => 'Partner is Active', 'ok' => $active, 'tab' => 'keys', 'required' => true],
        ['key' => 'live_keys', 'label' => 'Live API keys saved', 'ok' => $liveKeys, 'tab' => 'keys', 'required' => true],
        ['key' => 'methods', 'label' => 'At least one payment method ON', 'ok' => $methodsOn, 'tab' => 'methods', 'required' => true],
        ['key' => 'mdr', 'label' => 'Commercial MDR saved', 'ok' => $mdr, 'tab' => 'commercial', 'required' => true],
        ['key' => 'webhook', 'label' => 'Webhook URL ready to copy', 'ok' => $webhook, 'tab' => 'webhooks', 'required' => false],
    ];
    $ready = true;
    foreach ($items as $item) {
        if (!empty($item['required']) && empty($item['ok'])) {
            $ready = false;
            break;
        }
    }
    return ['items' => $items, 'ready' => $ready];
}

/**
 * Get per-method partner base MDR (P) from partner_methods table.
 * Returns 0 if not set.
 */
function getPartnerMethodMdr(string $partnerKey, string $method): float
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare('SELECT base_mdr_percent FROM partner_methods WHERE partner_key=? AND method=?');
        $st->execute([$partnerKey, $method]);
        $row = $st->fetch();
        return $row ? (float)$row['base_mdr_percent'] : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * Set per-method partner base MDR (P).
 * Creates method row if not exists. Audit logs old→new.
 */
function setPartnerMethodMdr(string $partnerKey, string $method, float $mdrPercent, string $updatedBy = 'admin'): array
{
    ensurePartnerControlTables();
    if ($mdrPercent < 0 || $mdrPercent > 100) {
        return ['ok' => false, 'error' => 'MDR must be between 0 and 100.'];
    }
    try {
        $old = getPartnerMethodMdr($partnerKey, $method);
        getDB()->prepare(
            'INSERT INTO partner_methods (partner_key, method, is_enabled, base_mdr_percent)
             VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE base_mdr_percent=VALUES(base_mdr_percent)'
        )->execute([$partnerKey, $method, $mdrPercent]);
        if (function_exists('logAudit')) {
            logAudit('partner_method_mdr', $updatedBy, "partner={$partnerKey} method={$method} old={$old} new={$mdrPercent}");
        }
        return ['ok' => true, 'old' => $old, 'new' => $mdrPercent];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get all partner method MDRs as associative array [method => mdr_percent].
 */
function getAllPartnerMethodMdrs(string $partnerKey): array
{
    ensurePartnerControlTables();
    try {
        $st = getDB()->prepare('SELECT method, base_mdr_percent FROM partner_methods WHERE partner_key=?');
        $st->execute([$partnerKey]);
        $result = [];
        foreach ($st->fetchAll() as $row) {
            $result[$row['method']] = (float)$row['base_mdr_percent'];
        }
        return $result;
    } catch (Throwable $e) {
        return [];
    }
}
