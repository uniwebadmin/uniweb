-- Migration 050: Add qr_code_id to transactions for direct QR payment attribution
-- Point 5: P2M QR — webhook resolve + analytics

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS qr_code_id INT UNSIGNED DEFAULT NULL AFTER payment_link_id;
ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_txn_qr_code (qr_code_id);
