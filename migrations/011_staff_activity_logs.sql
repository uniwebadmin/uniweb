-- Staff assignments + activity log. Mirrors runtime DDL in includes/staff.php
-- (ensureStaffRoles) so the tables exist on live without request-time DDL.

CREATE TABLE IF NOT EXISTS staff_merchant_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    merchant_id INT NOT NULL,
    assigned_by INT NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_staff_merchant (admin_id, merchant_id),
    INDEX (merchant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(64) NOT NULL,
    details TEXT NULL,
    merchant_id INT NULL,
    reference_type VARCHAR(32) NULL,
    reference_id VARCHAR(64) NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (admin_id),
    INDEX (merchant_id),
    INDEX (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
