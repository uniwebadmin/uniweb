ALTER TABLE admins ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL;
ALTER TABLE admins ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE admins ADD COLUMN IF NOT EXISTS mfa_enforced_at DATETIME DEFAULT NULL;
ALTER TABLE admins ADD COLUMN IF NOT EXISTS password_changed_at DATETIME DEFAULT NULL;

CREATE TABLE IF NOT EXISTS immutable_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(40) NOT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id INT DEFAULT NULL,
    action VARCHAR(80) NOT NULL,
    merchant_id INT DEFAULT NULL,
    resource_type VARCHAR(64) DEFAULT NULL,
    resource_id VARCHAR(100) DEFAULT NULL,
    reason VARCHAR(500) DEFAULT NULL,
    before_hash CHAR(64) DEFAULT NULL,
    after_hash CHAR(64) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_audit_event (event_id),
    INDEX idx_audit_action (action, created_at),
    INDEX idx_audit_merchant (merchant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incident_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_ref VARCHAR(40) NOT NULL,
    severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    title VARCHAR(190) NOT NULL,
    details TEXT NOT NULL,
    status ENUM('open','mitigating','resolved') NOT NULL DEFAULT 'open',
    opened_by INT DEFAULT NULL,
    resolved_by INT DEFAULT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_incident_ref (incident_ref),
    INDEX idx_incident_status (status, severity, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chargebacks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chargeback_ref VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    transaction_id INT DEFAULT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'razorpay',
    provider_dispute_id VARCHAR(100) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'INR',
    reason_code VARCHAR(64) DEFAULT NULL,
    reason_text VARCHAR(500) DEFAULT NULL,
    status ENUM('opened','evidence_required','submitted','won','lost','withdrawn') NOT NULL DEFAULT 'opened',
    evidence_due_at DATETIME DEFAULT NULL,
    evidence_submitted_at DATETIME DEFAULT NULL,
    evidence_notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_chargeback_ref (chargeback_ref),
    UNIQUE KEY uniq_provider_dispute (provider, provider_dispute_id),
    INDEX idx_chargeback_merchant (merchant_id, status),
    INDEX idx_chargeback_due (status, evidence_due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS recurring_mandates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mandate_ref VARCHAR(40) NOT NULL,
    merchant_id INT NOT NULL,
    customer_name VARCHAR(120) DEFAULT NULL,
    customer_vpa VARCHAR(120) DEFAULT NULL,
    amount DECIMAL(14,2) NOT NULL,
    frequency ENUM('daily','weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
    status ENUM('draft','pending_partner','active','paused','cancelled') NOT NULL DEFAULT 'draft',
    provider VARCHAR(40) DEFAULT NULL,
    provider_mandate_id VARCHAR(100) DEFAULT NULL,
    next_charge_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mandate_ref (mandate_ref),
    INDEX idx_mandate_merchant (merchant_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TRIGGER immutable_audit_prevent_update
BEFORE UPDATE ON immutable_audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit log rows are immutable';

CREATE TRIGGER immutable_audit_prevent_delete
BEFORE DELETE ON immutable_audit_log
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Audit log rows are immutable';
