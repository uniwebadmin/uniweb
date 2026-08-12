-- Migration 059: Add event_key column to notifications for dedup/idempotency.
-- Prevents duplicate notifications for one-shot events (e.g. "Payment Pack Ready").

ALTER TABLE notifications ADD COLUMN IF NOT EXISTS event_key VARCHAR(120) DEFAULT NULL;
ALTER TABLE notifications ADD INDEX IF NOT EXISTS idx_notif_event (merchant_id, event_key);
