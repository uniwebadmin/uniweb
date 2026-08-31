-- Payment reconcile metadata on transactions (webhook | poll | checkout | manual | reconcile)
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS last_reconcile_source VARCHAR(16) DEFAULT NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS last_reconcile_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS partner_event_ref VARCHAR(128) DEFAULT NULL;
