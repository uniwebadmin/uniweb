<?php
declare(strict_types=1);

/** Runtime schema ensures — avoid one-time update_v*.php dependency on live */

function schemaExecQuiet(string $sql): void
{
    try {
        getDB()->exec($sql);
    } catch (Throwable $e) { /* already applied / unsupported */ }
}

function ensureKycSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    // Expand ENUM → VARCHAR so all doc labels (video_kyc, partnership_deed, etc.) insert cleanly
    schemaExecQuiet("ALTER TABLE kyc_documents MODIFY COLUMN doc_type VARCHAR(50) NOT NULL");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN video_kyc_status VARCHAR(20) DEFAULT 'pending'");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN business_entity_type VARCHAR(50) DEFAULT 'sole_proprietorship'");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN pan_number VARCHAR(15) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN cin_llpin VARCHAR(30) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN aadhaar_number VARCHAR(20) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN bank_verified TINYINT(1) DEFAULT 0");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN deleted_at DATETIME DEFAULT NULL");
}

function ensurePasswordResetsTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(150) NOT NULL,
        token VARCHAR(64) NOT NULL UNIQUE,
        user_type ENUM('merchant','admin') DEFAULT 'merchant',
        expires_at TIMESTAMP NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_token (token),
        INDEX idx_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureAdminAuthSecurity(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    ensureStaffRoles();
    schemaExecQuiet("ALTER TABLE admins ADD COLUMN last_login_at DATETIME DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE admins ADD COLUMN last_login_ip VARCHAR(45) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE admins ADD COLUMN auth_version INT UNSIGNED NOT NULL DEFAULT 1");
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        succeeded TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_admin_login_lookup (username, ip_address, created_at),
        INDEX idx_admin_login_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureMerchantQrCodes(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS merchant_qr_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        qr_code VARCHAR(40) NOT NULL UNIQUE,
        merchant_id INT NOT NULL,
        payment_link_id INT DEFAULT NULL,
        label VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        is_test TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        scan_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_qr_merchant (merchant_id, status, created_at),
        INDEX idx_qr_payment_link (payment_link_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schemaExecQuiet("ALTER TABLE merchant_qr_codes ADD COLUMN qr_type VARCHAR(24) NOT NULL DEFAULT 'fixed' AFTER payment_link_id");
    schemaExecQuiet("ALTER TABLE payment_links ADD COLUMN qr_code_id INT UNSIGNED DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE payment_links ADD INDEX idx_links_qr_code (qr_code_id)");
}

function ensureMerchantAgentColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN parent_merchant_id INT DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN agent_commission DECIMAL(5,2) DEFAULT 0.50');
}

function ensureAmlFlagsTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS aml_flags (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        transaction_id INT DEFAULT NULL,
        flag_type VARCHAR(50) NOT NULL,
        severity ENUM('low','medium','high') DEFAULT 'medium',
        description TEXT,
        status ENUM('open','reviewed','cleared') DEFAULT 'open',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merchant (merchant_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureDisputesEngine(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS disputes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        dispute_id VARCHAR(30) NOT NULL UNIQUE,
        merchant_id INT NOT NULL,
        transaction_id INT NOT NULL,
        reason TEXT NOT NULL,
        status ENUM('open','under_review','resolved','closed') DEFAULT 'open',
        resolution TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merchant (merchant_id),
        INDEX idx_status (status),
        INDEX idx_txn (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensureMerchantAgreementSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS merchant_agreement_acceptances (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        agreement_version VARCHAR(30) NOT NULL,
        legal_name VARCHAR(190) NOT NULL,
        merchant_code VARCHAR(60) DEFAULT NULL,
        document_hash CHAR(64) NOT NULL,
        accepted_ip VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(500) DEFAULT NULL,
        accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_merchant_agreement_version (merchant_id, agreement_version),
        INDEX idx_agreement_merchant (merchant_id),
        INDEX idx_agreement_accepted (accepted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
