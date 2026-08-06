-- Migration 040: Grievance Redressal — escalation, SLA, monthly report
-- Based on RBI Payment Aggregator guidelines

CREATE TABLE IF NOT EXISTS grievance_complaints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id VARCHAR(32) NOT NULL UNIQUE,
    merchant_id INT DEFAULT NULL,
    customer_name VARCHAR(128) DEFAULT NULL,
    customer_email VARCHAR(255) DEFAULT NULL,
    customer_phone VARCHAR(32) DEFAULT NULL,
    transaction_id INT DEFAULT NULL,
    category ENUM('payment_failure','refund_delay','unauthorized_txn','settlement_delay','kyc_issue','tech_issue','other') NOT NULL DEFAULT 'other',
    subject VARCHAR(255) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('open','acknowledged','in_progress','escalated_l1','escalated_l2','resolved','rejected','closed') NOT NULL DEFAULT 'open',
    priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    escalation_level TINYINT NOT NULL DEFAULT 0,
    assigned_to INT DEFAULT NULL,
    sla_deadline DATETIME DEFAULT NULL,
    acknowledged_at TIMESTAMP NULL DEFAULT NULL,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    resolution_note TEXT DEFAULT NULL,
    resolution_category ENUM('resolved','partially_resolved','not_resolved','invalid') DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_merchant (merchant_id),
    INDEX idx_escalation (escalation_level),
    INDEX idx_sla (sla_deadline, status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS grievance_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT NOT NULL,
    action_type ENUM('created','acknowledged','replied','escalated','resolved','rejected','closed','reopened','note') NOT NULL DEFAULT 'note',
    action_by INT DEFAULT NULL,
    action_by_type ENUM('merchant','customer','staff','system') NOT NULL DEFAULT 'system',
    message TEXT DEFAULT NULL,
    old_status VARCHAR(32) DEFAULT NULL,
    new_status VARCHAR(32) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_complaint (complaint_id),
    INDEX idx_type (action_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
