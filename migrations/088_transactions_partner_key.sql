-- Migration 088: Canonical partner identity on money-path transactions (Phase R2)
-- partner_key = Registry partner that collected; payment_method = collect rail (upi, card, …)

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS partner_key VARCHAR(40) DEFAULT NULL AFTER payment_method;
ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_txn_partner_key (partner_key);

-- Best-effort backfill from legacy payment_method / bound orders
UPDATE transactions t
LEFT JOIN payment_order_transactions pot ON pot.transaction_id = t.id
LEFT JOIN payment_orders po ON po.id = pot.payment_order_id
SET t.partner_key = COALESCE(
    NULLIF(LOWER(TRIM(po.provider)), ''),
    CASE
        WHEN LOWER(TRIM(t.payment_method)) IN ('razorpay','cashfree','payu','decentro','rbl','axis','worldline','ccavenue','sandbox','payu_split')
            THEN LOWER(TRIM(t.payment_method))
        WHEN LOWER(TRIM(t.payment_method)) LIKE 'razorpay%' THEN 'razorpay'
        WHEN LOWER(TRIM(t.payment_method)) LIKE 'cashfree%' THEN 'cashfree'
        WHEN LOWER(TRIM(t.payment_method)) LIKE 'payu%' THEN 'payu'
        WHEN LOWER(TRIM(t.payment_method)) IN ('rbl_va','rbl_upi') THEN 'rbl'
        WHEN LOWER(TRIM(t.payment_method)) IN ('axis_va','axis_upi') THEN 'axis'
        ELSE NULL
    END
)
WHERE t.partner_key IS NULL OR TRIM(t.partner_key) = '';
