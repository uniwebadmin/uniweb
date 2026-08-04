-- Merchant risk, AML watchlist and blacklist tables.
-- Runtime ensure functions also create these, so this keeps fresh installs clean.

CREATE TABLE IF NOT EXISTS aml_flags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    transaction_id INT DEFAULT NULL,
    flag_type VARCHAR(32) NOT NULL,
    severity ENUM('low','medium','high') DEFAULT 'medium',
    description VARCHAR(255) DEFAULT NULL,
    status ENUM('open','reviewed','cleared') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, status),
    INDEX idx_txn (transaction_id),
    INDEX idx_type (flag_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS aml_watchlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('individual','entity','phone','email','upi','account') NOT NULL,
    value VARCHAR(255) NOT NULL,
    source VARCHAR(64) DEFAULT 'manual',
    reason VARCHAR(255) DEFAULT NULL,
    is_sanction TINYINT(1) DEFAULT 0,
    status ENUM('active','removed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type_value (type, value),
    INDEX idx_active (status, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS merchant_risk_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    score INT NOT NULL DEFAULT 0,
    reasons JSON DEFAULT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS blacklists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scope ENUM('merchant','customer') NOT NULL,
    target VARCHAR(255) NOT NULL,
    target_type ENUM('phone','email','merchant_id','customer_id','upi','ip') NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_scope_target (scope, target_type, target)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
