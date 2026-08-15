-- Migration 060: Add route/split scaffold columns to partner_commercial.
-- These are save/load only — no provider API calls. Route status stays 'scaffold'
-- until a future ticket implements live API integration.

-- P0-02: table may be missing. CREATE then ALTER (044-style).
CREATE TABLE IF NOT EXISTS partner_commercial (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL UNIQUE,
    base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
    settlement_mode VARCHAR(40) NOT NULL DEFAULT 'standard_settle_mode',
    route_enabled TINYINT(1) NOT NULL DEFAULT 0,
    route_mode VARCHAR(20) NOT NULL DEFAULT 'off',
    route_provider VARCHAR(30) NOT NULL DEFAULT 'none',
    route_linked_account_hint VARCHAR(120) DEFAULT NULL,
    route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture',
    route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold',
    updated_by VARCHAR(60) NOT NULL DEFAULT 'system',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE partner_commercial ADD COLUMN partner_key VARCHAR(40) NOT NULL DEFAULT '';
ALTER TABLE partner_commercial ADD COLUMN base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0;

ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_mode VARCHAR(20) NOT NULL DEFAULT 'off';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_provider VARCHAR(30) NOT NULL DEFAULT 'none';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_linked_account_hint VARCHAR(120) DEFAULT NULL;
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold';
