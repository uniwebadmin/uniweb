-- 056: Add public_go_live flag to gateway_registry
-- Allows admin to explicitly mark a partner as visible on the public website.
-- Default 0 (OFF) — partner must be active + live keys + >=1 live method + admin toggle ON.

ALTER TABLE gateway_registry ADD COLUMN public_go_live TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE gateway_registry ADD COLUMN public_go_live_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE gateway_registry ADD COLUMN public_go_live_by VARCHAR(120) DEFAULT NULL;
