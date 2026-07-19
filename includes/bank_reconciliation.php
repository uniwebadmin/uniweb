<?php
declare(strict_types=1);

/**
 * VIP Feature — Auto-Reconciliation.
 * Admin uploads the bank's settlement/payout statement (CSV). The system matches
 * each row against settlement_batches automatically — confirming batches that
 * already recorded the same UTR, and auto-settling batches that are stuck
 * pending/failed but whose amount+date now matches a bank credit, without any
 * manual data entry.
 */

function ensureBankReconciliationTables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS bank_reconciliation_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            uploaded_by INT NULL,
            rows_total INT NOT NULL DEFAULT 0,
            rows_confirmed INT NOT NULL DEFAULT 0,
            rows_auto_settled INT NOT NULL DEFAULT 0,
            rows_unmatched INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }

    try {
        $db->exec("ALTER TABLE settlement_batches ADD COLUMN bank_reconciled_at DATETIME NULL");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Parses a bank CSV with flexible header names. Recognised columns (case-insensitive):
 * utr/reference/rrn/txn ref, amount/credit amount/debit amount, date/value date/txn date.
 */
function parseBankStatementCsv(string $tmpPath): array
{
    $rows = [];
    $fh = fopen($tmpPath, 'r');
    if (!$fh) {
        return $rows;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return $rows;
    }
    $norm = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $findCol = function (array $candidates) use ($norm): ?int {
        foreach ($candidates as $c) {
            foreach ($norm as $i => $h) {
                if ($h === $c || str_contains($h, $c)) {
                    return $i;
                }
            }
        }
        return null;
    };
    $utrIdx = $findCol(['utr', 'rrn', 'reference', 'txn ref', 'ref no']);
    $amtIdx = $findCol(['amount', 'credit amount', 'credit', 'txn amount']);
    $dateIdx = $findCol(['date', 'value date', 'txn date']);

    while (($line = fgetcsv($fh)) !== false) {
        if (count($line) < 2) {
            continue;
        }
        $utr = $utrIdx !== null ? trim((string)($line[$utrIdx] ?? '')) : '';
        $amtRaw = $amtIdx !== null ? (string)($line[$amtIdx] ?? '') : '';
        $amt = (float)preg_replace('/[^0-9.\-]/', '', $amtRaw);
        $dateRaw = $dateIdx !== null ? trim((string)($line[$dateIdx] ?? '')) : '';
        if ($utr === '' && $amt <= 0) {
            continue;
        }
        $rows[] = ['utr' => $utr, 'amount' => $amt, 'date' => $dateRaw];
    }
    fclose($fh);
    return $rows;
}

/**
 * Matches parsed bank rows against settlement_batches:
 *  1. Exact UTR match -> mark bank_reconciled_at (confirms money actually moved).
 *  2. No UTR match but amount matches exactly one open/processing/failed batch
 *     within +/-3 days -> auto-settle that batch using the bank's UTR.
 *  3. Ambiguous or no match -> left for manual review.
 */
function reconcileBankStatementRows(array $rows, ?int $adminId = null, string $filename = 'upload.csv'): array
{
    ensureBankReconciliationTables();
    $db = getDB();
    $confirmed = 0;
    $autoSettled = 0;
    $unmatched = [];

    foreach ($rows as $row) {
        $utr = $row['utr'];
        $amount = round((float)$row['amount'], 2);
        $matchedThisRow = false;

        if ($utr !== '') {
            $st = $db->prepare("SELECT id FROM settlement_batches WHERE utr = ? LIMIT 1");
            $st->execute([$utr]);
            $existing = $st->fetch();
            if ($existing) {
                $db->prepare("UPDATE settlement_batches SET bank_reconciled_at = NOW() WHERE id = ?")
                    ->execute([$existing['id']]);
                $confirmed++;
                $matchedThisRow = true;
            }
        }

        if (!$matchedThisRow && $amount > 0) {
            $parsedDate = $row['date'] !== '' ? strtotime($row['date']) : false;
            $sql = "SELECT id, net_amount FROM settlement_batches
                    WHERE status IN ('open','processing','failed')
                    AND (utr IS NULL OR utr = '')
                    AND ABS(net_amount - ?) < 1.00";
            $params = [$amount];
            if ($parsedDate) {
                $sql .= " AND ABS(DATEDIFF(COALESCE(scheduled_at, created_at), ?)) <= 3";
                $params[] = date('Y-m-d', $parsedDate);
            }
            $sql .= " LIMIT 5";
            $st = $db->prepare($sql);
            $st->execute($params);
            $candidates = $st->fetchAll();

            if (count($candidates) === 1) {
                $batchId = (int)$candidates[0]['id'];
                $db->prepare("UPDATE settlement_batches SET status='settled', processed_at=NOW(), bank_reconciled_at=NOW(), utr=?, api_provider='bank_file', api_status='confirmed', api_message='Auto-reconciled from bank statement upload' WHERE id=?")
                    ->execute([$utr ?: ('BANKFILE-' . time() . '-' . $batchId), $batchId]);
                $autoSettled++;
                $matchedThisRow = true;
            }
        }

        if (!$matchedThisRow) {
            $unmatched[] = $row;
        }
    }

    try {
        $db->prepare('INSERT INTO bank_reconciliation_files (filename, uploaded_by, rows_total, rows_confirmed, rows_auto_settled, rows_unmatched) VALUES (?,?,?,?,?,?)')
            ->execute([mb_substr($filename, 0, 255), $adminId, count($rows), $confirmed, $autoSettled, count($unmatched)]);
    } catch (Throwable $e) { /* ok */ }

    return [
        'total' => count($rows),
        'confirmed' => $confirmed,
        'auto_settled' => $autoSettled,
        'unmatched' => $unmatched,
    ];
}

function getBankReconciliationHistory(int $limit = 20): array
{
    ensureBankReconciliationTables();
    $st = getDB()->prepare('SELECT * FROM bank_reconciliation_files ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
