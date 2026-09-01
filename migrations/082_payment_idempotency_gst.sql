-- Point #1: platform wallet — one credit row per success transaction_id
-- Point #14: gst_on_fee on transactions (canonical capture + reports)

ALTER TABLE transactions ADD COLUMN IF NOT EXISTS gst_on_fee DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER platform_fee;

-- Remove duplicate platform wallet credits before unique index (keep lowest id per txn)
DELETE pwt FROM platform_wallet_transactions pwt
INNER JOIN (
    SELECT transaction_id, MIN(id) AS keep_id
    FROM platform_wallet_transactions
    WHERE transaction_id IS NOT NULL AND transaction_id > 0 AND amount > 0
    GROUP BY transaction_id
    HAVING COUNT(*) > 1
) d ON d.transaction_id = pwt.transaction_id AND pwt.id != d.keep_id
WHERE pwt.transaction_id IS NOT NULL AND pwt.amount > 0;

ALTER TABLE platform_wallet_transactions
    ADD UNIQUE KEY uniq_platform_wallet_txn (transaction_id);
