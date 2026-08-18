-- Recurring / AutoPay live switch (default off until Admin enables after partner product approval).

INSERT INTO gateway_settings (setting_key, setting_value)
SELECT 'recurring_autopay_approved', '0'
WHERE NOT EXISTS (SELECT 1 FROM gateway_settings WHERE setting_key = 'recurring_autopay_approved');
