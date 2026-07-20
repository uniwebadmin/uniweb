CREATE TABLE IF NOT EXISTS wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    type ENUM('credit','debit','commission','subscription','settlement','refund','adjustment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_wallet_payment_credit (merchant_id, transaction_id),
    INDEX idx_wallet_merchant (merchant_id),
    INDEX idx_wallet_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_wallet_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('credit','debit','commission','settlement','refund','adjustment') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    transaction_id INT DEFAULT NULL,
    merchant_id INT DEFAULT NULL,
    reference VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_platform_wallet_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_id VARCHAR(30) NOT NULL UNIQUE,
    amount DECIMAL(14,2) NOT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    bank_name VARCHAR(100) DEFAULT NULL,
    account_number VARCHAR(30) DEFAULT NULL,
    ifsc_code VARCHAR(16) DEFAULT NULL,
    account_holder VARCHAR(100) DEFAULT NULL,
    utr VARCHAR(50) DEFAULT NULL,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO gateway_settings (setting_key, setting_value)
VALUES ('platform_wallet_balance','0'),('min_platform_settlement','1'),('min_settlement_amount','100')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
