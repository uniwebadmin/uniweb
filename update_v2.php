<?php
/**
 * One-time v2 update — run once then delete.
 * Visit: https://uniweb.co.in/update_v2.php?key=uniweb_update_v2
 */
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'uniweb_update_v2') { http_response_code(403); die('Access denied.'); }
require_once __DIR__ . '/config.php';

try {
    $pdo = getDB();
    $alters = [
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS business_entity_type VARCHAR(50) DEFAULT 'sole_proprietorship' AFTER business_type",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS pan_number VARCHAR(15) DEFAULT NULL AFTER gstin",
        "ALTER TABLE merchants ADD COLUMN IF NOT EXISTS cin_llpin VARCHAR(30) DEFAULT NULL AFTER pan_number",
        "ALTER TABLE kyc_documents MODIFY COLUMN doc_type VARCHAR(50) NOT NULL",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(150) NOT NULL,
            token VARCHAR(64) NOT NULL UNIQUE,
            user_type ENUM('merchant','admin') DEFAULT 'merchant',
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token),
            INDEX idx_email (email)
        ) ENGINE=InnoDB",
    ];
    foreach ($alters as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate column')) continue;
            throw $e;
        }
    }
    echo '<!DOCTYPE html><html><head><title>Update v2</title><style>body{font-family:system-ui;max-width:600px;margin:60px auto;padding:20px;background:#0f172a;color:#e2e8f0}.ok{background:#065f46;padding:20px;border-radius:12px}h1{color:#10b981}a{color:#10b981}</style></head><body>
    <h1>✓ Update v2 Complete</h1><div class="ok"><p>Added: business entity types, PAN/CIN fields, password reset table, expanded KYC doc types.</p></div>
    <p><strong>Delete this file now.</strong></p>
    <p><a href="admin_security.php">→ Admin Security (Change Password)</a></p></body></html>';
} catch (PDOException $e) {
    echo '<pre style="color:red">' . htmlspecialchars($e->getMessage()) . '</pre>';
}
