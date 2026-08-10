-- D3: Widen totp_secret columns to accommodate encrypted ciphertext (enc:v1:...)
-- Original migration 009 created VARCHAR(64) which is too small for AES-256-GCM output.
-- Encrypted values are ~100+ chars (IV + ciphertext + tag, base64-encoded with prefix).

ALTER TABLE admins MODIFY COLUMN totp_secret VARCHAR(256) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN totp_secret VARCHAR(256) NULL;
