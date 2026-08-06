-- Migration 048: KYC geo-location columns on kyc_documents
-- Point 1: Real Client IP + Live Geolocation on KYC/Upload

ALTER TABLE kyc_documents ADD COLUMN client_ip VARCHAR(45) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN ip_country VARCHAR(60) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN lat DECIMAL(10,6) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN lng DECIMAL(10,6) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN geo_accuracy_m INT DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN geo_source VARCHAR(20) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL;
ALTER TABLE kyc_documents ADD COLUMN device_fingerprint VARCHAR(255) DEFAULT NULL;
