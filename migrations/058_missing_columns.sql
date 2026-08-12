-- Migration 058: Add missing columns that code references but schema drifted on production.
-- Idempotent: uses ADD COLUMN IF NOT EXISTS (MariaDB) or silent failure (MySQL via runtime ensure).

-- 1. platform_settlements: mode, bank_account, processed_by (referenced by wallet.php INSERT)
ALTER TABLE platform_settlements ADD COLUMN IF NOT EXISTS mode VARCHAR(20) DEFAULT 'manual';
ALTER TABLE platform_settlements ADD COLUMN IF NOT EXISTS bank_account VARCHAR(30) DEFAULT NULL;
ALTER TABLE platform_settlements ADD COLUMN IF NOT EXISTS processed_by VARCHAR(120) DEFAULT NULL;

-- 2. payout_beneficiaries: account_number_last4 (referenced by payout.php INSERT/UPDATE)
ALTER TABLE payout_beneficiaries ADD COLUMN IF NOT EXISTS account_number_last4 VARCHAR(8) DEFAULT NULL;

-- 3. payout_orders: processed_at, utr (referenced by payout.php UPDATE on success)
ALTER TABLE payout_orders ADD COLUMN IF NOT EXISTS processed_at DATETIME DEFAULT NULL;
ALTER TABLE payout_orders ADD COLUMN IF NOT EXISTS utr VARCHAR(60) DEFAULT NULL;

-- 4. gateway_events: provider_order_id (referenced by evidence_pack.php JOIN)
ALTER TABLE gateway_events ADD COLUMN IF NOT EXISTS provider_order_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE gateway_events ADD INDEX IF NOT EXISTS idx_gateway_event_provider_order (provider_order_id);
