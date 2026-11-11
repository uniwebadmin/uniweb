CREATE TABLE IF NOT EXISTS fee_rules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL,
    method_key VARCHAR(40) NOT NULL DEFAULT '*',
    merchant_tier VARCHAR(40) NOT NULL DEFAULT '*',
    platform_fee_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    platform_fee_flat DECIMAL(12,2) NOT NULL DEFAULT 0,
    merchant_fee_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fee_rule (partner_key, method_key, merchant_tier),
    KEY idx_fee_rule_lookup (partner_key, method_key, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transaction_fee_snapshots (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT UNSIGNED NOT NULL,
    merchant_id INT UNSIGNED NOT NULL,
    partner_key VARCHAR(40) NOT NULL,
    method_key VARCHAR(40) NOT NULL,
    mode VARCHAR(12) NOT NULL DEFAULT 'live',
    amount DECIMAL(12,2) NOT NULL,
    platform_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    merchant_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
    rule_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transaction_fee_snapshot (transaction_id),
    KEY idx_fee_snapshot_rollup (partner_key, mode, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fee_invoice_drafts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
    platform_fee_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    status ENUM('draft','sent','paid') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fee_invoice_period (partner_key, period_start, period_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
