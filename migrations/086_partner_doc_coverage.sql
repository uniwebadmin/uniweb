-- Phase 3: persist progressive partner doc coverage on merchant-partner links.
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS coverage_status VARCHAR(32) NOT NULL DEFAULT 'not_started';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS coverage_updated_at TIMESTAMP NULL DEFAULT NULL;
