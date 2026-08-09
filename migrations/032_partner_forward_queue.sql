-- partner_forward_queue: KYC packages queued for submission to partner gateways
CREATE TABLE IF NOT EXISTS partner_forward_queue (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    partner_key VARCHAR(40) NOT NULL,
    package_payload LONGTEXT DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'queued',
    schedule_at DATETIME NOT NULL,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 3,
    last_attempt_at DATETIME DEFAULT NULL,
    partner_reference VARCHAR(100) DEFAULT NULL,
    partner_response LONGTEXT DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pfq_status (status, schedule_at),
    INDEX idx_pfq_merchant (merchant_id, status),
    INDEX idx_pfq_partner (partner_key, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
