-- Method request: Admin → Partner → Final Enable workflow columns.
-- Status values (VARCHAR): pending, sent_to_partner, partner_approved, partner_rejected, approved, rejected

ALTER TABLE merchant_method_requests MODIFY status VARCHAR(32) NOT NULL DEFAULT 'pending';
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS partner_gateway VARCHAR(40) DEFAULT NULL;
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS partner_ref VARCHAR(120) DEFAULT NULL;
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS partner_note VARCHAR(500) DEFAULT NULL;
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS hold_until DATETIME DEFAULT NULL;
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS sent_to_partner_at DATETIME DEFAULT NULL;
ALTER TABLE merchant_method_requests ADD COLUMN IF NOT EXISTS partner_responded_at DATETIME DEFAULT NULL;
