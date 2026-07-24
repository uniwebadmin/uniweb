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
}

function nbfcLiveDisburseAllowed(): bool
{
    $gw = trim((string)getSetting('nbfc_partner_gateway', 'payu'));
    if ($gw === '') {
        $gw = 'payu';
    }
    if (!function_exists('isGatewayConfigured') || !isGatewayConfigured($gw)) {
        return false;
    }
    return trim((string)getSetting('nbfc_live_enabled', '0')) === '1';
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
    if (function_exists('createNotification')) {
        createNotification(
            (int)$row['merchant_id'],
            'NBFC application update',
            'Ref ' . $row['app_ref'] . ' is now ' . $newStatus . '.'
            . (nbfcLiveDisburseAllowed() ? '' : ' Live money still needs partner keys.')
        );
    }
    return ['ok' => true, 'message' => 'Application marked ' . $newStatus . '.', 'status' => $newStatus];
}
