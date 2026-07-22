<?php
declare(strict_types=1);

/**
 * Payout module — SAFE scaffold only.
 *
 * Live money movement is intentionally DISABLED until a licensed payout partner
 * (RazorpayX / Cashfree Payouts / bank) keys are configured. This module covers:
 *  - Merchant "enable payouts" request → admin approve/reject
 *  - Beneficiary management UI (list + add/remove)
 *  - Maker-checker placeholder for high-value payouts
 *  - Failed-payout reason display (NO auto-reversal / auto-credit)
 *  - Display-only collection vs payout wallet split
 */

function ensurePayoutSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS merchant_payout_enable_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
                merchant_note VARCHAR(500) DEFAULT NULL,
                admin_note VARCHAR(500) DEFAULT NULL,
                decided_by VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME DEFAULT NULL,
                INDEX idx_per_merchant (merchant_id),
                INDEX idx_per_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema enable_requests: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payout_beneficiaries (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                label VARCHAR(120) NOT NULL,
                account_holder VARCHAR(190) NOT NULL,
                account_number VARCHAR(40) NOT NULL,
                ifsc_code VARCHAR(20) NOT NULL,
                bank_name VARCHAR(120) DEFAULT NULL,
                account_type VARCHAR(20) NOT NULL DEFAULT 'savings',
                upi_vpa VARCHAR(120) DEFAULT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                penny_drop_status VARCHAR(30) NOT NULL DEFAULT 'pending',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_pb_merchant (merchant_id),
                INDEX idx_pb_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema beneficiaries: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payout_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payout_id VARCHAR(40) NOT NULL UNIQUE,
                merchant_id INT NOT NULL,
                beneficiary_id INT DEFAULT NULL,
                batch_id INT DEFAULT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                purpose VARCHAR(120) DEFAULT NULL,
                status ENUM('draft','pending_maker','pending_checker','queued','processing','success','failed','cancelled') NOT NULL DEFAULT 'draft',
                failure_reason VARCHAR(500) DEFAULT NULL,
                maker_by VARCHAR(120) DEFAULT NULL,
                checker_by VARCHAR(120) DEFAULT NULL,
                maker_at DATETIME DEFAULT NULL,
                checker_at DATETIME DEFAULT NULL,
                partner_ref VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_po_merchant (merchant_id),
                INDEX idx_po_status (status),
                INDEX idx_po_batch (batch_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema orders: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payout_batches (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_code VARCHAR(40) NOT NULL UNIQUE,
                merchant_id INT NOT NULL,
                row_count INT NOT NULL DEFAULT 0,
                total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                status ENUM('draft','submitted','cancelled') NOT NULL DEFAULT 'draft',
                created_by VARCHAR(120) DEFAULT NULL,
                notes VARCHAR(500) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pbatch_merchant (merchant_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema batches: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payout_reversal_requests (
                id INT AUTO_INCREMENT PRIMARY KEY,
                payout_order_id INT NOT NULL,
                merchant_id INT NOT NULL,
                status ENUM('pending','approved','rejected','reconciled') NOT NULL DEFAULT 'pending',
                merchant_note VARCHAR(500) DEFAULT NULL,
                admin_note VARCHAR(500) DEFAULT NULL,
                decided_by VARCHAR(120) DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                decided_at DATETIME DEFAULT NULL,
                INDEX idx_prev_merchant (merchant_id),
                INDEX idx_prev_status (status),
                INDEX idx_prev_order (payout_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema reversals: ' . $e->getMessage());
    }
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS payout_api_credentials (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                key_prefix VARCHAR(24) NOT NULL,
                key_hash VARCHAR(64) NOT NULL,
                secret_hash VARCHAR(64) NOT NULL,
                status ENUM('active','revoked') NOT NULL DEFAULT 'active',
                last_used_at DATETIME DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_pac_merchant (merchant_id),
                INDEX idx_pac_status (status),
                UNIQUE KEY uq_pac_prefix (key_prefix)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema api_credentials: ' . $e->getMessage());
    }
    if (function_exists('schemaExecQuiet')) {
        schemaExecQuiet("ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0");
        schemaExecQuiet("ALTER TABLE payout_orders ADD COLUMN batch_id INT DEFAULT NULL");
        schemaExecQuiet("ALTER TABLE payout_beneficiaries ADD COLUMN penny_drop_note VARCHAR(255) DEFAULT NULL");
    } else {
        try {
            getDB()->exec("ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) { /* column exists */ }
        try {
            getDB()->exec("ALTER TABLE payout_orders ADD COLUMN batch_id INT DEFAULT NULL");
        } catch (Throwable $e) { /* column exists */ }
        try {
            getDB()->exec("ALTER TABLE payout_beneficiaries ADD COLUMN penny_drop_note VARCHAR(255) DEFAULT NULL");
        } catch (Throwable $e) { /* column exists */ }
    }
}

/** Safe UTF-8 truncate without requiring mbstring. */
function payoutStrLimit(string $value, int $max): string
{
    if ($max < 1) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

/** True only when a licensed partner payout rail has live keys configured. */
function payoutPartnerKeysConfigured(): bool
{
    $rzxKey = trim((string)getSetting('razorpayx_key_id', ''));
    $rzxSecret = trim((string)getSetting('razorpayx_key_secret', ''));
    if ($rzxKey !== '' && $rzxSecret !== '' && !str_contains(strtolower($rzxKey), 'pending')) {
        return true;
    }
    $cfId = trim((string)getSetting('cashfree_payout_client_id', ''));
    $cfSecret = trim((string)getSetting('cashfree_payout_client_secret', ''));
    if ($cfId !== '' && $cfSecret !== '' && !str_contains(strtolower($cfId), 'pending')) {
        return true;
    }
    return false;
}

function payoutLiveMoneyAllowed(): bool
{
    // Hard gate: scaffold never moves money until keys exist AND an explicit setting is on.
    if (!payoutPartnerKeysConfigured()) {
        return false;
    }
    return trim((string)getSetting('payout_live_enabled', '0')) === '1';
}

function payoutActivationMessage(): string
{
    if (payoutLiveMoneyAllowed()) {
        return 'Payout rail is active with a licensed partner.';
    }
    if (payoutPartnerKeysConfigured()) {
        return 'Partner keys are present. Live money movement stays off until an admin enables payout_live_enabled after compliance review.';
    }
    return 'Payouts activate when licensed payout partner keys are added (RazorpayX / Cashfree Payouts). Money movement is disabled until then.';
}

function merchantPayoutEnabled(array $merchant): bool
{
    return !empty($merchant['payout_enabled']);
}

function getMerchantPayoutEnableRequest(int $merchantId): ?array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM merchant_payout_enable_requests WHERE merchant_id=? ORDER BY id DESC LIMIT 1');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function requestPayoutEnable(int $merchantId, string $note = ''): array
{
    ensurePayoutSchema();
    try {
        $db = getDB();
        $chk = $db->prepare('SELECT id FROM merchant_payout_enable_requests WHERE merchant_id=? AND status="pending" LIMIT 1');
        $chk->execute([$merchantId]);
        if ($chk->fetch()) {
            return ['ok' => false, 'error' => 'A payout enable request is already pending.'];
        }
        $m = $db->prepare('SELECT payout_enabled FROM merchants WHERE id=?');
        $m->execute([$merchantId]);
        if ((int)$m->fetchColumn() === 1) {
            return ['ok' => false, 'error' => 'Payouts are already enabled for this account.'];
        }
        $db->prepare('INSERT INTO merchant_payout_enable_requests (merchant_id, merchant_note) VALUES (?,?)')
            ->execute([$merchantId, payoutStrLimit(trim($note), 500) ?: null]);
        return ['ok' => true, 'message' => 'Payout enable request submitted. Admin will review shortly.'];
    } catch (Throwable $e) {
        error_log('requestPayoutEnable: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not submit request. Please try again.'];
    }
}

function decidePayoutEnableRequest(int $requestId, bool $approve, string $decidedBy, string $adminNote = ''): array
{
    ensurePayoutSchema();
    $db = getDB();
    try {
        $st = $db->prepare('SELECT * FROM merchant_payout_enable_requests WHERE id=? LIMIT 1');
        $st->execute([$requestId]);
        $row = $st->fetch();
        if (!$row || $row['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'Request not found or already decided.'];
        }
        $status = $approve ? 'approved' : 'rejected';
        $db->prepare('UPDATE merchant_payout_enable_requests SET status=?, admin_note=?, decided_by=?, decided_at=NOW() WHERE id=?')
            ->execute([$status, payoutStrLimit(trim($adminNote), 500) ?: null, $decidedBy, $requestId]);
        if ($approve) {
            try {
                $db->prepare('UPDATE merchants SET payout_enabled=1 WHERE id=?')->execute([(int)$row['merchant_id']]);
            } catch (Throwable $e) {
                if (function_exists('schemaExecQuiet')) {
                    schemaExecQuiet('ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0');
                } else {
                    try { getDB()->exec('ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e2) {}
                }
                $db->prepare('UPDATE merchants SET payout_enabled=1 WHERE id=?')->execute([(int)$row['merchant_id']]);
            }
            createNotification((int)$row['merchant_id'], 'Payout Access Approved', 'Your payout enable request was approved. Live transfers still require licensed partner keys.');
        } else {
            createNotification((int)$row['merchant_id'], 'Payout Access Rejected', $adminNote !== '' ? $adminNote : 'Your payout enable request was rejected. Contact support for details.');
        }
        return ['ok' => true, 'message' => $approve ? 'Payout access approved.' : 'Payout access rejected.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getPayoutEnableRequests(string $status = 'pending', int $limit = 100): array
{
    ensurePayoutSchema();
    $sql = 'SELECT r.*, m.business_name, m.merchant_code FROM merchant_payout_enable_requests r JOIN merchants m ON m.id=r.merchant_id';
    $params = [];
    if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
        $sql .= ' WHERE r.status=?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(1, min(500, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getPendingPayoutEnableCount(): int
{
    ensurePayoutSchema();
    try {
        return (int)getDB()->query('SELECT COUNT(*) FROM merchant_payout_enable_requests WHERE status="pending"')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function listPayoutBeneficiaries(int $merchantId, bool $activeOnly = true): array
{
    ensurePayoutSchema();
    try {
        $sql = 'SELECT * FROM payout_beneficiaries WHERE merchant_id=?';
        if ($activeOnly) {
            $sql .= ' AND status="active"';
        }
        $sql .= ' ORDER BY id DESC LIMIT 100';
        $st = getDB()->prepare($sql);
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function addPayoutBeneficiary(int $merchantId, array $data): array
{
    ensurePayoutSchema();
    $label = trim((string)($data['label'] ?? ''));
    $holder = trim((string)($data['account_holder'] ?? ''));
    $account = preg_replace('/\D/', '', (string)($data['account_number'] ?? ''));
    $ifsc = strtoupper(trim((string)($data['ifsc_code'] ?? '')));
    $bank = trim((string)($data['bank_name'] ?? ''));
    $type = strtolower(trim((string)($data['account_type'] ?? 'savings')));
    $upi = strtolower(trim((string)($data['upi_vpa'] ?? '')));
    if ($label === '' || $holder === '' || strlen($account) < 6 || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        return ['ok' => false, 'error' => 'Label, account holder, valid account number and IFSC are required.'];
    }
    if (!in_array($type, ['savings', 'current'], true)) {
        $type = 'savings';
    }
    try {
        getDB()->prepare(
            'INSERT INTO payout_beneficiaries (merchant_id, label, account_holder, account_number, ifsc_code, bank_name, account_type, upi_vpa, penny_drop_status)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            $merchantId, payoutStrLimit($label, 120), payoutStrLimit($holder, 190), $account, $ifsc,
            $bank !== '' ? payoutStrLimit($bank, 120) : null, $type,
            $upi !== '' ? payoutStrLimit($upi, 120) : null,
            'pending', // penny-drop needs live bank keys — stay pending
        ]);
        return ['ok' => true, 'message' => 'Beneficiary saved. Penny-drop verification runs when bank/partner keys are configured.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save beneficiary.'];
    }
}

function deactivatePayoutBeneficiary(int $merchantId, int $beneficiaryId): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('UPDATE payout_beneficiaries SET status="inactive" WHERE id=? AND merchant_id=?');
        $st->execute([$beneficiaryId, $merchantId]);
        return ['ok' => $st->rowCount() > 0, 'message' => 'Beneficiary deactivated.', 'error' => 'Beneficiary not found.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update beneficiary.'];
    }
}

function listPayoutOrders(int $merchantId, int $limit = 50): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT o.*, b.label AS beneficiary_label, b.account_number
            FROM payout_orders o
            LEFT JOIN payout_beneficiaries b ON b.id=o.beneficiary_id
            WHERE o.merchant_id=? ORDER BY o.id DESC LIMIT ' . max(1, min(200, $limit)));
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Create a draft / maker-checker placeholder payout. NEVER dispatches to a partner
 * when keys are absent. Gated drafts stay in draft/pending_checker (not fake-failed).
 */
function createPayoutDraft(int $merchantId, int $beneficiaryId, float $amount, string $purpose, string $makerBy, ?int $batchId = null): array
{
    ensurePayoutSchema();
    if ($amount < 1 || $amount > 1000000) {
        return ['ok' => false, 'error' => 'Amount must be between ₹1 and ₹10,00,000.'];
    }
    $bens = listPayoutBeneficiaries($merchantId, true);
    $ben = null;
    foreach ($bens as $b) {
        if ((int)$b['id'] === $beneficiaryId) {
            $ben = $b;
            break;
        }
    }
    if (!$ben) {
        return ['ok' => false, 'error' => 'Select an active beneficiary.'];
    }

    $payoutId = 'PO' . strtoupper(bin2hex(random_bytes(6)));
    $needsChecker = $amount >= 50000; // high-value placeholder threshold
    $live = payoutLiveMoneyAllowed();
    // Without live rail: keep as draft (or pending_checker for high-value review). Never execute.
    if (!$live) {
        $status = $needsChecker ? 'pending_checker' : 'draft';
        $gateNote = payoutActivationMessage() . ' Draft only — no funds moved.';
    } else {
        $status = $needsChecker ? 'pending_checker' : 'pending_maker';
        $gateNote = null;
    }

    try {
        getDB()->prepare(
            'INSERT INTO payout_orders (payout_id, merchant_id, beneficiary_id, batch_id, amount, purpose, status, failure_reason, maker_by, maker_at)
             VALUES (?,?,?,?,?,?,?,?,?,NOW())'
        )->execute([
            $payoutId, $merchantId, $beneficiaryId, $batchId, $amount,
            payoutStrLimit(trim($purpose), 120) ?: 'Vendor payout',
            $status, $gateNote, $makerBy,
        ]);
    } catch (Throwable $e) {
        // Older schema without batch_id
        try {
            getDB()->prepare(
                'INSERT INTO payout_orders (payout_id, merchant_id, beneficiary_id, amount, purpose, status, failure_reason, maker_by, maker_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())'
            )->execute([
                $payoutId, $merchantId, $beneficiaryId, $amount,
                payoutStrLimit(trim($purpose), 120) ?: 'Vendor payout',
                $status, $gateNote, $makerBy,
            ]);
        } catch (Throwable $e2) {
            return ['ok' => false, 'error' => 'Could not record payout draft.'];
        }
    }
    return [
        'ok' => true,
        'blocked' => !$live,
        'payout_id' => $payoutId,
        'status' => $status,
        'message' => !$live
            ? ('Draft saved. ' . ($needsChecker ? 'High-value — awaiting checker. ' : '') . 'Live dispatch gated until partner keys are added. No wallet debit.')
            : ($needsChecker
                ? 'High-value payout submitted for checker approval (maker-checker).'
                : 'Payout queued for maker confirmation.'),
        'failure_reason' => $gateNote,
    ];
}

/**
 * Display-only collection vs payout wallet split.
 * Does not mutate balances — payout wallet stays 0 until partner rail is live.
 */
function getMerchantWalletSplitView(array $merchant, array $wallet): array
{
    $available = (float)($wallet['available'] ?? 0);
    $pendingOut = (float)($wallet['pending_out'] ?? 0);
    $onHold = (float)($wallet['on_hold'] ?? 0);
    return [
        'collection' => [
            'label' => 'Collection wallet',
            'available' => $available,
            'pending' => $pendingOut,
            'on_hold' => $onHold,
            'note' => 'Customer payments settle here for bank settlement.',
        ],
        'payout' => [
            'label' => 'Payout wallet',
            'available' => 0.0,
            'pending' => 0.0,
            'on_hold' => 0.0,
            'note' => payoutLiveMoneyAllowed()
                ? 'Funded when you allocate collection balance for vendor payouts.'
                : 'Display-only until licensed payout partner keys are added. No separate balance yet.',
        ],
        'gated' => !payoutLiveMoneyAllowed(),
        'activation_message' => payoutActivationMessage(),
    ];
}

function payoutStatusLabel(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'pending_maker' => 'Pending maker',
        'pending_checker' => 'Pending checker',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'success' => 'Success',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        default => ucfirst($status),
    };
}

/** CSV template header for bulk payout uploads. */
function payoutBulkCsvHeader(): string
{
    return "label,account_holder,account_number,ifsc_code,amount,purpose,bank_name,account_type\n";
}

/**
 * Parse a bulk payout CSV. Returns rows + row-level errors. Does not move money.
 * Expected columns: label, account_holder, account_number, ifsc_code, amount [, purpose, bank_name, account_type]
 */
function parsePayoutBulkCsv(string $csvText): array
{
    $csvText = trim(str_replace("\r\n", "\n", $csvText));
    if ($csvText === '') {
        return ['ok' => false, 'error' => 'CSV is empty.', 'rows' => [], 'errors' => []];
    }
    $lines = preg_split('/\n+/', $csvText) ?: [];
    if (count($lines) < 2) {
        return ['ok' => false, 'error' => 'CSV needs a header row and at least one data row.', 'rows' => [], 'errors' => []];
    }
    $header = str_getcsv(array_shift($lines));
    $header = array_map(static fn($h) => strtolower(trim((string)$h)), $header);
    $required = ['label', 'account_holder', 'account_number', 'ifsc_code', 'amount'];
    foreach ($required as $col) {
        if (!in_array($col, $header, true)) {
            return ['ok' => false, 'error' => 'Missing column: ' . $col, 'rows' => [], 'errors' => []];
        }
    }
    $rows = [];
    $errors = [];
    $lineNo = 1;
    foreach ($lines as $line) {
        $lineNo++;
        if (trim($line) === '') {
            continue;
        }
        $cols = str_getcsv($line);
        $assoc = [];
        foreach ($header as $i => $key) {
            $assoc[$key] = trim((string)($cols[$i] ?? ''));
        }
        $amount = (float)preg_replace('/[^\d.]/', '', $assoc['amount'] ?? '0');
        $ifsc = strtoupper($assoc['ifsc_code'] ?? '');
        $account = preg_replace('/\D/', '', $assoc['account_number'] ?? '');
        $rowErr = [];
        if (($assoc['label'] ?? '') === '' || ($assoc['account_holder'] ?? '') === '') {
            $rowErr[] = 'label and account_holder required';
        }
        if (strlen($account) < 6) {
            $rowErr[] = 'invalid account_number';
        }
        if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            $rowErr[] = 'invalid IFSC';
        }
        if ($amount < 1 || $amount > 1000000) {
            $rowErr[] = 'amount must be 1–1000000';
        }
        $row = [
            'label' => $assoc['label'] ?? '',
            'account_holder' => $assoc['account_holder'] ?? '',
            'account_number' => $account,
            'ifsc_code' => $ifsc,
            'amount' => $amount,
            'purpose' => $assoc['purpose'] ?? 'Bulk payout',
            'bank_name' => $assoc['bank_name'] ?? '',
            'account_type' => strtolower($assoc['account_type'] ?? 'savings') ?: 'savings',
            'line' => $lineNo,
        ];
        if ($rowErr) {
            $errors[] = ['line' => $lineNo, 'error' => implode('; ', $rowErr)];
        } else {
            $rows[] = $row;
        }
        if (count($rows) + count($errors) >= 200) {
            break; // hard cap per upload
        }
    }
    if (empty($rows) && empty($errors)) {
        return ['ok' => false, 'error' => 'No data rows found.', 'rows' => [], 'errors' => []];
    }
    return ['ok' => true, 'rows' => $rows, 'errors' => $errors, 'error' => null];
}

/**
 * Process bulk CSV: ensure beneficiaries exist, create gated payout drafts.
 * Never moves money when partner keys are absent.
 */
function processPayoutBulkCsv(int $merchantId, string $csvText, string $makerBy): array
{
    $parsed = parsePayoutBulkCsv($csvText);
    if (empty($parsed['ok'])) {
        return ['ok' => false, 'error' => $parsed['error'] ?? 'Invalid CSV.', 'created' => 0, 'failed' => 0, 'row_errors' => $parsed['errors'] ?? []];
    }
    $created = 0;
    $failed = 0;
    $rowErrors = $parsed['errors'];
    $active = listPayoutBeneficiaries($merchantId, true);

    foreach ($parsed['rows'] as $row) {
        // Find or create beneficiary by account+ifsc
        $benId = null;
        foreach ($active as $b) {
            if ((string)$b['account_number'] === $row['account_number'] && strtoupper((string)$b['ifsc_code']) === $row['ifsc_code']) {
                $benId = (int)$b['id'];
                break;
            }
        }
        if ($benId === null) {
            $add = addPayoutBeneficiary($merchantId, $row);
            if (empty($add['ok'])) {
                $failed++;
                $rowErrors[] = ['line' => $row['line'], 'error' => $add['error'] ?? 'Could not save beneficiary'];
                continue;
            }
            $active = listPayoutBeneficiaries($merchantId, true);
            foreach ($active as $b) {
                if ((string)$b['account_number'] === $row['account_number'] && strtoupper((string)$b['ifsc_code']) === $row['ifsc_code']) {
                    $benId = (int)$b['id'];
                    break;
                }
            }
        }
        if (!$benId) {
            $failed++;
            $rowErrors[] = ['line' => $row['line'], 'error' => 'Beneficiary missing after save'];
            continue;
        }
        $res = createPayoutDraft($merchantId, $benId, (float)$row['amount'], (string)$row['purpose'], $makerBy);
        if (!empty($res['ok'])) {
            $created++;
        } else {
            $failed++;
            $rowErrors[] = ['line' => $row['line'], 'error' => $res['error'] ?? 'Draft failed'];
        }
    }

    $msg = "Bulk upload processed: {$created} draft(s) recorded";
    if ($failed > 0) {
        $msg .= ", {$failed} row(s) failed validation";
    }
    if (!payoutLiveMoneyAllowed()) {
        $msg .= '. Live money movement is gated — drafts show failure reason, no funds moved.';
    }
    return ['ok' => true, 'message' => $msg, 'created' => $created, 'failed' => $failed, 'row_errors' => $rowErrors];
}

function updatePayoutBeneficiary(int $merchantId, int $beneficiaryId, array $data): array
{
    ensurePayoutSchema();
    $label = trim((string)($data['label'] ?? ''));
    $holder = trim((string)($data['account_holder'] ?? ''));
    $account = preg_replace('/\D/', '', (string)($data['account_number'] ?? ''));
    $ifsc = strtoupper(trim((string)($data['ifsc_code'] ?? '')));
    $bank = trim((string)($data['bank_name'] ?? ''));
    $type = strtolower(trim((string)($data['account_type'] ?? 'savings')));
    $upi = strtolower(trim((string)($data['upi_vpa'] ?? '')));
    if ($label === '' || $holder === '' || strlen($account) < 6 || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        return ['ok' => false, 'error' => 'Label, account holder, valid account number and IFSC are required.'];
    }
    if (!in_array($type, ['savings', 'current'], true)) {
        $type = 'savings';
    }
    try {
        $st = getDB()->prepare(
            'UPDATE payout_beneficiaries SET label=?, account_holder=?, account_number=?, ifsc_code=?, bank_name=?, account_type=?, upi_vpa=?, penny_drop_status="pending", penny_drop_note=NULL
             WHERE id=? AND merchant_id=? AND status="active"'
        );
        $st->execute([
            payoutStrLimit($label, 120), payoutStrLimit($holder, 190), $account, $ifsc,
            $bank !== '' ? payoutStrLimit($bank, 120) : null, $type,
            $upi !== '' ? payoutStrLimit($upi, 120) : null,
            $beneficiaryId, $merchantId,
        ]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Beneficiary not found or inactive.'];
        }
        return ['ok' => true, 'message' => 'Beneficiary updated. Penny-drop status reset to pending.'];
    } catch (Throwable $e) {
        try {
            $st = getDB()->prepare(
                'UPDATE payout_beneficiaries SET label=?, account_holder=?, account_number=?, ifsc_code=?, bank_name=?, account_type=?, upi_vpa=?, penny_drop_status="pending"
                 WHERE id=? AND merchant_id=? AND status="active"'
            );
            $st->execute([
                payoutStrLimit($label, 120), payoutStrLimit($holder, 190), $account, $ifsc,
                $bank !== '' ? payoutStrLimit($bank, 120) : null, $type,
                $upi !== '' ? payoutStrLimit($upi, 120) : null,
                $beneficiaryId, $merchantId,
            ]);
            return ['ok' => $st->rowCount() > 0, 'message' => 'Beneficiary updated.', 'error' => 'Beneficiary not found.'];
        } catch (Throwable $e2) {
            return ['ok' => false, 'error' => 'Could not update beneficiary.'];
        }
    }
}

/**
 * Gated penny-drop: activates only when payout/bank partner keys exist.
 * Never invents a verified status without keys.
 */
function requestPayoutBeneficiaryPennyDrop(int $merchantId, int $beneficiaryId): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM payout_beneficiaries WHERE id=? AND merchant_id=? AND status="active" LIMIT 1');
        $st->execute([$beneficiaryId, $merchantId]);
        $ben = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Beneficiary not found.'];
    }
    if (!$ben) {
        return ['ok' => false, 'error' => 'Beneficiary not found.'];
    }
    if (!payoutPartnerKeysConfigured() && !function_exists('verifyBankAccount')) {
        $note = 'Penny-drop activates when payout partner / bank verification keys are added.';
        try {
            getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=?, penny_drop_note=? WHERE id=? AND merchant_id=?')
                ->execute(['keys_pending', $note, $beneficiaryId, $merchantId]);
        } catch (Throwable $e) {
            try {
                getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=? WHERE id=? AND merchant_id=?')
                    ->execute(['keys_pending', $beneficiaryId, $merchantId]);
            } catch (Throwable $e2) { /* ok */ }
        }
        return ['ok' => true, 'gated' => true, 'message' => $note];
    }
    if (!payoutPartnerKeysConfigured()) {
        $note = 'Penny-drop activates when payout partner keys are added (RazorpayX / Cashfree Payouts).';
        try {
            getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=?, penny_drop_note=? WHERE id=? AND merchant_id=?')
                ->execute(['keys_pending', $note, $beneficiaryId, $merchantId]);
        } catch (Throwable $e) {
            try {
                getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=? WHERE id=? AND merchant_id=?')
                    ->execute(['keys_pending', $beneficiaryId, $merchantId]);
            } catch (Throwable $e2) { /* ok */ }
        }
        return ['ok' => true, 'gated' => true, 'message' => $note];
    }
    // Keys present: submit via existing bank verify scaffold (still may be pending until partner confirms).
    $verify = verifyBankAccount((string)$ben['account_number'], (string)$ben['ifsc_code'], $merchantId);
    $status = (($verify['status'] ?? '') === 'verified') ? 'verified' : 'submitted';
    $note = (string)($verify['message'] ?? 'Penny-drop submitted to partner.');
    try {
        getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=?, penny_drop_note=? WHERE id=? AND merchant_id=?')
            ->execute([$status, payoutStrLimit($note, 255), $beneficiaryId, $merchantId]);
    } catch (Throwable $e) {
        getDB()->prepare('UPDATE payout_beneficiaries SET penny_drop_status=? WHERE id=? AND merchant_id=?')
            ->execute([$status, $beneficiaryId, $merchantId]);
    }
    return ['ok' => true, 'gated' => false, 'message' => $note, 'status' => $status];
}

/** Maker-checker: second privileged user approves a pending_checker draft. Execution stays gated. */
function approvePayoutChecker(int $merchantId, int $orderId, string $checkerBy): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM payout_orders WHERE id=? AND merchant_id=? LIMIT 1');
        $st->execute([$orderId, $merchantId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    if (!$row || ($row['status'] ?? '') !== 'pending_checker') {
        return ['ok' => false, 'error' => 'Order is not awaiting checker approval.'];
    }
    if (strcasecmp((string)($row['maker_by'] ?? ''), $checkerBy) === 0) {
        return ['ok' => false, 'error' => 'Maker cannot approve their own high-value payout (maker-checker).'];
    }
    $next = payoutLiveMoneyAllowed() ? 'queued' : 'draft';
    $note = payoutLiveMoneyAllowed()
        ? null
        : (payoutActivationMessage() . ' Checker approved — live dispatch still gated.');
    try {
        getDB()->prepare('UPDATE payout_orders SET status=?, checker_by=?, checker_at=NOW(), failure_reason=COALESCE(?, failure_reason) WHERE id=? AND merchant_id=?')
            ->execute([$next, $checkerBy, $note, $orderId, $merchantId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update order.'];
    }
    return [
        'ok' => true,
        'message' => payoutLiveMoneyAllowed()
            ? 'Checker approved. Payout queued (partner rail).'
            : 'Checker approved. Live execution remains gated until partner keys + payout_live_enabled.',
    ];
}

/**
 * Request reversal for a failed payout — goes to reconciliation queue.
 * NEVER auto-credits the wallet.
 */
function requestPayoutReversal(int $merchantId, int $orderId, string $note = ''): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM payout_orders WHERE id=? AND merchant_id=? LIMIT 1');
        $st->execute([$orderId, $merchantId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    if (!$row) {
        return ['ok' => false, 'error' => 'Order not found.'];
    }
    if (($row['status'] ?? '') !== 'failed') {
        return ['ok' => false, 'error' => 'Only failed payouts can request reversal review.'];
    }
    try {
        $chk = getDB()->prepare('SELECT id FROM payout_reversal_requests WHERE payout_order_id=? AND status="pending" LIMIT 1');
        $chk->execute([$orderId]);
        if ($chk->fetch()) {
            return ['ok' => false, 'error' => 'A reversal request is already pending reconciliation.'];
        }
        getDB()->prepare('INSERT INTO payout_reversal_requests (payout_order_id, merchant_id, merchant_note) VALUES (?,?,?)')
            ->execute([$orderId, $merchantId, payoutStrLimit(trim($note), 500) ?: null]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create reversal request.'];
    }
    return [
        'ok' => true,
        'message' => 'Reversal request sent to reconciliation queue. Wallet is NOT auto-credited — ops must confirm the bank did not debit.',
    ];
}

function getPayoutReversalRequests(string $status = 'pending', int $limit = 100): array
{
    ensurePayoutSchema();
    $sql = 'SELECT r.*, o.payout_id, o.amount, o.failure_reason, m.business_name, m.merchant_code
            FROM payout_reversal_requests r
            JOIN payout_orders o ON o.id=r.payout_order_id
            JOIN merchants m ON m.id=r.merchant_id';
    $params = [];
    if (in_array($status, ['pending', 'approved', 'rejected', 'reconciled'], true)) {
        $sql .= ' WHERE r.status=?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY r.created_at DESC LIMIT ' . max(1, min(300, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Decide a reversal request. approve/reconciled = mark reviewed only — NEVER credits wallet.
 */
function decidePayoutReversal(int $requestId, string $decision, string $decidedBy, string $adminNote = ''): array
{
    ensurePayoutSchema();
    if (!in_array($decision, ['approved', 'rejected', 'reconciled'], true)) {
        return ['ok' => false, 'error' => 'Invalid decision.'];
    }
    try {
        $st = getDB()->prepare('SELECT * FROM payout_reversal_requests WHERE id=? LIMIT 1');
        $st->execute([$requestId]);
        $row = $st->fetch();
        if (!$row || ($row['status'] ?? '') !== 'pending') {
            return ['ok' => false, 'error' => 'Request not found or already decided.'];
        }
        getDB()->prepare('UPDATE payout_reversal_requests SET status=?, admin_note=?, decided_by=?, decided_at=NOW() WHERE id=?')
            ->execute([$decision, payoutStrLimit(trim($adminNote), 500) ?: null, $decidedBy, $requestId]);
        // Explicit: no wallet credit here. Ops must use a separate controlled ledger action after bank confirm.
        if (function_exists('createNotification')) {
            $msg = $decision === 'rejected'
                ? 'Your payout reversal request was rejected. ' . ($adminNote ?: 'Contact support.')
                : 'Your payout reversal request was marked ' . $decision . ' after reconciliation review. No automatic wallet credit was applied.';
            createNotification((int)$row['merchant_id'], 'Payout reversal update', $msg);
        }
        return [
            'ok' => true,
            'message' => 'Reversal marked ' . $decision . '. Wallet was NOT auto-credited (policy).',
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ---- Merchant payout API keys (generate / rotate / revoke) — live use gated ---- */

function listPayoutApiCredentials(int $merchantId): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare('SELECT id, key_prefix, status, last_used_at, revoked_at, created_at FROM payout_api_credentials WHERE merchant_id=? ORDER BY id DESC LIMIT 20');
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function generatePayoutApiCredential(int $merchantId, bool $revokeExisting = true): array
{
    ensurePayoutSchema();
    $prefix = 'uwpo_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $secret = 'psec_' . bin2hex(random_bytes(24));
    $keyHash = hash('sha256', $prefix);
    $secretHash = hash('sha256', $secret);
    try {
        if ($revokeExisting) {
            getDB()->prepare("UPDATE payout_api_credentials SET status='revoked', revoked_at=NOW() WHERE merchant_id=? AND status='active'")
                ->execute([$merchantId]);
        }
        getDB()->prepare('INSERT INTO payout_api_credentials (merchant_id, key_prefix, key_hash, secret_hash) VALUES (?,?,?,?)')
            ->execute([$merchantId, $prefix, $keyHash, $secretHash]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create payout API credential.'];
    }
    if (function_exists('createNotification')) {
        createNotification($merchantId, 'Payout API key generated', 'A new payout API credential was created. Live payout API calls stay gated until partner keys + payout_live_enabled. Secret shown once only.');
    }
    return [
        'ok' => true,
        'message' => 'Payout API credential created. Copy the secret now — it cannot be shown again. Live use stays gated.',
        'key' => $prefix,
        'secret' => $secret,
        'gated' => !payoutLiveMoneyAllowed(),
    ];
}

function revokePayoutApiCredential(int $merchantId, int $credentialId): array
{
    ensurePayoutSchema();
    try {
        $st = getDB()->prepare("UPDATE payout_api_credentials SET status='revoked', revoked_at=NOW() WHERE id=? AND merchant_id=? AND status='active'");
        $st->execute([$credentialId, $merchantId]);
        return ['ok' => $st->rowCount() > 0, 'message' => 'Payout API key revoked.', 'error' => 'Key not found or already revoked.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not revoke key.'];
    }
}
