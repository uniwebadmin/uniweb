<?php
/**
 * UNIWEB Database Migration v4
 * Run once via browser: https://uniweb.co.in/update_v4.php
 * DELETE this file after successful migration.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace">';

try {
    $db = getDB();

    $db->exec("CREATE TABLE IF NOT EXISTS kyc_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        doc_type VARCHAR(50) NOT NULL,
        doc_number VARCHAR(100) NOT NULL,
        status ENUM('pending','submitted','verified','failed','rejected') DEFAULT 'pending',
        api_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_merchant_doc (merchant_id, doc_type),
        INDEX idx_merchant (merchant_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS gateway_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        gateway ENUM('razorpay','cashfree','payu','decentro','phonepe') NOT NULL,
        status ENUM('draft','submitted','approved','rejected','pending_review') DEFAULT 'submitted',
        payload LONGTEXT,
        admin_id INT,
        admin_notes TEXT,
        gateway_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_merchant (merchant_id),
        INDEX idx_gateway (gateway)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS otp_verifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        identifier VARCHAR(255) NOT NULL,
        otp_code VARCHAR(10) NOT NULL,
        otp_type VARCHAR(50) DEFAULT 'login',
        used TINYINT(1) DEFAULT 0,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_identifier (identifier, otp_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS sms_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        phone VARCHAR(20),
        message TEXT,
        status VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Merchant columns for verification
    $cols = [
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS aadhaar_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS iec_number VARCHAR(20) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS udyam_number VARCHAR(30) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS bank_verified TINYINT(1) DEFAULT 0",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS video_kyc_status VARCHAR(20) DEFAULT 'pending'",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS otp_enabled TINYINT(1) DEFAULT 0",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL",
    ];
    foreach ($cols as $sql) {
        try { $db->exec($sql); echo "OK: $sql\n"; } catch (Throwable $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
    }

    // Default gateway settings
    $defaults = [
        'support_email' => COMPANY_SUPPORT_EMAIL,
        'support_phone' => COMPANY_PHONE,
        'platform_name' => COMPANY_LEGAL_NAME,
        'otp_login_enabled' => '0',
        'sms_enabled' => '0',
        'whatsapp_enabled' => '0',
        'international_payments' => '1',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',
        'cashfree_app_id' => '',
        'cashfree_secret_key' => '',
        'payu_merchant_key' => '',
        'payu_merchant_salt' => '',
        'decentro_client_id' => '',
        'decentro_client_secret' => '',
        'phonepe_merchant_id' => '',
        'phonepe_salt_key' => '',
        'sms_api_key' => '',
        'sms_sender_id' => 'UNIWEB',
        'whatsapp_api_token' => '',
        'whatsapp_phone_id' => '',
    ];
    $ins = $db->prepare('INSERT IGNORE INTO gateway_settings (setting_key, setting_value) VALUES (?,?)');
    foreach ($defaults as $k => $v) {
        $ins->execute([$k, $v]);
    }

    echo "\n✅ Migration v4 completed successfully!\n";
    echo "⚠️  DELETE this file (update_v4.php) from server now.\n";
} catch (Throwable $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
echo '</pre>';
