-- Migration 075: Wipe legacy plaintext RBL keys from gateway_settings (Plane B only).
-- Safe to run multiple times.

UPDATE gateway_settings SET setting_value = ''
WHERE setting_key IN (
    'rbl_client_id',
    'rbl_client_secret',
    'rbl_corp_id',
    'rbl_master_account',
    'rbl_app_name',
    'rbl_base_url',
    'rbl_maker_id',
    'rbl_checker_id',
    'rbl_approver_id'
) AND setting_value IS NOT NULL AND setting_value != '';
