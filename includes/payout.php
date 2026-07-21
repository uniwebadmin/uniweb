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
                INDEX idx_po_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('ensurePayoutSchema orders: ' . $e->getMessage());
    }
    schemaExecQuiet("ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0");
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
            ->execute([$merchantId, mb_substr(trim($note), 0, 500) ?: null]);
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
            ->execute([$status, mb_substr(trim($adminNote), 0, 500) ?: null, $decidedBy, $requestId]);
        if ($approve) {
            try {
                $db->prepare('UPDATE merchants SET payout_enabled=1 WHERE id=?')->execute([(int)$row['merchant_id']]);
            } catch (Throwable $e) {
                schemaExecQuiet('ALTER TABLE merchants ADD COLUMN payout_enabled TINYINT(1) NOT NULL DEFAULT 0');
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
            $merchantId, mb_substr($label, 0, 120), mb_substr($holder, 0, 190), $account, $ifsc,
            $bank !== '' ? mb_substr($bank, 0, 120) : null, $type,
            $upi !== '' ? mb_substr($upi, 0, 120) : null,
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
 * Create a draft / maker-checker placeholder payout. NEVER dispatches to a partner.
 * Returns failure_reason messaging for UI demos without moving money.
 */
function createPayoutDraft(int $merchantId, int $beneficiaryId, float $amount, string $purpose, string $makerBy): array
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
    $status = $needsChecker ? 'pending_checker' : 'pending_maker';

    // Hard block: never queue live money without partner keys + live flag.
    if (!payoutLiveMoneyAllowed()) {
        $status = 'failed';
        $failure = payoutActivationMessage() . ' Draft recorded for audit only — no funds moved.';
        try {
            getDB()->prepare(
                'INSERT INTO payout_orders (payout_id, merchant_id, beneficiary_id, amount, purpose, status, failure_reason, maker_by, maker_at)
                 VALUES (?,?,?,?,?,?,?,?,NOW())'
            )->execute([
                $payoutId, $merchantId, $beneficiaryId, $amount,
                mb_substr(trim($purpose), 0, 120) ?: 'Vendor payout',
                $status, $failure, $makerBy,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Could not record payout draft.'];
        }
        return [
            'ok' => true,
            'blocked' => true,
            'payout_id' => $payoutId,
            'message' => 'Payout recorded as failed (gated). Reason shown below. No wallet debit and no auto-reversal.',
            'failure_reason' => $failure,
        ];
    }

    // Live path reserved for future partner wiring — still requires maker-checker.
    try {
        getDB()->prepare(
            'INSERT INTO payout_orders (payout_id, merchant_id, beneficiary_id, amount, purpose, status, maker_by, maker_at)
             VALUES (?,?,?,?,?,?,?,NOW())'
        )->execute([
            $payoutId, $merchantId, $beneficiaryId, $amount,
            mb_substr(trim($purpose), 0, 120) ?: 'Vendor payout',
            $status, $makerBy,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create payout order.'];
    }
    return [
        'ok' => true,
        'blocked' => false,
        'payout_id' => $payoutId,
        'message' => $needsChecker
            ? 'High-value payout submitted for checker approval (maker-checker).'
            : 'Payout queued for maker confirmation.',
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
