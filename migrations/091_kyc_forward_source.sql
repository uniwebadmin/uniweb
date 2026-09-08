-- KYC forward queue: distinguish post-verify rows from legacy gateway_submit sync.
ALTER TABLE partner_forward_queue
    ADD COLUMN IF NOT EXISTS forward_source VARCHAR(32) NOT NULL DEFAULT 'gateway_sync' AFTER status,
    ADD COLUMN IF NOT EXISTS partner_inbound_status VARCHAR(24) DEFAULT NULL AFTER forward_source,
    ADD COLUMN IF NOT EXISTS partner_inbound_message VARCHAR(500) DEFAULT NULL AFTER partner_inbound_status,
    ADD COLUMN IF NOT EXISTS partner_inbound_at DATETIME DEFAULT NULL AFTER partner_inbound_message;
