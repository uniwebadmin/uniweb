-- Open vs fixed payment links (amount_type).
-- amount=0 + amount_type=open → customer enters amount on checkout.

ALTER TABLE payment_links
  ADD COLUMN IF NOT EXISTS amount_type VARCHAR(16) NOT NULL DEFAULT 'fixed';
