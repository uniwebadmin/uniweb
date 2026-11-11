ALTER TABLE gateway_registry
    MODIFY COLUMN integration_status ENUM('legacy_unverified','draft','sandbox','live','paused','retired') NOT NULL DEFAULT 'legacy_unverified';

UPDATE gateway_registry
SET integration_status='retired',
    lifecycle_resume_status=NULL
WHERE retired_at IS NOT NULL
  AND integration_status <> 'retired';
