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
-- P0-02: table may be missing. CREATE then ALTER (no FK — payment_orders may lag).
CREATE TABLE IF NOT EXISTS gateway_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    processing_status VARCHAR(32) NOT NULL DEFAULT 'received',
    payment_order_id BIGINT UNSIGNED DEFAULT NULL,
    provider_order_id VARCHAR(120) DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_gateway_event (provider, event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE gateway_events ADD COLUMN provider_order_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE gateway_events ADD INDEX IF NOT EXISTS idx_gateway_event_provider_order (provider_order_id);
