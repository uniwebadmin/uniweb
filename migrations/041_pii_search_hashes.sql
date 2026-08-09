-- Migration 041: Add HMAC hash columns for searchable PII fields
-- Allows admin search by PAN/GSTIN without decrypting ciphertext.
-- Hash is HMAC-SHA256 of normalised value using ENCRYPTION_KEY.

ALTER TABLE merchants
    ADD COLUMN IF NOT EXISTS pan_hash VARCHAR(64) DEFAULT NULL AFTER pan_number,
    ADD COLUMN IF NOT EXISTS gstin_hash VARCHAR(64) DEFAULT NULL AFTER gstin,
    ADD COLUMN IF NOT EXISTS cin_hash VARCHAR(64) DEFAULT NULL AFTER cin_llpin,
    ADD COLUMN IF NOT EXISTS aadhaar_hash VARCHAR(64) DEFAULT NULL AFTER aadhaar_number;

-- Backfill hashes for existing plaintext rows (skip already-encrypted — those need
-- a runtime backfill via admin_encrypt_pii.php which has access to the key).
-- Plaintext rows still present will get hashed here.
UPDATE merchants SET pan_hash = SHA2(UPPER(TRIM(pan_number)), 256) WHERE pan_hash IS NULL AND pan_number IS NOT NULL AND pan_number != '' AND pan_number NOT LIKE 'enc:v1:%';
UPDATE merchants SET gstin_hash = SHA2(UPPER(TRIM(gstin)), 256) WHERE gstin_hash IS NULL AND gstin IS NOT NULL AND gstin != '' AND gstin NOT LIKE 'enc:v1:%';
UPDATE merchants SET cin_hash = SHA2(UPPER(TRIM(cin_llpin)), 256) WHERE cin_hash IS NULL AND cin_llpin IS NOT NULL AND cin_llpin != '' AND cin_llpin NOT LIKE 'enc:v1:%';
UPDATE merchants SET aadhaar_hash = SHA2(TRIM(aadhaar_number), 256) WHERE aadhaar_hash IS NULL AND aadhaar_number IS NOT NULL AND aadhaar_number != '' AND aadhaar_number NOT LIKE 'enc:v1:%';
