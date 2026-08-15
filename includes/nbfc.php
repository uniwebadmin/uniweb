<?php
declare(strict_types=1);

/**
 * NBFC / merchant finance entitlement + application drafts.
 * Live disbursement stays off until partner keys exist (never invent bank APIs).
 */

function ensureNbfcSchema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS nbfc_applications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                app_ref VARCHAR(32) NOT NULL,
                amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                tenure_months INT NOT NULL DEFAULT 12,
                purpose VARCHAR(255) DEFAULT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "draft",
                merchant_note VARCHAR(500) DEFAULT NULL,
                admin_note VARCHAR(500) DEFAULT NULL,
                partner_ref VARCHAR(120) DEFAULT NULL,
                decided_by VARCHAR(120) DEFAULT NULL,
                decided_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_nbfc_ref (app_ref),
                INDEX idx_nbfc_merchant (merchant_id),
                INDEX idx_nbfc_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        error_log('ensureNbfcSchema: ' . $e->getMessage());
    }
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS nbfc_loans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                merchant_id INT NOT NULL,
                application_id INT NOT NULL,
                loan_ref VARCHAR(32) NOT NULL,
                principal DECIMAL(14,2) NOT NULL,
                tenure_months INT NOT NULL,
                interest_rate_pa DECIMAL(6,2) NOT NULL DEFAULT 18.00,
                emi_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
                status VARCHAR(32) NOT NULL DEFAULT "active",
                disbursed_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_nbfc_loan_ref (loan_ref),
                INDEX idx_nbfc_loan_merchant (merchant_id),
                INDEX idx_nbfc_loan_app (application_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { /* ok */ }
    try {
        $db->exec(
            'CREATE TABLE IF NOT EXISTS nbfc_emi_schedule (
                id INT AUTO_INCREMENT PRIMARY KEY,
                loan_id INT NOT NULL,
                installment_no INT NOT NULL,
                due_date DATE NOT NULL,
                amount DECIMAL(14,2) NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT "upcoming",
                paid_at DATETIME DEFAULT NULL,
                UNIQUE KEY uq_nbfc_emi (loan_id, installment_no),
                INDEX idx_nbfc_emi_loan (loan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) { /* ok */ }
}

function nbfcLiveDisburseAllowed(): bool
{
    // P11-02: NBFC lending is excluded from UniWeb product. Never disburse.
    return false;
}

function merchantNbfcEntitled(array $merchant): bool
{
    $enabled = function_exists('getMerchantEnabledMethods')
        ? getMerchantEnabledMethods($merchant)
        : [];
    return in_array('nbfc', $enabled, true);
}

function listNbfcApplications(int $merchantId, int $limit = 50): array
{
    ensureNbfcSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM nbfc_applications WHERE merchant_id=? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getAdminNbfcApplications(string $status = 'submitted', int $limit = 100): array
{
    ensureNbfcSchema();
    $sql = 'SELECT a.*, m.business_name, m.merchant_code FROM nbfc_applications a JOIN merchants m ON m.id=a.merchant_id';
    $params = [];
    if (in_array($status, ['draft', 'submitted', 'sent_to_partner', 'approved', 'rejected'], true)) {
        $sql .= ' WHERE a.status=?';
        $params[] = $status;
    } elseif ($status === 'actionable') {
        $sql .= ' WHERE a.status IN ("submitted","sent_to_partner")';
    }
    $sql .= ' ORDER BY a.id ASC LIMIT ' . max(1, min(200, $limit));
    try {
        $st = getDB()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function submitNbfcApplication(int $merchantId, float $amount, int $tenureMonths, string $purpose, string $note = ''): array
{
    ensureNbfcSchema();
    $st = getDB()->prepare('SELECT * FROM merchants WHERE id=? LIMIT 1');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }
    if (!merchantNbfcEntitled($merchant)) {
        return ['ok' => false, 'error' => 'NBFC access is not enabled yet. Wait for partner approval on your method request.'];
    }
    if ($amount < 1000 || $amount > 5000000) {
        return ['ok' => false, 'error' => 'Amount must be between ₹1,000 and ₹50,00,000.'];
    }
    $tenureMonths = max(3, min(60, $tenureMonths));
    $purpose = mb_substr(trim($purpose), 0, 255);
    if ($purpose === '') {
        return ['ok' => false, 'error' => 'Please enter a purpose.'];
    }
    $ref = 'NBFC' . strtoupper(bin2hex(random_bytes(5)));
    $status = 'submitted';
    try {
        getDB()->prepare(
            'INSERT INTO nbfc_applications (merchant_id, app_ref, amount, tenure_months, purpose, status, merchant_note)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $merchantId,
            $ref,
            $amount,
            $tenureMonths,
            $purpose,
            $status,
            mb_substr(trim($note), 0, 500) ?: null,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save application.'];
    }
    if (function_exists('createNotification')) {
        createNotification(
            $merchantId,
            'NBFC application submitted',
            'Ref ' . $ref . ' is with UniWeb admin. Live disbursement waits for partner keys.'
        );
    }
    return [
        'ok' => true,
        'app_ref' => $ref,
        'message' => nbfcLiveDisburseAllowed()
            ? 'Application submitted. Admin can forward it to the NBFC partner.'
            : 'Application saved. Partner production keys are still needed before real disbursement.',
        'live' => nbfcLiveDisburseAllowed(),
    ];
}

function decideNbfcApplication(int $appId, string $action, string $actor, string $note = ''): array
{
    ensureNbfcSchema();
    $st = getDB()->prepare('SELECT * FROM nbfc_applications WHERE id=? LIMIT 1');
    $st->execute([$appId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Application not found.'];
    }
    $status = (string)$row['status'];
    $newStatus = null;
    $partnerRef = $row['partner_ref'] ?? null;
    if ($action === 'send_partner' && in_array($status, ['submitted', 'rejected'], true)) {
        $newStatus = 'sent_to_partner';
        $partnerRef = $partnerRef ?: ('NBFCP-' . $appId);
        if (function_exists('submitMerchantToGateway')) {
            try {
                $gw = trim((string)getSetting('nbfc_partner_gateway', 'payu')) ?: 'payu';
                submitMerchantToGateway((int)$row['merchant_id'], $gw, 0, 'NBFC application ' . $row['app_ref']);
            } catch (Throwable $e) { /* local trail only */ }
        }
    } elseif ($action === 'approve' && in_array($status, ['submitted', 'sent_to_partner'], true)) {
        $newStatus = 'approved';
    } elseif ($action === 'reject' && !in_array($status, ['approved', 'rejected'], true)) {
        $newStatus = 'rejected';
    } else {
        return ['ok' => false, 'error' => 'Action not allowed for status: ' . $status];
    }
    try {
        getDB()->prepare(
            'UPDATE nbfc_applications SET status=?, admin_note=?, partner_ref=?, decided_by=?, decided_at=NOW() WHERE id=?'
        )->execute([
            $newStatus,
            mb_substr(trim($note), 0, 500) ?: null,
            $partnerRef,
            mb_substr($actor, 0, 120),
            $appId,
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update application.'];
    }
    $loanRef = null;
    if ($newStatus === 'approved') {
        $loan = createNbfcLoanFromApplication($appId);
        $loanRef = $loan['loan_ref'] ?? null;
    }
    if (function_exists('createNotification')) {
        createNotification(
            (int)$row['merchant_id'],
            'NBFC application update',
            'Ref ' . $row['app_ref'] . ' is now ' . $newStatus . '.'
            . ($loanRef ? (' Loan ' . $loanRef . ' + EMI schedule ready.') : '')
            . (nbfcLiveDisburseAllowed() ? '' : ' Live money still needs partner keys.')
        );
    }
    return [
        'ok' => true,
        'message' => 'Application marked ' . $newStatus . '.' . ($loanRef ? (' Loan ' . $loanRef . ' created.') : ''),
        'status' => $newStatus,
        'loan_ref' => $loanRef,
    ];
}

/** Simple EMI: equal monthly installment with flat annual rate / 12. */
function nbfcCalculateEmi(float $principal, int $months, float $ratePa = 18.0): float
{
    $months = max(1, $months);
    if ($principal <= 0) {
        return 0.0;
    }
    $r = ($ratePa / 100) / 12;
    if ($r <= 0) {
        return round($principal / $months, 2);
    }
    $pow = pow(1 + $r, $months);
    return round($principal * $r * $pow / ($pow - 1), 2);
}

function createNbfcLoanFromApplication(int $appId, float $ratePa = 18.0): array
{
    ensureNbfcSchema();
    $st = getDB()->prepare('SELECT * FROM nbfc_applications WHERE id=? LIMIT 1');
    $st->execute([$appId]);
    $app = $st->fetch();
    if (!$app || (string)$app['status'] !== 'approved') {
        return ['ok' => false, 'error' => 'Application must be approved first.'];
    }
    $chk = getDB()->prepare('SELECT loan_ref FROM nbfc_loans WHERE application_id=? LIMIT 1');
    $chk->execute([$appId]);
    $existing = $chk->fetchColumn();
    if ($existing) {
        return ['ok' => true, 'loan_ref' => (string)$existing, 'existing' => true];
    }

    $principal = (float)$app['amount'];
    $months = max(3, (int)$app['tenure_months']);
    $emi = nbfcCalculateEmi($principal, $months, $ratePa);
    $loanRef = 'LN' . strtoupper(bin2hex(random_bytes(5)));
    $disburseLive = nbfcLiveDisburseAllowed();
    $loanStatus = $disburseLive ? 'active' : 'scheduled'; // scheduled = ledger ready, money gated

    try {
        getDB()->prepare(
            'INSERT INTO nbfc_loans (merchant_id, application_id, loan_ref, principal, tenure_months, interest_rate_pa, emi_amount, status, disbursed_at)
             VALUES (?,?,?,?,?,?,?,?,?)'
        )->execute([
            (int)$app['merchant_id'],
            $appId,
            $loanRef,
            $principal,
            $months,
            $ratePa,
            $emi,
            $loanStatus,
            $disburseLive ? date('Y-m-d H:i:s') : null,
        ]);
        $loanId = (int)getDB()->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create loan.'];
    }

    $start = new DateTimeImmutable('first day of next month');
    for ($i = 1; $i <= $months; $i++) {
        $due = $start->modify('+' . ($i - 1) . ' months')->format('Y-m-d');
        try {
            getDB()->prepare(
                'INSERT INTO nbfc_emi_schedule (loan_id, installment_no, due_date, amount, status) VALUES (?,?,?,?,?)'
            )->execute([$loanId, $i, $due, $emi, 'upcoming']);
        } catch (Throwable $e) {
            break;
        }
    }

    return [
        'ok' => true,
        'loan_ref' => $loanRef,
        'loan_id' => $loanId,
        'emi_amount' => $emi,
        'live_disbursed' => $disburseLive,
    ];
}

function getNbfcLoanByRef(string $loanRef, int $merchantId = 0): ?array
{
    ensureNbfcSchema();
    try {
        if ($merchantId > 0) {
            $st = getDB()->prepare('SELECT * FROM nbfc_loans WHERE loan_ref=? AND merchant_id=? LIMIT 1');
            $st->execute([$loanRef, $merchantId]);
        } else {
            $st = getDB()->prepare('SELECT * FROM nbfc_loans WHERE loan_ref=? LIMIT 1');
            $st->execute([$loanRef]);
        }
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function listNbfcLoansForMerchant(int $merchantId): array
{
    ensureNbfcSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM nbfc_loans WHERE merchant_id=? ORDER BY id DESC LIMIT 50');
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function getNbfcEmiSchedule(int $loanId): array
{
    ensureNbfcSchema();
    try {
        $st = getDB()->prepare('SELECT * FROM nbfc_emi_schedule WHERE loan_id=? ORDER BY installment_no ASC');
        $st->execute([$loanId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
