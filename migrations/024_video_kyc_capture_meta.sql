-- Video KYC live capture metadata (IP + recorded timestamp)
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS ip_address VARCHAR(45) DEFAULT NULL AFTER file_size;
ALTER TABLE kyc_documents ADD COLUMN IF NOT EXISTS recorded_at DATETIME DEFAULT NULL AFTER ip_address;
