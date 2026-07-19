<?php
declare(strict_types=1);

function ensureRefundsEngine(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS refunds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            refund_id VARCHAR(32) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            transaction_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
            reason TEXT,
            admin_note VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME NULL,
            INDEX (merchant_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function processRefund(int $transactionId, float $amount, string $reason, ?int $adminId = null): array
{
    ensureRefundsEngine();
    ensureWalletEngine();
    $db = getDB();

    $st = $db->prepare('SELECT t.*, m.email, m.business_name FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.id=?');
    $st->execute([$transactionId]);
    $txn = $st->fetch();
    if (!$txn) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }
    if ($txn['status'] !== 'success') {
        return ['ok' => false, 'error' => 'Only successful transactions can be refunded.'];
    }

    $isTest = !empty($txn['is_test']);
    $max = sanitizePaymentAmount((float)$txn['amount'], $isTest);
    if ($amount <= 0) {
        $amount = $max;
    }
    $amount = min($amount, $max);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid refund amount.'];
    }

    $dup = $db->prepare("SELECT id FROM refunds WHERE transaction_id=? AND status IN ('pending','completed')");
    $dup->execute([$transactionId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'error' => 'Refund already exists for this transaction.'];
    }

    $refundId = generateId('RFD');
    $db->prepare('INSERT INTO refunds (refund_id, merchant_id, transaction_id, amount, status, reason, admin_note) VALUES (?,?,?,?,?,?,?)')
        ->execute([$refundId, (int)$txn['merchant_id'], $transactionId, $amount, 'pending', $reason, $adminId ? 'admin:' . $adminId : null]);

    $merchantId = (int)$txn['merchant_id'];
    $mst = $db->prepare('SELECT * FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch();

    if ($merchant && debitMerchantWallet($merchantId, $amount, 'refund', $transactionId, $refundId, 'Refund for ' . $txn['txn_id'])) {
        $db->prepare("UPDATE transactions SET status='refunded' WHERE id=?")->execute([$transactionId]);
        $db->prepare("UPDATE refunds SET status='completed', processed_at=NOW() WHERE refund_id=?")->execute([$refundId]);
        createNotification($merchantId, 'Refund Processed', formatMoney($amount) . ' refunded for ' . $txn['txn_id']);
        dispatchMerchantWebhook($merchantId, 'refund.completed', [
            'refund_id' => $refundId,
            'txn_id' => $txn['txn_id'],
            'amount' => $amount,
        ]);
        return ['ok' => true, 'refund_id' => $refundId, 'amount' => $amount];
    }

    $db->prepare("UPDATE refunds SET status='failed', processed_at=NOW() WHERE refund_id=?")->execute([$refundId]);
    return ['ok' => false, 'error' => 'Wallet debit failed — insufficient merchant balance for refund.'];
}

function getMerchantRefunds(int $merchantId, int $limit = 30): array
{
    ensureRefundsEngine();
    $st = getDB()->prepare('SELECT r.*, t.txn_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.merchant_id=? ORDER BY r.created_at DESC LIMIT ?');
    $st->bindValue(1, $merchantId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
