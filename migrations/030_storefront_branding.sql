-- Storefront visual branding: optional logo for the public sales page.
-- Mirrors ensureMerchantWebsiteEngine()/storefront schema-ensure in includes/merchant_website.php.
-- Idempotent IF NOT EXISTS — safe if runtime schema ensure already added the column.

ALTER TABLE merchant_storefronts ADD COLUMN IF NOT EXISTS logo_url VARCHAR(500) DEFAULT NULL AFTER template_key;
