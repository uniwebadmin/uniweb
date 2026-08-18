-- Route / Split live switch (Phase 11 — default OFF until Owner + partner commercial + SDK).

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'route_split_live_enabled', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'route_split_live_enabled');
