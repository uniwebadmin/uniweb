-- Mandatory email/mobile OTP verification at signup.
-- Mirrors ensureSignupVerificationSchema() in includes/schema_ensure.php.
-- Idempotent IF NOT EXISTS — safe if runtime schema ensure already added columns.

ALTER TABLE merchants ADD COLUMN IF NOT EXISTS email_verified_at DATETIME DEFAULT NULL;
ALTER TABLE merchants ADD COLUMN IF NOT EXISTS phone_verified_at DATETIME DEFAULT NULL;
