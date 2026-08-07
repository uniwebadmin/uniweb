-- Migration 051: Recurring Mandates (eNACH / UPI Autopay)
-- Supports subscription billing, recurring payments, and mandate registration

CREATE TABLE IF NOT EXISTS mandates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT UNSIGNED NOT NULL,
    mandate_ref VARCHAR(64) NOT NULL UNIQUE,
    customer_name VARCHAR(200) DEFAULT NULL,
    customer_email VARCHAR(200) DEFAULT NULL,
    customer_phone VARCHAR(20) DEFAULT NULL,
    customer_upi_id VARCHAR(120) DEFAULT NULL,
    mandate_type ENUM('upi_autopay','enach','physical') NOT NULL DEFAULT 'upi_autopay',
    status ENUM('pending','registered','active','paused','cancelled','failed','expired') NOT NULL DEFAULT 'pending',
    max_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    frequency ENUM('onetime','daily','weekly','monthly','quarterly','halfyearly','yearly','as_presented') NOT NULL DEFAULT 'monthly',
    start_date DATE NOT NULL,
    end_date DATE DEFAULT NULL,
    next_debit_date DATE DEFAULT NULL,
    last_debit_date DATE DEFAULT NULL,
    last_debit_amount DECIMAL(14,2) DEFAULT NULL,
    total_debited DECIMAL(14,2) NOT NULL DEFAULT 0,
    debit_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_debits INT UNSIGNED DEFAULT NULL,
    gateway VARCHAR(32) DEFAULT NULL,
    gateway_mandate_id VARCHAR(128) DEFAULT NULL,
    gateway_response JSON DEFAULT NULL,
    failure_reason VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mandate_merchant (merchant_id, status),
    INDEX idx_mandate_next_debit (status, next_debit_date),
    INDEX idx_mandate_ref (mandate_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mandate_debits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mandate_id INT UNSIGNED NOT NULL,
    merchant_id INT UNSIGNED NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending','success','failed','retried') NOT NULL DEFAULT 'pending',
    transaction_id INT UNSIGNED DEFAULT NULL,
    gateway_order_id VARCHAR(128) DEFAULT NULL,
    failure_reason VARCHAR(500) DEFAULT NULL,
    attempted_at DATETIME DEFAULT NULL,
    settled_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_debit_mandate (mandate_id, status),
    INDEX idx_debit_merchant (merchant_id, status),
    INDEX idx_debit_date (status, created_at),
    FOREIGN KEY (mandate_id) REFERENCES mandates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
