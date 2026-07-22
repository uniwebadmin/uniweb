-- KYC document rejection reason (shown to merchants on KYC status).
-- Mirrors ensureKycSchema() columns in includes/schema_ensure.php.
-- Idempotent IF NOT EXISTS — safe if runtime schema ensure already added columns.

ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS rejection_reason VARCHAR(500) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS reviewed_at DATETIME DEFAULT NULL;
