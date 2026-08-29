-- Live money switches + intelligent routing — default OFF until Owner enables in Platform Settings.
-- Idempotent: only inserts missing keys; does not force-OFF keys already turned ON by Owner.

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'payout_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'payout_live_enabled');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'recurring_autopay_approved', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'recurring_autopay_approved');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'route_split_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'route_split_live_enabled');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'intelligent_routing_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'intelligent_routing_enabled');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'intelligent_routing_strategy', 'score'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'intelligent_routing_strategy');

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'intelligent_routing_success_window_hours', '168'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'intelligent_routing_success_window_hours');
