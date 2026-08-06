-- Migration 039: Rolling Reserve — hold % on new/high-risk merchants, release schedule
-- Based on PDF Rolling Reserve specification

CREATE TABLE IF NOT EXISTS rolling_reserve_config (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    hold_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    release_days INT NOT NULL DEFAULT 7,
    auto_release TINYINT(1) DEFAULT 1,
    applies_to ENUM('all','new_merchants','high_risk') NOT NULL DEFAULT 'all',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rolling_reserve_holds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    transaction_id INT NOT NULL,
    held_amount DECIMAL(14,2) NOT NULL,
    hold_percentage DECIMAL(5,2) NOT NULL,
    held_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    release_date DATE NOT NULL,
    released_at TIMESTAMP NULL DEFAULT NULL,
    released_by INT DEFAULT NULL,
    release_settlement_id INT DEFAULT NULL,
    status ENUM('held','released','manually_released','cancelled') NOT NULL DEFAULT 'held',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant_status (merchant_id, status),
    INDEX idx_release_date (release_date, status),
    INDEX idx_txn (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
