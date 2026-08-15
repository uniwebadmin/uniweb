-- NBFC applications + live switches (defaults off until owner pastes keys).

-- 044-style: CREATE + ALTER before INSERT.
CREATE TABLE IF NOT EXISTS gateway_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE gateway_settings ADD COLUMN setting_key VARCHAR(100) NOT NULL DEFAULT '';
ALTER TABLE gateway_settings ADD COLUMN setting_value TEXT;

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'nbfc_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'nbfc_live_enabled');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'payout_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'payout_live_enabled');
