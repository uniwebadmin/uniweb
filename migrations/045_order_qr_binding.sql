-- Migration 045: Add order_id binding to QR codes and enhance payment_orders
-- B1: Orders + Dynamic QR / Link bound to order_id

-- Add payment_order_id to merchant_qr_codes for direct order binding
ALTER TABLE merchant_qr_codes ADD COLUMN IF NOT EXISTS payment_order_id INT DEFAULT NULL;
ALTER TABLE merchant_qr_codes ADD INDEX IF NOT EXISTS idx_qr_order (payment_order_id);

-- Add customer fields to payment_orders for richer order context
ALTER TABLE payment_orders ADD COLUMN IF NOT EXISTS customer_note VARCHAR(500) DEFAULT NULL;
ALTER TABLE payment_orders ADD COLUMN IF NOT EXISTS source VARCHAR(32) DEFAULT 'link';
ALTER TABLE payment_orders ADD INDEX IF NOT EXISTS idx_order_merchant_status (merchant_id, status, created_at);
