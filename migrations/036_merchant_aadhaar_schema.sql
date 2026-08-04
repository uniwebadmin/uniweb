-- Widen aadhaar_number to hold encrypted ciphertext

ALTER TABLE merchants MODIFY COLUMN aadhaar_number VARCHAR(255) DEFAULT NULL;
