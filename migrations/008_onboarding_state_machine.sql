ALTER TABLE merchants ADD COLUMN IF NOT EXISTS onboarding_state VARCHAR(32) NOT NULL DEFAULT 'draft';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS bank_verification_status VARCHAR(32) NOT NULL DEFAULT 'pending';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS website_review_status VARCHAR(32) NOT NULL DEFAULT 'pending';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS video_kyc_status VARCHAR(32) NOT NULL DEFAULT 'pending';
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS onboarding_submitted_at DATETIME NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS live_enabled_at DATETIME NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS live_enabled_by INT NULL;

ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS storage_key VARCHAR(255) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS sha256 CHAR(64) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS mime_type VARCHAR(120) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS file_size BIGINT DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS scan_status VARCHAR(32) NOT NULL DEFAULT 'pending';
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS retention_until DATE DEFAULT NULL;

CREATE TABLE IF NOT EXISTS approval_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_ref VARCHAR(40) NOT NULL,
    action_type VARCHAR(64) NOT NULL,
    merchant_id INT DEFAULT NULL,
    resource_type VARCHAR(64) DEFAULT NULL,
    resource_id VARCHAR(100) DEFAULT NULL,
    requested_by_type ENUM('admin','staff','system') NOT NULL,
    requested_by_id INT DEFAULT NULL,
    request_reason VARCHAR(500) NOT NULL,
    payload JSON DEFAULT NULL,
    before_hash CHAR(64) DEFAULT NULL,
    status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    checked_by_type ENUM('admin','staff','system') DEFAULT NULL,
    checked_by_id INT DEFAULT NULL,
    checker_reason VARCHAR(500) DEFAULT NULL,
    after_hash CHAR(64) DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    checked_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_approval_ref (request_ref),
    INDEX idx_approval_queue (status,action_type,requested_at),
    INDEX idx_approval_merchant (merchant_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS merchant_agreement_acceptances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    agreement_version VARCHAR(40) NOT NULL,
    legal_name VARCHAR(190) NOT NULL,
    merchant_code VARCHAR(64) NOT NULL,
    document_hash CHAR(64) NOT NULL,
    accepted_ip VARCHAR(64) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    accepted_at DATETIME NOT NULL,
    UNIQUE KEY uniq_merchant_agreement_version (merchant_id,agreement_version),
    INDEX idx_agreement_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

UPDATE merchants
SET onboarding_state=CASE
    WHEN kyc_status='verified' THEN 'verified'
    WHEN kyc_status IN ('submitted','pending') THEN 'submitted'
    WHEN kyc_status='rejected' THEN 'clarification'
    ELSE 'draft'
END
WHERE onboarding_state='draft';

UPDATE merchants SET account_mode='test',onboarding_state='submitted',live_enabled_at=NULL
WHERE email='demo@uniweb.co.in';
