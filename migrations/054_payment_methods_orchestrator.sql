-- Migration 054: Payment methods ON/OFF per merchant + gateway registry

CREATE TABLE IF NOT EXISTS merchant_payment_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    method_key VARCHAR(40) NOT NULL,
    method_label VARCHAR(60) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    updated_by VARCHAR(20) NOT NULL DEFAULT 'merchant',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_merchant_method (merchant_id, method_key),
    INDEX idx_merchant (merchant_id, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gateway_registry (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gateway_method_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway_id INT NOT NULL,
    method_key VARCHAR(40) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uniq_gateway_method (gateway_id, method_key),
    INDEX idx_method (method_key, is_active),
    FOREIGN KEY (gateway_id) REFERENCES gateway_registry(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Half-applied DBs may have gateway_registry without these columns. ALTER before INSERT.
ALTER TABLE gateway_registry ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN supports_collection TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE gateway_registry ADD COLUMN supports_payout TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN supports_refund TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN supports_recurring TINYINT(1) NOT NULL DEFAULT 0;

-- Seed default payment methods
INSERT INTO gateway_registry (gateway_key, gateway_name, is_active, supports_collection, supports_payout, supports_refund, supports_recurring) VALUES
    ('upi_p2m', 'UPI P2M', 1, 1, 0, 1, 1),
    ('qr_code', 'QR Code', 1, 1, 0, 0, 0),
    ('credit_card', 'Credit Card', 0, 1, 0, 1, 1),
    ('debit_card', 'Debit Card', 0, 1, 0, 1, 0),
    ('net_banking', 'Net Banking', 0, 1, 0, 1, 0),
    ('wallet', 'Wallet', 0, 1, 0, 1, 0),
    ('payout', 'Payout', 0, 0, 1, 0, 0),
    ('recurring', 'Recurring / AutoPay', 0, 1, 0, 0, 1)
ON DUPLICATE KEY UPDATE gateway_name=VALUES(gateway_name);

-- Map methods to themselves (single-gateway mode)
INSERT INTO gateway_method_map (gateway_id, method_key, is_active)
    SELECT id, gateway_key, is_active FROM gateway_registry
ON DUPLICATE KEY UPDATE is_active=VALUES(is_active);
