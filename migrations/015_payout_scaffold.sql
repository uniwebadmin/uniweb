-- Payout module scaffold (enable request, beneficiaries, orders).
-- Mirrors ensurePayoutSchema() in includes/payout.php.
-- Live money movement remains gated until licensed partner keys are configured.

CREATE TABLE IF NOT EXISTS merchant_payout_enable_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    merchant_note VARCHAR(500) DEFAULT NULL,
    admin_note VARCHAR(500) DEFAULT NULL,
    decided_by VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME DEFAULT NULL,
    INDEX idx_per_merchant (merchant_id),
    INDEX idx_per_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_beneficiaries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    label VARCHAR(120) NOT NULL,
    account_holder VARCHAR(190) NOT NULL,
    account_number VARCHAR(40) NOT NULL,
    ifsc_code VARCHAR(20) NOT NULL,
    bank_name VARCHAR(120) DEFAULT NULL,
    account_type VARCHAR(20) NOT NULL DEFAULT 'savings',
    upi_vpa VARCHAR(120) DEFAULT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    penny_drop_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pb_merchant (merchant_id),
    INDEX idx_pb_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_id VARCHAR(40) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    beneficiary_id INT DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    purpose VARCHAR(120) DEFAULT NULL,
    status ENUM('draft','pending_maker','pending_checker','queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'draft',
    failure_reason VARCHAR(500) DEFAULT NULL,
    maker_by VARCHAR(120) DEFAULT NULL,
    checker_by VARCHAR(120) DEFAULT NULL,
    maker_at DATETIME DEFAULT NULL,
    checker_at DATETIME DEFAULT NULL,
    partner_ref VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_po_merchant (merchant_id),
    INDEX idx_po_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0;
