-- Migration 071: Canonical VA in merchant_virtual_accounts; sync merchants.axis_va_* mirror.
-- Safe to re-run (idempotent).

INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
SELECT m.id, 'axis', m.axis_va_id, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, 'Primary', 'active', 1, CURDATE()
FROM merchants m
WHERE m.axis_va_number IS NOT NULL AND m.axis_va_number != ''
  AND NOT EXISTS (
    SELECT 1 FROM merchant_virtual_accounts v WHERE v.va_number = m.axis_va_number
  );

UPDATE merchants m
INNER JOIN (
    SELECT v.merchant_id, v.va_id, v.va_number, v.ifsc, v.upi_id
    FROM merchant_virtual_accounts v
    INNER JOIN (
        SELECT merchant_id, MIN(id) AS pick_id
        FROM merchant_virtual_accounts
        WHERE status = 'active'
        GROUP BY merchant_id
    ) pick ON pick.pick_id = v.id
) primary_va ON primary_va.merchant_id = m.id
SET m.axis_va_id = primary_va.va_id,
    m.axis_va_number = primary_va.va_number,
    m.axis_va_ifsc = primary_va.ifsc,
    m.axis_va_upi = primary_va.upi_id;

UPDATE merchants m
SET m.axis_va_id = NULL, m.axis_va_number = NULL, m.axis_va_ifsc = NULL, m.axis_va_upi = NULL
WHERE NOT EXISTS (
    SELECT 1 FROM merchant_virtual_accounts v
    WHERE v.merchant_id = m.id AND v.status = 'active'
)
AND (m.axis_va_number IS NOT NULL AND m.axis_va_number != '');
