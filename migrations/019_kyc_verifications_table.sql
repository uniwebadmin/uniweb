-- Base table for kyc_verifications (Aadhaar/PAN/GST/bank document verification
-- results — saveVerification()/getVerifications() in includes/verification.php,
-- also read by includes/onboarding_security.php). This table had no CREATE
-- TABLE anywhere in the repo (migrations/, dev_local/schema.sql, or a runtime
-- ensure*Schema() guard) — a genuinely fresh install/sandbox never gets it,
-- so any KYC document/bank verification write fails with "table doesn't
-- exist". Columns match the exact fields used by saveVerification()'s
-- INSERT ... ON DUPLICATE KEY UPDATE, hence the (merchant_id, doc_type)
-- unique key.
CREATE TABLE IF NOT EXISTS kyc_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    doc_number VARCHAR(120) DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'submitted',
    api_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_merchant_doctype (merchant_id, doc_type),
    INDEX idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
