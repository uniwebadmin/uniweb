-- Auto method queue: document partner webhook secret setting key (app settings table).
-- Runtime ensureMethodRequestSchema() already expands merchant_method_requests.
-- New catalog keys (nbfc, instant_settlement, payout) are code-level; no new table required.

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'method_partner_webhook_secret', ''
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'method_partner_webhook_secret');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'nbfc_partner_gateway', 'payu'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'nbfc_partner_gateway');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'instant_settlement_gateway', 'razorpay'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'instant_settlement_gateway');
