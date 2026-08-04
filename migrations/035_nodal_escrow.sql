-- Nodal / escrow account ledger for RBI PA-PG customer-fund separation.

CREATE TABLE IF NOT EXISTS nodal_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    bank_name VARCHAR(120) NOT NULL,
    account_holder VARCHAR(120) NOT NULL,
    account_number VARCHAR(64) NOT NULL,
    ifsc_code VARCHAR(20) NOT NULL,
    branch VARCHAR(120) DEFAULT NULL,
    purpose VARCHAR(120) DEFAULT 'collections_and_settlements',
    is_primary TINYINT(1) DEFAULT 0,
    status ENUM('pending','verified','suspended') DEFAULT 'pending',
    verification_notes TEXT,
    verified_by INT DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_primary (is_primary, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS nodal_wallet_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nodal_account_id INT NOT NULL,
    merchant_id INT DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    settlement_id VARCHAR(64) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    type ENUM('credit','debit') NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_nodal (nodal_account_id),
    INDEX idx_merchant (merchant_id),
    INDEX idx_settlement (settlement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
