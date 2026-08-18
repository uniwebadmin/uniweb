-- Migration 069: Keep Agents (parent_merchant_id) and Sub-Merchant hierarchy in sync.
-- Safe to re-run (idempotent).

INSERT INTO merchant_hierarchy (parent_merchant_id, child_merchant_id, relationship, status)
SELECT m.parent_merchant_id, m.id, 'franchise', 'active'
FROM merchants m
WHERE m.parent_merchant_id IS NOT NULL
  AND m.parent_merchant_id > 0
  AND m.status != 'deleted'
ON DUPLICATE KEY UPDATE
  status = 'active',
  relationship = IF(merchant_hierarchy.relationship = 'branch', VALUES(relationship), merchant_hierarchy.relationship);

UPDATE merchants m
INNER JOIN merchant_hierarchy h
  ON h.child_merchant_id = m.id AND h.status = 'active'
SET m.parent_merchant_id = h.parent_merchant_id
WHERE m.parent_merchant_id IS NULL OR m.parent_merchant_id = 0;
