ALTER TABLE gateway_registry
    ADD COLUMN IF NOT EXISTS integration_status ENUM('legacy_unverified','draft','sandbox','live') NOT NULL DEFAULT 'legacy_unverified',
    ADD COLUMN IF NOT EXISTS lifecycle_updated_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS lifecycle_updated_by INT DEFAULT NULL;
