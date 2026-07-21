-- Customer portal ticket cross-role upgrades (016).
-- Expands message sender types for merchant/staff replies + merchant index.
-- Mirrors ensureCustomerPortalSchema() upgrades in includes/customer_portal.php.

ALTER TABLE customer_tickets ADD INDEX idx_merchant (merchant_id);

ALTER TABLE customer_ticket_messages
    MODIFY sender_type ENUM('customer','admin','merchant','staff') NOT NULL;

ALTER TABLE customer_ticket_messages
    ADD COLUMN sender_label VARCHAR(120) DEFAULT NULL AFTER sender_type;
