-- Partner Registry v2 — Global Control Room fields (online collect partners only).
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS partner_type ENUM('pg','other_online') NOT NULL DEFAULT 'pg';
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS contract_mode ENUM('platform','linked_existing','hybrid') NOT NULL DEFAULT 'platform';
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS allows_existing_merchant_link TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_collect TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_upi TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_card TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_netbanking TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_refund TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_pay_later TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS cap_kyc_forward_api TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS doc_pack_json TEXT DEFAULT NULL;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS policy_urls_json TEXT DEFAULT NULL;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS routing_priority INT NOT NULL DEFAULT 50;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS circuit_breaker_on TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS connector_notes VARCHAR(255) DEFAULT NULL;
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS credential_test_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing';
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS credential_live_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing';
ALTER TABLE gateway_registry ADD COLUMN IF NOT EXISTS display_description VARCHAR(500) DEFAULT NULL;

UPDATE gateway_registry SET cap_refund = supports_refund WHERE cap_refund = 0 AND supports_refund = 1;
UPDATE gateway_registry SET cap_collect = supports_collection WHERE registry_kind = 'partner' AND cap_collect = 0 AND supports_collection = 1;
