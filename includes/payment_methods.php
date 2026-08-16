<?php
declare(strict_types=1);

/**
 * Payment Methods ON/OFF system.
 *
 * Per-merchant and admin-controlled payment method toggles.
 * Uses merchant_payment_methods table for granular control.
 */

function ensurePaymentMethodsTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_payment_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            method_key VARCHAR(40) NOT NULL,
            method_label VARCHAR(60) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 0,
            updated_by VARCHAR(20) NOT NULL DEFAULT 'merchant',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_merchant_method (merchant_id, method_key),
            INDEX idx_merchant (merchant_id, is_enabled)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_registry (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway_key VARCHAR(40) NOT NULL UNIQUE,
            gateway_name VARCHAR(80) NOT NULL,
            adapter_class VARCHAR(120) DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            supports_collection TINYINT(1) NOT NULL DEFAULT 1,
            supports_payout TINYINT(1) NOT NULL DEFAULT 0,
            supports_refund TINYINT(1) NOT NULL DEFAULT 0,
            supports_recurring TINYINT(1) NOT NULL DEFAULT 0,
            webhook_url VARCHAR(255) DEFAULT NULL,
            config_json TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try { getDB()->exec("ALTER TABLE gateway_registry ADD COLUMN public_go_live TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already exists */ }
        try { getDB()->exec("ALTER TABLE gateway_registry ADD COLUMN public_go_live_at TIMESTAMP NULL DEFAULT NULL"); } catch (Throwable $e) { /* already exists */ }
        try { getDB()->exec("ALTER TABLE gateway_registry ADD COLUMN public_go_live_by VARCHAR(120) DEFAULT NULL"); } catch (Throwable $e) { /* already exists */ }

        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_method_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway_id INT NOT NULL,
            method_key VARCHAR(40) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uniq_gateway_method (gateway_id, method_key),
            INDEX idx_method (method_key, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        seedDefaultGateways();
    } catch (Throwable $e) { /* ok */ }
}

function seedDefaultGateways(): void
{
    $db = getDB();
    $defaults = [
        ['upi_p2m', 'UPI P2M', 1, 1, 0, 1, 1],
        ['qr_code', 'QR Code', 1, 1, 0, 0, 0],
        ['credit_card', 'Credit Card', 0, 1, 0, 1, 1],
        ['debit_card', 'Debit Card', 0, 1, 0, 1, 0],
        ['net_banking', 'Net Banking', 0, 1, 0, 1, 0],
        ['wallet', 'Wallet', 0, 1, 0, 1, 0],
        ['payout', 'Payout', 0, 0, 1, 0, 0],
        ['recurring', 'Recurring / AutoPay', 0, 1, 0, 0, 1],
    ];
    foreach ($defaults as $g) {
        try {
            $db->prepare("INSERT INTO gateway_registry (gateway_key, gateway_name, is_active, supports_collection, supports_payout, supports_refund, supports_recurring)
                VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE gateway_name=VALUES(gateway_name)")
                ->execute($g);
        } catch (Throwable $e) {}
    }
    try {
        $db->exec("INSERT INTO gateway_method_map (gateway_id, method_key, is_active)
            SELECT id, gateway_key, is_active FROM gateway_registry
            ON DUPLICATE KEY UPDATE is_active=VALUES(is_active)");
    } catch (Throwable $e) {}
}

/**
 * Get all available payment methods from gateway_registry.
 */
function getAllPaymentMethods(): array
{
    ensurePaymentMethodsTable();
    try {
        $st = getDB()->query("SELECT * FROM gateway_registry ORDER BY id ASC");
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Display order for collect methods: UPI first, then QR/VA, then cards, then netbanking, then rest.
 */
function paymentMethodDisplayPriority(string $methodKey): int
{
    $key = strtolower(trim($methodKey));
    return match ($key) {
        'upi_p2m', 'upi', 'payu_upi', 'razorpay_upi', 'cashfree_upi' => 10,
        'qr_code', 'axis_va' => 20,
        'debit_card', 'dc' => 30,
        'credit_card', 'cc' => 31,
        'net_banking', 'netbanking', 'nb' => 40,
        'emi' => 50,
        'wallet' => 60,
        'razorpay', 'cashfree' => 70,
        'payout', 'recurring' => 90,
        default => 80,
    };
}

/**
 * Stable sort: UPI → cards → netbanking → other (Block 5 live order).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function sortPaymentMethodsUpiFirst(array $rows, string $keyField = 'gateway_key'): array
{
    usort($rows, static function (array $a, array $b) use ($keyField): int {
        $ka = (string)($a[$keyField] ?? $a['key'] ?? '');
        $kb = (string)($b[$keyField] ?? $b['key'] ?? '');
        $pa = paymentMethodDisplayPriority($ka);
        $pb = paymentMethodDisplayPriority($kb);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcmp($ka, $kb);
    });
    return $rows;
}

/**
 * Get a merchant's payment method settings.
 * Returns only ACTIVE gateways with their ON/OFF status.
 * Inactive gateways are hidden from merchants.
 */
function getMerchantPaymentMethods(int $merchantId): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $st = $db->prepare(
            "SELECT g.gateway_key, g.gateway_name, g.is_active AS gateway_active,
                    g.supports_collection, g.supports_payout, g.supports_refund, g.supports_recurring,
                    COALESCE(m.is_enabled, 0) AS is_enabled, m.updated_by, m.updated_at
             FROM gateway_registry g
             LEFT JOIN merchant_payment_methods m ON m.method_key = g.gateway_key AND m.merchant_id = ?
             WHERE g.is_active = 1
             ORDER BY g.id ASC"
        );
        $st->execute([$merchantId]);
        return sortPaymentMethodsUpiFirst($st->fetchAll(), 'gateway_key');
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get only enabled method keys for a merchant.
 */
function getMerchantEnabledMethodKeys(int $merchantId): array
{
    $methods = getMerchantPaymentMethods($merchantId);
    $enabled = [];
    foreach ($methods as $m) {
        if ((int)$m['is_enabled'] === 1) {
            $enabled[] = $m['gateway_key'];
        }
    }
    if (empty($enabled)) {
        return ['upi_p2m'];
    }
    return $enabled;
}

/**
 * Toggle a payment method ON/OFF for a merchant.
 */
function toggleMerchantPaymentMethod(int $merchantId, string $methodKey, bool $enabled, string $updatedBy = 'merchant'): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM gateway_registry WHERE gateway_key=?");
        $st->execute([$methodKey]);
        $gateway = $st->fetch();
        if (!$gateway) {
            return ['ok' => false, 'error' => 'Unknown payment method.'];
        }

        $db->prepare(
            "INSERT INTO merchant_payment_methods (merchant_id, method_key, method_label, is_enabled, updated_by)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled), updated_by=VALUES(updated_by)"
        )->execute([
            $merchantId,
            $methodKey,
            $gateway['gateway_name'],
            $enabled ? 1 : 0,
            $updatedBy,
        ]);

        return ['ok' => true, 'enabled' => $enabled];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Bulk set payment methods for a merchant.
 */
function setMerchantPaymentMethods(int $merchantId, array $enabledKeys, string $updatedBy = 'merchant'): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $all = getAllPaymentMethods();
        foreach ($all as $g) {
            $isEnabled = in_array($g['gateway_key'], $enabledKeys, true);
            $db->prepare(
                "INSERT INTO merchant_payment_methods (merchant_id, method_key, method_label, is_enabled, updated_by)
                 VALUES (?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled), updated_by=VALUES(updated_by)"
            )->execute([
                $merchantId,
                $g['gateway_key'],
                $g['gateway_name'],
                $isEnabled ? 1 : 0,
                $updatedBy,
            ]);
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Register a new gateway/partner — creates as INACTIVE by default.
 * Seeds partner_methods rows (all disabled) for the new partner.
 * Does not auto-activate or auto-enable methods.
 */
function registerGateway(string $key, string $name, array $capabilities = []): array
{
    ensurePaymentMethodsTable();
    $db = getDB();

    // Validate key: safe slug charset only
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $key)) {
        return ['ok' => false, 'error' => 'Partner key must be 2–40 chars: lowercase letters, numbers, underscore only.'];
    }
    if (trim($name) === '') {
        return ['ok' => false, 'error' => 'Display name is required.'];
    }

    try {
        $collection = $capabilities['collection'] ?? 1;
        $payout = $capabilities['payout'] ?? 0;
        $refund = $capabilities['refund'] ?? 0;
        $recurring = $capabilities['recurring'] ?? 0;
        $adapter = $capabilities['adapter'] ?? null;
        $webhookUrl = $capabilities['webhook_url'] ?? null;
        $configJson = $capabilities['config_json'] ?? null;
        $sortOrder = $capabilities['sort_order'] ?? 99;

        // Check for duplicate
        $check = $db->prepare("SELECT id FROM gateway_registry WHERE gateway_key=?");
        $check->execute([$key]);
        if ($check->fetch()) {
            return ['ok' => false, 'error' => 'Partner key already exists. Use a different key or configure the existing one.'];
        }

        // Insert as INACTIVE (is_active=0)
        $db->prepare(
            "INSERT INTO gateway_registry (gateway_key, gateway_name, adapter_class, is_active, supports_collection, supports_payout, supports_refund, supports_recurring, webhook_url, config_json, sort_order)
             VALUES (?,?,?,?,0,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE gateway_key=gateway_key"
        )->execute([$key, $name, $adapter, $collection, $payout, $refund, $recurring, $webhookUrl, $configJson, $sortOrder]);

        $gatewayId = (int)$db->lastInsertId();
        if (!$gatewayId) {
            $st = $db->prepare("SELECT id FROM gateway_registry WHERE gateway_key=?");
            $st->execute([$key]);
            $gatewayId = (int)$st->fetchColumn();
        }

        // Seed gateway_method_map (inactive)
        $db->prepare("INSERT INTO gateway_method_map (gateway_id, method_key, is_active) VALUES (?,?,0) ON DUPLICATE KEY UPDATE is_active=0")
            ->execute([$gatewayId, $key]);

        // Seed partner_methods rows (all disabled) for standard methods
        if (function_exists('seedPartnerMethods')) {
            // seedPartnerMethods seeds for all registry partners — call it to cover the new one
            seedPartnerMethods();
        } elseif (class_exists('PDO') && $db) {
            $defaultMethods = ['upi', 'credit_card', 'debit_card', 'netbanking', 'emi', 'emandate_upi', 'emandate_card', 'emandate_nb'];
            foreach ($defaultMethods as $method) {
                try {
                    $db->prepare("INSERT IGNORE INTO partner_methods (partner_key, method, is_enabled, priority) VALUES (?,?,0,50)")
                        ->execute([$key, $method]);
                } catch (Throwable $e) { /* ok */ }
            }
        }

        return ['ok' => true, 'gateway_id' => $gatewayId, 'gateway_name' => $name];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get all registered gateways (Partner Registry admin view).
 */
function getRegisteredGateways(): array
{
    ensurePaymentMethodsTable();
    try {
        return getDB()->query("SELECT * FROM gateway_registry ORDER BY is_active DESC, id ASC")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Check if a gateway is active.
 */
if (!function_exists('isGatewayActive')) {
function isGatewayActive(string $key): bool
{
    ensurePaymentMethodsTable();
    try {
        $st = getDB()->prepare("SELECT is_active FROM gateway_registry WHERE gateway_key=?");
        $st->execute([$key]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;
    }
}
}

/**
 * Get methods supported by a specific gateway.
 */
function getGatewayMethods(int $gatewayId): array
{
    ensurePaymentMethodsTable();
    try {
        $st = getDB()->prepare("SELECT * FROM gateway_method_map WHERE gateway_id=? AND is_active=1");
        $st->execute([$gatewayId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sync all partners from getPartnerRegistry() into gateway_registry.
 * New partners appear as INACTIVE. Existing ones keep their status.
 */
function syncPartnerGateways(): void
{
    ensurePaymentMethodsTable();
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $registry = getPartnerRegistry();
    $db = getDB();
    foreach ($registry as $key => $p) {
        $supportsCollection = in_array('collection', $p['capabilities'] ?? ['collection'], true) || true;
        $supportsPayout = str_contains($p['use'] ?? '', 'Payout') || str_contains($p['use'] ?? '', 'payout');
        $supportsRefund = str_contains($p['type'] ?? '', 'gateway');
        $supportsRecurring = str_contains($p['use'] ?? '', 'Recurring') || str_contains($p['use'] ?? '', 'recurring');

        // Check if already exists
        $st = $db->prepare("SELECT id, is_active FROM gateway_registry WHERE gateway_key=?");
        $st->execute([$key]);
        $existing = $st->fetch();
        if ($existing) {
            // Update name/capabilities but keep is_active as-is
            $db->prepare("UPDATE gateway_registry SET gateway_name=?, supports_collection=?, supports_payout=?, supports_refund=?, supports_recurring=?, webhook_url=? WHERE id=?")
                ->execute([$p['name'], 1, $supportsPayout ? 1 : 0, $supportsRefund ? 1 : 0, $supportsRecurring ? 1 : 0, $p['webhook'] ?? null, $existing['id']]);
        } else {
            // Insert as INACTIVE
            $db->prepare("INSERT INTO gateway_registry (gateway_key, gateway_name, is_active, supports_collection, supports_payout, supports_refund, supports_recurring, webhook_url) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$key, $p['name'], 0, 1, $supportsPayout ? 1 : 0, $supportsRefund ? 1 : 0, $supportsRecurring ? 1 : 0, $p['webhook'] ?? null]);
            $gid = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO gateway_method_map (gateway_id, method_key, is_active) VALUES (?,?,0) ON DUPLICATE KEY UPDATE is_active=VALUES(is_active)")
                ->execute([$gid, $key]);
        }
    }
}

/**
 * Activate a gateway and auto-add its methods to all merchants (as OFF by default).
 */
function activateGatewayForAllMerchants(int $gatewayId): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT * FROM gateway_registry WHERE id=?");
        $st->execute([$gatewayId]);
        $gw = $st->fetch();
        if (!$gw) return ['ok' => false, 'error' => 'Gateway not found.'];

        // Activate gateway
        $db->prepare("UPDATE gateway_registry SET is_active=1 WHERE id=?")->execute([$gatewayId]);
        $db->prepare("UPDATE gateway_method_map SET is_active=1 WHERE gateway_id=?")->execute([$gatewayId]);

        // Auto-add to all merchants as OFF (is_enabled=0) — they can toggle ON
        $merchants = $db->query("SELECT id FROM merchants WHERE status != 'deleted'")->fetchAll();
        $stmt = $db->prepare("INSERT INTO merchant_payment_methods (merchant_id, method_key, method_label, is_enabled, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE method_label=VALUES(method_label)");
        foreach ($merchants as $m) {
            $stmt->execute([$m['id'], $gw['gateway_key'], $gw['gateway_name'], 0, 'system']);
        }

        return ['ok' => true, 'gateway_name' => $gw['gateway_name'], 'merchants' => count($merchants)];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Deactivate a gateway — methods stay in merchant list but marked inactive.
 */
function deactivateGateway(int $gatewayId): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $db->prepare("UPDATE gateway_registry SET is_active=0 WHERE id=?")->execute([$gatewayId]);
        $db->prepare("UPDATE gateway_method_map SET is_active=0 WHERE gateway_id=?")->execute([$gatewayId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get gateway details by ID.
 */
function getGatewayById(int $gatewayId): ?array
{
    ensurePaymentMethodsTable();
    try {
        $st = getDB()->prepare("SELECT * FROM gateway_registry WHERE id=?");
        $st->execute([$gatewayId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Save gateway config (API keys stored in gateway_settings table).
 */
function saveGatewayConfig(int $gatewayId, array $keys): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $gw = getGatewayById($gatewayId);
        if (!$gw) return ['ok' => false, 'error' => 'Gateway not found.'];

        foreach ($keys as $k => $v) {
            $v = trim((string)$v);
            $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
                ->execute([$k, $v, $v]);
        }

        // Store config metadata in config_json
        $configMeta = json_decode($gw['config_json'] ?? '{}', true) ?: [];
        $configMeta['keys_saved_at'] = date('Y-m-d H:i:s');
        $configMeta['keys_count'] = count(array_filter($keys, fn($v) => trim($v) !== ''));
        $db->prepare("UPDATE gateway_registry SET config_json=? WHERE id=?")
            ->execute([json_encode($configMeta), $gatewayId]);

        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Ensure gateway_health table exists for tracking success rates and response times.
 */
if (!function_exists('ensureGatewayHealthTable')) {
function ensureGatewayHealthTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_health (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway_key VARCHAR(40) NOT NULL UNIQUE,
            total_attempts INT NOT NULL DEFAULT 0,
            success_count INT NOT NULL DEFAULT 0,
            fail_count INT NOT NULL DEFAULT 0,
            total_response_ms INT NOT NULL DEFAULT 0,
            last_success_at TIMESTAMP NULL DEFAULT NULL,
            last_fail_at TIMESTAMP NULL DEFAULT NULL,
            last_error VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'healthy',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}
}

/**
 * Record a gateway payment attempt result.
 */
if (!function_exists('recordGatewayAttempt')) {
function recordGatewayAttempt(string $gatewayKey, bool $success, int $responseMs = 0, string $error = ''): void
{
    ensureGatewayHealthTable();
    $db = getDB();
    try {
        $st = $db->prepare("SELECT total_attempts, success_count FROM gateway_health WHERE gateway_key=?");
        $st->execute([$gatewayKey]);
        $row = $st->fetch();

        if ($row) {
            $newTotal = (int)$row['total_attempts'] + 1;
            $newSuccess = (int)$row['success_count'] + ($success ? 1 : 0);
            $newFail = $newTotal - $newSuccess;
            $rate = $newTotal > 0 ? $newSuccess / $newTotal : 1.0;
            $status = $rate < 0.5 ? 'down' : ($rate < 0.8 ? 'degraded' : 'healthy');

            $db->prepare(
                "UPDATE gateway_health SET total_attempts=?, success_count=?, fail_count=?,
                    total_response_ms=total_response_ms+?,
                    last_success_at=IF(?=1,NOW(),last_success_at),
                    last_fail_at=IF(?=0,NOW(),last_fail_at),
                    last_error=IF(?=0,?,last_error),
                    status=?
                WHERE gateway_key=?"
            )->execute([
                $newTotal, $newSuccess, $newFail, $responseMs,
                $success ? 1 : 0,
                $success ? 1 : 0,
                $success ? 1 : 0,
                $error,
                $status,
                $gatewayKey,
            ]);
        } else {
            $status = $success ? 'healthy' : 'down';
            $db->prepare(
                "INSERT INTO gateway_health (gateway_key, total_attempts, success_count, fail_count, total_response_ms, last_success_at, last_fail_at, last_error, status)
                 VALUES (?,1,?,?,?,IF(?=1,NOW(),NULL),IF(?=0,NOW(),NULL),?,?)"
            )->execute([
                $gatewayKey,
                $success ? 1 : 0,
                $success ? 0 : 1,
                $responseMs,
                $success ? 1 : 0,
                $success ? 1 : 0,
                $success ? '' : $error,
                $status,
            ]);
        }
    } catch (Throwable $e) { /* ok */ }
}
}

/**
 * Get smart gateway priority order based on success rate + response time.
 * Returns array of gateway keys sorted best-to-worst.
 * Excludes 'down' gateways.
 */
if (!function_exists('getSmartGatewayOrder')) {
function getSmartGatewayOrder(array $gatewayKeys = []): array
{
    ensureGatewayHealthTable();
    ensurePaymentMethodsTable();
    $db = getDB();

    if (empty($gatewayKeys)) {
        $st = $db->query("SELECT gateway_key FROM gateway_registry WHERE is_active=1 ORDER BY id ASC");
        $gatewayKeys = array_column($st->fetchAll(), 'gateway_key');
    }
    if (empty($gatewayKeys)) return [];

    try {
        $placeholders = implode(',', array_fill(0, count($gatewayKeys), '?'));
        $st = $db->prepare(
            "SELECT g.gateway_key,
                    COALESCE(h.total_attempts, 0) AS total_attempts,
                    COALESCE(h.success_count, 0) AS success_count,
                    COALESCE(h.fail_count, 0) AS fail_count,
                    COALESCE(h.total_response_ms, 0) AS total_response_ms,
                    COALESCE(h.status, 'healthy') AS status
             FROM gateway_registry g
             LEFT JOIN gateway_health h ON h.gateway_key = g.gateway_key
             WHERE g.gateway_key IN ($placeholders) AND g.is_active = 1
             ORDER BY
                CASE WHEN COALESCE(h.status, 'healthy') = 'down' THEN 1 ELSE 0 END,
                CASE WHEN COALESCE(h.status, 'healthy') = 'degraded' THEN 1 ELSE 0 END,
                (COALESCE(h.success_count, 0) / NULLIF(COALESCE(h.total_attempts, 1), 0)) DESC,
                (COALESCE(h.total_response_ms, 0) / NULLIF(COALESCE(h.total_attempts, 1), 0)) ASC,
                g.id ASC"
        );
        $st->execute($gatewayKeys);
        return array_column($st->fetchAll(), 'gateway_key');
    } catch (Throwable $e) {
        return $gatewayKeys;
    }
}
}

/**
 * Get gateway health summary for admin dashboard.
 */
if (!function_exists('getGatewayHealthSummary')) {
function getGatewayHealthSummary(): array
{
    ensureGatewayHealthTable();
    ensurePaymentMethodsTable();
    try {
        $st = getDB()->query(
            "SELECT g.id, g.gateway_key, g.gateway_name, g.is_active,
                    COALESCE(h.total_attempts, 0) AS total_attempts,
                    COALESCE(h.success_count, 0) AS success_count,
                    COALESCE(h.fail_count, 0) AS fail_count,
                    COALESCE(h.total_response_ms, 0) AS total_response_ms,
                    COALESCE(h.last_success_at, '') AS last_success_at,
                    COALESCE(h.last_fail_at, '') AS last_fail_at,
                    COALESCE(h.last_error, '') AS last_error,
                    COALESCE(h.status, 'healthy') AS status
             FROM gateway_registry g
             LEFT JOIN gateway_health h ON h.gateway_key = g.gateway_key
             ORDER BY g.is_active DESC, h.status ASC, g.id ASC"
        );
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
}

/**
 * Get active collection gateways for a merchant (for checkout routing).
 * Returns gateway keys in smart priority order.
 */
if (!function_exists('getMerchantCheckoutGateways')) {
function getMerchantCheckoutGateways(int $merchantId): array
{
    $methods = getMerchantPaymentMethods($merchantId);
    $enabledKeys = [];
    foreach ($methods as $m) {
        if ((int)$m['is_enabled'] === 1 && (int)$m['supports_collection'] === 1) {
            $enabledKeys[] = $m['gateway_key'];
        }
    }
    if (empty($enabledKeys)) return ['upi_p2m'];
    return getSmartGatewayOrder($enabledKeys);
}
}

/**
 * Methods a merchant can put on a payment link / QR.
 *
 * Source of truth = merchants.enabled_methods (+ approved method requests).
 * Do not hide Direct UPI just because gateway_registry has no "direct" row
 * or partner_methods.is_enabled=0 (same idea as checkout tabs).
 *
 * @return array List of method descriptors with key, label, gateway, pay_key, type
 */
function get_available_pay_methods(int $merchantId): array
{
    $db = getDB();
    $methods = [];

    try {
        $mst = $db->prepare('SELECT * FROM merchants WHERE id=?');
        $mst->execute([$merchantId]);
        $merchant = $mst->fetch();
        if (!$merchant) {
            return [];
        }
        if (in_array((string)($merchant['status'] ?? ''), ['blocked', 'suspended', 'deleted'], true)) {
            return [];
        }
    } catch (Throwable $e) {
        return [];
    }

    if (!function_exists('getPaymentMethodCatalog')) {
        return [];
    }
    $catalog = getPaymentMethodCatalog();

    if (!function_exists('getMerchantEnabledMethods') && is_file(__DIR__ . '/provision.php')) {
        require_once __DIR__ . '/provision.php';
    }
    if (!function_exists('merchantEntitledMethods') && is_file(__DIR__ . '/method_requests.php')) {
        require_once __DIR__ . '/method_requests.php';
    }

    if (function_exists('merchantEntitledMethods')) {
        $enabledKeys = merchantEntitledMethods($merchant);
    } elseif (function_exists('getMerchantEnabledMethods')) {
        $enabledKeys = getMerchantEnabledMethods($merchant);
    } else {
        $enabledKeys = getMerchantEnabledMethodKeys($merchantId);
    }
    if (empty($enabledKeys)) {
        $enabledKeys = ['upi_p2m'];
    }

    $isTest = function_exists('isMerchantPaymentTest')
        ? isMerchantPaymentTest($merchant)
        : !function_exists('isMerchantLive') || !isMerchantLive($merchant);

    if (!function_exists('isPartnerMethodEnabled') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }

    foreach ($enabledKeys as $methodKey) {
        $cat = $catalog[$methodKey] ?? null;
        if (!$cat) {
            continue;
        }
        $gateway = (string)($cat['gateway'] ?? '');

        // Platform Direct UPI — always list when merchant entitled
        if ($gateway === 'direct') {
            $methods[] = [
                'key' => $methodKey,
                'label' => $cat['label'],
                'pay_key' => $cat['pay_key'] ?? $methodKey,
                'gateway' => $gateway,
                'collection_mode' => $cat['collection_mode'] ?? '',
                'icon' => $cat['icon'] ?? '',
                'type' => 'p2m',
            ];
            continue;
        }

        // Axis VA — test always; live needs Axis configured
        if ($gateway === 'axis') {
            if (!$isTest && function_exists('isGatewayConfigured') && !isGatewayConfigured('axis')) {
                continue;
            }
            $methods[] = [
                'key' => $methodKey,
                'label' => $cat['label'],
                'pay_key' => $cat['pay_key'] ?? $methodKey,
                'gateway' => $gateway,
                'collection_mode' => $cat['collection_mode'] ?? '',
                'icon' => $cat['icon'] ?? '',
                'type' => 'p2m',
            ];
            continue;
        }

        // Partner PG — Live Mode hard-gates: registry active + credentials + partner method ON.
        // Test Mode may list entitled methods for Instant Test Pay (no real settlement).
        // P9-06: never present cards/POS as available without Partner Registry + merchant activation.
        if (!$isTest) {
            if (function_exists('isGatewayActive') && !isGatewayActive($gateway)) {
                continue;
            }
            if (function_exists('isGatewayConfigured') && !isGatewayConfigured($gateway)) {
                continue;
            }
            if (function_exists('isPartnerMethodEnabled')) {
                $partnerMethod = catalogKeyToPartnerMethodName($methodKey);
                $methodOn = isPartnerMethodEnabled($gateway, $partnerMethod)
                    || isPartnerMethodEnabled($gateway, $methodKey);
                if (!$methodOn) {
                    continue;
                }
            }
        }

        $methods[] = [
            'key' => $methodKey,
            'label' => $cat['label'],
            'pay_key' => $cat['pay_key'] ?? $methodKey,
            'gateway' => $gateway,
            'collection_mode' => $cat['collection_mode'] ?? '',
            'icon' => $cat['icon'] ?? '',
            'type' => $gateway === 'payu' ? 'payu' : ($gateway === 'razorpay' ? 'razorpay' : ($gateway === 'cashfree' ? 'cashfree' : 'p2m')),
        ];
    }

    if (empty($methods) && isset($catalog['upi_p2m'])) {
        $cat = $catalog['upi_p2m'];
        $methods[] = [
            'key' => 'upi_p2m',
            'label' => $cat['label'],
            'pay_key' => $cat['pay_key'] ?? 'upi',
            'gateway' => 'direct',
            'collection_mode' => $cat['collection_mode'] ?? 'direct_upi',
            'icon' => $cat['icon'] ?? '',
            'type' => 'p2m',
        ];
    }

    return sortPaymentMethodsUpiFirst($methods, 'key');
}

/** Map catalog keys (upi_p2m, …) to partner_methods.method names (upi, …). */
function catalogKeyToPartnerMethodName(string $methodKey): string
{
    return match ($methodKey) {
        'upi_p2m', 'axis_va', 'payu_upi', 'razorpay_upi', 'cashfree_upi' => 'upi',
        'debit_card' => 'debit_card',
        'credit_card' => 'credit_card',
        'netbanking' => 'netbanking',
        'emi' => 'emi',
        'wallet' => 'wallet',
        'emandate_upi' => 'emandate_upi',
        'emandate_card' => 'emandate_card',
        'emandate_nb' => 'emandate_nb',
        default => $methodKey,
    };
}

/**
 * E1: Check if a specific method is available for a merchant.
 */
function isMethodAvailable(int $merchantId, string $methodKey): bool
{
    $methods = get_available_pay_methods($merchantId);
    foreach ($methods as $m) {
        if ($m['key'] === $methodKey) return true;
    }
    return false;
}

/**
 * E1: Get available pay method keys (just the keys, for quick checks).
 */
function get_available_pay_method_keys(int $merchantId): array
{
    return array_column(get_available_pay_methods($merchantId), 'key');
}
