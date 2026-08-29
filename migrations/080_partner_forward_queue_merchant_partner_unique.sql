-- Per-partner forward queue rows (merchant + partner_key). Legacy uniq_merchant_forward
-- is dropped automatically by partnerForwardQueueUpgradeLegacySchema() on first queue access.
ALTER TABLE partner_forward_queue ADD UNIQUE KEY uniq_merchant_partner (merchant_id, partner_key);
