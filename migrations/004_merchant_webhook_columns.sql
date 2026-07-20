ALTER TABLE merchants ADD COLUMN IF NOT EXISTS webhook_url VARCHAR(500) NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS webhook_signing_secret VARCHAR(64) NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS webhook_signing_secret_previous VARCHAR(64) NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS webhook_secret_rotated_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS merchant_webhook_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    payload MEDIUMTEXT,
    response_code INT NULL,
    response_body TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant_webhook_merchant (merchant_id),
    INDEX idx_merchant_webhook_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
