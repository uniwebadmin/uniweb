-- Customer portal ticket cross-role upgrades (016).
-- Expands message sender types for merchant/staff replies + merchant index.
-- Mirrors ensureCustomerPortalSchema() upgrades in includes/customer_portal.php.
-- Idempotent: IF NOT EXISTS on index/column; MODIFY ENUM is a no-op when already expanded.

ALTER TABLE customer_tickets ADD INDEX IF NOT EXISTS idx_merchant (merchant_id);

ALTER TABLE customer_ticket_messages
    MODIFY sender_type ENUM('customer','admin','merchant','staff') NOT NULL;

ALTER TABLE customer_ticket_messages
    ADD COLUMN IF NOT EXISTS sender_label VARCHAR(120) DEFAULT NULL AFTER sender_type;
