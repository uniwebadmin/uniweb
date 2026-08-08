-- Onboarding invites table
CREATE TABLE IF NOT EXISTS onboarding_invites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NOT NULL,
    phone VARCHAR(20) DEFAULT '',
    business_name VARCHAR(200) DEFAULT '',
    business_type VARCHAR(50) DEFAULT 'retail',
    business_entity_type VARCHAR(50) DEFAULT 'sole_proprietorship',
    note TEXT,
    created_by INT DEFAULT 0,
    used_by INT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
