-- NBFC applications + live switches (defaults off until owner pastes keys).

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'nbfc_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'nbfc_live_enabled');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'payout_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'payout_live_enabled');
