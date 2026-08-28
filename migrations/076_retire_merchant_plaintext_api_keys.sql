-- Merchant API auth uses api_credentials (key_hash + secret_hash) only.
-- Wipe legacy plaintext columns on merchants; PHP ensureMerchantApiKeys backfills missing rows on next portal visit.
UPDATE merchants
SET api_key = NULL,
    api_secret = NULL,
    test_api_key = NULL,
    test_api_secret = NULL
WHERE (api_key IS NOT NULL AND api_key != '')
   OR (api_secret IS NOT NULL AND api_secret != '')
   OR (test_api_key IS NOT NULL AND test_api_key != '')
   OR (test_api_secret IS NOT NULL AND test_api_secret != '');
