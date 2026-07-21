-- KYC document rejection reason (shown to merchants on KYC status).
-- Mirrors ensureKycSchema() columns in includes/schema_ensure.php.

ALTER TABLE kyc_documents ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN reviewed_at DATETIME DEFAULT NULL;
