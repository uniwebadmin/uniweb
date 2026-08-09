-- 040_partner_control_plane.sql
-- Block B: Partner data model — credentials, methods, merchant links, reason maps

-- Extend gateway_registry with sort_order (partners table)
ALTER TABLE gateway_registry ADD COLUMN sort_order INT NOT NULL DEFAULT 99;

-- Encrypted partner credentials (test/live env separated)
CREATE TABLE IF NOT EXISTS partner_credentials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL,
    env ENUM('test','live') NOT NULL DEFAULT 'test',
    encrypted_payload TEXT NOT NULL,
    last4 VARCHAR(8) NOT NULL DEFAULT '',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_env (partner_key, env),
    INDEX idx_partner (partner_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-partner payment methods with enable/disable + priority + amount limits
CREATE TABLE IF NOT EXISTS partner_methods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL,
    method VARCHAR(40) NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    priority INT NOT NULL DEFAULT 50,
    min_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
    max_amt DECIMAL(14,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_method (partner_key, method),
    INDEX idx_partner_enabled (partner_key, is_enabled)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Merchant ↔ partner external links (KYC forwarding, vendor/child accounts)
CREATE TABLE IF NOT EXISTS partner_merchant_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    partner_key VARCHAR(40) NOT NULL,
    external_id VARCHAR(120) DEFAULT NULL,
    kyc_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    live_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_merchant_partner (merchant_id, partner_key),
    INDEX idx_partner (partner_key, kyc_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gateway failure reason maps (partner-specific error code → human message)
CREATE TABLE IF NOT EXISTS gateway_reason_maps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    partner_key VARCHAR(40) NOT NULL,
    raw_code VARCHAR(120) NOT NULL,
    msg_en VARCHAR(500) NOT NULL DEFAULT '',
    msg_hi VARCHAR(500) NOT NULL DEFAULT '',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_partner_code (partner_key, raw_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
