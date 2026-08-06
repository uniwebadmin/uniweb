-- Migration 049: Multi-GST support — user_merchant_roles table + gstin column
-- Point 4: Same PAN, different GSTINs

CREATE TABLE IF NOT EXISTS user_merchant_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_email VARCHAR(150) NOT NULL,
    user_phone VARCHAR(20) NOT NULL DEFAULT '',
    merchant_id INT NOT NULL,
    role ENUM('owner','staff') NOT NULL DEFAULT 'owner',
    invited_by INT DEFAULT NULL,
    invited_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('active','invited','revoked') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_user_merchant (user_email, merchant_id),
    INDEX idx_merchant (merchant_id),
    INDEX idx_user_phone (user_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE merchants ADD COLUMN gstin VARCHAR(15) DEFAULT NULL;
