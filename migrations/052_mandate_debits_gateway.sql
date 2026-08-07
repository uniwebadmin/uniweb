-- Migration 052: Add gateway_ref and gateway_response to mandate_debits
-- Required for recurring mandate debit gateway integration

ALTER TABLE mandate_debits
    ADD COLUMN gateway_ref VARCHAR(128) DEFAULT NULL AFTER gateway_order_id,
    ADD COLUMN gateway_response JSON DEFAULT NULL AFTER gateway_ref,
    ADD COLUMN processed_at DATETIME DEFAULT NULL AFTER attempted_at,
    ADD INDEX idx_debit_gateway_ref (gateway_ref);

ALTER TABLE mandate_debits
    MODIFY COLUMN status ENUM('pending','processing','success','failed','retried') NOT NULL DEFAULT 'pending';
