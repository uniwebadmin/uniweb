-- Migration 046: Add expired_at to payment_orders for B2 status machine
ALTER TABLE payment_orders ADD COLUMN IF NOT EXISTS expired_at DATETIME DEFAULT NULL;
ALTER TABLE payment_orders ADD INDEX IF NOT EXISTS idx_order_expires (status, expires_at);
