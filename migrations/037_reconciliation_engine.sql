-- Migration 037: Reconciliation Engine Enhancement
-- Gateway settlement file upload + auto-match + daily summary + manual resolve

CREATE TABLE IF NOT EXISTS gateway_settlement_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gateway VARCHAR(32) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    uploaded_by INT DEFAULT NULL,
    file_date DATE DEFAULT NULL,
    rows_total INT DEFAULT 0,
    rows_matched INT DEFAULT 0,
    rows_unmatched INT DEFAULT 0,
    rows_amount_total DECIMAL(14,2) DEFAULT 0,
    rows_amount_matched DECIMAL(14,2) DEFAULT 0,
    status ENUM('processing','completed','failed') DEFAULT 'processing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gateway_date (gateway, file_date),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gateway_settlement_rows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    gateway VARCHAR(32) NOT NULL,
    utr VARCHAR(64) DEFAULT NULL,
    gateway_ref VARCHAR(128) DEFAULT NULL,
    merchant_code VARCHAR(64) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    settlement_date DATE DEFAULT NULL,
    txn_id VARCHAR(64) DEFAULT NULL,
    match_status ENUM('unmatched','matched','manual_resolved','ignored') DEFAULT 'unmatched',
    matched_txn_id INT DEFAULT NULL,
    match_reason VARCHAR(255) DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES gateway_settlement_files(id) ON DELETE CASCADE,
    INDEX idx_file (file_id),
    INDEX idx_match_status (match_status),
    INDEX idx_utr (utr),
    INDEX idx_gateway_ref (gateway_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reconciliation_daily_summary (
    id INT AUTO_INCREMENT PRIMARY KEY,
    summary_date DATE NOT NULL,
    gateway VARCHAR(32) NOT NULL,
    total_txns INT DEFAULT 0,
    success_txns INT DEFAULT 0,
    failed_txns INT DEFAULT 0,
    pending_txns INT DEFAULT 0,
    total_amount DECIMAL(14,2) DEFAULT 0,
    success_amount DECIMAL(14,2) DEFAULT 0,
    webhooks_received INT DEFAULT 0,
    webhooks_matched INT DEFAULT 0,
    webhooks_unmatched INT DEFAULT 0,
    settlement_files_processed INT DEFAULT 0,
    mismatches INT DEFAULT 0,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_date_gateway (summary_date, gateway)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add reconciliation_status column to transactions if not exists
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reconciliation_status ENUM('unreconciled','matched','mismatched','manual_resolved') DEFAULT 'unreconciled' AFTER status;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reconciled_at TIMESTAMP NULL DEFAULT NULL AFTER reconciliation_status;
