CREATE TABLE IF NOT EXISTS refunds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    refund_id VARCHAR(32) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    transaction_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    provider VARCHAR(32) DEFAULT NULL,
    provider_refund_id VARCHAR(120) DEFAULT NULL,
    provider_status VARCHAR(50) DEFAULT NULL,
    provider_reference VARCHAR(120) DEFAULT NULL,
    failure_reason VARCHAR(500) DEFAULT NULL,
    reason TEXT,
    admin_note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    UNIQUE KEY uniq_provider_refund (provider,provider_refund_id),
    INDEX idx_refund_merchant (merchant_id),
    INDEX idx_refund_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE refunds ADD COLUMN IF NOT EXISTS provider VARCHAR(32) DEFAULT NULL;
ALTER TABLE refunds ADD COLUMN IF NOT EXISTS provider_refund_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE refunds ADD COLUMN IF NOT EXISTS provider_status VARCHAR(50) DEFAULT NULL;
ALTER TABLE refunds ADD COLUMN IF NOT EXISTS provider_reference VARCHAR(120) DEFAULT NULL;
ALTER TABLE refunds ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) DEFAULT NULL;

ALTER TABLE bank_accounts ADD COLUMN IF NOT EXISTS razorpay_contact_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE bank_accounts ADD COLUMN IF NOT EXISTS razorpay_fund_account_id VARCHAR(120) DEFAULT NULL;
