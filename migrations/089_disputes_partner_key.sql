-- Migration 089: Dispute identity tied to txn partner_key (Phase R3)

ALTER TABLE disputes ADD COLUMN IF NOT EXISTS partner_key VARCHAR(40) DEFAULT NULL AFTER transaction_id;
ALTER TABLE disputes ADD INDEX IF NOT EXISTS idx_dispute_partner_key (partner_key);

UPDATE disputes d
JOIN transactions t ON t.id = d.transaction_id
SET d.partner_key = COALESCE(NULLIF(LOWER(TRIM(t.partner_key)), ''), d.partner_key)
WHERE d.partner_key IS NULL OR TRIM(d.partner_key) = '';
