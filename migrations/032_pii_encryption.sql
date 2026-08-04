-- Migration 032: widen sensitive PII columns and add last-4 display fields
-- for AES-256-GCM encrypted values. Existing plaintext rows get last4 backfilled
-- by a separate runtime backfill script because encryption needs PHP + the key.

ALTER TABLE bank_accounts
    ADD COLUMN IF NOT EXISTS account_number_last4 VARCHAR(4) DEFAULT NULL AFTER account_number,
    MODIFY COLUMN account_number VARCHAR(255) DEFAULT NULL;

ALTER TABLE payout_beneficiaries
    ADD COLUMN IF NOT EXISTS account_number_last4 VARCHAR(4) DEFAULT NULL AFTER account_number,
    MODIFY COLUMN account_number VARCHAR(255) NOT NULL;

ALTER TABLE kyc_verifications
    ADD COLUMN IF NOT EXISTS doc_number_last4 VARCHAR(4) DEFAULT NULL AFTER doc_number,
    MODIFY COLUMN doc_number VARCHAR(255) DEFAULT NULL;

-- Backfill last-4 for rows that are still plaintext (skip already-encrypted values).
UPDATE bank_accounts SET account_number_last4 = RIGHT(account_number, 4) WHERE account_number_last4 IS NULL AND account_number NOT LIKE 'enc:v1:%' AND account_number IS NOT NULL AND account_number != '';
UPDATE payout_beneficiaries SET account_number_last4 = RIGHT(account_number, 4) WHERE account_number_last4 IS NULL AND account_number NOT LIKE 'enc:v1:%' AND account_number IS NOT NULL AND account_number != '';
UPDATE kyc_verifications SET doc_number_last4 = RIGHT(doc_number, 4) WHERE doc_number_last4 IS NULL AND doc_number NOT LIKE 'enc:v1:%' AND doc_number IS NOT NULL AND doc_number != '';
