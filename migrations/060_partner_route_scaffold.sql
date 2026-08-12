-- Migration 060: Add route/split scaffold columns to partner_commercial.
-- These are save/load only — no provider API calls. Route status stays 'scaffold'
-- until a future ticket implements live API integration.

ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_mode VARCHAR(20) NOT NULL DEFAULT 'off';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_provider VARCHAR(30) NOT NULL DEFAULT 'none';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_linked_account_hint VARCHAR(120) DEFAULT NULL;
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture';
ALTER TABLE partner_commercial ADD COLUMN IF NOT EXISTS route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold';
