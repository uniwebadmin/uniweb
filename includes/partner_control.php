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
    $defaultMethods = ['upi', 'credit_card', 'debit_card', 'netbanking', 'emi', 'emandate_upi', 'emandate_card', 'emandate_nb'];

    foreach ($registry as $key => $p) {
        foreach ($defaultMethods as $method) {
            try {
                $db->prepare("INSERT IGNORE INTO partner_methods (partner_key, method, is_enabled, priority) VALUES (?,?,0,50)")
                    ->execute([$key, $method]);
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

    // Build payload from submitted keys
    $payload = [];
    $last4 = '';
    foreach ($configKeys as $key => $meta) {
        if (!isset($keys[$key])) continue;
        $val = trim((string)$keys[$key]);
        if ($val === '') continue;
        $payload[$key] = $val;
        // Track last4 of first secret-like field
        if ($last4 === '' && (str_contains($key, 'secret') || str_contains($key, 'salt') || str_contains($key, 'pass'))) {
            $last4 = substr($val, -4);
        }
    }

    if (empty($payload)) {
        return 'no_keys';
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
 * Bridge function: read a single partner key from partner_credentials (encrypted, source of truth).
 * Falls back to getSetting() (gateway_settings) only if no partner_credentials row exists yet.
 * This allows gradual migration without breaking live flows.
 */
function getPartnerSetting(string $partnerKey, string $keyName, string $default = ''): string
{
    static $cache = [];
    $ck = $partnerKey . ':' . $keyName;
    if (array_key_exists($ck, $cache)) {
        return $cache[$ck];
    }

    $env = 'test';
    if (function_exists('getSetting')) {
        $envVal = getSetting($partnerKey . '_environment', '');
        if ($envVal === 'live') {
            $env = 'live';
        }
    }

    $creds = getPartnerCredentials($partnerKey, $env);
    if (empty($creds)) {
        $creds = getPartnerCredentials($partnerKey, 'test');
    }

    if (!empty($creds[$keyName])) {
        $cache[$ck] = (string)$creds[$keyName];
        return $cache[$ck];
    }

    if (function_exists('getSetting')) {
        $cache[$ck] = getSetting($keyName, $default);
        return $cache[$ck];
    }

    $cache[$ck] = $default;
    return $default;
}

/**
 * One-time migration: copy plaintext PG keys from gateway_settings into encrypted partner_credentials.
 * Only migrates if partner_credentials has no row for that partner+env yet.
 * Called once at load time from ensurePartnerControlTables().
 */
function migrateGatewaySettingsToPartnerCredentials(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (!function_exists('getSetting') || !function_exists('sensitiveEncrypt')) {
        return;
    }

    $partners = [
        'razorpay' => ['test' => ['razorpay_key_id', 'razorpay_key_secret']],
        'razorpayx' => ['test' => ['razorpayx_key_id', 'razorpayx_key_secret', 'razorpayx_account_number']],
        'cashfree' => ['test' => ['cashfree_app_id', 'cashfree_secret_key']],
        'payu' => ['test' => ['payu_merchant_key', 'payu_merchant_salt']],
        'phonepe' => ['test' => ['phonepe_merchant_id', 'phonepe_salt_key']],
        'pinelabs' => ['test' => ['pinelabs_merchant_id', 'pinelabs_access_code', 'pinelabs_secure_key']],
        'worldline' => ['test' => ['worldline_merchant_id', 'worldline_access_key', 'worldline_secret_key']],
        'decentro' => ['test' => ['decentro_client_id', 'decentro_client_secret']],
        'axis' => ['test' => ['axis_client_id', 'axis_client_secret']],
    ];

    $db = getDB();
    foreach ($partners as $partnerKey => $envs) {
        foreach ($envs as $env => $keys) {
            try {
                $st = $db->prepare("SELECT id FROM partner_credentials WHERE partner_key=? AND env=?");
                $st->execute([$partnerKey, $env]);
                if ($st->fetch()) continue;

                $payload = [];
                $last4 = '';
                foreach ($keys as $k) {
                    $val = trim((string)getSetting($k, ''));
                    if ($val !== '') {
                        $payload[$k] = $val;
                        if ($last4 === '' && (str_contains($k, 'secret') || str_contains($k, 'salt') || str_contains($k, 'pass'))) {
                            $last4 = substr($val, -4);
                        }
                    }
                }
                if (empty($payload)) continue;

                $encrypted = sensitiveEncrypt(json_encode($payload));
                $db->prepare(
                    "INSERT INTO partner_credentials (partner_key, env, encrypted_payload, last4) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), last4=VALUES(last4)"
                )->execute([$partnerKey, $env, $encrypted, $last4]);
                error_log("UniWeb: migrated {$partnerKey} {$env} keys from gateway_settings to partner_credentials");
            } catch (Throwable $e) {
                error_log("UniWeb: key migration failed for {$partnerKey} {$env}: " . $e->getMessage());
            }
        }
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
            $result[$r['env']] = true;
            $result[$r['env'] . '_last4'] = $r['last4'];
        }
        return $result;
    } catch (Throwable $e) {
        return ['test' => false, 'live' => false, 'test_last4' => '', 'live_last4' => ''];
    }
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
        return $st->fetchAll();
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
        $st = getDB()->query(
            "SELECT pm.method, pm.partner_key, pm.priority, pm.min_amt, pm.max_amt
             FROM partner_methods pm
             INNER JOIN gateway_registry gr ON gr.gateway_key = pm.partner_key
             WHERE pm.is_enabled = 1 AND gr.is_active = 1
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
        }
        $db = getDB();
        $db->prepare("UPDATE gateway_registry SET public_go_live=?, public_go_live_at=?, public_go_live_by=? WHERE id=?")
            ->execute([$goLive ? 1 : 0, $goLive ? date('Y-m-d H:i:s') : null, $goLive ? $adminEmail : null, $gatewayId]);
        if (function_exists('logAudit')) {
            logAudit('partner_go_live', $adminEmail, "partner={$pk} go_live=" . ($goLive ? 'ON' : 'OFF'));
        }
        return ['ok' => true, 'go_live' => $goLive];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
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
