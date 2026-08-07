-- Migration 047: Payout jobs table for async payout processing
-- C1: payout_jobs table + lifecycle

CREATE TABLE IF NOT EXISTS payout_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(40) NOT NULL UNIQUE,
    payout_order_id INT NOT NULL,
    merchant_id INT NOT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('queued','processing','success','failed','retry','cancelled') NOT NULL DEFAULT 'queued',
    adapter VARCHAR(60) DEFAULT NULL,
    partner_ref VARCHAR(120) DEFAULT NULL,
    utr VARCHAR(120) DEFAULT NULL,
    attempt INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    next_retry_at DATETIME DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    payload JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    INDEX idx_pjob_status (status, next_retry_at),
    INDEX idx_pjob_merchant (merchant_id, status),
    INDEX idx_pjob_order (payout_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
