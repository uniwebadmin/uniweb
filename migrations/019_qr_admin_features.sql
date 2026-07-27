-- Migration 019: QR admin features — expiry, notifications, bulk actions support
-- Applied idempotently by Gateway Settings → Apply pending migrations

CREATE TABLE IF NOT EXISTS merchant_qr_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qr_code VARCHAR(40) NOT NULL UNIQUE,
    merchant_id INT NOT NULL,
    payment_link_id INT DEFAULT NULL,
    qr_type VARCHAR(24) NOT NULL DEFAULT 'fixed',
    label VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_test TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    expires_at DATETIME DEFAULT NULL,
    valid_from DATETIME DEFAULT NULL,
    notify_on_pay TINYINT(1) NOT NULL DEFAULT 0,
    notify_channels VARCHAR(255) DEFAULT NULL,
    print_template VARCHAR(32) NOT NULL DEFAULT 'default',
    category VARCHAR(64) DEFAULT NULL,
    scan_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_qr_merchant (merchant_id, status, created_at),
    INDEX idx_qr_payment_link (payment_link_id),
    INDEX idx_qr_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE merchant_qr_codes
    ADD COLUMN IF NOT EXISTS expires_at DATETIME DEFAULT NULL AFTER status,
    ADD COLUMN IF NOT EXISTS valid_from DATETIME DEFAULT NULL AFTER expires_at,
    ADD COLUMN IF NOT EXISTS notify_on_pay TINYINT(1) NOT NULL DEFAULT 0 AFTER valid_from,
    ADD COLUMN IF NOT EXISTS notify_channels VARCHAR(255) DEFAULT NULL AFTER notify_on_pay,
    ADD COLUMN IF NOT EXISTS print_template VARCHAR(32) NOT NULL DEFAULT 'default' AFTER notify_channels,
    ADD COLUMN IF NOT EXISTS category VARCHAR(64) DEFAULT NULL AFTER print_template;

ALTER TABLE payment_links ADD COLUMN IF NOT EXISTS qr_code_id INT UNSIGNED DEFAULT NULL;
ALTER TABLE payment_links ADD INDEX IF NOT EXISTS idx_links_qr_code (qr_code_id);

CREATE TABLE IF NOT EXISTS qr_code_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qr_code_id INT UNSIGNED NOT NULL,
    merchant_id INT UNSIGNED NOT NULL,
    event_type ENUM('scan','payment','share','print','download','enable','disable','edit','duplicate','delete','expired') NOT NULL,
    event_data JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_qr_event_qr (qr_code_id, created_at),
    INDEX idx_qr_event_merchant (merchant_id, event_type, created_at),
    INDEX idx_qr_event_type (event_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
