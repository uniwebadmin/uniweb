-- Migration 068: Remove mistaken sapaisa / SavaPay partner row (not a real UniWeb rail).
-- Safe: only deletes gateway_key=sapaisa when inactive.

DELETE FROM partner_methods WHERE partner_key = 'sapaisa';
DELETE FROM partner_credentials WHERE partner_key = 'sapaisa';
DELETE FROM gateway_reason_maps WHERE partner_key = 'sapaisa';

DELETE gm FROM gateway_method_map gm
INNER JOIN gateway_registry gr ON gr.id = gm.gateway_id
WHERE gr.gateway_key = 'sapaisa';

DELETE FROM merchant_payment_methods WHERE method_key = 'sapaisa';

DELETE FROM gateway_registry WHERE gateway_key = 'sapaisa';
