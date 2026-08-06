-- Migration 042: Webhook reliability — retry queue, idempotency, dead letter

CREATE TABLE IF NOT EXISTS webhook_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(128) NOT NULL,
    gateway VARCHAR(32) NOT NULL,
    event_type VARCHAR(64) DEFAULT NULL,
    payload LONGTEXT,
    signature VARCHAR(255) DEFAULT NULL,
    status ENUM('received','processing','completed','failed','dead_letter') NOT NULL DEFAULT 'received',
    retry_count INT NOT NULL DEFAULT 0,
    max_retries INT NOT NULL DEFAULT 5,
    last_error TEXT DEFAULT NULL,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    next_retry_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_event_id (event_id),
    INDEX idx_status_retry (status, next_retry_at),
    INDEX idx_gateway (gateway, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
