<?php
declare(strict_types=1);

function getPgReconciliationReport(int $days = 7): array
{
    ensurePgWebhookTables();
    $db = getDB();
    $days = max(1, min(90, $days));

    $webhooks = $db->query("SELECT gateway, status, COUNT(*) AS c FROM pg_webhook_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY) GROUP BY gateway, status")->fetchAll();
    $txnSuccess = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();
    $txnPending = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='pending' AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();

    $unmatched = $db->query("SELECT w.id, w.gateway, w.reference, w.link_id, w.status, w.event_type, w.created_at
        FROM pg_webhook_logs w
        LEFT JOIN transactions t ON t.utr = w.reference OR t.txn_id = w.reference
        WHERE w.status IN ('received','processed','success') AND w.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        AND t.id IS NULL
        ORDER BY w.created_at DESC LIMIT 50")->fetchAll();

    $missingWebhooks = $db->query("SELECT t.txn_id, t.amount, t.payment_method, t.utr, t.created_at
        FROM transactions t
        LEFT JOIN pg_webhook_logs w ON w.reference = t.utr OR w.link_id IN (SELECT link_id FROM payment_links WHERE id = t.payment_link_id)
        WHERE t.status='success' AND t.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
        AND t.payment_method IN ('razorpay','cashfree','payu','card','netbanking','wallet')
        AND w.id IS NULL
        ORDER BY t.created_at DESC LIMIT 50")->fetchAll();

    $refunds = 0;
    $delayedRefunds = 0;
    try {
        ensureRefundsEngine();
        $refunds = (int)$db->query("SELECT COUNT(*) FROM refunds WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)")->fetchColumn();
        $delayedRefunds = (int)$db->query("SELECT COUNT(*) FROM refunds WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)")->fetchColumn();
    } catch (Throwable $e) { /* ok */ }
    $delayedSettlements = 0;
    try {
        $delayedSettlements = (int)$db->query("SELECT COUNT(*) FROM settlements WHERE status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    return [
        'days' => $days,
        'webhook_stats' => $webhooks,
        'transactions_success' => $txnSuccess,
        'transactions_pending' => $txnPending,
        'refunds' => $refunds,
        'delayed_refunds' => $delayedRefunds,
        'delayed_settlements' => $delayedSettlements,
        'unmatched_webhooks' => $unmatched,
        'txns_without_webhook' => $missingWebhooks,
    ];
}

/**
 * Ensure reconciliation tables exist.
 */
function ensureReconciliationTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS gateway_settlement_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(32) NOT NULL,
            filename VARCHAR(255) NOT NULL,
            uploaded_by INT DEFAULT NULL,
            file_date DATE DEFAULT NULL,
            rows_total INT DEFAULT 0,
            rows_matched INT DEFAULT 0,
            rows_unmatched INT DEFAULT 0,
            rows_amount_total DECIMAL(14,2) DEFAULT 0,
            rows_amount_matched DECIMAL(14,2) DEFAULT 0,
            status ENUM('processing','completed','failed') DEFAULT 'processing',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gateway_date (gateway, file_date),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS gateway_settlement_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            file_id INT NOT NULL,
            gateway VARCHAR(32) NOT NULL,
            utr VARCHAR(64) DEFAULT NULL,
            gateway_ref VARCHAR(128) DEFAULT NULL,
            merchant_code VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            settlement_date DATE DEFAULT NULL,
            txn_id VARCHAR(64) DEFAULT NULL,
            match_status ENUM('unmatched','matched','manual_resolved','ignored') DEFAULT 'unmatched',
            matched_txn_id INT DEFAULT NULL,
            match_reason VARCHAR(255) DEFAULT NULL,
            resolved_by INT DEFAULT NULL,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_file (file_id),
            INDEX idx_match_status (match_status),
            INDEX idx_utr (utr),
            INDEX idx_gateway_ref (gateway_ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS reconciliation_daily_summary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            summary_date DATE NOT NULL,
            gateway VARCHAR(32) NOT NULL,
            total_txns INT DEFAULT 0,
            success_txns INT DEFAULT 0,
            failed_txns INT DEFAULT 0,
            pending_txns INT DEFAULT 0,
            total_amount DECIMAL(14,2) DEFAULT 0,
            success_amount DECIMAL(14,2) DEFAULT 0,
            webhooks_received INT DEFAULT 0,
            webhooks_matched INT DEFAULT 0,
            webhooks_unmatched INT DEFAULT 0,
            settlement_files_processed INT DEFAULT 0,
            mismatches INT DEFAULT 0,
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_date_gateway (summary_date, gateway)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add reconciliation_status to transactions if missing
        try {
            $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reconciliation_status ENUM('unreconciled','matched','mismatched','manual_resolved') DEFAULT 'unreconciled' AFTER status");
            $db->exec("ALTER TABLE transactions ADD COLUMN IF NOT EXISTS reconciled_at TIMESTAMP NULL DEFAULT NULL AFTER reconciliation_status");
        } catch (Throwable $e) { /* column may already exist */ }
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Parse a gateway settlement CSV file.
 * Recognised columns (case-insensitive): utr/reference/rrn/txn ref, amount, date, merchant code/id, gateway ref/order id
 */
function parseGatewaySettlementCsv(string $tmpPath, string $gateway): array
{
    $rows = [];
    $fh = fopen($tmpPath, 'r');
    if (!$fh) return $rows;
    $header = fgetcsv($fh);
    if (!$header) { fclose($fh); return $rows; }
    $norm = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $findCol = function (array $candidates) use ($norm): ?int {
        foreach ($candidates as $c) {
            foreach ($norm as $i => $h) {
                if ($h === $c || str_contains($h, $c)) return $i;
            }
        }
        return null;
    };
    $utrIdx = $findCol(['utr', 'rrn', 'reference', 'txn ref', 'ref no', 'bank ref']);
    $amtIdx = $findCol(['amount', 'settlement amount', 'txn amount', 'net amount']);
    $dateIdx = $findCol(['date', 'settlement date', 'value date', 'txn date']);
    $merchantIdx = $findCol(['merchant code', 'merchant id', 'mid', 'merchant']);
    $gatewayRefIdx = $findCol(['order id', 'gateway ref', 'payment id', 'txn id', 'gateway txn id']);

    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) < 2) continue;
        $utr = $utrIdx !== null ? trim((string)($line[$utrIdx] ?? '')) : '';
        $amtRaw = $amtIdx !== null ? (string)($line[$amtIdx] ?? '') : '';
        $amt = (float)preg_replace('/[^0-9.\-]/', '', $amtRaw);
        $dateRaw = $dateIdx !== null ? trim((string)($line[$dateIdx] ?? '')) : '';
        $merchantCode = $merchantIdx !== null ? trim((string)($line[$merchantIdx] ?? '')) : '';
        $gatewayRef = $gatewayRefIdx !== null ? trim((string)($line[$gatewayRefIdx] ?? '')) : '';
        if ($utr === '' && $gatewayRef === '' && $amt <= 0) continue;
        $rows[] = [
            'utr' => $utr,
            'gateway_ref' => $gatewayRef,
            'amount' => $amt,
            'date' => $dateRaw,
            'merchant_code' => $merchantCode,
        ];
    }
    fclose($fh);
    return $rows;
}

/**
 * Match parsed gateway settlement rows against transactions.
 * 1. Exact UTR match -> mark matched
 * 2. Gateway ref match -> mark matched
 * 3. Amount + merchant + date match (within +/-2 days) -> suggested match
 * 4. No match -> unmatched
 */
function reconcileGatewaySettlementRows(array $rows, string $gateway, ?int $adminId = null, string $filename = 'upload.csv'): array
{
    ensureReconciliationTables();
    $db = getDB();
    $matched = 0;
    $unmatched = 0;
    $totalAmount = 0.0;
    $matchedAmount = 0.0;

    $db->prepare('INSERT INTO gateway_settlement_files (gateway, filename, uploaded_by, rows_total, status) VALUES (?,?,?,?,?)')
        ->execute([$gateway, mb_substr($filename, 0, 255), $adminId, count($rows), 'processing']);
    $fileId = (int)$db->lastInsertId();

    foreach ($rows as $row) {
        $utr = $row['utr'];
        $gatewayRef = $row['gateway_ref'];
        $amount = round((float)$row['amount'], 2);
        $merchantCode = $row['merchant_code'];
        $totalAmount += $amount;
        $parsedDate = $row['date'] !== '' ? strtotime($row['date']) : false;
        $settlementDate = $parsedDate ? date('Y-m-d', $parsedDate) : null;
        $matchedTxnId = null;
        $matchReason = null;
        $matchStatus = 'unmatched';

        // 1. Try UTR match
        if ($utr !== '') {
            $st = $db->prepare("SELECT id, txn_id, amount, status FROM transactions WHERE utr = ? LIMIT 1");
            $st->execute([$utr]);
            $txn = $st->fetch();
            if ($txn) {
                $matchedTxnId = (int)$txn['id'];
                $matchStatus = 'matched';
                $matchReason = 'Exact UTR match';
                $matchedAmount += $amount;
                $db->prepare("UPDATE transactions SET reconciliation_status='matched', reconciled_at=NOW() WHERE id=?")
                    ->execute([$matchedTxnId]);
            }
        }

        // 2. Try gateway ref match (txn_id column)
        if ($matchedTxnId === null && $gatewayRef !== '') {
            $st = $db->prepare("SELECT id, txn_id, amount, status FROM transactions WHERE txn_id = ? LIMIT 1");
            $st->execute([$gatewayRef]);
            $txn = $st->fetch();
            if ($txn) {
                $matchedTxnId = (int)$txn['id'];
                $matchStatus = 'matched';
                $matchReason = 'Gateway reference match';
                $matchedAmount += $amount;
                $db->prepare("UPDATE transactions SET reconciliation_status='matched', reconciled_at=NOW() WHERE id=?")
                    ->execute([$matchedTxnId]);
            }
        }

        // 3. Try amount + merchant + date match
        if ($matchedTxnId === null && $amount > 0) {
            $sql = "SELECT id, txn_id, amount, status FROM transactions WHERE amount = ? AND payment_method = ?";
            $params = [$amount, $gateway];
            if ($merchantCode !== '') {
                $sql .= " AND merchant_id IN (SELECT id FROM merchants WHERE merchant_code = ?)";
                $params[] = $merchantCode;
            }
            if ($settlementDate) {
                $sql .= " AND ABS(DATEDIFF(created_at, ?)) <= 2";
                $params[] = $settlementDate;
            }
            $sql .= " AND reconciliation_status = 'unreconciled' LIMIT 1";
            $st = $db->prepare($sql);
            $st->execute($params);
            $txn = $st->fetch();
            if ($txn) {
                $matchedTxnId = (int)$txn['id'];
                $matchStatus = 'matched';
                $matchReason = 'Amount' . ($merchantCode ? ', merchant' : '') . ($settlementDate ? ', date' : '') . ' match';
                $matchedAmount += $amount;
                $db->prepare("UPDATE transactions SET reconciliation_status='matched', reconciled_at=NOW() WHERE id=?")
                    ->execute([$matchedTxnId]);
            }
        }

        if ($matchStatus === 'unmatched') {
            $unmatched++;
        } else {
            $matched++;
        }

        $db->prepare(
            "INSERT INTO gateway_settlement_rows
             (file_id, gateway, utr, gateway_ref, merchant_code, amount, settlement_date, txn_id, match_status, matched_txn_id, match_reason)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        )->execute([
            $fileId, $gateway, $utr, $gatewayRef, $merchantCode, $amount, $settlementDate,
            $gatewayRef, $matchStatus, $matchedTxnId, $matchReason,
        ]);
    }

    $db->prepare('UPDATE gateway_settlement_files SET rows_matched=?, rows_unmatched=?, rows_amount_total=?, rows_amount_matched=?, status=? WHERE id=?')
        ->execute([$matched, $unmatched, $totalAmount, $matchedAmount, 'completed', $fileId]);

    return [
        'file_id' => $fileId,
        'total' => count($rows),
        'matched' => $matched,
        'unmatched' => $unmatched,
        'total_amount' => $totalAmount,
        'matched_amount' => $matchedAmount,
    ];
}

/**
 * Manually resolve an unmatched gateway settlement row.
 */
function manualResolveSettlementRow(int $rowId, int $txnId, ?int $adminId = null, string $reason = ''): bool
{
    ensureReconciliationTables();
    $db = getDB();
    $st = $db->prepare("SELECT * FROM gateway_settlement_rows WHERE id=? AND match_status='unmatched' FOR UPDATE");
    $db->beginTransaction();
    try {
        $st->execute([$rowId]);
        $row = $st->fetch();
        if (!$row) throw new RuntimeException('Unmatched row not found.');
        $txnSt = $db->prepare("SELECT id FROM transactions WHERE id=?");
        $txnSt->execute([$txnId]);
        if (!$txnSt->fetch()) throw new RuntimeException('Transaction not found.');
        $db->prepare("UPDATE gateway_settlement_rows SET match_status='manual_resolved', matched_txn_id=?, match_reason=?, resolved_by=?, resolved_at=NOW() WHERE id=?")
            ->execute([$txnId, $reason ?: 'Manual resolve', $adminId, $rowId]);
        $db->prepare("UPDATE transactions SET reconciliation_status='manual_resolved', reconciled_at=NOW() WHERE id=?")
            ->execute([$txnId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/**
 * Ignore an unmatched gateway settlement row.
 */
function ignoreSettlementRow(int $rowId, ?int $adminId = null, string $reason = ''): bool
{
    ensureReconciliationTables();
    $db = getDB();
    $db->prepare("UPDATE gateway_settlement_rows SET match_status='ignored', match_reason=?, resolved_by=?, resolved_at=NOW() WHERE id=? AND match_status='unmatched'")
        ->execute([$reason ?: 'Ignored', $adminId, $rowId]);
    return $db->prepare("SELECT COUNT(*) FROM gateway_settlement_rows WHERE id=? AND match_status='ignored'")->execute([$rowId])->fetchColumn() > 0;
}

/**
 * Get gateway settlement files history.
 */
function getGatewaySettlementFiles(int $limit = 20): array
{
    ensureReconciliationTables();
    $st = getDB()->prepare('SELECT * FROM gateway_settlement_files ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Get unmatched rows for a specific file.
 */
function getUnmatchedSettlementRows(int $fileId, int $limit = 100): array
{
    ensureReconciliationTables();
    $st = getDB()->prepare('SELECT * FROM gateway_settlement_rows WHERE file_id=? AND match_status=? ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $fileId, PDO::PARAM_INT);
    $st->bindValue(2, 'unmatched');
    $st->bindValue(3, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Generate daily reconciliation summary for a specific date.
 */
function generateDailyReconciliationSummary(string $date): array
{
    ensureReconciliationTables();
    $db = getDB();
    $gateways = ['razorpay', 'cashfree', 'payu', 'axis', 'upi', 'card', 'netbanking', 'wallet'];
    $summaries = [];

    foreach ($gateways as $gw) {
        $totalTxns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE payment_method='{$gw}' AND DATE(created_at)='{$date}'")->fetchColumn();
        if ($totalTxns === 0) continue;

        $successTxns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE payment_method='{$gw}' AND status='success' AND DATE(created_at)='{$date}'")->fetchColumn();
        $failedTxns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE payment_method='{$gw}' AND status='failed' AND DATE(created_at)='{$date}'")->fetchColumn();
        $pendingTxns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE payment_method='{$gw}' AND status='pending' AND DATE(created_at)='{$date}'")->fetchColumn();
        $totalAmount = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE payment_method='{$gw}' AND DATE(created_at)='{$date}'")->fetchColumn();
        $successAmount = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE payment_method='{$gw}' AND status='success' AND DATE(created_at)='{$date}'")->fetchColumn();

        $webhooksReceived = 0;
        $webhooksMatched = 0;
        $webhooksUnmatched = 0;
        try {
            $webhooksReceived = (int)$db->query("SELECT COUNT(*) FROM pg_webhook_logs WHERE gateway='{$gw}' AND DATE(created_at)='{$date}'")->fetchColumn();
            $webhooksMatched = (int)$db->query("SELECT COUNT(*) FROM pg_webhook_logs WHERE gateway='{$gw}' AND status='processed' AND DATE(created_at)='{$date}'")->fetchColumn();
            $webhooksUnmatched = (int)$db->query("SELECT COUNT(*) FROM pg_webhook_logs WHERE gateway='{$gw}' AND status IN ('received','failed') AND DATE(created_at)='{$date}'")->fetchColumn();
        } catch (Throwable $e) {}

        $settlementFiles = 0;
        try {
            $settlementFiles = (int)$db->query("SELECT COUNT(*) FROM gateway_settlement_files WHERE gateway='{$gw}' AND file_date='{$date}'")->fetchColumn();
        } catch (Throwable $e) {}

        $mismatches = $webhooksUnmatched + ($totalTxns - $successTxns - $failedTxns - $pendingTxns > 0 ? $totalTxns - $successTxns - $failedTxns - $pendingTxns : 0);

        $db->prepare(
            "INSERT INTO reconciliation_daily_summary
             (summary_date, gateway, total_txns, success_txns, failed_txns, pending_txns, total_amount, success_amount,
              webhooks_received, webhooks_matched, webhooks_unmatched, settlement_files_processed, mismatches)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
              total_txns=VALUES(total_txns), success_txns=VALUES(success_txns), failed_txns=VALUES(failed_txns),
              pending_txns=VALUES(pending_txns), total_amount=VALUES(total_amount), success_amount=VALUES(success_amount),
              webhooks_received=VALUES(webhooks_received), webhooks_matched=VALUES(webhooks_matched),
              webhooks_unmatched=VALUES(webhooks_unmatched), settlement_files_processed=VALUES(settlement_files_processed),
              mismatches=VALUES(mismatches)"
        )->execute([
            $date, $gw, $totalTxns, $successTxns, $failedTxns, $pendingTxns, $totalAmount, $successAmount,
            $webhooksReceived, $webhooksMatched, $webhooksUnmatched, $settlementFiles, $mismatches,
        ]);

        $summaries[$gw] = [
            'total_txns' => $totalTxns,
            'success_txns' => $successTxns,
            'failed_txns' => $failedTxns,
            'pending_txns' => $pendingTxns,
            'total_amount' => $totalAmount,
            'success_amount' => $successAmount,
            'webhooks_received' => $webhooksReceived,
            'webhooks_matched' => $webhooksMatched,
            'webhooks_unmatched' => $webhooksUnmatched,
            'settlement_files' => $settlementFiles,
            'mismatches' => $mismatches,
        ];
    }

    return $summaries;
}

/**
 * Get daily reconciliation summaries for a date range.
 */
function getDailyReconciliationSummaries(int $days = 30): array
{
    ensureReconciliationTables();
    $db = getDB();
    $st = $db->prepare(
        "SELECT * FROM reconciliation_daily_summary
         WHERE summary_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         ORDER BY summary_date DESC, gateway"
    );
    $st->bindValue(1, $days, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Auto-mark transactions as matched when webhook + transaction both exist.
 */
function autoMarkReconciledTransactions(int $days = 1): int
{
    ensureReconciliationTables();
    $db = getDB();
    $count = $db->exec(
        "UPDATE transactions t
         INNER JOIN pg_webhook_logs w ON w.reference = t.utr AND w.status = 'processed'
         SET t.reconciliation_status = 'matched', t.reconciled_at = NOW()
         WHERE t.reconciliation_status = 'unreconciled'
         AND t.status = 'success'
         AND t.created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );
    return (int)$count;
}
