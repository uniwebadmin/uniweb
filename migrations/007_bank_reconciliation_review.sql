CREATE TABLE IF NOT EXISTS bank_reconciliation_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    uploaded_by INT NULL,
    rows_total INT NOT NULL DEFAULT 0,
    rows_confirmed INT NOT NULL DEFAULT 0,
    rows_auto_settled INT NOT NULL DEFAULT 0,
    rows_suggested INT NOT NULL DEFAULT 0,
    rows_unmatched INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE bank_reconciliation_files ADD COLUMN IF NOT EXISTS rows_suggested INT NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS bank_reconciliation_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id INT DEFAULT NULL,
    batch_id INT DEFAULT NULL,
    merchant_id INT DEFAULT NULL,
    bank_reference VARCHAR(120) DEFAULT NULL,
    beneficiary_account_last4 CHAR(4) DEFAULT NULL,
    statement_amount DECIMAL(14,2) NOT NULL,
    statement_date DATE DEFAULT NULL,
    match_status ENUM('suggested','confirmed','rejected','unmatched') NOT NULL DEFAULT 'suggested',
    match_reason VARCHAR(500) DEFAULT NULL,
    reviewed_by INT DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reconciliation_match_status (match_status,created_at),
    INDEX idx_reconciliation_match_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE settlement_batches ADD COLUMN IF NOT EXISTS bank_reconciled_at DATETIME NULL;
