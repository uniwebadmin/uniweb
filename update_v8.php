<?php
/**
 * UNIWEB Wallet Engine v8
 * Run once: https://uniweb.co.in/update_v8.php then DELETE.
 */
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
echo '<pre style="background:#111;color:#0f0;padding:20px;font-family:monospace">';
try {
    $db = getDB();

    try {
        $db->exec("ALTER TABLE transactions ADD COLUMN wallet_credited TINYINT(1) NOT NULL DEFAULT 0");
        echo "OK: wallet_credited column\n";
    } catch (Throwable $e) {
        echo "SKIP wallet_credited: " . $e->getMessage() . "\n";
    }

    try {
        $db->exec("ALTER TABLE wallet_transactions ADD COLUMN transaction_id INT DEFAULT NULL");
        echo "OK: wallet_transactions.transaction_id\n";
    } catch (Throwable $e) {
        echo "SKIP wallet_txn_id: " . $e->getMessage() . "\n";
    }

    $db->exec("CREATE TABLE IF NOT EXISTS platform_wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('credit','debit','commission','settlement','refund') NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        balance_after DECIMAL(12,2) NOT NULL,
        transaction_id INT DEFAULT NULL,
        merchant_id INT DEFAULT NULL,
        reference VARCHAR(100) DEFAULT NULL,
        description VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: platform_wallet_transactions\n";

    $db->exec("CREATE TABLE IF NOT EXISTS platform_settlements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        settlement_id VARCHAR(30) NOT NULL UNIQUE,
        amount DECIMAL(12,2) NOT NULL,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        bank_name VARCHAR(100) DEFAULT NULL,
        account_number VARCHAR(30) DEFAULT NULL,
        ifsc_code VARCHAR(16) DEFAULT NULL,
        account_holder VARCHAR(100) DEFAULT NULL,
        utr VARCHAR(50) DEFAULT NULL,
        processed_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: platform_settlements\n";

    $settings = [
        ['platform_wallet_balance', '0'],
        ['platform_bank_name', ''],
        ['platform_account_holder', COMPANY_LEGAL_NAME],
        ['platform_account_number', ''],
        ['platform_ifsc', ''],
        ['min_platform_settlement', '100'],
    ];
    $ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
    foreach ($settings as [$k, $v]) {
        $ins->execute([$k, $v]);
        echo "OK setting: $k\n";
    }

    $backfilled = backfillWalletCredits();
    echo "OK: backfilled $backfilled transactions into wallets\n";
    echo "\n✅ Migration v8 done. DELETE update_v8.php now.\n";
} catch (Throwable $e) {
    echo '❌ ' . $e->getMessage();
}
echo '</pre>';
