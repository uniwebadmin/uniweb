<?php
declare(strict_types=1);

/** Runtime schema ensures — avoid one-time update_v*.php dependency on live */

function schemaEnsureSkipHeavy(): bool
{
    if (defined('UNIWEB_HEALTH_PROBE') && UNIWEB_HEALTH_PROBE) {
        return true;
    }
    $script = strtolower(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
    return $script === 'health.php';
}

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
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN rejection_reason VARCHAR(500) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN reviewed_at DATETIME DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN ip_address VARCHAR(45) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN recorded_at DATETIME DEFAULT NULL");
    // Point 1: Real Client IP + Live Geolocation
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN client_ip VARCHAR(45) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN ip_country VARCHAR(60) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN lat DECIMAL(10,6) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN lng DECIMAL(10,6) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN geo_accuracy_m INT DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN geo_source VARCHAR(20) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN user_agent VARCHAR(255) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN device_fingerprint VARCHAR(255) DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN is_masked TINYINT(1) NOT NULL DEFAULT 0");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN mask_method VARCHAR(50) DEFAULT NULL");
    // Document versioning: track version number per doc_type per merchant
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN version_number INT NOT NULL DEFAULT 1");
    schemaExecQuiet("ALTER TABLE kyc_documents ADD COLUMN replaced_by INT DEFAULT NULL");
    ensureMissingColumns();
}

function ensureSignupVerificationSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN email_verified_at DATETIME DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN phone_verified_at DATETIME DEFAULT NULL");
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
        qr_type VARCHAR(24) NOT NULL DEFAULT 'fixed',
        label VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        description VARCHAR(255) DEFAULT NULL,
        is_test TINYINT(1) NOT NULL DEFAULT 1,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        expires_at DATETIME DEFAULT NULL,
        valid_from DATETIME DEFAULT NULL,
        notify_on_pay TINYINT(1) NOT NULL DEFAULT 0,
        notify_channels VARCHAR(255) DEFAULT NULL,
        print_template VARCHAR(32) NOT NULL DEFAULT 'default',
        category VARCHAR(64) DEFAULT NULL,
        scan_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_qr_merchant (merchant_id, status, created_at),
        INDEX idx_qr_payment_link (payment_link_id),
        INDEX idx_qr_expires (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schemaExecQuiet("ALTER TABLE payment_links ADD COLUMN IF NOT EXISTS qr_code_id INT UNSIGNED DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE payment_links ADD INDEX IF NOT EXISTS idx_links_qr_code (qr_code_id)");
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS qr_code_events (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        qr_code_id INT UNSIGNED NOT NULL,
        merchant_id INT UNSIGNED NOT NULL,
        event_type ENUM('scan','payment','share','print','download','enable','disable','edit','duplicate','delete','expired') NOT NULL,
        event_data JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_qr_event_qr (qr_code_id, created_at),
        INDEX idx_qr_event_merchant (merchant_id, event_type, created_at),
        INDEX idx_qr_event_type (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Widen ENUM -> VARCHAR so new alert event types (expiry_alert, low_scan_alert)
    // can be logged without another migration; logQrEvent()'s PHP whitelist still
    // guards what actually gets inserted.
    schemaExecQuiet("ALTER TABLE qr_code_events MODIFY COLUMN event_type VARCHAR(32) NOT NULL");
    // Point 5: Direct qr_code_id on transactions for webhook resolve + analytics
    schemaExecQuiet("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS qr_code_id INT UNSIGNED DEFAULT NULL");
    schemaExecQuiet("ALTER TABLE transactions ADD INDEX IF NOT EXISTS idx_txn_qr_code (qr_code_id)");
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
        status ENUM('open','under_review','forwarded_partner','resolved','closed') DEFAULT 'open',
        resolution TEXT,
        sla_due_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merchant (merchant_id),
        INDEX idx_status (status),
        INDEX idx_txn (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schemaExecQuiet('ALTER TABLE disputes ADD COLUMN sla_due_at DATETIME DEFAULT NULL AFTER resolution');
    schemaExecQuiet("UPDATE disputes SET sla_due_at = DATE_ADD(created_at, INTERVAL 5 DAY) WHERE sla_due_at IS NULL");
    // Block 9 V1: Admin-first single partner forward (bulk / smart route later)
    schemaExecQuiet("ALTER TABLE disputes MODIFY COLUMN status ENUM('open','under_review','forwarded_partner','resolved','closed') DEFAULT 'open'");
    schemaExecQuiet('ALTER TABLE disputes ADD COLUMN forwarded_partner_key VARCHAR(40) DEFAULT NULL AFTER resolution');
    schemaExecQuiet('ALTER TABLE disputes ADD COLUMN forwarded_at DATETIME DEFAULT NULL AFTER forwarded_partner_key');
    schemaExecQuiet('ALTER TABLE disputes ADD COLUMN forwarded_note VARCHAR(500) DEFAULT NULL AFTER forwarded_at');
}

/**
 * Block 9 V1: forward one dispute to a partner (Admin first). No bulk / smart route yet.
 *
 * @return array{ok:bool,message:string}
 */
function forwardDisputeToPartner(int $disputeId, string $partnerKey, string $note, int $adminId = 0): array
{
    ensureDisputesEngine();
    $partnerKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($partnerKey))) ?? '';
    if ($partnerKey === '') {
        return ['ok' => false, 'message' => 'Select a partner to forward this dispute.'];
    }
    $note = mb_substr(trim($note), 0, 500);
    if ($note === '') {
        $note = 'Forwarded by Admin for partner review';
    }
    try {
        $db = getDB();
        $st = $db->prepare('SELECT d.*, m.business_name FROM disputes d JOIN merchants m ON m.id=d.merchant_id WHERE d.id=? LIMIT 1');
        $st->execute([$disputeId]);
        $row = $st->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => 'Dispute not found.'];
        }
        if (!in_array((string)$row['status'], ['open', 'under_review', 'forwarded_partner'], true)) {
            return ['ok' => false, 'message' => 'This dispute is already closed or resolved.'];
        }
        $db->prepare("UPDATE disputes SET status='forwarded_partner', forwarded_partner_key=?, forwarded_at=NOW(), forwarded_note=?, resolution=? WHERE id=?")
            ->execute([$partnerKey, $note, 'Forwarded for payment-network review — ' . $note, $disputeId]);
        if (function_exists('logStaffActivity')) {
            logStaffActivity('dispute_forward_partner', $row['dispute_id'] . ' → ' . $partnerKey, (int)$row['merchant_id'], 'dispute', (string)$row['dispute_id']);
        }
        if (function_exists('createNotification')) {
            createNotification(
                (int)$row['merchant_id'],
                'Dispute forwarded',
                'Admin forwarded ' . $row['dispute_id'] . ' for payment-network review. You will be updated when it is resolved.',
                'dispute_' . $disputeId
            );
        }
        return ['ok' => true, 'message' => 'Dispute forwarded to ' . $partnerKey . ' (single forward — bulk later).'];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Could not forward dispute.'];
    }
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
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN signature_name VARCHAR(190) DEFAULT NULL AFTER legal_name");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN pdf_filename VARCHAR(255) DEFAULT NULL AFTER signature_name");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN partner_names VARCHAR(500) DEFAULT NULL AFTER pdf_filename");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN requires_resign TINYINT(1) NOT NULL DEFAULT 0 AFTER partner_names");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN geo_lat DECIMAL(10,7) DEFAULT NULL AFTER requires_resign");
    schemaExecQuiet("ALTER TABLE merchant_agreement_acceptances ADD COLUMN geo_lng DECIMAL(10,7) DEFAULT NULL AFTER geo_lat");
}

