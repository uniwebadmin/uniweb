-- Canonical partner channel contract shared by onboarding, checkout, status,
-- notifications, support, disputes, refunds, and admin surfaces.
ALTER TABLE gateway_registry
    ADD COLUMN IF NOT EXISTS channel_capabilities_json TEXT DEFAULT NULL;
