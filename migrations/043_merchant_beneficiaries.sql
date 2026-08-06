-- Migration 043: Merchant Beneficiaries with Penny Drop verification
-- A6: Beneficiary table for verified bank/UPI accounts before auto payout

CREATE TABLE IF NOT EXISTS merchant_beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    type ENUM('bank','upi') NOT NULL DEFAULT 'bank',
    account_holder VARCHAR(160) NOT NULL,
    account_number VARCHAR(64) NOT NULL,
    ifsc_code VARCHAR(20) DEFAULT NULL,
    upi_id VARCHAR(128) DEFAULT NULL,
    bank_name VARCHAR(100) DEFAULT NULL,
    status ENUM('unverified','pending','verified','failed','disabled') NOT NULL DEFAULT 'unverified',
    verify_name VARCHAR(160) DEFAULT NULL,
    verify_score DECIMAL(5,2) DEFAULT NULL,
    verify_response TEXT DEFAULT NULL,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    verified_by INT DEFAULT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, status),
    INDEX idx_status (status),
    INDEX idx_upi (upi_id),
    INDEX idx_account (account_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
