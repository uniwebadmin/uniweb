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
 * Get a merchant's payment method settings.
 * Returns all methods with their ON/OFF status.
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
             ORDER BY g.id ASC"
        );
        $st->execute([$merchantId]);
        return $st->fetchAll();
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
 * Register a new gateway/partner — auto-adds to all merchant method lists.
 */
function registerGateway(string $key, string $name, array $capabilities = []): array
{
    ensurePaymentMethodsTable();
    $db = getDB();
    try {
        $collection = $capabilities['collection'] ?? 1;
        $payout = $capabilities['payout'] ?? 0;
        $refund = $capabilities['refund'] ?? 0;
        $recurring = $capabilities['recurring'] ?? 0;
        $adapter = $capabilities['adapter'] ?? null;
        $webhookUrl = $capabilities['webhook_url'] ?? null;
        $configJson = $capabilities['config_json'] ?? null;

        $db->prepare(
            "INSERT INTO gateway_registry (gateway_key, gateway_name, adapter_class, is_active, supports_collection, supports_payout, supports_refund, supports_recurring, webhook_url, config_json)
             VALUES (?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE gateway_name=VALUES(gateway_name), adapter_class=VALUES(adapter_class), is_active=1,
                   supports_collection=VALUES(supports_collection), supports_payout=VALUES(supports_payout),
                   supports_refund=VALUES(supports_refund), supports_recurring=VALUES(supports_recurring),
                   webhook_url=VALUES(webhook_url), config_json=VALUES(config_json)"
        )->execute([$key, $name, $adapter, 1, $collection, $payout, $refund, $recurring, $webhookUrl, $configJson]);

        $gatewayId = (int)$db->lastInsertId();
        if (!$gatewayId) {
            $st = $db->prepare("SELECT id FROM gateway_registry WHERE gateway_key=?");
            $st->execute([$key]);
            $gatewayId = (int)$st->fetchColumn();
        }

        $db->prepare("INSERT INTO gateway_method_map (gateway_id, method_key, is_active) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_active=1")
            ->execute([$gatewayId, $key]);

        return ['ok' => true, 'gateway_id' => $gatewayId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Get all registered gateways (for admin orchestrator view).
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
