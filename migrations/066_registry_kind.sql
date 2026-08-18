-- Split payment methods vs partners inside gateway_registry (same table, explicit kind).
ALTER TABLE gateway_registry
    ADD COLUMN IF NOT EXISTS registry_kind ENUM('method','partner') NOT NULL DEFAULT 'partner';

UPDATE gateway_registry SET registry_kind = 'method'
WHERE gateway_key IN (
    'upi_p2m', 'qr_code', 'credit_card', 'debit_card', 'net_banking', 'netbanking',
    'wallet', 'emi', 'payout', 'recurring'
);

UPDATE gateway_registry SET registry_kind = 'partner'
WHERE gateway_key NOT IN (
    'upi_p2m', 'qr_code', 'credit_card', 'debit_card', 'net_banking', 'netbanking',
    'wallet', 'emi', 'payout', 'recurring'
);
