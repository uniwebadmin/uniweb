-- Phase 2: soft-retire partners + merchant already-live link fields.
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS retired_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS retired_by VARCHAR(120) DEFAULT NULL;

ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS account_source VARCHAR(20) NOT NULL DEFAULT 'platform';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS partner_mid VARCHAR(120) DEFAULT NULL;
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS credential_status VARCHAR(20) NOT NULL DEFAULT 'missing';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS env VARCHAR(10) NOT NULL DEFAULT 'test';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS encrypted_payload TEXT DEFAULT NULL;
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS last4 VARCHAR(8) NOT NULL DEFAULT '';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS checkout_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS linked_by VARCHAR(20) NOT NULL DEFAULT 'merchant';
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS linked_by_id INT DEFAULT NULL;
ALTER TABLE partner_merchant_links ADD COLUMN IF NOT EXISTS owner_override TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX IF NOT EXISTS idx_gateway_retired ON gateway_registry (retired_at);
