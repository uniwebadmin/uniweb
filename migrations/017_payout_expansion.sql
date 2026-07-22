-- Payout module expansion (017): batches, reversal queue, API credentials, penny-drop note.
-- Mirrors ensurePayoutSchema() upgrades in includes/payout.php.

CREATE TABLE IF NOT EXISTS payout_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_code VARCHAR(40) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    row_count INT NOT NULL DEFAULT 0,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('draft','submitted','cancelled') NOT NULL DEFAULT 'draft',
    created_by VARCHAR(120) DEFAULT NULL,
    notes VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pbatch_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_reversal_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    payout_order_id INT NOT NULL,
    merchant_id INT NOT NULL,
    status ENUM('pending','approved','rejected','reconciled') NOT NULL DEFAULT 'pending',
    merchant_note VARCHAR(500) DEFAULT NULL,
    admin_note VARCHAR(500) DEFAULT NULL,
    decided_by VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME DEFAULT NULL,
    INDEX idx_prev_merchant (merchant_id),
    INDEX idx_prev_status (status),
    INDEX idx_prev_order (payout_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payout_api_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    key_prefix VARCHAR(24) NOT NULL,
    key_hash VARCHAR(64) NOT NULL,
    secret_hash VARCHAR(64) NOT NULL,
    status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    last_used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pac_merchant (merchant_id),
    INDEX idx_pac_status (status),
    UNIQUE KEY uq_pac_prefix (key_prefix)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE payout_orders ADD COLUMN batch_id INT DEFAULT NULL;
ALTER TABLE payout_beneficiaries ADD COLUMN penny_drop_note VARCHAR(255) DEFAULT NULL;
