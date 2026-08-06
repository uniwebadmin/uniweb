-- Migration 041: Merchant Health Score — KYC quality + dispute rate + volume + settlement regularity

CREATE TABLE IF NOT EXISTS merchant_health_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    health_score INT NOT NULL DEFAULT 0,
    kyc_quality_score INT NOT NULL DEFAULT 0,
    dispute_rate_score INT NOT NULL DEFAULT 0,
    volume_score INT NOT NULL DEFAULT 0,
    settlement_score INT NOT NULL DEFAULT 0,
    support_score INT NOT NULL DEFAULT 0,
    reasons JSON DEFAULT NULL,
    trend VARCHAR(8) DEFAULT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY idx_merchant (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
