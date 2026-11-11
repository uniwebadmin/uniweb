ALTER TABLE gateway_registry
    MODIFY COLUMN integration_status ENUM('legacy_unverified','draft','sandbox','live','paused') NOT NULL DEFAULT 'legacy_unverified',
    ADD COLUMN IF NOT EXISTS lifecycle_resume_status ENUM('legacy_unverified','draft','sandbox','live') DEFAULT NULL;
