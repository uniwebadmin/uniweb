-- Migration 074: Wipe legacy plaintext partner API keys from gateway_settings (Plane B only).
-- Safe to run multiple times; only clears known legacy partner secret keys.

UPDATE gateway_settings SET setting_value = ''
WHERE setting_key IN (
    'decentro_api_key',
    'decentro_api_secret',
    'pinelabs_api_key',
    'pinelabs_api_secret',
    'axis_api_key',
    'axis_api_secret'
) AND setting_value IS NOT NULL AND setting_value != '';
