<?php
declare(strict_types=1);

/** Merchant + Platform wallet ledger */

function refreshMerchantWalletBalance(int $merchantId): float
{
    ensureWalletEngine();
    $db = getDB();
    $mst = $db->prepare('SELECT account_mode, kyc_status FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch() ?: null;
    $isTest = isMerchantTest($merchant);
    $threshold = walletCorruptThreshold($isTest);

    try {
        $row = $db->prepare('SELECT wallet_balance FROM merchants WHERE id = ?');
        $row->execute([$merchantId]);
        $bal = (float)($row->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $bal = rebuildMerchantWalletBalance($merchantId);
    }

    if ($bal > $threshold || $bal < 0) {
        if ($isTest) {
            $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id=? AND ABS(amount)>1000')->execute([$merchantId]);
            $db->prepare("UPDATE settlements SET status='failed', processed_at=NOW() WHERE merchant_id=? AND status IN ('pending','processing') AND amount>100")->execute([$merchantId]);
            $bal = rebuildMerchantWalletBalance($merchantId);
            if ($bal > $threshold) {
                resetMerchantWalletFromTransactions($merchantId);
                $bal = rebuildMerchantWalletBalance($merchantId);
            }
            if ($bal > $threshold) {
                $db->prepare('UPDATE merchants SET wallet_balance=0 WHERE id=?')->execute([$merchantId]);
                $bal = 0;
            }
        } else {
            reconcileMerchantWallet($merchantId);
            $bal = rebuildMerchantWalletBalance($merchantId);
        }
    }

    return walletAmount($bal, $isTest);
}

function getPlatformWalletBalance(): float
{
    ensurePlatformWalletTables();
    return (float)getSetting('platform_wallet_balance', '0');
}

function ensurePlatformWalletTables(): void
{
    ensureWalletEngine();
}

function ensureWalletEngine(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $migrations = [
            "ALTER TABLE merchants ADD COLUMN wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0",
            "ALTER TABLE merchants ADD COLUMN account_mode ENUM('test','live') NOT NULL DEFAULT 'test'",
            "ALTER TABLE transactions ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE transactions ADD COLUMN platform_fee DECIMAL(10,2) DEFAULT 0",
            "ALTER TABLE transactions ADD COLUMN split_amount DECIMAL(10,2) DEFAULT NULL",
            "ALTER TABLE transactions ADD COLUMN collection_mode VARCHAR(32) DEFAULT NULL",
            "ALTER TABLE transactions ADD COLUMN wallet_credited TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE transactions MODIFY COLUMN payment_method VARCHAR(32) NOT NULL DEFAULT 'upi'",
            "ALTER TABLE payment_links ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0",
            "ALTER TABLE settlements ADD COLUMN utr VARCHAR(50) DEFAULT NULL",
            "ALTER TABLE settlements ADD COLUMN processed_at TIMESTAMP NULL",
        ];
        foreach ($migrations as $sql) {
            try { $db->exec($sql); } catch (Throwable $e) { /* ok */ }
        }

        $db->exec("CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            type ENUM('credit','debit','commission','subscription','settlement','refund') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL,
            reference VARCHAR(100) DEFAULT NULL,
            description VARCHAR(255) DEFAULT NULL,
            transaction_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (merchant_id),
            INDEX (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        try { $db->exec("ALTER TABLE wallet_transactions ADD COLUMN transaction_id INT DEFAULT NULL"); } catch (Throwable $e) { /* ok */ }

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

        $db->exec("CREATE TABLE IF NOT EXISTS settlements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            settlement_id VARCHAR(30) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            fee DECIMAL(12,2) DEFAULT 0,
            net_amount DECIMAL(12,2) NOT NULL,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            bank_account_id INT DEFAULT NULL,
            utr VARCHAR(50) DEFAULT NULL,
            processed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $defaults = [
            ['platform_wallet_balance', '0'],
            ['min_platform_settlement', '1'],
            ['min_settlement_amount', '100'],
        ];
        $ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
        foreach ($defaults as [$k, $v]) {
            try { $ins->execute([$k, $v]); } catch (Throwable $e) { /* ok */ }
        }
    } catch (Throwable $e) {
        error_log('ensureWalletEngine: ' . $e->getMessage());
    }
}

function rebuildMerchantWalletBalance(int $merchantId): float
{
    ensureWalletEngine();
    $db = getDB();
    $stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE merchant_id = ?');
    $stmt->execute([$merchantId]);
    $bal = round((float)$stmt->fetchColumn(), 2);
    $db->prepare('UPDATE merchants SET wallet_balance = ? WHERE id = ?')->execute([$bal, $merchantId]);
    return $bal;
}

function rebuildPlatformWalletBalance(): float
{
    ensureWalletEngine();
    $sum = (float)getDB()->query('SELECT COALESCE(SUM(amount), 0) FROM platform_wallet_transactions')->fetchColumn();
    setPlatformWalletBalance(round($sum, 2));
    return round($sum, 2);
}

function fixCorruptTransactionAmounts(): int
{
    $db = getDB();
    $db->exec("UPDATE transactions SET amount=1.00, platform_fee=0, split_amount=1.00, wallet_credited=0
        WHERE status='success' AND is_test=1 AND (amount > 100 OR COALESCE(platform_fee,0) > 100 OR COALESCE(split_amount,0) > 100)");
    $db->exec("UPDATE transactions SET platform_fee=LEAST(COALESCE(platform_fee,0),100), split_amount=LEAST(COALESCE(split_amount,amount),100), wallet_credited=0
        WHERE status='success' AND is_test=1 AND amount <= 100 AND (COALESCE(platform_fee,0) > 1 OR COALESCE(split_amount,0) > 100)");
    $db->exec("UPDATE transactions SET amount=LEAST(amount,100), platform_fee=0, split_amount=LEAST(amount,100), wallet_credited=0
        WHERE status='success' AND is_test=1 AND amount > 1000 AND amount < 500000");
    $db->exec("UPDATE transactions SET amount=LEAST(amount,500000), platform_fee=LEAST(COALESCE(platform_fee,0),50000), split_amount=LEAST(COALESCE(split_amount,amount),500000), wallet_credited=0
        WHERE status='success' AND is_test=0 AND (amount > 500000 OR COALESCE(platform_fee,0) > 500000 OR COALESCE(split_amount,0) > 500000)");
    $rows = $db->query("SELECT t.id, t.merchant_id, t.amount, t.is_test, t.payment_link_id, pl.amount AS link_amount
        FROM transactions t
        LEFT JOIN payment_links pl ON t.payment_link_id = pl.id
        WHERE (t.is_test=1 AND (t.amount > 100 OR t.amount < 0))
            OR t.amount > 500000 OR t.amount < 0
            OR (t.amount > 1000 AND t.amount < 500000)
            OR COALESCE(t.platform_fee,0) > 1000
            OR COALESCE(t.split_amount,0) > 1000")->fetchAll();
    $fixed = 0;
    foreach ($rows as $row) {
        $isTest = !empty($row['is_test']);
        $correct = round((float)($row['link_amount'] ?? 1), 2);
        if ($isTest) {
            if ($correct <= 0 || $correct > 100) {
                $correct = 1.0;
            }
        } else {
            $correct = sanitizePaymentAmount($correct, false);
            if ($correct <= 0) {
                $correct = sanitizePaymentAmount((float)$row['amount'], false);
            }
        }
        $m = $db->prepare('SELECT commission_rate FROM merchants WHERE id=?');
        $m->execute([(int)$row['merchant_id']]);
        $merchant = $m->fetch() ?: ['commission_rate' => 0.1];
        $split = calculateSplitBreakdown($correct, $merchant);
        $db->prepare('UPDATE transactions SET amount=?, platform_fee=?, split_amount=?, wallet_credited=0 WHERE id=?')
            ->execute([$correct, $split['platform_fee'], $split['merchant_net'], (int)$row['id']]);
        $fixed++;
    }
    $db->exec("UPDATE payment_links SET amount=1.00 WHERE amount > 100 AND link_id LIKE 'DEMO%'");
    $db->exec("UPDATE payment_links SET amount=LEAST(amount,100) WHERE amount > 100 AND (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%')");
    return $fixed;
}

function fixCorruptPaymentLinks(): int
{
    $db = getDB();
    $rows = $db->query("SELECT id, amount FROM payment_links WHERE (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%') AND (amount > 100 OR amount < 0)")->fetchAll();
    $fixed = 0;
    foreach ($rows as $row) {
        $db->prepare('UPDATE payment_links SET amount=LEAST(amount,100) WHERE id=?')->execute([(int)$row['id']]);
        $fixed++;
    }
    $db->exec("UPDATE settlements SET amount=LEAST(amount,100), net_amount=LEAST(net_amount,100) WHERE amount > 1000 AND merchant_id IN (SELECT id FROM merchants WHERE account_mode='test')");
    $db->exec("DELETE FROM platform_settlements WHERE amount > 1000");
    $db->exec("DELETE FROM settlements WHERE amount > 500000");
    return $fixed;
}

function fixCorruptGatewaySettings(): void
{
    $db = getDB();
    $caps = [
        'min_settlement_amount' => ['default' => '100', 'max' => 100],
        'min_platform_settlement' => ['default' => '1', 'max' => 1],
        'aml_high_value_threshold' => ['default' => '200000', 'max' => 500000],
    ];
    foreach ($caps as $key => $rule) {
        $val = (float)getSetting($key, $rule['default']);
        if ($val <= 0 || $val > $rule['max'] || !is_finite($val)) {
            $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
                ->execute([$key, $rule['default'], $rule['default']]);
            clearSettingCache($key);
        }
    }
    $platformBal = (float)getSetting('platform_wallet_balance', '0');
    if ($platformBal > 1000 || $platformBal < 0) {
        $db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
        clearSettingCache('platform_wallet_balance');
    }
    try {
        $db->exec("UPDATE gateway_settings SET setting_value='100' WHERE setting_key='min_settlement_amount' AND CAST(setting_value AS DECIMAL(20,2)) > 100");
        $db->exec("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement' AND CAST(setting_value AS DECIMAL(20,2)) > 1");
        $db->exec("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance' AND CAST(setting_value AS DECIMAL(20,2)) > 1000");
        clearSettingCache();
    } catch (Throwable $e) { /* ok */ }
}

function dedupeWalletTransactionCredits(): int
{
    ensureWalletEngine();
    $db = getDB();
    $removed = 0;
    try {
        $dupes = $db->query(
            "SELECT transaction_id, MIN(id) AS keep_id, COUNT(*) AS cnt
             FROM wallet_transactions
             WHERE transaction_id IS NOT NULL AND transaction_id > 0
             GROUP BY transaction_id
             HAVING cnt > 1"
        )->fetchAll();
        foreach ($dupes as $d) {
            $st = $db->prepare('DELETE FROM wallet_transactions WHERE transaction_id=? AND id!=?');
            $st->execute([(int)$d['transaction_id'], (int)$d['keep_id']]);
            $removed += $st->rowCount();
        }
    } catch (Throwable $e) { /* ok */ }
    return $removed;
}

function expectedMerchantCreditForTransaction(array $txn): float
{
    $isTest = !empty($txn['is_test']);
    $cap = walletCreditCap($isTest);
    $gross = sanitizePaymentAmount((float)($txn['amount'] ?? 0), $isTest);
    $mode = $txn['collection_mode'] ?? 'platform_pg';
    $merchantNet = (float)($txn['split_amount'] ?? 0);
    if ($merchantNet > $cap || $merchantNet < 0) {
        $merchantNet = $gross;
    }
    $merchantAmount = $mode === 'direct_upi' ? $gross : $merchantNet;
    $merchantAmount = min(max(0, $merchantAmount), $cap);
    if ($merchantAmount <= 0 && $gross > 0) {
        $merchantAmount = min($gross, $isTest ? 1.0 : $cap);
    }
    return round($merchantAmount, 2);
}

function reconcileMerchantWallet(int $merchantId): float
{
    ensureWalletEngine();
    $db = getDB();
    $mst = $db->prepare('SELECT account_mode, kyc_status FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch() ?: null;
    $isTest = isMerchantTest($merchant);
    $threshold = walletCorruptThreshold($isTest);
    $absMax = $isTest ? 1000.0 : 500000.0;

    dedupeWalletTransactionCredits();
    $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id=? AND ABS(amount)>?')->execute([$merchantId, $absMax]);

    $sumSt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE merchant_id=?');
    $sumSt->execute([$merchantId]);
    $ledger = round((float)$sumSt->fetchColumn(), 2);

    $balSt = $db->prepare('SELECT wallet_balance FROM merchants WHERE id=?');
    $balSt->execute([$merchantId]);
    $stored = round((float)($balSt->fetchColumn() ?: 0), 2);

    $successSt = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
    $successSt->execute([$merchantId]);
    $successCount = (int)$successSt->fetchColumn();

    $creditSt = $db->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE merchant_id=? AND amount > 0 AND type='credit'");
    $creditSt->execute([$merchantId]);
    $creditEntries = (int)$creditSt->fetchColumn();

    $needsRebuild = $ledger > $threshold
        || $stored > $threshold
        || abs($ledger - $stored) > 0.02
        || ($successCount > 0 && $creditEntries === 0)
        || ($isTest && $successCount > 0 && $creditEntries > $successCount * 2);

    if ($needsRebuild) {
        resetMerchantWalletFromTransactions($merchantId);
        return refreshMerchantWalletBalance($merchantId);
    }

    $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$ledger, $merchantId]);
    return walletAmount($ledger, $isTest);
}

function sealWalletCredits(): void
{
    ensureWalletEngine();
    fixCorruptTransactionAmounts();
}

function hasCorruptWalletData(): bool
{
    try {
        $db = getDB();
        if ((int)$db->query('SELECT COUNT(*) FROM merchants WHERE wallet_balance > 1000 OR wallet_balance < 0')->fetchColumn() > 0) {
            return true;
        }
        if ((int)$db->query("SELECT COUNT(*) FROM gateway_settings WHERE setting_key IN ('min_settlement_amount','min_platform_settlement') AND CAST(setting_value AS DECIMAL(20,2)) > 100")->fetchColumn() > 0) {
            return true;
        }
        if ((float)getSetting('platform_wallet_balance', '0') > 1000) {
            return true;
        }
        if ((int)$db->query('SELECT COUNT(*) FROM wallet_transactions WHERE ABS(amount) > 1000')->fetchColumn() > 0) {
            return true;
        }
        if ((int)$db->query("SELECT COUNT(*) FROM settlements WHERE status IN ('pending','processing') AND amount > 1000")->fetchColumn() > 0) {
            return true;
        }
        if ((int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND ((is_test=1 AND amount > 100) OR amount > 500000)")->fetchColumn() > 0) {
            return true;
        }
        if ((int)$db->query("SELECT COUNT(*) FROM payment_links WHERE amount > 100 AND (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%')")->fetchColumn() > 0) {
            return true;
        }
        $dupes = $db->query(
            "SELECT COUNT(*) FROM (
                SELECT transaction_id FROM wallet_transactions
                WHERE transaction_id IS NOT NULL AND transaction_id > 0
                GROUP BY transaction_id HAVING COUNT(*) > 1
            ) x"
        )->fetchColumn();
        if ((int)$dupes > 0) {
            return true;
        }
    } catch (Throwable $e) {
        return false;
    }
    return false;
}

function nukeCorruptWalletState(): array
{
    ensureWalletEngine();
    $db = getDB();
    fixCorruptGatewaySettings();
    fixCorruptPaymentLinks();
    fixCorruptInvoices();
    fixCorruptTransactionAmounts();
    dedupeWalletTransactionCredits();

    $db->exec('DELETE FROM wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec('DELETE FROM platform_wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec("UPDATE settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing') AND amount > 100");
    $db->exec("UPDATE platform_settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing') AND amount > 1000");
    $db->exec("UPDATE transactions SET amount=LEAST(amount,100), platform_fee=LEAST(COALESCE(platform_fee,0),100), split_amount=LEAST(COALESCE(split_amount,amount),100) WHERE status='success' AND amount > 100");
    $db->exec("UPDATE payment_links SET amount=LEAST(amount,100) WHERE amount > 100 AND (is_test=1 OR link_id LIKE 'DEMO%' OR link_id LIKE 'LNK%')");
    $db->exec('UPDATE transactions SET wallet_credited=0 WHERE status=\'success\'');
    setPlatformWalletBalance(0);
    $db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='100' WHERE setting_key='min_settlement_amount'")->execute();
    clearSettingCache();

    foreach ($db->query('SELECT id FROM merchants')->fetchAll() as $m) {
        $mid = (int)$m['id'];
        $sx = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
        $sx->execute([$mid]);
        if ((int)$sx->fetchColumn() > 0) {
            resetMerchantWalletFromTransactions($mid);
        } else {
            $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id=?')->execute([$mid]);
            $db->prepare('UPDATE merchants SET wallet_balance=0 WHERE id=?')->execute([$mid]);
        }
    }
    repairPlatformWallet();

    return [
        'platform' => getPlatformWalletBalance(),
        'merchants_zeroed' => false,
    ];
}

function walletFullRepair(): array
{
    ensureWalletEngine();
    fixCorruptGatewaySettings();
    fixCorruptPaymentLinks();
    fixCorruptInvoices();
    fixCorruptTransactionAmounts();
    dedupeWalletTransactionCredits();

    $db = getDB();
    $db->exec('DELETE FROM wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec('DELETE FROM platform_wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec("UPDATE settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing') AND amount > 100");
    $db->exec("DELETE FROM settlements WHERE amount > 1000 OR amount < 0");
    $db->exec("UPDATE platform_settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing')");
    $db->exec('DELETE FROM platform_settlements WHERE amount > 1000 OR amount < 0');
    $db->exec("UPDATE merchants SET wallet_balance=0 WHERE wallet_balance > 1000 OR wallet_balance < 0");
    $db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='100' WHERE setting_key='min_settlement_amount'")->execute();
    clearSettingCache();

    $merchantReport = [];
    foreach ($db->query('SELECT id, email FROM merchants')->fetchAll() as $m) {
        $mid = (int)$m['id'];
        $sx = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
        $sx->execute([$mid]);
        if ((int)$sx->fetchColumn() > 0) {
            resetMerchantWalletFromTransactions($mid);
        } else {
            $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id=?')->execute([$mid]);
            $db->prepare('UPDATE merchants SET wallet_balance=0 WHERE id=?')->execute([$mid]);
        }
        $w = ensureMerchantWalletReady($mid);
        $merchantReport[] = $m['email'] . ': bal=' . $w['balance'] . ' avail=' . $w['available'];
    }

    repairPlatformWallet();
    $platform = ensurePlatformWalletReady();

    return [
        'merchants' => $merchantReport,
        'platform' => $platform,
    ];
}

function scanCorruptAmounts(): array
{
    $db = getDB();
    $hits = [];
    $queries = [
        'merchants.wallet_balance' => "SELECT id, email, wallet_balance AS val FROM merchants WHERE wallet_balance > 1000 OR wallet_balance < 0",
        'gateway_settings' => "SELECT setting_key AS label, setting_value AS val FROM gateway_settings WHERE setting_key IN ('platform_wallet_balance','min_platform_settlement','min_settlement_amount') AND CAST(setting_value AS DECIMAL(20,2)) > 1000",
        'wallet_transactions' => "SELECT id, merchant_id, amount AS val FROM wallet_transactions WHERE ABS(amount) > 1000 LIMIT 20",
        'platform_wallet_transactions' => "SELECT id, amount AS val FROM platform_wallet_transactions WHERE ABS(amount) > 1000 LIMIT 20",
        'transactions.amount' => "SELECT id, merchant_id, amount AS val FROM transactions WHERE amount > 1000 AND amount < 500000 LIMIT 20",
        'transactions.platform_fee' => "SELECT id, merchant_id, platform_fee AS val FROM transactions WHERE COALESCE(platform_fee,0) > 1000 LIMIT 20",
        'settlements' => "SELECT id, merchant_id, amount AS val FROM settlements WHERE amount > 1000 LIMIT 20",
        'platform_settlements' => "SELECT id, amount AS val FROM platform_settlements WHERE amount > 1000 LIMIT 20",
        'payment_links' => "SELECT id, amount AS val FROM payment_links WHERE amount > 100 AND (is_test=1 OR link_id LIKE 'DEMO%') LIMIT 20",
    ];
    foreach ($queries as $label => $sql) {
        try {
            $rows = $db->query($sql)->fetchAll();
            if ($rows) {
                $hits[$label] = $rows;
            }
        } catch (Throwable $e) { /* ok */ }
    }
    return $hits;
}

function autoWalletRepairIfNeeded(): void
{
    fixCorruptGatewaySettings();
    if (!hasCorruptWalletData()) {
        return;
    }
    if (!empty($_SESSION['wallet_repair_v26'])) {
        return;
    }
    $_SESSION['wallet_repair_v26'] = true;
    walletFullRepair();
}

function normalizedSettingAmount(string $key, string $default, float $cap = 100.0): float
{
    fixCorruptGatewaySettings();
    $v = (float)getSetting($key, $default);
    if ($v <= 0 || $v > $cap || !is_finite($v)) {
        return (float)$default;
    }
    return $v;
}

function fixCorruptInvoices(): int
{
    $db = getDB();
    $db->exec('UPDATE invoices SET amount=LEAST(amount,100000), tax_amount=LEAST(tax_amount,10000), total_amount=LEAST(total_amount,100000) WHERE total_amount > 100000 OR amount > 100000');
    return (int)$db->query('SELECT ROW_COUNT()')->fetchColumn();
}

function forceWalletDataReset(): array
{
    ensureWalletEngine();
    $db = getDB();
    fixCorruptGatewaySettings();
    fixCorruptPaymentLinks();
    fixCorruptInvoices();
    fixCorruptTransactionAmounts();

    $db->exec('DELETE FROM platform_wallet_transactions');
    $db->exec('DELETE FROM platform_settlements');
    $db->exec('DELETE FROM wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec('DELETE FROM settlements WHERE amount > 1000');
    $db->exec("UPDATE merchants SET wallet_balance=0 WHERE wallet_balance > 1000 OR wallet_balance < 0");
    $db->exec("UPDATE payment_links SET amount=1.00 WHERE amount > 100");
    $db->exec("UPDATE transactions SET wallet_credited=0 WHERE status='success'");
    $db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='100' WHERE setting_key='min_settlement_amount'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='200000' WHERE setting_key='aml_high_value_threshold'")->execute();

    setPlatformWalletBalance(0);
    fixCorruptTransactionAmounts();
    sealWalletCredits();

    return [
        'platform' => getPlatformWalletBalance(),
        'links' => fixCorruptPaymentLinks(),
    ];
}

function purgeCorruptWalletData(): array
{
    ensureWalletEngine();
    $db = getDB();
    fixCorruptGatewaySettings();

    fixCorruptPaymentLinks();
    $db->exec("UPDATE payment_links SET amount=1.00 WHERE amount > 100");
    $db->exec('DELETE FROM wallet_transactions WHERE ABS(amount) > 1000');
    $db->exec('DELETE FROM settlements WHERE amount > 1000 OR amount < 0');
    $db->exec("UPDATE settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing') AND amount > 100");
    $db->exec('DELETE FROM platform_settlements WHERE amount > 1000 OR amount < 0');
    $db->exec("UPDATE platform_settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing')");
    $db->exec('DELETE FROM platform_wallet_transactions WHERE ABS(amount) > 1000');

    fixCorruptTransactionAmounts();
    $db->exec("UPDATE transactions SET wallet_credited=0 WHERE status='success' AND (amount > 100 OR COALESCE(platform_fee,0) > 100 OR COALESCE(split_amount,0) > 100)");
    foreach ($db->query('SELECT id FROM merchants')->fetchAll() as $m) {
        $mid = (int)$m['id'];
        $sx = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
        $sx->execute([$mid]);
        if ((int)$sx->fetchColumn() > 0) {
            resetMerchantWalletFromTransactions($mid);
        }
    }
    repairPlatformWallet();
    walletSanityRepair();

    $report = [];
    foreach ($db->query('SELECT id, email FROM merchants')->fetchAll() as $m) {
        $w = ensureMerchantWalletReady((int)$m['id']);
        $report[] = $m['email'] . ': ' . $w['balance'];
    }

    return [
        'merchants' => $report,
        'platform' => getPlatformWalletBalance(),
        'min_settlement' => normalizedSettingAmount('min_settlement_amount', '100'),
        'min_platform' => normalizedSettingAmount('min_platform_settlement', '1'),
    ];
}

function walletSanityRepair(): void
{
    $db = getDB();
    try {
        fixCorruptGatewaySettings();
        $suspect = $db->query("SELECT id, wallet_balance, account_mode FROM merchants WHERE wallet_balance > 1000 OR wallet_balance < 0")->fetchAll();
        foreach ($suspect as $row) {
            $mid = (int)$row['id'];
            $stmt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE merchant_id=?');
            $stmt->execute([$mid]);
            $ledger = (float)$stmt->fetchColumn();
            if ($ledger > 1000 || (float)$row['wallet_balance'] > 1000) {
                resetMerchantWalletFromTransactions($mid);
            } else {
                rebuildMerchantWalletBalance($mid);
            }
        }
        $ledgerSum = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM platform_wallet_transactions')->fetchColumn();
        $stored = (float)getSetting('platform_wallet_balance', '0');
        $pendingSum = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn();
        if ($pendingSum > 1000) {
            $db->exec("DELETE FROM platform_settlements WHERE amount > 1000 OR amount < 0");
            $db->exec("UPDATE platform_settlements SET status='failed' WHERE status IN ('pending','processing') AND amount <= 1000");
            $pendingSum = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn();
        }
        if (abs($stored - $ledgerSum) > 0.01 || $stored > 1000 || $ledgerSum > 1000) {
            if ($ledgerSum > 1000) {
                $db->exec('DELETE FROM platform_wallet_transactions WHERE ABS(amount) > 1000');
                $ledgerSum = (float)$db->query('SELECT COALESCE(SUM(amount), 0) FROM platform_wallet_transactions')->fetchColumn();
            }
            if ($ledgerSum > 1000 || $stored > 1000) {
                repairPlatformWallet();
                $ledgerSum = (float)getPlatformWalletBalance();
            }
            setPlatformWalletBalance(round($ledgerSum, 2));
        }
    } catch (Throwable $e) { /* ok */ }
}

function resetMerchantWalletFromTransactions(int $merchantId): float
{
    ensureWalletEngine();
    $db = getDB();
    $db->prepare('DELETE FROM wallet_transactions WHERE merchant_id = ?')->execute([$merchantId]);
    $db->prepare('UPDATE merchants SET wallet_balance = 0 WHERE id = ?')->execute([$merchantId]);
    $db->prepare('UPDATE transactions SET wallet_credited = 0 WHERE merchant_id = ? AND status = ?')->execute([$merchantId, 'success']);
    $ids = $db->prepare('SELECT id FROM transactions WHERE merchant_id = ? AND status = ?');
    $ids->execute([$merchantId, 'success']);
    foreach ($ids->fetchAll() as $r) {
        creditWalletsFromTransaction((int)$r['id']);
    }
    return refreshMerchantWalletBalance($merchantId);
}

function repairAllWallets(): array
{
    $result = walletFullRepair();
    return [
        'merchants' => $result['merchants'],
        'backfilled' => 0,
        'platform' => $result['platform']['balance'] ?? 0,
        'min_settlement' => normalizedSettingAmount('min_settlement_amount', '100'),
        'min_platform' => normalizedSettingAmount('min_platform_settlement', '1'),
    ];
}

function repairPlatformWallet(): float
{
    ensureWalletEngine();
    $db = getDB();
    fixCorruptGatewaySettings();
    fixCorruptTransactionAmounts();

    $db->exec('DELETE FROM platform_wallet_transactions');
    $db->exec("UPDATE platform_settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing')");
    $db->exec('DELETE FROM platform_settlements WHERE amount > 100 OR amount < 0');
    setPlatformWalletBalance(0);
    $db->prepare("UPDATE gateway_settings SET setting_value='1' WHERE setting_key='min_platform_settlement'")->execute();
    $db->prepare("UPDATE gateway_settings SET setting_value='0' WHERE setting_key='platform_wallet_balance'")->execute();
    clearSettingCache();

    $txns = $db->query("SELECT id, platform_fee, amount, txn_id, merchant_id, is_test FROM transactions
        WHERE status='success' AND COALESCE(platform_fee,0) > 0
        AND ((is_test=1 AND amount <= 100 AND COALESCE(platform_fee,0) <= 100) OR (is_test=0 AND amount <= 500000))
        ORDER BY id ASC")->fetchAll();
    foreach ($txns as $t) {
        $fee = min((float)$t['platform_fee'], walletCreditCap(!empty($t['is_test'])));
        if ($fee <= 0 || $fee > 1000) {
            continue;
        }
        $ref = (string)($t['txn_id'] ?? ('TXN' . $t['id']));
        creditPlatformWallet($fee, 'commission', (int)$t['id'], (int)$t['merchant_id'], $ref, 'Commission from ' . $ref, !empty($t['is_test']));
    }

    return rebuildPlatformWalletBalance();
}

function ensurePlatformWalletReady(): array
{
    ensureWalletEngine();
    fixCorruptGatewaySettings();
    $db = getDB();

    $stored = (float)getPlatformWalletBalance();
    $ledgerSum = (float)$db->query('SELECT COALESCE(SUM(amount),0) FROM platform_wallet_transactions')->fetchColumn();
    $pending = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn();

    if ($stored > 1000 || $ledgerSum > 1000 || $pending > 1000 || abs($stored - $ledgerSum) > 0.02) {
        repairPlatformWallet();
        $stored = (float)getPlatformWalletBalance();
        $ledgerSum = $stored;
        $pending = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn();
    }

    if ($pending > 1000) {
        $db->exec("UPDATE platform_settlements SET status='failed', processed_at=NOW() WHERE status IN ('pending','processing')");
        $pending = 0;
    }

    $balance = walletAmount(min($stored, $ledgerSum), true);
    $pending = walletAmount($pending, true);
    $commission = walletAmount((float)$db->query("SELECT COALESCE(SUM(amount),0) FROM platform_wallet_transactions WHERE type='commission' AND amount > 0 AND amount <= 1000")->fetchColumn(), true);

    return [
        'balance' => $balance,
        'available' => walletAmount(max(0, $balance - $pending), true),
        'pending' => $pending,
        'commission' => min($commission, $balance),
    ];
}

function getEffectivePlatformMinWithdraw(float $available): float
{
    $available = walletAmount($available, true);
    if ($available <= 0) {
        return 1.0;
    }
    return min(1.0, $available);
}

function syncMerchantWallet(int $merchantId): float
{
    ensureWalletEngine();
    fixCorruptGatewaySettings();
    reconcileMerchantWallet($merchantId);
    $db = getDB();
    $rows = $db->prepare("SELECT id FROM transactions WHERE merchant_id=? AND status='success' AND (wallet_credited IS NULL OR wallet_credited=0)");
    $rows->execute([$merchantId]);
    foreach ($rows->fetchAll() as $r) {
        creditWalletsFromTransaction((int)$r['id']);
    }
    return refreshMerchantWalletBalance($merchantId);
}

function ensureMerchantWalletReady(int $merchantId): array
{
    ensureWalletEngine();
    $db = getDB();

    $merchant = null;
    try {
        $mst = $db->prepare('SELECT * FROM merchants WHERE id=?');
        $mst->execute([$merchantId]);
        $merchant = $mst->fetch() ?: null;
    } catch (Throwable $e) { /* ok */ }

    $isTest = isSettlementSandbox($merchant);

    if ($merchant && $isTest) {
        clearStuckTestSettlements($merchantId);
    }

    reconcileMerchantWallet($merchantId);

    $st = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE merchant_id=?');
    $st->execute([$merchantId]);
    $bal = round((float)$st->fetchColumn(), 2);
    $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$bal, $merchantId]);

    $sx = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success'");
    $sx->execute([$merchantId]);
    $successCount = (int)$sx->fetchColumn();

    $uc = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success' AND (wallet_credited IS NULL OR wallet_credited=0)");
    $uc->execute([$merchantId]);
    if ((int)$uc->fetchColumn() > 0) {
        $rows = $db->prepare("SELECT id FROM transactions WHERE merchant_id=? AND status='success' AND (wallet_credited IS NULL OR wallet_credited=0)");
        $rows->execute([$merchantId]);
        foreach ($rows->fetchAll() as $r) {
            creditWalletsFromTransaction((int)$r['id']);
        }
        $st->execute([$merchantId]);
        $bal = round((float)$st->fetchColumn(), 2);
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$bal, $merchantId]);
    }

    $available = getMerchantAvailableBalance($merchantId);
    return [
        'balance' => walletAmount($bal, $isTest),
        'available' => walletAmount($available, $isTest),
        'success_txns' => $successCount,
        'is_test' => $isTest,
    ];
}

/** Sandbox settlement = Test Mode dashboard / test account / demo store — complete instantly */
function isSettlementSandbox(?array $merchant): bool
{
    if ($merchant && strcasecmp((string)($merchant['email'] ?? ''), 'demo@uniweb.co.in') === 0) {
        return true;
    }
    if (function_exists('isMerchantPaymentTest')) {
        return isMerchantPaymentTest($merchant);
    }
    return isMerchantTest($merchant);
}

function processMerchantSettlement(int $merchantId, array $merchant, float $amount): array
{
    $db = getDB();
    ensureWalletEngine();
    $isTest = isSettlementSandbox($merchant);
    if ($isTest) {
        clearStuckTestSettlements($merchantId);
    }

    ensureMerchantWalletReady($merchantId);
    $available = walletAmount(getMerchantAvailableBalance($merchantId), $isTest);
    $min = walletAmount(getEffectiveMinSettlement($merchant, $available), $isTest);

    if ($amount <= 0) {
        $amount = $available;
    }
    $amount = round($amount, 2);

    if ($amount < $min) {
        return ['ok' => false, 'error' => 'Minimum transfer is ' . formatMoney($min) . '. Available: ' . formatMoney($available) . '.'];
    }
    if ($amount > $available) {
        return ['ok' => false, 'error' => 'Insufficient balance. Available: ' . formatMoney($available) . '.'];
    }

    $bank = $db->prepare('SELECT * FROM bank_accounts WHERE merchant_id = ? AND is_primary = 1 AND status = ?');
    $bank->execute([$merchantId, 'active']);
    $bankAccount = $bank->fetch();

    if (!$bankAccount && $isTest) {
        try {
            $db->prepare('INSERT INTO bank_accounts (merchant_id, bank_name, account_holder, account_number, ifsc_code, account_type, is_primary, status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$merchantId, 'Axis Bank', $merchant['business_name'] ?? 'Merchant', '925020001534663', 'UTIB0000249', 'current', 1, 'active']);
        } catch (Throwable $e) { /* ok */ }
        $bank->execute([$merchantId, 'active']);
        $bankAccount = $bank->fetch();
    }

    if (!$bankAccount) {
        return ['ok' => false, 'error' => 'Add bank account first.', 'redirect' => 'add_bank.php'];
    }

    $settlementId = generateId('STL');

    if (!debitMerchantWallet($merchantId, $amount, 'settlement', null, $settlementId, 'Transfer to bank')) {
        ensureMerchantWalletReady($merchantId);
        return ['ok' => false, 'error' => 'Wallet debit failed. Balance synced — try again.'];
    }

    try {
        $db->prepare('INSERT INTO settlements (settlement_id, merchant_id, amount, fee, net_amount, bank_account_id, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$settlementId, $merchantId, $amount, 0, $amount, $bankAccount['id'], $isTest ? 'completed' : 'pending']);

        if ($isTest) {
            $db->prepare("UPDATE settlements SET utr=?, processed_at=NOW() WHERE settlement_id=?")
                ->execute(['TEST' . time(), $settlementId]);
        }
    } catch (Throwable $e) {
        creditMerchantWallet($merchantId, $amount, 'refund', null, $settlementId, 'Transfer failed — refunded');
        error_log('processMerchantSettlement insert: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Transfer failed: ' . $e->getMessage()];
    }

    createNotification($merchantId, 'Bank Transfer', formatMoney($amount) . ' transferred — ' . $settlementId);

    return [
        'ok' => true,
        'settlement_id' => $settlementId,
        'test' => $isTest,
        'message' => $isTest
            ? '₹' . number_format($amount, 2) . ' transferred to bank (test mode — instant).'
            : 'Transfer request submitted. Processing within 24 hours.',
    ];
}

function getPlatformAvailableBalance(): float
{
    ensurePlatformWalletTables();
    $bal = getPlatformWalletBalance();
    try {
        $pending = (float)getDB()->query("SELECT COALESCE(SUM(amount),0) FROM platform_settlements WHERE status IN ('pending','processing')")->fetchColumn();
        return max(0, $bal - $pending);
    } catch (Throwable $e) {
        return max(0, $bal);
    }
}

function setPlatformWalletBalance(float $balance): void
{
    $db = getDB();
    $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute(['platform_wallet_balance', (string)round($balance, 2), (string)round($balance, 2)]);
    clearSettingCache('platform_wallet_balance');
}

function creditMerchantWallet(int $merchantId, float $amount, string $type, ?int $transactionId, string $reference, string $description, ?bool $isTest = null): bool
{
    if ($amount <= 0) return true;
    $db = getDB();
    ensureWalletEngine();

    if ($isTest === null) {
        $m = $db->prepare('SELECT account_mode, kyc_status FROM merchants WHERE id=?');
        $m->execute([$merchantId]);
        $isTest = isMerchantTest($m->fetch() ?: null);
    }
    $cap = walletCreditCap($isTest);
    if ($amount > $cap) {
        $amount = $isTest ? 1.0 : $cap;
    }

    if ($transactionId) {
        $chk = $db->prepare('SELECT id FROM wallet_transactions WHERE merchant_id=? AND transaction_id=? LIMIT 1');
        $chk->execute([$merchantId, $transactionId]);
        if ($chk->fetch()) {
            return true;
        }
    }

    $db->beginTransaction();
    try {
        $sumSt = $db->prepare('SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE merchant_id=?');
        $sumSt->execute([$merchantId]);
        $balance = round((float)$sumSt->fetchColumn() + $amount, 2);
        try {
            $db->prepare('INSERT INTO wallet_transactions (merchant_id, type, amount, balance_after, reference, description, transaction_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$merchantId, $type, $amount, $balance, $reference, $description, $transactionId]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO wallet_transactions (merchant_id, type, amount, balance_after, reference, description) VALUES (?,?,?,?,?,?)')
                ->execute([$merchantId, $type, $amount, $balance, $reference, $description]);
        }
        $db->prepare('UPDATE merchants SET wallet_balance = ? WHERE id = ?')->execute([$balance, $merchantId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('creditMerchantWallet: ' . $e->getMessage());
        return false;
    }
}

function debitMerchantWallet(int $merchantId, float $amount, string $type, ?int $transactionId, string $reference, string $description): bool
{
    if ($amount <= 0) return true;
    ensureWalletEngine();
    $balance = refreshMerchantWalletBalance($merchantId);
    if ($balance < $amount) return false;
    $db = getDB();
    $db->beginTransaction();
    try {
        $newBalance = round($balance - $amount, 2);
        try {
            $db->prepare('INSERT INTO wallet_transactions (merchant_id, type, amount, balance_after, reference, description, transaction_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$merchantId, $type, -$amount, $newBalance, $reference, $description, $transactionId]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO wallet_transactions (merchant_id, type, amount, balance_after, reference, description) VALUES (?,?,?,?,?,?)')
                ->execute([$merchantId, $type, -$amount, $newBalance, $reference, $description]);
        }
        $db->prepare('UPDATE merchants SET wallet_balance = ? WHERE id = ?')->execute([$newBalance, $merchantId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        error_log('debitMerchantWallet: ' . $e->getMessage());
        return false;
    }
}

function creditPlatformWallet(float $amount, string $type, ?int $transactionId, ?int $merchantId, string $reference, string $description, bool $isTest = true): bool
{
    if ($amount <= 0) return true;
    $cap = walletCreditCap($isTest);
    if ($amount > $cap) {
        $amount = $isTest ? 1.0 : $cap;
    }
    $newBalance = getPlatformWalletBalance() + $amount;
    setPlatformWalletBalance($newBalance);
    getDB()->prepare('INSERT INTO platform_wallet_transactions (type, amount, balance_after, transaction_id, merchant_id, reference, description) VALUES (?,?,?,?,?,?,?)')
        ->execute([$type, $amount, $newBalance, $transactionId, $merchantId, $reference, $description]);
    return true;
}

function debitPlatformWallet(float $amount, string $type, ?int $transactionId, string $reference, string $description): bool
{
    if ($amount <= 0) return true;
    $balance = getPlatformWalletBalance();
    if ($balance < $amount) return false;
    $newBalance = $balance - $amount;
    setPlatformWalletBalance($newBalance);
    getDB()->prepare('INSERT INTO platform_wallet_transactions (type, amount, balance_after, transaction_id, reference, description) VALUES (?,?,?,?,?,?)')
        ->execute([$type, -$amount, $newBalance, $transactionId, $reference, $description]);
    return true;
}

function isTransactionWalletCredited(int $transactionId): bool
{
    $row = getDB()->prepare('SELECT wallet_credited FROM transactions WHERE id = ?');
    $row->execute([$transactionId]);
    return (bool)($row->fetchColumn() ?: 0);
}

function markTransactionWalletCredited(int $transactionId): void
{
    try {
        getDB()->prepare('UPDATE transactions SET wallet_credited = 1 WHERE id = ?')->execute([$transactionId]);
    } catch (Throwable $e) {
        // column may not exist yet
    }
}

function creditWalletsFromTransaction(int $transactionId): void
{
    if (isTransactionWalletCredited($transactionId)) return;

    $db = getDB();
    $stmt = $db->prepare('SELECT t.*, m.commission_rate, m.collection_mode FROM transactions t JOIN merchants m ON t.merchant_id = m.id WHERE t.id = ?');
    $stmt->execute([$transactionId]);
    $txn = $stmt->fetch();
    if (!$txn || $txn['status'] !== 'success') return;

    $isTest = !empty($txn['is_test']);
    $cap = walletCreditCap($isTest);

    $gross = sanitizePaymentAmount((float)$txn['amount'], $isTest);
    if ($isTest && ($gross > 100 || $gross < 0)) {
        $gross = 1.0;
        $db->prepare('UPDATE transactions SET amount=1.00, platform_fee=0, split_amount=1.00 WHERE id=?')->execute([$transactionId]);
        $txn['amount'] = 1.0;
    } elseif (!$isTest && ($gross <= 0 || $gross > 500000)) {
        return;
    }

    $split = [
        'platform_fee' => (float)($txn['platform_fee'] ?? 0),
        'merchant_net' => (float)($txn['split_amount'] ?? 0),
    ];
    if ($split['merchant_net'] > $cap || $split['merchant_net'] < 0) {
        $split['merchant_net'] = $gross;
        $split['platform_fee'] = 0;
    }
    if ($split['platform_fee'] > $cap || $split['platform_fee'] < 0) {
        $split['platform_fee'] = 0;
    }
    if ($split['merchant_net'] <= 0 && $split['platform_fee'] <= 0) {
        $calc = calculateSplitBreakdown($gross, $txn);
        $split['platform_fee'] = min((float)$calc['platform_fee'], $cap);
        $split['merchant_net'] = min((float)$calc['merchant_net'], $cap);
    }

    $mode = $txn['collection_mode'] ?? 'platform_pg';
    $ref = $txn['txn_id'] ?? ('TXN' . $transactionId);
    $desc = 'Payment collection — ' . ($txn['payment_method'] ?? 'payment') . ($isTest ? ' (Test/Sandbox)' : '');

    $merchantAmount = $mode === 'direct_upi'
        ? $gross
        : (float)$split['merchant_net'];
    $merchantAmount = min(max(0, $merchantAmount), $cap);
    if ($merchantAmount <= 0) {
        $merchantAmount = max(0, (float)$txn['amount'] - (float)$split['platform_fee']);
    }
    if ($merchantAmount <= 0 && (float)$txn['amount'] > 0) {
        $merchantAmount = min((float)$txn['amount'], $isTest ? 1.0 : $cap);
    }
    if ($isTest && $merchantAmount < 0.01) {
        $merchantAmount = 1.0;
    }

    $credited = false;
    if ($merchantAmount > 0) {
        $credited = creditMerchantWallet((int)$txn['merchant_id'], $merchantAmount, 'credit', $transactionId, $ref, $desc, $isTest);
    }
    $platformFee = min((float)$split['platform_fee'], $cap);
    if ($platformFee > 0) {
        creditPlatformWallet($platformFee, 'commission', $transactionId, (int)$txn['merchant_id'], $ref, 'Commission from ' . $ref, $isTest);
    }

    if ($credited) {
        markTransactionWalletCredited($transactionId);
    }
}

function getMinSettlementForMerchant(?array $merchant = null): float
{
    $merchant = $merchant ?? (function () {
        try { return getMerchant(); } catch (Throwable $e) { return null; }
    })();
    if ($merchant && isMerchantTest($merchant)) {
        return 1.0;
    }
    return normalizedSettingAmount('min_settlement_amount', (string)MIN_SETTLEMENT, 100.0);
}

function getEffectiveMinSettlement(?array $merchant, float $available): float
{
    $isTest = isMerchantTest($merchant);
    $min = getMinSettlementForMerchant($merchant);
    $available = walletAmount($available, $isTest);
    if ($isTest) {
        return 1.0;
    }
    $min = min($min, 100.0);
    if ($min > 100 || $min < 0 || !is_finite($min)) {
        $min = 100.0;
    }
    if ($available > 0 && $available < $min) {
        return max(1.0, round($available, 2));
    }
    return $min;
}

/** Bank/PG approvers expect exactly 3 settlement states — collapse any legacy/odd DB value into one of these. */
function canonicalSettlementStatus(?string $raw): array
{
    $raw = strtolower(trim((string)$raw));
    if (in_array($raw, ['completed', 'complete', 'success', 'settled', 'paid'], true)) {
        return ['key' => 'completed', 'label' => 'Complete', 'class' => 'text-brand-400'];
    }
    if (in_array($raw, ['failed', 'fail', 'rejected', 'cancelled', 'canceled', 'error'], true)) {
        return ['key' => 'failed', 'label' => 'Failed', 'class' => 'text-red-400'];
    }
    // Anything else (pending, processing, initiated, unknown/corrupt legacy text) reads as Pending.
    return ['key' => 'pending', 'label' => 'Pending', 'class' => 'text-amber-400'];
}

function settlementStatusBadge(?string $raw): string
{
    $s = canonicalSettlementStatus($raw);
    $dot = ['completed' => 'bg-emerald-400', 'failed' => 'bg-red-400', 'pending' => 'bg-amber-400'][$s['key']];
    return '<span class="inline-flex items-center gap-1.5 text-xs font-semibold ' . $s['class'] . '"><span class="w-1.5 h-1.5 rounded-full ' . $dot . '"></span>' . e($s['label']) . '</span>';
}

/**
 * Human-readable reason for a settlement's current status — bank/PG approvers and
 * merchants both need to see WHY something is pending/failed/complete, not just a label.
 */
function settlementReasonText(array $settlement, ?array $merchant = null): string
{
    $s = canonicalSettlementStatus($settlement['status'] ?? null);
    $isTest = isSettlementSandbox($merchant);
    $utr = trim((string)($settlement['utr'] ?? ''));
    $amount = formatMoney((float)($settlement['amount'] ?? $settlement['net_amount'] ?? 0));

    if ($s['key'] === 'completed') {
        if ($isTest || stripos($utr, 'TEST') === 0) {
            return 'Sandbox settlement — instantly marked complete because this is a Test Mode transfer. No real bank movement.';
        }
        return $utr !== ''
            ? "Bank transfer completed. UTR $utr confirms {$amount} reached the merchant's bank account."
            : "Marked complete — {$amount} settled to merchant's registered bank account.";
    }

    if ($s['key'] === 'failed') {
        $note = trim((string)($settlement['api_message'] ?? $settlement['failure_reason'] ?? ''));
        if ($note !== '') {
            return 'Failed: ' . $note;
        }
        return 'Failed — bank rejected the transfer or payout API returned an error. Check bank account details (IFSC/account number) and retry.';
    }

    // pending
    if ($isTest) {
        return 'Test settlement queued — should auto-complete within seconds. If it stays pending, refresh this page.';
    }
    return "Submitted to bank for processing. Live NEFT/IMPS payouts typically clear within 24 hours on business days. {$amount} is reserved and will move once the bank confirms.";
}

function clearStuckTestSettlements(int $merchantId): int
{
    $db = getDB();
    $st = $db->prepare("SELECT id, settlement_id, amount, status FROM settlements WHERE merchant_id=? AND status IN ('pending','processing')");
    $st->execute([$merchantId]);
    $rows = $st->fetchAll();
    if (empty($rows)) {
        return 0;
    }
    $cleared = 0;
    foreach ($rows as $row) {
        $ref = (string)$row['settlement_id'];
        $chk = $db->prepare("SELECT id FROM wallet_transactions WHERE merchant_id=? AND reference=? AND amount < 0 LIMIT 1");
        $chk->execute([$merchantId, $ref]);
        if ($chk->fetch()) {
            $db->prepare("UPDATE settlements SET status='completed', utr=?, processed_at=NOW() WHERE id=?")
                ->execute(['AUTO' . $row['id'], (int)$row['id']]);
        } else {
            $db->prepare("UPDATE settlements SET status='failed', processed_at=NOW() WHERE id=?")->execute([(int)$row['id']]);
        }
        $cleared++;
    }
    return $cleared;
}

function getMerchantAvailableBalance(int $merchantId): float
{
    $db = getDB();
    $mst = $db->prepare('SELECT account_mode, kyc_status FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $isTest = isMerchantTest($mst->fetch() ?: null);
    $wallet = refreshMerchantWalletBalance($merchantId);
    $pending = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM settlements WHERE merchant_id = ? AND status IN ('pending','processing')");
    $pending->execute([$merchantId]);
    $pendingAmt = (float)$pending->fetchColumn();
    $pendingCap = walletCorruptThreshold($isTest);
    if ($pendingAmt > $pendingCap) {
        $db->prepare("UPDATE settlements SET status='failed', processed_at=NOW() WHERE merchant_id=? AND status IN ('pending','processing') AND amount>?")->execute([$merchantId, $isTest ? 100 : 500000]);
        $pendingAmt = 0;
    }
    return walletAmount(max(0, $wallet - $pendingAmt), $isTest);
}

function getMerchantWalletLedger(int $merchantId, int $limit = 40): array
{
    $stmt = getDB()->prepare('SELECT * FROM wallet_transactions WHERE merchant_id = ? ORDER BY created_at DESC LIMIT ?');
    $stmt->bindValue(1, $merchantId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function getPlatformWalletLedger(int $limit = 40): array
{
    return getDB()->query('SELECT * FROM platform_wallet_transactions ORDER BY created_at DESC LIMIT ' . (int)$limit)->fetchAll();
}

function approvePendingTransaction(int $transactionId): bool
{
    $db = getDB();
    $txn = $db->prepare('SELECT * FROM transactions WHERE id = ?');
    $txn->execute([$transactionId]);
    $row = $txn->fetch();
    if (!$row || $row['status'] !== 'pending') return false;

    $db->prepare("UPDATE transactions SET status = 'success' WHERE id = ?")->execute([$transactionId]);
    creditWalletsFromTransaction($transactionId);
    return true;
}

function backfillWalletCredits(): int
{
    ensureWalletEngine();
    fixCorruptTransactionAmounts();
    fixCorruptPaymentLinks();
    $db = getDB();
    $rows = $db->query("SELECT id FROM transactions WHERE status = 'success' AND (wallet_credited IS NULL OR wallet_credited = 0)")->fetchAll();
    $count = 0;
    foreach ($rows as $r) {
        creditWalletsFromTransaction((int)$r['id']);
        $count++;
    }
    return $count;
}

function getUncreditedSuccessCount(): int
{
    ensureWalletEngine();
    try {
        return (int)getDB()->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND (wallet_credited IS NULL OR wallet_credited=0)")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
