-- Migration 070: Unify merchant MDR — merchant_pricing (M) is canonical; mirror commission_rate.
-- Safe to re-run (idempotent).

INSERT INTO merchant_pricing (merchant_id, partner_id, mdr_percent, effective_from, created_by)
SELECT m.id, NULL, m.commission_rate, CURDATE(), 'migration:070'
FROM merchants m
WHERE m.commission_rate IS NOT NULL
  AND m.commission_rate > 0
  AND m.status != 'deleted'
  AND NOT EXISTS (SELECT 1 FROM merchant_pricing mp WHERE mp.merchant_id = m.id);

UPDATE merchants m
INNER JOIN (
    SELECT mp.merchant_id, mp.mdr_percent
    FROM merchant_pricing mp
    INNER JOIN (
        SELECT merchant_id, MAX(id) AS max_id
        FROM merchant_pricing
        WHERE effective_from <= CURDATE()
        GROUP BY merchant_id
    ) latest ON latest.max_id = mp.id
) priced ON priced.merchant_id = m.id
SET m.commission_rate = priced.mdr_percent
WHERE m.commission_rate IS NULL
   OR ABS(m.commission_rate - priced.mdr_percent) > 0.0001;
