-- B-02: Merchant KYC PII columns must hold AES ciphertext (enc:v1:…), not only short plaintext.
-- Short VARCHAR caused truncated cipher → garbled / unreadable UI (B-01).

ALTER TABLE merchants MODIFY COLUMN pan_number VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN gstin VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN cin_llpin VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN aadhaar_number VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN udyam_number VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN iec_number VARCHAR(255) DEFAULT NULL;
ALTER TABLE merchants MODIFY COLUMN address VARCHAR(1000) DEFAULT NULL;
