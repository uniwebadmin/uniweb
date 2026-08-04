<?php
declare(strict_types=1);

/**
 * Nodal / escrow account ledger for RBI PA-PG customer-fund separation.
 *
 * Customer collections are credited to the nodal (escrow) ledger.
 * Merchant payouts and platform commission are debited / separated from it.
 * Platform commission wallet stays in a separate operating/commission ledger.
 */

function ensureNodalAccountsTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS nodal_accounts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            bank_name VARCHAR(120) NOT NULL,
            account_holder VARCHAR(120) NOT NULL,
            account_number VARCHAR(64) NOT NULL,
            ifsc_code VARCHAR(20) NOT NULL,
            branch VARCHAR(120) DEFAULT NULL,
            purpose VARCHAR(120) DEFAULT 'collections_and_settlements',
            is_primary TINYINT(1) DEFAULT 0,
            status ENUM('pending','verified','suspended') DEFAULT 'pending',
            verification_notes TEXT,
            verified_by INT DEFAULT NULL,
            verified_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_primary (is_primary, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function ensureNodalLedgerTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS nodal_wallet_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nodal_account_id INT NOT NULL,
            merchant_id INT DEFAULT NULL,
            transaction_id INT DEFAULT NULL,
            settlement_id VARCHAR(64) DEFAULT NULL,
            amount DECIMAL(14,2) NOT NULL,
            type ENUM('credit','debit') NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_nodal (nodal_account_id),
            INDEX idx_merchant (merchant_id),
            INDEX idx_settlement (settlement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getNodalAccounts(string $status = ''): array
{
    ensureNodalAccountsTable();
    $sql = 'SELECT * FROM nodal_accounts';
    $params = [];
    if ($status !== '') {
        $sql .= ' WHERE status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY is_primary DESC, id DESC';
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function getPrimaryNodalAccount(): ?array
{
    ensureNodalAccountsTable();
    $st = getDB()->prepare('SELECT * FROM nodal_accounts WHERE is_primary=1 AND status="verified" ORDER BY id DESC LIMIT 1');
    $st->execute();
    $row = $st->fetch();
    return $row ?: null;
}

function getNodalAccountById(int $id): ?array
{
    ensureNodalAccountsTable();
    $st = getDB()->prepare('SELECT * FROM nodal_accounts WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function saveNodalAccount(array $data, ?int $adminId = null): int
{
    ensureNodalAccountsTable();
    $db = getDB();
    $id = (int)($data['id'] ?? 0);
    $name = trim($data['name'] ?? '');
    $bank = trim($data['bank_name'] ?? '');
    $holder = trim($data['account_holder'] ?? '');
    $number = trim($data['account_number'] ?? '');
    $ifsc = trim($data['ifsc_code'] ?? '');
    $branch = trim($data['branch'] ?? '');
    $purpose = trim($data['purpose'] ?? 'collections_and_settlements');
    $primary = !empty($data['is_primary']) ? 1 : 0;

    if ($name === '' || $bank === '' || $holder === '' || $number === '' || $ifsc === '') {
        throw new InvalidArgumentException('All bank details are required.');
    }

    if ($primary) {
        $db->prepare('UPDATE nodal_accounts SET is_primary=0')->execute();
    }

    if ($id > 0) {
        $db->prepare(
            'UPDATE nodal_accounts SET name=?, bank_name=?, account_holder=?, account_number=?, ifsc_code=?, branch=?, purpose=?, is_primary=? WHERE id=?'
        )->execute([$name, $bank, $holder, $number, $ifsc, $branch, $purpose, $primary, $id]);
        return $id;
    }

    $db->prepare(
        'INSERT INTO nodal_accounts (name, bank_name, account_holder, account_number, ifsc_code, branch, purpose, is_primary) VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$name, $bank, $holder, $number, $ifsc, $branch, $purpose, $primary]);
    return (int)$db->lastInsertId();
}

function verifyNodalAccount(int $id, int $adminId, string $notes): bool
{
    ensureNodalAccountsTable();
    $db = getDB();
    $st = $db->prepare('SELECT id FROM nodal_accounts WHERE id=?');
    $st->execute([$id]);
    if (!$st->fetch()) {
        return false;
    }
    $db->prepare(
        'UPDATE nodal_accounts SET status="verified", verification_notes=?, verified_by=?, verified_at=NOW() WHERE id=?'
    )->execute([$notes, $adminId, $id]);
    logStaffActivity('nodal_verified', 'Nodal account #' . $id . ' verified', null);
    return true;
}

function suspendNodalAccount(int $id): bool
{
    ensureNodalAccountsTable();
    getDB()->prepare('UPDATE nodal_accounts SET status="suspended", is_primary=0 WHERE id=?')->execute([$id]);
    return true;
}

function isNodalAccountSeparateFromPlatform(array $nodal): bool
{
    $platformAccount = getSetting('platform_account_number', '');
    $platformIfsc = getSetting('platform_ifsc', '');
    if ($platformAccount !== '' && str_replace(' ', '', $platformAccount) === str_replace(' ', '', (string)$nodal['account_number'])) {
        return false;
    }
    if ($platformIfsc !== '' && strtoupper(str_replace(' ', '', $platformIfsc)) === strtoupper(str_replace(' ', '', (string)$nodal['ifsc_code']))) {
        return false;
    }
    return true;
}

function recordNodalCollection(int $transactionId, int $merchantId, float $amount, string $description = ''): bool
{
    ensureNodalLedgerTable();
    $nodal = getPrimaryNodalAccount();
    if (!$nodal) {
        return false;
    }
    $desc = $description !== '' ? $description : 'Customer collection credit';
    try {
        getDB()->prepare(
            'INSERT INTO nodal_wallet_ledger (nodal_account_id, merchant_id, transaction_id, amount, type, description) VALUES (?,?,?,?,?,?)'
        )->execute([$nodal['id'], $merchantId, $transactionId, round($amount, 2), 'credit', $desc]);
        return true;
    } catch (Throwable $e) {
        error_log('recordNodalCollection: ' . $e->getMessage());
        return false;
    }
}

function recordNodalPayout(string $settlementId, int $merchantId, float $amount, string $description = ''): bool
{
    ensureNodalLedgerTable();
    $nodal = getPrimaryNodalAccount();
    if (!$nodal) {
        return false;
    }
    $desc = $description !== '' ? $description : 'Merchant settlement payout debit';
    try {
        getDB()->prepare(
            'INSERT INTO nodal_wallet_ledger (nodal_account_id, merchant_id, settlement_id, amount, type, description) VALUES (?,?,?,?,?,?)'
        )->execute([$nodal['id'], $merchantId, $settlementId, round($amount, 2), 'debit', $desc]);
        return true;
    } catch (Throwable $e) {
        error_log('recordNodalPayout: ' . $e->getMessage());
        return false;
    }
}

function getNodalBalance(int $nodalAccountId): float
{
    ensureNodalLedgerTable();
    $st = getDB()->prepare(
        'SELECT COALESCE(SUM(CASE WHEN type="credit" THEN amount ELSE -amount END),0) FROM nodal_wallet_ledger WHERE nodal_account_id=?'
    );
    $st->execute([$nodalAccountId]);
    return round((float)$st->fetchColumn(), 2);
}

function getNodalLedger(int $nodalAccountId, int $limit = 50): array
{
    ensureNodalLedgerTable();
    $st = getDB()->prepare('SELECT * FROM nodal_wallet_ledger WHERE nodal_account_id=? ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $nodalAccountId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function getNodalAuditSummary(): array
{
    ensureNodalAccountsTable();
    ensureNodalLedgerTable();
    $db = getDB();
    $accounts = getNodalAccounts();
    $summary = [];
    foreach ($accounts as $acc) {
        $summary[] = [
            'id' => (int)$acc['id'],
            'name' => $acc['name'],
            'bank' => $acc['bank_name'],
            'account' => $acc['account_number'],
            'ifsc' => $acc['ifsc_code'],
            'status' => $acc['status'],
            'is_primary' => (bool)$acc['is_primary'],
            'is_separate' => isNodalAccountSeparateFromPlatform($acc),
            'balance' => getNodalBalance((int)$acc['id']),
        ];
    }
    return $summary;
}
