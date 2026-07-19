<?php
/**
 * UNIWEB B2B BaaS Migration v6
 * Run once: https://uniweb.co.in/update_v6.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace">';
try {
    $db = getDB();
    $cols = [
        "ALTER TABLE merchants ADD COLUMN account_mode ENUM('test','live') NOT NULL DEFAULT 'test'",
        "ALTER TABLE merchants ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0",
        "ALTER TABLE merchants ADD COLUMN subscription_plan VARCHAR(50) NOT NULL DEFAULT 'starter'",
        "ALTER TABLE merchants ADD COLUMN monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 0",
        "ALTER TABLE merchants ADD COLUMN test_api_key VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN test_api_secret VARCHAR(128) DEFAULT NULL",
        "ALTER TABLE payment_links ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE transactions ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0",
        "ALTER TABLE transactions ADD COLUMN platform_fee DECIMAL(10,2) DEFAULT 0",
        "ALTER TABLE transactions ADD COLUMN split_amount DECIMAL(10,2) DEFAULT NULL",
    ];
    foreach ($cols as $sql) {
        try { $db->exec($sql); echo "OK: $sql\n"; } catch (Throwable $e) { echo "SKIP: " . $e->getMessage() . "\n"; }
    }
    $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        merchant_id INT NOT NULL,
        type ENUM('credit','debit','commission','subscription','settlement') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        reference VARCHAR(100) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: wallet_transactions table\n";

    $db->exec("CREATE TABLE IF NOT EXISTS split_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        merchant_id INT NOT NULL,
        recipient_type ENUM('platform','merchant','agent') NOT NULL,
        recipient_id INT DEFAULT NULL,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','completed','failed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
        FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: split_payments table\n";

    $settings = [
        ['platform_margin_pct', '5'],
        ['emi_mdr', '2.50'],
        ['bnpl_mdr', '2.00'],
        ['international_mdr', '3.50'],
        ['axis_mdr', '0'],
    ];
    $ins = $db->prepare('INSERT IGNORE INTO gateway_settings (setting_key, setting_value) VALUES (?,?)');
    foreach ($settings as [$k, $v]) {
        $ins->execute([$k, $v]);
        echo "OK setting: $k = $v\n";
    }

    $db->exec("UPDATE merchants SET account_mode='live' WHERE kyc_status='verified'");
    $db->exec("UPDATE merchants SET account_mode='test' WHERE kyc_status != 'verified' OR kyc_status IS NULL");

    $merchants = $db->query("SELECT id FROM merchants WHERE test_api_key IS NULL OR test_api_key = ''")->fetchAll();
    $upd = $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=? WHERE id=?');
    foreach ($merchants as $m) {
        $upd->execute(['test_' . bin2hex(random_bytes(16)), 'testsec_' . bin2hex(random_bytes(24)), $m['id']]);
    }
    echo "OK: test API keys generated for " . count($merchants) . " merchants\n";
    echo "\n✅ Migration v6 done. DELETE update_v6.php now.\n";
} catch (Throwable $e) {
    echo '❌ ' . $e->getMessage();
}
echo '</pre>';
