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
    // Schema changes are versioned under migrations/. Request-time DDL is forbidden.
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
    $accountIdx = $findCol(['beneficiary account', 'account number', 'beneficiary a/c', 'account']);
    $merchantIdx = $findCol(['merchant code', 'merchant id', 'mid']);

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
        $account = $accountIdx !== null ? preg_replace('/\D/', '', (string)($line[$accountIdx] ?? '')) : '';
        $merchantCode = $merchantIdx !== null ? trim((string)($line[$merchantIdx] ?? '')) : '';
        $rows[] = [
            'utr' => $utr,
            'amount' => $amt,
            'date' => $dateRaw,
            'beneficiary_account_last4' => $account !== '' ? substr($account, -4) : '',
            'merchant_code' => $merchantCode,
        ];
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
    $suggested = 0;
    $unmatched = [];
    $db->prepare('INSERT INTO bank_reconciliation_files (filename,uploaded_by,rows_total) VALUES (?,?,?)')
        ->execute([mb_substr($filename, 0, 255), $adminId, count($rows)]);
    $fileId = (int)$db->lastInsertId();

    foreach ($rows as $row) {
        $utr = $row['utr'];
        $amount = round((float)$row['amount'], 2);
        $accountLast4 = preg_replace('/\D/', '', (string)($row['beneficiary_account_last4'] ?? ''));
        $accountLast4 = $accountLast4 !== '' ? substr($accountLast4, -4) : '';
        $merchantCode = trim((string)($row['merchant_code'] ?? ''));
        $matchedThisRow = false;

        if ($utr !== '' && $accountLast4 !== '') {
            $st = $db->prepare(
                "SELECT sb.id,sb.merchant_id,sb.net_amount,sb.status,RIGHT(b.account_number,4) AS account_last4
                 FROM settlement_batches sb
                 JOIN merchants m ON m.id=sb.merchant_id
                 JOIN bank_accounts b ON b.merchant_id=sb.merchant_id AND b.is_primary=1 AND b.status='active'
                 WHERE sb.utr=? AND (?='' OR m.merchant_code=?) LIMIT 1"
            );
            $st->execute([$utr, $merchantCode, $merchantCode]);
            $existing = $st->fetch();
            if ($existing
                && $existing['status'] === 'settled'
                && abs((float)$existing['net_amount'] - $amount) < 0.001
                && hash_equals((string)$existing['account_last4'], $accountLast4)
            ) {
                $db->prepare("UPDATE settlement_batches SET bank_reconciled_at = NOW() WHERE id = ?")
                    ->execute([$existing['id']]);
                $db->prepare(
                    "INSERT INTO bank_reconciliation_matches
                     (file_id,batch_id,merchant_id,bank_reference,beneficiary_account_last4,statement_amount,statement_date,match_status,match_reason,reviewed_by,reviewed_at)
                     VALUES (?,?,?,?,?,?,?,'confirmed','Exact UTR, amount, merchant and beneficiary account match',?,NOW())"
                )->execute([
                    $fileId, (int)$existing['id'], (int)$existing['merchant_id'], $utr, $accountLast4, $amount,
                    !empty($row['date']) && strtotime($row['date']) ? date('Y-m-d', strtotime($row['date'])) : null,
                    $adminId,
                ]);
                $confirmed++;
                $matchedThisRow = true;
            }
        }

        if (!$matchedThisRow && $amount > 0 && $utr !== '' && $accountLast4 !== '' && $merchantCode !== '') {
            $parsedDate = $row['date'] !== '' ? strtotime($row['date']) : false;
            $sql = "SELECT sb.id,sb.merchant_id,sb.net_amount
                    FROM settlement_batches sb
                    JOIN merchants m ON m.id=sb.merchant_id
                    JOIN bank_accounts b ON b.merchant_id=sb.merchant_id AND b.is_primary=1 AND b.status='active'
                    WHERE sb.status IN ('processing','failed')
                    AND (sb.utr IS NULL OR sb.utr='')
                    AND sb.net_amount=?
                    AND m.merchant_code=?
                    AND RIGHT(b.account_number,4)=?";
            $params = [$amount, $merchantCode, $accountLast4];
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
                $db->prepare(
                    "INSERT INTO bank_reconciliation_matches
                     (file_id,batch_id,merchant_id,bank_reference,beneficiary_account_last4,statement_amount,statement_date,match_status,match_reason)
                     VALUES (?,?,?,?,?,?,?,'suggested','Exact amount, merchant and beneficiary account; requires maker-checker review')"
                )->execute([
                    $fileId, $batchId, (int)$candidates[0]['merchant_id'], $utr, $accountLast4, $amount,
                    $parsedDate ? date('Y-m-d', $parsedDate) : null,
                ]);
                $suggested++;
                $matchedThisRow = true;
            }
        }

        if (!$matchedThisRow) {
            $unmatched[] = $row;
        }
    }

    $db->prepare('UPDATE bank_reconciliation_files SET rows_confirmed=?,rows_auto_settled=0,rows_suggested=?,rows_unmatched=? WHERE id=?')
        ->execute([$confirmed, $suggested, count($unmatched), $fileId]);

    return [
        'total' => count($rows),
        'confirmed' => $confirmed,
        'auto_settled' => 0,
        'suggested' => $suggested,
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

/** Maker-checker confirmation of a suggested bank match. Never auto-settles. */
function confirmBankReconciliationMatch(int $matchId): void
{
    ensureBankReconciliationTables();
    $db = getDB();
    $st = $db->prepare("SELECT * FROM bank_reconciliation_matches WHERE id=? AND match_status='suggested' FOR UPDATE");
    $db->beginTransaction();
    try {
        $st->execute([$matchId]);
        $match = $st->fetch();
        if (!$match) {
            throw new RuntimeException('Suggested reconciliation match not found.');
        }
        $batchId = (int)$match['batch_id'];
        $batch = $db->prepare('SELECT id,status,utr,net_amount FROM settlement_batches WHERE id=? FOR UPDATE');
        $batch->execute([$batchId]);
        $row = $batch->fetch();
        if (!$row) {
            throw new RuntimeException('Settlement batch missing for reconciliation match.');
        }
        $utr = trim((string)($match['bank_reference'] ?? ''));
        if ($utr === '') {
            throw new RuntimeException('Confirmed bank match requires a UTR/reference.');
        }
        if (abs((float)$row['net_amount'] - (float)$match['statement_amount']) > 0.009) {
            throw new RuntimeException('Statement amount does not match batch net amount.');
        }
        $db->prepare(
            "UPDATE settlement_batches
             SET status=IF(status='settled','settled','processing'),
                 utr=?, provider_status='bank_confirmed', bank_reconciled_at=NOW()
             WHERE id=?"
        )->execute([$utr, $batchId]);
        $db->prepare(
            "UPDATE bank_reconciliation_matches
             SET match_status='confirmed', match_reason=CONCAT(COALESCE(match_reason,''),' | checker confirmed')
             WHERE id=?"
        )->execute([$matchId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
