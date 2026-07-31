-- Migration 031: Multiple Virtual Accounts per merchant + smart-assignment counters
-- Backward compatible: merchants.axis_va_number/etc stay as the "primary" VA for
-- existing code paths. This table lets a merchant hold MANY VAs so payment load
-- can be spread across them instead of a single VA becoming a bottleneck.

CREATE TABLE IF NOT EXISTS merchant_virtual_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    gateway VARCHAR(32) NOT NULL DEFAULT 'axis',
    va_id VARCHAR(64) DEFAULT NULL,
    va_number VARCHAR(64) NOT NULL,
    ifsc VARCHAR(20) DEFAULT NULL,
    upi_id VARCHAR(120) DEFAULT NULL,
    label VARCHAR(120) DEFAULT NULL,
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    txn_count_today INT UNSIGNED NOT NULL DEFAULT 0,
    txn_count_total INT UNSIGNED NOT NULL DEFAULT 0,
    fail_count_today INT UNSIGNED NOT NULL DEFAULT 0,
    last_assigned_at DATETIME DEFAULT NULL,
    counters_reset_on DATE DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_va_number (va_number),
    INDEX idx_va_merchant (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE merchant_qr_codes
    ADD COLUMN IF NOT EXISTS virtual_account_id INT UNSIGNED DEFAULT NULL AFTER payment_link_id;
ALTER TABLE merchant_qr_codes ADD INDEX IF NOT EXISTS idx_qr_va (virtual_account_id);

-- Backfill: any merchant that already has a single Axis VA gets a matching
-- primary row here so it shows up in the multi-VA admin UI immediately.
INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
SELECT m.id, 'axis', m.axis_va_id, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, 'Primary', 'active', 1, CURDATE()
FROM merchants m
WHERE m.axis_va_number IS NOT NULL AND m.axis_va_number != ''
  AND NOT EXISTS (SELECT 1 FROM merchant_virtual_accounts v WHERE v.va_number = m.axis_va_number);
