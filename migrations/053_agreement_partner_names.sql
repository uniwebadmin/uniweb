-- Migration 053: Add partner_names to merchant_agreement_acceptances
-- Tracks which partner gateways were included when the agreement was signed
-- When a new partner approves, merchant must re-sign with updated partner list

ALTER TABLE merchant_agreement_acceptances
    ADD COLUMN partner_names VARCHAR(500) DEFAULT NULL,
    ADD COLUMN requires_resign TINYINT(1) NOT NULL DEFAULT 0;

-- Backfill: existing acceptances had no partner tracking
UPDATE merchant_agreement_acceptances SET partner_names = '' WHERE partner_names IS NULL;
