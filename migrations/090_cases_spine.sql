-- Cases spine: partner inbound events + forward columns on tickets/complaints
CREATE TABLE IF NOT EXISTS case_partner_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_type ENUM('support_ticket','customer_complaint','dispute') NOT NULL,
    case_db_id INT UNSIGNED NOT NULL,
    case_ref VARCHAR(40) NOT NULL,
    merchant_id INT UNSIGNED DEFAULT NULL,
    partner_key VARCHAR(40) DEFAULT NULL,
    event_type ENUM('forwarded','not_wired','need_info','partner_query','partner_reply') NOT NULL,
    message TEXT NOT NULL,
    created_by_type ENUM('admin','merchant','partner_manual','system') NOT NULL DEFAULT 'admin',
    created_by_id INT UNSIGNED DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_case (case_type, case_db_id),
    KEY idx_merchant (merchant_id),
    KEY idx_ref (case_ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
