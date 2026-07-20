CREATE TABLE IF NOT EXISTS payment_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_ref VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    payment_link_id INT DEFAULT NULL,
    mode ENUM('test','live') NOT NULL,
    expected_amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    provider VARCHAR(32) DEFAULT NULL,
    provider_order_id VARCHAR(120) DEFAULT NULL,
    idempotency_key VARCHAR(100) DEFAULT NULL,
    status ENUM('created','pending','authorized','paid','failed','expired','cancelled') NOT NULL DEFAULT 'created',
    description VARCHAR(255) DEFAULT NULL,
    customer_name VARCHAR(160) DEFAULT NULL,
    customer_email VARCHAR(190) DEFAULT NULL,
    customer_phone VARCHAR(32) DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_payment_order_ref (order_ref),
    UNIQUE KEY uniq_provider_order (provider, provider_order_id),
    UNIQUE KEY uniq_merchant_order_idempotency (merchant_id, mode, idempotency_key),
    INDEX idx_payment_order_link (payment_link_id),
    INDEX idx_payment_order_merchant_status (merchant_id, mode, status),
    INDEX idx_payment_order_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_order_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) NOT NULL,
    provider_payment_id VARCHAR(120) DEFAULT NULL,
    provider_order_id VARCHAR(120) DEFAULT NULL,
    amount DECIMAL(14,2) DEFAULT NULL,
    currency CHAR(3) DEFAULT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'created',
    signature_verified TINYINT(1) NOT NULL DEFAULT 0,
    provider_verified TINYINT(1) NOT NULL DEFAULT 0,
    captured TINYINT(1) NOT NULL DEFAULT 0,
    failure_code VARCHAR(100) DEFAULT NULL,
    failure_message VARCHAR(500) DEFAULT NULL,
    raw_reference VARCHAR(190) DEFAULT NULL,
    verified_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_provider_payment (provider, provider_payment_id),
    INDEX idx_attempt_order (payment_order_id),
    INDEX idx_attempt_status (provider, status),
    CONSTRAINT fk_attempt_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gateway_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(190) NOT NULL,
    event_type VARCHAR(100) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    signature_valid TINYINT(1) NOT NULL DEFAULT 0,
    processing_status ENUM('received','processed','duplicate','rejected','failed') NOT NULL DEFAULT 'received',
    payment_order_id BIGINT UNSIGNED DEFAULT NULL,
    error_message VARCHAR(500) DEFAULT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_gateway_event (provider, event_id),
    INDEX idx_gateway_event_hash (provider, payload_hash),
    INDEX idx_gateway_event_status (processing_status, received_at),
    CONSTRAINT fk_event_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ledger_accounts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_code VARCHAR(100) NOT NULL,
    owner_type ENUM('merchant','platform','provider','bank','system') NOT NULL,
    owner_id BIGINT DEFAULT NULL,
    account_type ENUM('asset','liability','revenue','expense','equity') NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    mode ENUM('test','live') NOT NULL,
    status ENUM('active','frozen','closed') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_ledger_account (account_code, currency, mode),
    INDEX idx_ledger_owner (owner_type, owner_id, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payment_order_transactions (
    payment_order_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    transaction_id INT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_order_transaction (transaction_id),
    CONSTRAINT fk_order_transaction_order FOREIGN KEY (payment_order_id) REFERENCES payment_orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ledger_journals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_ref VARCHAR(80) NOT NULL,
    business_type VARCHAR(50) NOT NULL,
    business_reference VARCHAR(190) NOT NULL,
    mode ENUM('test','live') NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    description VARCHAR(500) DEFAULT NULL,
    metadata JSON DEFAULT NULL,
    posted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_journal_ref (journal_ref),
    UNIQUE KEY uniq_business_journal (business_type, business_reference, mode),
    INDEX idx_journal_posted (posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ledger_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    journal_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    entry_side ENUM('debit','credit') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_journal_account_side (journal_id, account_id, entry_side),
    INDEX idx_ledger_entry_account (account_id, id),
    CONSTRAINT fk_entry_journal FOREIGN KEY (journal_id) REFERENCES ledger_journals(id),
    CONSTRAINT fk_entry_account FOREIGN KEY (account_id) REFERENCES ledger_accounts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_idempotency_keys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    merchant_id INT NOT NULL,
    mode ENUM('test','live') NOT NULL,
    idempotency_key VARCHAR(100) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_code SMALLINT DEFAULT NULL,
    response_body MEDIUMTEXT DEFAULT NULL,
    locked_until DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uniq_api_idempotency (merchant_id, mode, idempotency_key),
    INDEX idx_api_idempotency_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS financial_backfills (
    backfill_key VARCHAR(100) PRIMARY KEY,
    result_json JSON DEFAULT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER ledger_entries_prevent_update
BEFORE UPDATE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger entries are immutable';

CREATE TRIGGER ledger_entries_prevent_delete
BEFORE DELETE ON ledger_entries
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger entries are immutable';

CREATE TRIGGER ledger_journals_prevent_update
BEFORE UPDATE ON ledger_journals
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger journals are immutable';

CREATE TRIGGER ledger_journals_prevent_delete
BEFORE DELETE ON ledger_journals
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Ledger journals are immutable';
