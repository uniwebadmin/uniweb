-- Merchant -> Admin "Request to Enable" payment method workflow.
-- Mirrors ensureMethodRequestSchema() in includes/method_requests.php.

CREATE TABLE IF NOT EXISTS merchant_method_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    method_key VARCHAR(40) NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    merchant_note VARCHAR(500) DEFAULT NULL,
    admin_note VARCHAR(500) DEFAULT NULL,
    decided_by VARCHAR(120) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME DEFAULT NULL,
    INDEX idx_mmr_merchant (merchant_id),
    INDEX idx_mmr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
