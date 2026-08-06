-- Migration 038: Risk Engine — velocity rules, risk scoring, auto-actions
-- Based on PDF Risk Engine Complete Specification

CREATE TABLE IF NOT EXISTS risk_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rule_name VARCHAR(128) NOT NULL,
    rule_type ENUM('velocity','amount','merchant','blacklist','time','custom') NOT NULL,
    scope ENUM('transaction','merchant') NOT NULL DEFAULT 'transaction',
    parameters JSON DEFAULT NULL,
    action ENUM('allow','flag','hold','block') NOT NULL DEFAULT 'flag',
    score_weight INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_type_active (rule_type, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT DEFAULT NULL,
    merchant_id INT DEFAULT NULL,
    rule_id INT DEFAULT NULL,
    rule_name VARCHAR(128) NOT NULL,
    risk_score INT NOT NULL DEFAULT 0,
    action_taken ENUM('allow','flag','hold','block') NOT NULL DEFAULT 'allow',
    details JSON DEFAULT NULL,
    resolved TINYINT(1) DEFAULT 0,
    resolved_by INT DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolution_note VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_txn (transaction_id),
    INDEX idx_merchant (merchant_id),
    INDEX idx_action (action_taken),
    INDEX idx_resolved (resolved),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_merchant_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    max_txn_amount DECIMAL(14,2) DEFAULT NULL,
    max_txn_count_hour INT DEFAULT NULL,
    max_txn_count_day INT DEFAULT NULL,
    max_volume_day DECIMAL(14,2) DEFAULT NULL,
    auto_hold_threshold INT DEFAULT 70,
    auto_block_threshold INT DEFAULT 85,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS risk_velocity_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fingerprint_type ENUM('upi','card','device','phone','email','ip') NOT NULL,
    fingerprint_value VARCHAR(255) NOT NULL,
    merchant_id INT DEFAULT NULL,
    txn_count_1h INT DEFAULT 0,
    txn_count_24h INT DEFAULT 0,
    txn_amount_24h DECIMAL(14,2) DEFAULT 0,
    last_txn_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fp_type_value (fingerprint_type, fingerprint_value),
    INDEX idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
