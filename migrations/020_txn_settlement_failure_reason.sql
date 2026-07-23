<?php
-- Exact reason polish: store partner-mapped failure text on txn + settlement rows.
ALTER TABLE transactions ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) DEFAULT NULL;
ALTER TABLE settlements ADD COLUMN IF NOT EXISTS failure_reason VARCHAR(500) DEFAULT NULL;
ALTER TABLE settlements ADD COLUMN IF NOT EXISTS api_message VARCHAR(255) DEFAULT NULL;
