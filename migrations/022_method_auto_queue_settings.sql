-- Auto method queue: document partner webhook secret setting key (app settings table).
-- Runtime ensureMethodRequestSchema() already expands merchant_method_requests.
-- New catalog keys (nbfc, instant_settlement, payout) are code-level; no new table required.

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
SELECT 'method_partner_webhook_secret', ''
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'method_partner_webhook_secret');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'nbfc_partner_gateway', 'payu'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'nbfc_partner_gateway');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'instant_settlement_gateway', 'razorpay'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'instant_settlement_gateway');
