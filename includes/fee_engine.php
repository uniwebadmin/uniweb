<?php
declare(strict_types=1);

/**
 * No-custody commercial fee reporting. This module never debits customer or
 * merchant settlement funds; it only snapshots reportable amounts.
 */
function ensureFeeEngineSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS fee_rules (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        partner_key VARCHAR(40) NOT NULL,
        method_key VARCHAR(40) NOT NULL DEFAULT '*',
        merchant_tier VARCHAR(40) NOT NULL DEFAULT '*',
        platform_fee_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
        platform_fee_flat DECIMAL(12,2) NOT NULL DEFAULT 0,
        merchant_fee_pct DECIMAL(8,4) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fee_rule (partner_key, method_key, merchant_tier)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS transaction_fee_snapshots (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT UNSIGNED NOT NULL,
        merchant_id INT UNSIGNED NOT NULL,
        partner_key VARCHAR(40) NOT NULL,
        method_key VARCHAR(40) NOT NULL,
        mode VARCHAR(12) NOT NULL DEFAULT 'live',
        amount DECIMAL(12,2) NOT NULL,
        platform_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        merchant_fee DECIMAL(12,2) NOT NULL DEFAULT 0,
        rule_id INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_transaction_fee_snapshot (transaction_id),
        KEY idx_fee_snapshot_rollup (partner_key, mode, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS fee_invoice_drafts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        partner_key VARCHAR(40) NOT NULL,
        period_start DATE NOT NULL,
        period_end DATE NOT NULL,
        transaction_count INT UNSIGNED NOT NULL DEFAULT 0,
        platform_fee_total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('draft','sent','paid') NOT NULL DEFAULT 'draft',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_fee_invoice_period (partner_key, period_start, period_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function feeEngineMethodKey(string $method): string
{
    $method = strtolower(trim($method));
    return match ($method) {
        'dc', 'cc', 'card', 'credit_card', 'debit_card' => 'card',
        'nb', 'net_banking', 'netbanking' => 'netbanking',
        'payu_upi', 'razorpay_upi', 'cashfree_upi' => 'upi',
        default => $method !== '' ? $method : '*',
    };
}

/** @return array{rule_id:int,platform_fee:float,merchant_fee:float} */
function calculateTransactionFeeSnapshot(array $transaction): array
{
    ensureFeeEngineSchema();
    $partner = strtolower(trim((string)($transaction['partner_key'] ?? '')));
    $method = feeEngineMethodKey((string)($transaction['payment_method'] ?? ''));
    $amount = max(0, round((float)($transaction['amount'] ?? 0), 2));
    $rule = null;
    if ($partner !== '') {
        $st = getDB()->prepare(
            "SELECT * FROM fee_rules
             WHERE partner_key=? AND is_active=1
               AND method_key IN (?, '*') AND merchant_tier IN ('*')
             ORDER BY method_key<>'*' DESC, id DESC LIMIT 1"
        );
        $st->execute([$partner, $method]);
        $rule = $st->fetch() ?: null;
    }
    $platform = $rule ? max((float)$rule['platform_fee_flat'], $amount * ((float)$rule['platform_fee_pct'] / 100)) : 0.0;
    $merchant = $rule ? $amount * ((float)$rule['merchant_fee_pct'] / 100) : 0.0;
    return [
        'rule_id' => (int)($rule['id'] ?? 0),
        'platform_fee' => round($platform, 2),
        'merchant_fee' => round($merchant, 2),
    ];
}

function recordTransactionFeeSnapshot(int $transactionId): bool
{
    if ($transactionId <= 0) {
        return false;
    }
    ensureFeeEngineSchema();
    $st = getDB()->prepare('SELECT id,merchant_id,partner_key,payment_method,amount,is_test,status FROM transactions WHERE id=? LIMIT 1');
    $st->execute([$transactionId]);
    $transaction = $st->fetch();
    if (!$transaction || !in_array(strtolower((string)$transaction['status']), ['success', 'paid', 'captured'], true)) {
        return false;
    }
    $fees = calculateTransactionFeeSnapshot($transaction);
    getDB()->prepare(
        'INSERT INTO transaction_fee_snapshots
         (transaction_id,merchant_id,partner_key,method_key,mode,amount,platform_fee,merchant_fee,rule_id)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE platform_fee=VALUES(platform_fee), merchant_fee=VALUES(merchant_fee), rule_id=VALUES(rule_id)'
    )->execute([
        (int)$transaction['id'],
        (int)$transaction['merchant_id'],
        strtolower(trim((string)($transaction['partner_key'] ?? ''))) ?: 'unknown',
        feeEngineMethodKey((string)$transaction['payment_method']),
        !empty($transaction['is_test']) ? 'test' : 'live',
        (float)$transaction['amount'],
        $fees['platform_fee'],
        $fees['merchant_fee'],
        $fees['rule_id'] > 0 ? $fees['rule_id'] : null,
    ]);
    return true;
}

/** @return list<array<string,mixed>> */
function buildMonthlyFeeRollup(string $periodStart, string $periodEnd, bool $liveOnly = true): array
{
    ensureFeeEngineSchema();
    $modeWhere = $liveOnly ? " AND mode='live'" : '';
    $st = getDB()->prepare(
        "SELECT partner_key, COUNT(*) AS transaction_count, SUM(platform_fee) AS platform_fee_total
         FROM transaction_fee_snapshots
         WHERE created_at>=? AND created_at<? {$modeWhere}
         GROUP BY partner_key ORDER BY partner_key"
    );
    $st->execute([$periodStart . ' 00:00:00', $periodEnd . ' 00:00:00']);
    return $st->fetchAll() ?: [];
}

function createMonthlyFeeInvoiceDrafts(string $periodStart, string $periodEnd, bool $liveOnly = true): int
{
    ensureFeeEngineSchema();
    $count = 0;
    foreach (buildMonthlyFeeRollup($periodStart, $periodEnd, $liveOnly) as $row) {
        getDB()->prepare(
            'INSERT INTO fee_invoice_drafts
             (partner_key,period_start,period_end,transaction_count,platform_fee_total,status)
             VALUES (?,?,?,?,?,\'draft\')
             ON DUPLICATE KEY UPDATE
               transaction_count=VALUES(transaction_count),
               platform_fee_total=VALUES(platform_fee_total)'
        )->execute([
            (string)$row['partner_key'],
            $periodStart,
            $periodEnd,
            (int)$row['transaction_count'],
            round((float)$row['platform_fee_total'], 2),
        ]);
        $count++;
    }
    return $count;
}

function updateFeeInvoiceDraftStatus(int $draftId, string $status): bool
{
    ensureFeeEngineSchema();
    if (!in_array($status, ['draft', 'sent', 'paid'], true) || $draftId < 1) {
        return false;
    }
    $st = getDB()->prepare('UPDATE fee_invoice_drafts SET status=? WHERE id=?');
    $st->execute([$status, $draftId]);
    return $st->rowCount() > 0;
}

/** @return list<array<string,mixed>> */
function exportFeeInvoiceDrafts(string $periodStart = '', string $periodEnd = ''): array
{
    ensureFeeEngineSchema();
    $where = [];
    $params = [];
    if ($periodStart !== '') {
        $where[] = 'period_start >= ?';
        $params[] = $periodStart;
    }
    if ($periodEnd !== '') {
        $where[] = 'period_end <= ?';
        $params[] = $periodEnd;
    }
    $sql = 'SELECT partner_key,period_start,period_end,transaction_count,platform_fee_total,status FROM fee_invoice_drafts';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY period_start DESC, partner_key ASC';
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll() ?: [];
}
