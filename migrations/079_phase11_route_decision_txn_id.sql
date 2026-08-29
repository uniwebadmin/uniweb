-- Phase 11 routing log — link successful payments to route decision rows (audit correlation).

ALTER TABLE phase11_route_decisions ADD COLUMN IF NOT EXISTS txn_id VARCHAR(40) DEFAULT NULL;
ALTER TABLE phase11_route_decisions ADD INDEX IF NOT EXISTS idx_p11_txn (txn_id, created_at);
