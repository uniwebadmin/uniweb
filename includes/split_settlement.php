<?php
declare(strict_types=1);

/**
 * Split Settlement at Transaction Level.
 *
 * When a payment is collected, the platform fee and merchant net are tracked
 * separately from the gross amount. This module provides functions to:
 *   - Calculate the split for a transaction (gross → platform_fee + merchant_net + gst)
 *   - Record the split in a dedicated table
 *   - Query splits for settlement and reconciliation
 *
 * Split formula:
 *   platform_fee = gross * mdr_rate + fixed_fee
 *   gst_on_fee = platform_fee * 0.18 (18% GST on platform fee)
 *   merchant_net = gross - platform_fee - gst_on_fee
 */

function ensureSplitSettlementTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS transaction_splits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            merchant_id INT NOT NULL,
            gross_amount DECIMAL(14,2) NOT NULL,
            platform_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            gst_on_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            merchant_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            mdr_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
            fixed_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            split_status ENUM('pending','settled','reversed') NOT NULL DEFAULT 'pending',
            settled_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_txn (transaction_id),
            INDEX idx_merchant_status (merchant_id, split_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Get MDR rate and fixed fee for a merchant.
 */
function getMerchantSplitConfig(int $merchantId): array
{
    $db = getDB();
    $mdrRate = 0.02; // 2% default
    $fixedFee = 0.0;

    try {
        $st = $db->prepare("SELECT mdr_rate, fixed_fee FROM merchant_split_config WHERE merchant_id=?");
        $st->execute([$merchantId]);
        $row = $st->fetch();
        if ($row) {
            $mdrRate = (float)$row['mdr_rate'];
            $fixedFee = (float)$row['fixed_fee'];
        }
    } catch (Throwable $e) {}

    return [
        'mdr_rate' => $mdrRate,
        'fixed_fee' => $fixedFee,
        'gst_rate' => 0.18, // 18% GST on platform fee
    ];
}

/**
 * Calculate the split for a transaction.
 */
function calculateTransactionSplit(float $grossAmount, int $merchantId): array
{
    $config = getMerchantSplitConfig($merchantId);
    $platformFee = round($grossAmount * $config['mdr_rate'] + $config['fixed_fee'], 2);
    $gstOnFee = round($platformFee * $config['gst_rate'], 2);
    $merchantNet = round($grossAmount - $platformFee - $gstOnFee, 2);

    return [
        'gross_amount' => $grossAmount,
        'platform_fee' => $platformFee,
        'gst_on_fee' => $gstOnFee,
        'merchant_net' => $merchantNet,
        'mdr_rate' => $config['mdr_rate'],
        'fixed_fee' => $config['fixed_fee'],
    ];
}

/**
 * Record a split for a transaction (called after successful payment).
 */
function recordTransactionSplit(int $transactionId, int $merchantId, float $grossAmount): array
{
    ensureSplitSettlementTable();
    $split = calculateTransactionSplit($grossAmount, $merchantId);

    try {
        getDB()->prepare(
            "INSERT INTO transaction_splits
             (transaction_id, merchant_id, gross_amount, platform_fee, gst_on_fee, merchant_net, mdr_rate, fixed_fee, split_status)
             VALUES (?,?,?,?,?,?,?,?, 'pending')
             ON DUPLICATE KEY UPDATE gross_amount=VALUES(gross_amount), platform_fee=VALUES(platform_fee),
             gst_on_fee=VALUES(gst_on_fee), merchant_net=VALUES(merchant_net)"
        )->execute([
            $transactionId, $merchantId,
            $split['gross_amount'], $split['platform_fee'], $split['gst_on_fee'],
            $split['merchant_net'], $split['mdr_rate'], $split['fixed_fee'],
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'split' => $split];
}

/**
 * Mark splits as settled when a settlement batch is processed.
 */
function markSplitsSettled(int $merchantId, array $transactionIds): int
{
    ensureSplitSettlementTable();
    if (empty($transactionIds)) return 0;

    try {
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $st = getDB()->prepare(
            "UPDATE transaction_splits SET split_status='settled', settled_at=NOW()
             WHERE merchant_id=? AND transaction_id IN ($placeholders) AND split_status='pending'"
        );
        $st->execute(array_merge([$merchantId], $transactionIds));
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get pending splits for a merchant (for settlement calculation).
 */
function getPendingSplits(int $merchantId, int $limit = 1000): array
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare(
            "SELECT ts.*, t.txn_id, t.created_at AS txn_at
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             WHERE ts.merchant_id=? AND ts.split_status='pending'
             ORDER BY ts.created_at ASC LIMIT ?"
        );
        $st->bindValue(1, $merchantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get split summary for a merchant.
 */
function getSplitSummary(int $merchantId, int $days = 30): array
{
    ensureSplitSettlementTable();
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));
    try {
        $st = getDB()->prepare(
            "SELECT
                COUNT(*) as total_splits,
                COALESCE(SUM(gross_amount),0) as total_gross,
                COALESCE(SUM(platform_fee),0) as total_fee,
                COALESCE(SUM(gst_on_fee),0) as total_gst,
                COALESCE(SUM(merchant_net),0) as total_net,
                SUM(CASE WHEN split_status='settled' THEN 1 ELSE 0 END) as settled_count,
                SUM(CASE WHEN split_status='pending' THEN 1 ELSE 0 END) as pending_count
             FROM transaction_splits WHERE merchant_id=? AND created_at >= ?"
        );
        $st->execute([$merchantId, $since]);
        return $st->fetch() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Update merchant's split config (admin action).
 */
function updateMerchantSplitConfig(int $merchantId, float $mdrRate, float $fixedFee): bool
{
    ensureSplitSettlementTable();
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_split_config (
            merchant_id INT PRIMARY KEY,
            mdr_rate DECIMAL(6,4) NOT NULL DEFAULT 0.0200,
            fixed_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        getDB()->prepare(
            "INSERT INTO merchant_split_config (merchant_id, mdr_rate, fixed_fee)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE mdr_rate=VALUES(mdr_rate), fixed_fee=VALUES(fixed_fee)"
        )->execute([$merchantId, $mdrRate, $fixedFee]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
