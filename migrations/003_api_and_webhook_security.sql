CREATE TABLE IF NOT EXISTS api_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    credential_name VARCHAR(100) NOT NULL DEFAULT 'Default',
    mode ENUM('test','live') NOT NULL,
    key_prefix VARCHAR(20) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    secret_hash VARCHAR(255) NOT NULL,
    scopes JSON NOT NULL,
    allowed_origins JSON DEFAULT NULL,
    status ENUM('active','revoked','expired') NOT NULL DEFAULT 'active',
    last_used_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_api_key_hash (key_hash),
    INDEX idx_api_merchant_mode (merchant_id, mode, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_rate_limits (
    credential_id BIGINT UNSIGNED NOT NULL,
    window_started_at DATETIME NOT NULL,
    request_count INT NOT NULL DEFAULT 0,
    PRIMARY KEY (credential_id),
    CONSTRAINT fk_rate_credential FOREIGN KEY (credential_id) REFERENCES api_credentials(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS merchant_webhook_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(80) NOT NULL,
    merchant_id INT NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    destination_url VARCHAR(500) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status ENUM('queued','delivering','delivered','retry','failed','dead') NOT NULL DEFAULT 'queued',
    attempt_count INT NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME DEFAULT NULL,
    response_code INT DEFAULT NULL,
    response_body VARCHAR(2000) DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    delivered_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_merchant_webhook_event (merchant_id, event_id),
    INDEX idx_webhook_queue (status, next_attempt_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
