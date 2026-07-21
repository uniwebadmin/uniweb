-- LOCAL DEV schema for UniWeb (reconstructed for Cursor Cloud dev environment).
-- The real production schema lives in gitignored migrations/*.sql (not in repo).
-- This creates the core tables so the app can boot; runtime ensure*() helpers
-- create the remaining secondary tables on demand.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS gateway_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS merchants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_code VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(190) NOT NULL DEFAULT '',
    email VARCHAR(190) NOT NULL,
    phone VARCHAR(30) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL,
    business_name VARCHAR(190) DEFAULT '',
    business_type VARCHAR(50) DEFAULT 'retail',
    business_entity_type VARCHAR(50) DEFAULT 'sole_proprietorship',
    pan_number VARCHAR(15) DEFAULT NULL,
    aadhaar_number VARCHAR(20) DEFAULT NULL,
    cin_llpin VARCHAR(30) DEFAULT NULL,
    address VARCHAR(255) DEFAULT '',
    country VARCHAR(60) DEFAULT 'India',
    state VARCHAR(80) DEFAULT '',
    district VARCHAR(80) DEFAULT '',
    city VARCHAR(80) DEFAULT '',
    pincode VARCHAR(12) DEFAULT '',
    api_key VARCHAR(120) DEFAULT NULL,
    api_secret VARCHAR(160) DEFAULT NULL,
    test_api_key VARCHAR(120) DEFAULT NULL,
    test_api_secret VARCHAR(160) DEFAULT NULL,
    upi_id VARCHAR(120) DEFAULT NULL,
    kyc_status VARCHAR(20) DEFAULT 'pending',
    video_kyc_status VARCHAR(20) DEFAULT 'pending',
    account_mode VARCHAR(10) DEFAULT 'test',
    collection_mode VARCHAR(30) DEFAULT 'direct_upi',
    commission_rate DECIMAL(5,2) DEFAULT 0.50,
    agent_commission DECIMAL(5,2) DEFAULT 0.50,
    parent_merchant_id INT DEFAULT NULL,
    provision_profile VARCHAR(30) DEFAULT 'minimal',
    website_url VARCHAR(255) DEFAULT NULL,
    website_status VARCHAR(20) DEFAULT 'pending',
    bank_verified TINYINT(1) DEFAULT 0,
    totp_enabled TINYINT(1) DEFAULT 0,
    totp_secret VARCHAR(64) DEFAULT NULL,
    wallet_balance DECIMAL(14,2) DEFAULT 0.00,
    status VARCHAR(20) DEFAULT 'active',
    deleted_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(190) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(120) DEFAULT '',
    role VARCHAR(30) DEFAULT 'super',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME DEFAULT NULL,
    last_login_ip VARCHAR(45) DEFAULT NULL,
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_links (
    id INT AUTO_INCREMENT PRIMARY KEY,
    link_id VARCHAR(40) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    amount DECIMAL(12,2) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(160) DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    payment_method VARCHAR(40) DEFAULT NULL,
    gateway_code VARCHAR(40) DEFAULT NULL,
    link_label VARCHAR(120) DEFAULT NULL,
    link_collection_mode VARCHAR(30) DEFAULT NULL,
    qr_code_id INT UNSIGNED DEFAULT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    expires_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, status),
    INDEX idx_links_qr_code (qr_code_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id VARCHAR(50) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    payment_link_id INT DEFAULT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    fee DECIMAL(12,2) DEFAULT 0,
    net_amount DECIMAL(12,2) DEFAULT 0,
    currency VARCHAR(8) DEFAULT 'INR',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(40) DEFAULT NULL,
    gateway VARCHAR(40) DEFAULT NULL,
    gateway_txn_id VARCHAR(120) DEFAULT NULL,
    customer_name VARCHAR(160) DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    customer_phone VARCHAR(30) DEFAULT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 1,
    settled TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, is_test, status),
    INDEX idx_status (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    title VARCHAR(190) NOT NULL,
    message TEXT,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bank_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    bank_name VARCHAR(120) DEFAULT NULL,
    account_holder VARCHAR(160) DEFAULT NULL,
    account_number VARCHAR(40) DEFAULT NULL,
    ifsc_code VARCHAR(20) DEFAULT NULL,
    account_type VARCHAR(20) DEFAULT 'current',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settlements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    settlement_id VARCHAR(50) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    bank_account_id INT DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    fee DECIMAL(14,2) NOT NULL DEFAULT 0,
    net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    utr VARCHAR(60) DEFAULT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS kyc_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    doc_type VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) DEFAULT NULL,
    file_path VARCHAR(500) DEFAULT NULL,
    storage_key VARCHAR(255) DEFAULT NULL,
    sha256 CHAR(64) DEFAULT NULL,
    mime_type VARCHAR(100) DEFAULT NULL,
    file_size INT DEFAULT NULL,
    scan_status VARCHAR(20) DEFAULT 'pending',
    status VARCHAR(20) DEFAULT 'pending',
    retention_until DATE DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_merchant (merchant_id, doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- wallet_transactions and platform_wallet_transactions are created by the real
-- migrations (migrations/002_legacy_wallet_baseline.sql) — do not create them here.

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS platform_fee DECIMAL(12,2) DEFAULT 0;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS split_amount DECIMAL(12,2) DEFAULT 0;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS wallet_credited TINYINT(1) DEFAULT 0;

-- Additional merchant columns exercised by checkout / gateway routing (dev schema)
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS axis_va_number VARCHAR(40) DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS axis_va_ifsc VARCHAR(20) DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS axis_va_upi VARCHAR(120) DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS payu_child_key VARCHAR(120) DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS razorpay_linked_account_id VARCHAR(120) DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS cashfree_vendor_id VARCHAR(120) DEFAULT NULL;
