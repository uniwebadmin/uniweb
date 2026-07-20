ALTER TABLE merchants ADD COLUMN IF NOT EXISTS settlement_mode VARCHAR(16) NOT NULL DEFAULT 'manual';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS settlement_rail VARCHAR(24) NOT NULL DEFAULT 'wallet';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS batch_interval_minutes INT NOT NULL DEFAULT 120;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS settlement_use_platform_default TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS next_batch_at DATETIME NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS last_batch_at DATETIME NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS settlement_batch_id INT NULL;

CREATE TABLE IF NOT EXISTS settlement_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(32) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    settlement_rail ENUM('platform_pg','axis_va','wallet') NOT NULL DEFAULT 'wallet',
    batch_type ENUM('scheduled','manual') NOT NULL DEFAULT 'scheduled',
    txn_count INT NOT NULL DEFAULT 0,
    gross_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    fee_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('open','processing','settled','failed') NOT NULL DEFAULT 'open',
    settlement_id VARCHAR(32) NULL,
    period_start DATETIME NULL,
    period_end DATETIME NULL,
    scheduled_at DATETIME NULL,
    processed_at DATETIME NULL,
    utr VARCHAR(64) NULL,
    api_provider VARCHAR(32) NULL,
    api_status VARCHAR(32) NOT NULL DEFAULT 'pending',
    api_message VARCHAR(255) NULL,
    provider_payout_id VARCHAR(120) DEFAULT NULL,
    provider_status VARCHAR(50) DEFAULT NULL,
    failure_reason VARCHAR(500) DEFAULT NULL,
    bank_reconciled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_batch_merchant (merchant_id),
    INDEX idx_batch_status (status),
    INDEX idx_batch_scheduled (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE settlement_batches ADD COLUMN IF NOT EXISTS provider_payout_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE settlement_batches ADD COLUMN IF NOT EXISTS provider_status VARCHAR(50) DEFAULT NULL;
ALTER TABLE settlement_batches ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) DEFAULT NULL;
ALTER TABLE settlement_batches ADD COLUMN IF NOT EXISTS bank_reconciled_at DATETIME NULL;
ALTER TABLE settlement_batches MODIFY COLUMN settlement_id VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS settlement_batch_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    transaction_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    payment_method VARCHAR(32) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_settlement_transaction (transaction_id),
    INDEX idx_settlement_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO gateway_settings (setting_key,setting_value)
VALUES
('default_settlement_mode','manual'),
('default_settlement_rail','platform_pg'),
('default_batch_interval_minutes','120'),
('settlement_batch_enabled','1'),
('axis_batch_enabled','0'),
('platform_pg_batch_enabled','0')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