/** Ensure invoices carry customer address for complete tax-invoice PDFs. */
function ensureInvoiceSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id VARCHAR(40) NOT NULL UNIQUE,
        merchant_id INT NOT NULL,
        customer_name VARCHAR(190) NOT NULL,
        customer_email VARCHAR(150) DEFAULT NULL,
        customer_phone VARCHAR(20) DEFAULT NULL,
        customer_address VARCHAR(500) DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        items TEXT,
        status VARCHAR(30) NOT NULL DEFAULT 'sent',
        due_date DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inv_merchant (merchant_id),
        INDEX idx_inv_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    schemaExecQuiet("ALTER TABLE invoices ADD COLUMN customer_address VARCHAR(500) DEFAULT NULL");
}

/** Partner-mapped failure text on txn / settlement rows (exact-reason polish). */
function ensureFailureReasonColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN failure_reason VARCHAR(500) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE settlements ADD COLUMN failure_reason VARCHAR(500) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE settlements ADD COLUMN api_message VARCHAR(255) DEFAULT NULL');
}

/** Ensure missing merchant KYC + transaction columns exist locally. */
function ensureMissingColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN gstin VARCHAR(20) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN udyam_number VARCHAR(30) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN iec_number VARCHAR(20) DEFAULT NULL');
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN onboarding_state VARCHAR(32) NOT NULL DEFAULT 'draft'");
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN onboarding_submitted_at DATETIME DEFAULT NULL');
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN account_mode VARCHAR(16) NOT NULL DEFAULT 'test'");
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN live_enabled_at DATETIME DEFAULT NULL');
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN bank_verification_status VARCHAR(32) NOT NULL DEFAULT 'pending'");
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN website_review_status VARCHAR(32) NOT NULL DEFAULT 'pending'");
    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN enabled_methods TEXT DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchant_agreement_acceptances ADD COLUMN partner_names VARCHAR(500) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchant_agreement_acceptances ADD COLUMN requires_resign TINYINT(1) NOT NULL DEFAULT 0');
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN metadata JSON DEFAULT NULL');
    ensureContactInquirySchema();

    // P0-02: partner_commercial may be missing entirely — CREATE then ALTER (aligned with split_settlement).
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS partner_commercial (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_key VARCHAR(40) NOT NULL UNIQUE,
        base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
        settlement_mode VARCHAR(40) NOT NULL DEFAULT 'standard_settle_mode',
        route_enabled TINYINT(1) NOT NULL DEFAULT 0,
        route_mode VARCHAR(20) NOT NULL DEFAULT 'off',
        route_provider VARCHAR(30) NOT NULL DEFAULT 'none',
        route_linked_account_hint VARCHAR(120) DEFAULT NULL,
        route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture',
        route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold',
        updated_by VARCHAR(60) NOT NULL DEFAULT 'system',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    schemaExecQuiet('ALTER TABLE partner_commercial ADD COLUMN partner_key VARCHAR(40) NOT NULL DEFAULT \'\'');
    schemaExecQuiet('ALTER TABLE partner_commercial ADD COLUMN base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0');
    schemaExecQuiet('ALTER TABLE partner_commercial ADD COLUMN settlement_mode VARCHAR(40) NOT NULL DEFAULT \'standard_settle_mode\'');

    // P0-02: gateway_events table + evidence-pack JOIN column (no FK — payment_orders may lag).
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS gateway_events (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        provider VARCHAR(32) NOT NULL,
        event_id VARCHAR(190) NOT NULL,
        event_type VARCHAR(100) NOT NULL,
        payload_hash CHAR(64) NOT NULL,
        signature_valid TINYINT(1) NOT NULL DEFAULT 0,
        processing_status VARCHAR(32) NOT NULL DEFAULT 'received',
        payment_order_id BIGINT UNSIGNED DEFAULT NULL,
        provider_order_id VARCHAR(120) DEFAULT NULL,
        error_message VARCHAR(500) DEFAULT NULL,
        received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        processed_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_gateway_event (provider, event_id),
        INDEX idx_gateway_event_hash (provider, payload_hash),
        INDEX idx_gateway_event_status (processing_status, received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    schemaExecQuiet('ALTER TABLE gateway_events ADD COLUMN payment_order_id BIGINT UNSIGNED DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payment_orders ADD COLUMN provider_order_id VARCHAR(120) DEFAULT NULL');

    schemaExecQuiet("CREATE TABLE IF NOT EXISTS kyc_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        file_name VARCHAR(255) DEFAULT NULL,
        file_path VARCHAR(500) DEFAULT NULL,
        storage_key VARCHAR(255) DEFAULT NULL,
        sha256 CHAR(64) DEFAULT NULL,
        mime_type VARCHAR(100) DEFAULT NULL,
        file_size INT DEFAULT NULL,
        scan_status VARCHAR(20) DEFAULT 'pending',
        status VARCHAR(20) DEFAULT 'pending',
        retention_until DATE DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_merchant (merchant_id, doc_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (function_exists('ensureSplitSettlementTable')) {
        ensureSplitSettlementTable();
    }

    // 058: schema drift — columns referenced by code but missing from original migrations
    schemaExecQuiet("ALTER TABLE platform_settlements ADD COLUMN mode VARCHAR(20) DEFAULT 'manual'");
    schemaExecQuiet('ALTER TABLE platform_settlements ADD COLUMN bank_account VARCHAR(30) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE platform_settlements ADD COLUMN processed_by VARCHAR(120) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payout_beneficiaries ADD COLUMN account_number_last4 VARCHAR(8) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payout_orders ADD COLUMN processed_at DATETIME DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payout_orders ADD COLUMN utr VARCHAR(60) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE gateway_events ADD COLUMN provider_order_id VARCHAR(120) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE gateway_events ADD INDEX idx_gateway_event_provider_order (provider_order_id)');

    // 059: notification dedup — event_key for idempotent notification creation
    schemaExecQuiet('ALTER TABLE notifications ADD COLUMN event_key VARCHAR(120) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE notifications ADD INDEX idx_notif_event (merchant_id, event_key)');
    schemaExecQuiet('ALTER TABLE notifications ADD COLUMN archived_at DATETIME DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE notifications ADD INDEX idx_notif_archived (merchant_id, archived_at)');

    // 044 / reason maps — partner_key required by INSERT seed
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS gateway_reason_maps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_key VARCHAR(40) NOT NULL DEFAULT '',
        raw_code VARCHAR(120) NOT NULL,
        msg_en VARCHAR(500) NOT NULL DEFAULT '',
        msg_hi VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_partner_code (partner_key, raw_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    schemaExecQuiet("ALTER TABLE gateway_reason_maps ADD COLUMN partner_key VARCHAR(40) NOT NULL DEFAULT ''");
    schemaExecQuiet("ALTER TABLE gateway_reason_maps ADD COLUMN msg_en VARCHAR(500) NOT NULL DEFAULT ''");
    schemaExecQuiet("ALTER TABLE gateway_reason_maps ADD COLUMN msg_hi VARCHAR(500) NOT NULL DEFAULT ''");

    schemaExecQuiet("CREATE TABLE IF NOT EXISTS partner_credentials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partner_key VARCHAR(40) NOT NULL,
        env VARCHAR(8) NOT NULL DEFAULT 'test',
        encrypted_payload TEXT NOT NULL,
        last4 VARCHAR(8) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_partner_env (partner_key, env)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 060: partner route/split scaffold columns on partner_commercial
    schemaExecQuiet('ALTER TABLE partner_commercial ADD COLUMN route_enabled TINYINT(1) NOT NULL DEFAULT 0');
    schemaExecQuiet("ALTER TABLE partner_commercial ADD COLUMN route_mode VARCHAR(20) NOT NULL DEFAULT 'off'");
    schemaExecQuiet("ALTER TABLE partner_commercial ADD COLUMN route_provider VARCHAR(30) NOT NULL DEFAULT 'none'");
    schemaExecQuiet('ALTER TABLE partner_commercial ADD COLUMN route_linked_account_hint VARCHAR(120) DEFAULT NULL');
    schemaExecQuiet("ALTER TABLE partner_commercial ADD COLUMN route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture'");
    schemaExecQuiet("ALTER TABLE partner_commercial ADD COLUMN route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold'");

    // payment_links pack / QR columns — txn detail, receipt, qr_pay (not only checkout.php)
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN gateway_code VARCHAR(32) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN pack_id VARCHAR(32) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN link_label VARCHAR(128) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN link_collection_mode VARCHAR(32) DEFAULT NULL');
    schemaExecQuiet("ALTER TABLE payment_links ADD COLUMN amount_type VARCHAR(16) NOT NULL DEFAULT 'fixed'");
    schemaExecQuiet('ALTER TABLE payment_links ADD COLUMN qr_code_id INT UNSIGNED DEFAULT NULL');
}

/** F2: Ensure pricing snapshot columns on transactions table. */
function ensurePricingSnapshotColumns(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN mdr_m DECIMAL(6,4) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN mdr_p DECIMAL(6,4) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN partner_fee DECIMAL(14,2) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE transactions ADD COLUMN pricing_snapshot JSON DEFAULT NULL');
}

/** B-02: room for enc:v1: AES blobs (short VARCHAR truncates → B-01 garbled UI). */
function ensureSensitivePiiColumnWidths(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN pan_number VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN gstin VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN cin_llpin VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN aadhaar_number VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN udyam_number VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN iec_number VARCHAR(255) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE merchants MODIFY COLUMN address VARCHAR(1000) DEFAULT NULL');
}

/**
 * Ensure all app tables use utf8mb4_unicode_ci collation.
 * Prevents "Illegal mix of collations" errors on JOINs between tables
 * created under different server defaults (general_ci vs uca1400_ai_ci vs unicode_ci).
 * Runs once per request, converts only tables that exist and differ.
 */
function ensureCollationConsistency(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $script = strtolower(basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
    if (in_array($script, ['admin_login.php', 'login.php', 'staff_login.php', 'admin_forgot_password.php', 'merchant_register.php', 'health.php'], true)) {
        return;
    }

    if (!function_exists('getDB')) {
        return;
    }

    try {
        $db = getDB();
        $target = 'utf8mb4_unicode_ci';

        $rows = $db->query("SELECT TABLE_NAME, TABLE_COLLATION
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_TYPE = 'BASE TABLE'
              AND TABLE_COLLATION IS NOT NULL
              AND TABLE_COLLATION != '{$target}'")->fetchAll();

        if (!empty($rows)) {
            $db->exec("SET FOREIGN_KEY_CHECKS = 0");
            foreach ($rows as $row) {
                $table = $row['TABLE_NAME'];
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) continue;
                try {
                    $db->exec("ALTER TABLE `{$table}` CONVERT TO CHARACTER SET utf8mb4 COLLATE {$target}");
                } catch (Throwable $e) {
                    error_log("UniWeb collation fix failed for {$table}: " . $e->getMessage());
                }
            }
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        }

        ensureJoinKeyColumnCollations($db, $target);
    } catch (Throwable $e) {
        error_log('UniWeb ensureCollationConsistency failed: ' . $e->getMessage());
    }
}

/**
 * Table CONVERT can leave individual VARCHAR join keys on utf8mb4_bin.
 * Those mixes throw 1267 on Admin KYC / partner_forward_queue / registry JOINs.
 */
function ensureJoinKeyColumnCollations(PDO $db, string $target = 'utf8mb4_unicode_ci'): void
{
    $pairs = [
        ['gateway_registry', 'gateway_key'],
        ['partner_methods', 'partner_key'],
        ['partner_methods', 'method'],
        ['partner_merchant_links', 'partner_key'],
        ['partner_forward_queue', 'partner_key'],
        ['partner_forward_queue', 'status'],
        ['partner_credentials', 'partner_key'],
        ['kyc_documents', 'doc_type'],
        ['kyc_documents', 'status'],
        ['merchants', 'merchant_code'],
        ['merchants', 'email'],
        ['merchants', 'kyc_status'],
        ['merchants', 'business_entity_type'],
        ['gateway_submissions', 'gateway_key'],
    ];
    foreach ($pairs as [$table, $col]) {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $col)) {
            continue;
        }
        try {
            $st = $db->query("SHOW FULL COLUMNS FROM `{$table}` WHERE Field = " . $db->quote($col));
            $info = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
            if (!$info || empty($info['Collation'])) {
                continue;
            }
            if (strcasecmp((string)$info['Collation'], $target) === 0) {
                continue;
            }
            $type = (string)$info['Type'];
            $null = strtoupper((string)$info['Null']) === 'YES' ? 'NULL' : 'NOT NULL';
            $defaultSql = '';
            if ($info['Default'] !== null) {
                $defaultSql = ' DEFAULT ' . $db->quote((string)$info['Default']);
            } elseif (strtoupper((string)$info['Null']) === 'YES') {
                $defaultSql = ' DEFAULT NULL';
            }
            $db->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$col}` {$type} CHARACTER SET utf8mb4 COLLATE {$target} {$null}{$defaultSql}");
        } catch (Throwable $e) {
            error_log("UniWeb join-key collation fix failed for {$table}.{$col}: " . $e->getMessage());
        }
    }
}

if (!function_exists('initErrorCatcher') && is_file(__DIR__ . '/error_catcher.php')) {
    require_once __DIR__ . '/error_catcher.php';
}

function ensureContactInquirySchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    schemaExecQuiet("CREATE TABLE IF NOT EXISTS contact_inquiries (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        inquiry_id VARCHAR(32) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(190) NOT NULL,
        subject VARCHAR(190) NOT NULL,
        message TEXT NOT NULL,
        ip VARCHAR(45) DEFAULT NULL,
        email_sent TINYINT(1) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_contact_status (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function recordPublicContactInquiry(string $name, string $email, string $subject, string $message, bool $emailSent): array
{
    ensureContactInquirySchema();
    $inquiryId = function_exists('generateId') ? generateId('CTI') : ('CTI' . strtoupper(bin2hex(random_bytes(6))));
    try {
        getDB()->prepare(
            'INSERT INTO contact_inquiries (inquiry_id, name, email, subject, message, ip, email_sent, status)
             VALUES (?,?,?,?,?,?,?,\'open\')'
        )->execute([
            $inquiryId,
            mb_substr($name, 0, 120),
            mb_substr($email, 0, 190),
            mb_substr($subject, 0, 190),
            mb_substr($message, 0, 4000),
            mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            $emailSent ? 1 : 0,
        ]);
        return ['ok' => true, 'inquiry_id' => $inquiryId];
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('error', 'Contact inquiry save failed: ' . $e->getMessage());
        }
        return ['ok' => false, 'inquiry_id' => $inquiryId, 'error' => $e->getMessage()];
    }
}

function listPublicContactInquiries(int $limit = 20): array
{
    ensureContactInquirySchema();
    $limit = max(1, min(50, $limit));
    try {
        return getDB()->query(
            "SELECT * FROM contact_inquiries ORDER BY FIELD(status,'open','closed'), created_at DESC LIMIT {$limit}"
        )->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function closePublicContactInquiry(string $inquiryId): bool
{
    ensureContactInquirySchema();
    $inquiryId = trim($inquiryId);
    if ($inquiryId === '' || !preg_match('/^CTI[A-Z0-9]+$/i', $inquiryId)) {
        return false;
    }
    try {
        $stmt = getDB()->prepare("UPDATE contact_inquiries SET status = 'closed' WHERE inquiry_id = ? AND status = 'open'");
        $stmt->execute([$inquiryId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

if (function_exists('getDB')) {
    ensureCollationConsistency();
}
if (!schemaEnsureSkipHeavy()) {
    ensureMissingColumns();
    ensureSensitivePiiColumnWidths();
}
