<?php
declare(strict_types=1);

/**
 * Split Settlement at Transaction Level.
 *
 * When a payment is collected, the platform fee and merchant net are tracked
 * separately from the gross amount. This module provides functions to:
 *   - Calculate the split for a transaction (gross → platform_fee + merchant_net + gst)
 *   - Record the split in a dedicated table
 *   - Query splits for settlement and reconciliation
 *
 * Split formula (F1/F2):
 *   M = merchant mdr_percent (merchant_pricing)
 *   P = partner base mdr_percent (partner_commercial)
 *   platform_fee = gross * (M - P) / 100
 *   merchant_net = gross * (1 - M/100) = gross - gross*M/100
 *   partner_fee  = gross * P / 100 (informational; partner charges on their side)
 *
 * Default M when merchant goes live if unset: 2.00%
 */

/** Default merchant MDR percent if no merchant_pricing row exists. */
const DEFAULT_MDR_PERCENT = 2.00;

function ensureSplitSettlementTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS transaction_splits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            merchant_id INT NOT NULL,
            gross_amount DECIMAL(14,2) NOT NULL,
            platform_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            gst_on_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            merchant_net DECIMAL(14,2) NOT NULL DEFAULT 0,
            mdr_rate DECIMAL(6,4) NOT NULL DEFAULT 0,
            fixed_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            split_status ENUM('pending','settled','reversed') NOT NULL DEFAULT 'pending',
            settled_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY idx_txn (transaction_id),
            INDEX idx_merchant_status (merchant_id, split_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // F1: merchant_pricing table — per-merchant MDR controlled by admin
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_pricing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            partner_id VARCHAR(40) DEFAULT NULL,
            mdr_percent DECIMAL(6,4) NOT NULL,
            effective_from DATE NOT NULL,
            created_by VARCHAR(60) NOT NULL DEFAULT 'system',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, effective_from),
            INDEX idx_partner (partner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // F1: partner_commercial table — partner base MDR (P), admin-set
        getDB()->exec("CREATE TABLE IF NOT EXISTS partner_commercial (
            id INT AUTO_INCREMENT PRIMARY KEY,
            partner_key VARCHAR(40) NOT NULL UNIQUE,
            base_mdr_percent DECIMAL(6,4) NOT NULL DEFAULT 0,
            settlement_mode ENUM('route_mode','standard_settle_mode') NOT NULL DEFAULT 'standard_settle_mode',
            route_enabled TINYINT(1) NOT NULL DEFAULT 0,
            route_mode VARCHAR(20) NOT NULL DEFAULT 'off',
            route_provider VARCHAR(30) NOT NULL DEFAULT 'none',
            route_linked_account_hint VARCHAR(120) DEFAULT NULL,
            route_split_on VARCHAR(20) NOT NULL DEFAULT 'capture',
            route_status VARCHAR(20) NOT NULL DEFAULT 'scaffold',
            updated_by VARCHAR(60) NOT NULL DEFAULT 'system',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // F3: partner_transfers table — idempotent split/transfer records
        getDB()->exec("CREATE TABLE IF NOT EXISTS partner_transfers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT NOT NULL,
            merchant_id INT NOT NULL,
            partner_key VARCHAR(40) NOT NULL,
            partner_transfer_id VARCHAR(200) DEFAULT NULL,
            transfer_type ENUM('merchant_leg','platform_leg','refund_reversal') NOT NULL DEFAULT 'merchant_leg',
            amount DECIMAL(14,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'INR',
            status ENUM('pending','processed','failed') NOT NULL DEFAULT 'pending',
            linked_account_id VARCHAR(120) DEFAULT NULL,
            failure_reason VARCHAR(500) DEFAULT NULL,
            idempotency_key VARCHAR(200) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_idem (idempotency_key),
            INDEX idx_txn (transaction_id),
            INDEX idx_merchant_status (merchant_id, status),
            INDEX idx_partner (partner_key, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * F1: Get partner base MDR (P) from partner_commercial table.
 * Returns 0 if not set (no partner cost → platform keeps full M).
 */
function getPartnerBaseMdr(string $partnerKey, ?string $method = null): float
{
    ensureSplitSettlementTable();
    if ($method !== null && $method !== '') {
        if (function_exists('getPartnerMethodMdr')) {
            $perMethod = getPartnerMethodMdr($partnerKey, $method);
            if ($perMethod > 0) return $perMethod;
        }
    }
    try {
        $st = getDB()->prepare('SELECT base_mdr_percent FROM partner_commercial WHERE partner_key=?');
        $st->execute([$partnerKey]);
        $row = $st->fetch();
        return $row ? (float)$row['base_mdr_percent'] : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

/**
 * F1: Get merchant MDR (M) in force at a given date (or now).
 * Falls back to DEFAULT_MDR_PERCENT if no row exists.
 */
function getMerchantMdr(int $merchantId, ?string $date = null): float
{
    ensureSplitSettlementTable();
    $date = $date ?: date('Y-m-d');
    try {
        $st = getDB()->prepare(
            'SELECT mdr_percent FROM merchant_pricing
             WHERE merchant_id=? AND effective_from <= ?
             ORDER BY effective_from DESC, id DESC LIMIT 1'
        );
        $st->execute([$merchantId, $date]);
        $row = $st->fetch();
        return $row ? (float)$row['mdr_percent'] : DEFAULT_MDR_PERCENT;
    } catch (Throwable $e) {
        return DEFAULT_MDR_PERCENT;
    }
}

/**
 * F1: Set merchant MDR (M). Rejects if M < P (partner base MDR).
 * @return array{ok:bool, error?:string}
 */
function setMerchantMdr(int $merchantId, float $mdrPercent, ?string $partnerKey = null, string $createdBy = 'admin'): array
{
    ensureSplitSettlementTable();
    if ($mdrPercent < 0 || $mdrPercent > 100) {
        return ['ok' => false, 'error' => 'MDR must be between 0 and 100.'];
    }
    // F1: M ≥ P rule
    if ($partnerKey !== null) {
        $p = getPartnerBaseMdr($partnerKey);
        if ($mdrPercent < $p) {
            return ['ok' => false, 'error' => "Merchant MDR ({$mdrPercent}%) must be ≥ partner base MDR ({$p}%)."];
        }
    }
    try {
        getDB()->prepare(
            'INSERT INTO merchant_pricing (merchant_id, partner_id, mdr_percent, effective_from, created_by)
             VALUES (?,?,?,?,?)'
        )->execute([$merchantId, $partnerKey, $mdrPercent, date('Y-m-d'), $createdBy]);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * F1: Set partner base MDR (P) and settlement mode.
 */
function setPartnerCommercial(string $partnerKey, float $baseMdr, string $settlementMode = 'standard_settle_mode', string $updatedBy = 'admin'): bool
{
    ensureSplitSettlementTable();
    if (!in_array($settlementMode, ['route_mode', 'standard_settle_mode'], true)) {
        $settlementMode = 'standard_settle_mode';
    }
    try {
        getDB()->prepare(
            'INSERT INTO partner_commercial (partner_key, base_mdr_percent, settlement_mode, updated_by)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE base_mdr_percent=VALUES(base_mdr_percent), settlement_mode=VALUES(settlement_mode), updated_by=VALUES(updated_by)'
        )->execute([$partnerKey, $baseMdr, $settlementMode, $updatedBy]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * P1-02: create a default partner_commercial row on first open so the form is never empty/fatal.
 */
function ensurePartnerCommercialSeeded(string $partnerKey, string $updatedBy = 'system'): void
{
    ensureSplitSettlementTable();
    $partnerKey = strtolower((string)preg_replace('/[^a-z0-9_]/', '', $partnerKey));
    if ($partnerKey === '') {
        return;
    }
    try {
        getDB()->prepare(
            'INSERT INTO partner_commercial (partner_key, base_mdr_percent, settlement_mode, updated_by)
             VALUES (?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE partner_key = partner_key'
        )->execute([$partnerKey, 'standard_settle_mode', $updatedBy]);
    } catch (Throwable $e) {
        // non-fatal — save_commercial UPSERT still works
    }
}

/**
 * F1: Get partner settlement mode (route_mode or standard_settle_mode).
 */
function getPartnerSettlementMode(string $partnerKey): string
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare('SELECT settlement_mode FROM partner_commercial WHERE partner_key=?');
        $st->execute([$partnerKey]);
        $row = $st->fetch();
        return $row ? (string)$row['settlement_mode'] : 'standard_settle_mode';
    } catch (Throwable $e) {
        return 'standard_settle_mode';
    }
}

/**
 * 2.9: Get partner route/split scaffold config (save/load only — no API calls).
 * Returns defaults if no row exists.
 */
function getPartnerRouteConfig(string $partnerKey): array
{
    ensureSplitSettlementTable();
    $defaults = [
        'route_enabled' => 0,
        'route_mode' => 'off',
        'route_provider' => 'none',
        'route_linked_account_hint' => '',
        'route_split_on' => 'capture',
        'route_status' => 'scaffold',
    ];
    try {
        $st = getDB()->prepare('SELECT route_enabled, route_mode, route_provider, route_linked_account_hint, route_split_on, route_status FROM partner_commercial WHERE partner_key=?');
        $st->execute([$partnerKey]);
        $row = $st->fetch();
        if (!$row) {
            return $defaults;
        }
        return [
            'route_enabled' => (int)$row['route_enabled'],
            'route_mode' => (string)$row['route_mode'],
            'route_provider' => (string)$row['route_provider'],
            'route_linked_account_hint' => (string)($row['route_linked_account_hint'] ?? ''),
            'route_split_on' => (string)$row['route_split_on'],
            'route_status' => (string)$row['route_status'],
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * 2.9: Save partner route/split scaffold config (save only — no API calls).
 */
function setPartnerRouteConfig(string $partnerKey, array $cfg, string $updatedBy = 'admin'): bool
{
    ensureSplitSettlementTable();
    $validModes = ['off', 'internal_only', 'partner_api'];
    $validProviders = ['none', 'razorpay_route', 'cashfree_vendor', 'other'];
    $validSplitOn = ['capture', 'settlement', 'manual'];
    $validStatus = ['scaffold', 'ready_for_api', 'live'];

    $routeEnabled = !empty($cfg['route_enabled']) ? 1 : 0;
    $routeMode = in_array($cfg['route_mode'] ?? '', $validModes, true) ? $cfg['route_mode'] : 'off';
    $routeProvider = in_array($cfg['route_provider'] ?? '', $validProviders, true) ? $cfg['route_provider'] : 'none';
    $routeHint = trim((string)($cfg['route_linked_account_hint'] ?? ''));
    $routeSplitOn = in_array($cfg['route_split_on'] ?? '', $validSplitOn, true) ? $cfg['route_split_on'] : 'capture';
    $routeStatus = in_array($cfg['route_status'] ?? '', $validStatus, true) ? $cfg['route_status'] : 'scaffold';

    // P11-01: never persist live Route unless Owner has set route_split_live_enabled=1.
    $ownerLive = trim((string)getSetting('route_split_live_enabled', '0')) === '1';
    if ($routeStatus === 'live' && !$ownerLive) {
        $routeStatus = 'ready_for_api';
    }

    try {
        getDB()->prepare(
            'INSERT INTO partner_commercial (partner_key, route_enabled, route_mode, route_provider, route_linked_account_hint, route_split_on, route_status, updated_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE route_enabled=VALUES(route_enabled), route_mode=VALUES(route_mode), route_provider=VALUES(route_provider),
             route_linked_account_hint=VALUES(route_linked_account_hint), route_split_on=VALUES(route_split_on), route_status=VALUES(route_status), updated_by=VALUES(updated_by)'
        )->execute([$partnerKey, $routeEnabled, $routeMode, $routeProvider, $routeHint, $routeSplitOn, $routeStatus, $updatedBy]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 2.9: Can use partner route for live split? Returns false unless
 * route_mode=partner_api AND route_status=live. This will stay false
 * until a future ticket implements the actual API integration.
 */
function canUsePartnerRoute(string $partnerKey): bool
{
    // P11-01: live Route/Split API stays off until Owner says start + keys + commercial.
    if (trim((string)getSetting('route_split_live_enabled', '0')) !== '1') {
        return false;
    }
    $cfg = getPartnerRouteConfig($partnerKey);
    return $cfg['route_enabled'] === 1
        && $cfg['route_mode'] === 'partner_api'
        && $cfg['route_status'] === 'live';
}

/**
 * F1: Get all partner commercial records (for admin view).
 */
function getAllPartnerCommercial(): array
{
    ensureSplitSettlementTable();
    try {
        return getDB()->query('SELECT * FROM partner_commercial ORDER BY partner_key')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * F1: Get merchant pricing history.
 */
function getMerchantPricingHistory(int $merchantId): array
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare('SELECT * FROM merchant_pricing WHERE merchant_id=? ORDER BY effective_from DESC, id DESC');
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get MDR rate and fixed fee for a merchant.
 */
function getMerchantSplitConfig(int $merchantId): array
{
    $db = getDB();
    $mdrRate = 0.02; // 2% default
    $fixedFee = 0.0;

    try {
        $st = $db->prepare("SELECT mdr_rate, fixed_fee FROM merchant_split_config WHERE merchant_id=?");
        $st->execute([$merchantId]);
        $row = $st->fetch();
        if ($row) {
            $mdrRate = (float)$row['mdr_rate'];
            $fixedFee = (float)$row['fixed_fee'];
        }
    } catch (Throwable $e) {}

    return [
        'mdr_rate' => $mdrRate,
        'fixed_fee' => $fixedFee,
        'gst_rate' => 0.18, // 18% GST on platform fee
    ];
}

/**
 * Calculate the split for a transaction.
 */
function calculateTransactionSplit(float $grossAmount, int $merchantId): array
{
    $config = getMerchantSplitConfig($merchantId);
    $platformFee = round($grossAmount * $config['mdr_rate'] + $config['fixed_fee'], 2);
    $gstOnFee = round($platformFee * $config['gst_rate'], 2);
    $merchantNet = round($grossAmount - $platformFee - $gstOnFee, 2);

    return [
        'gross_amount' => $grossAmount,
        'platform_fee' => $platformFee,
        'gst_on_fee' => $gstOnFee,
        'merchant_net' => $merchantNet,
        'mdr_rate' => $config['mdr_rate'],
        'fixed_fee' => $config['fixed_fee'],
    ];
}

/**
 * Record a split for a transaction (called after successful payment).
 */
function recordTransactionSplit(int $transactionId, int $merchantId, float $grossAmount): array
{
    ensureSplitSettlementTable();
    $split = calculateTransactionSplit($grossAmount, $merchantId);

    try {
        getDB()->prepare(
            "INSERT INTO transaction_splits
             (transaction_id, merchant_id, gross_amount, platform_fee, gst_on_fee, merchant_net, mdr_rate, fixed_fee, split_status)
             VALUES (?,?,?,?,?,?,?,?, 'pending')
             ON DUPLICATE KEY UPDATE gross_amount=VALUES(gross_amount), platform_fee=VALUES(platform_fee),
             gst_on_fee=VALUES(gst_on_fee), merchant_net=VALUES(merchant_net)"
        )->execute([
            $transactionId, $merchantId,
            $split['gross_amount'], $split['platform_fee'], $split['gst_on_fee'],
            $split['merchant_net'], $split['mdr_rate'], $split['fixed_fee'],
        ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'split' => $split];
}

/**
 * Mark splits as settled when a settlement batch is processed.
 */
function markSplitsSettled(int $merchantId, array $transactionIds): int
{
    ensureSplitSettlementTable();
    if (empty($transactionIds)) return 0;

    try {
        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $st = getDB()->prepare(
            "UPDATE transaction_splits SET split_status='settled', settled_at=NOW()
             WHERE merchant_id=? AND transaction_id IN ($placeholders) AND split_status='pending'"
        );
        $st->execute(array_merge([$merchantId], $transactionIds));
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get pending splits for a merchant (for settlement calculation).
 */
function getPendingSplits(int $merchantId, int $limit = 1000): array
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare(
            "SELECT ts.*, t.txn_id, t.created_at AS txn_at
             FROM transaction_splits ts
             JOIN transactions t ON t.id = ts.transaction_id
             WHERE ts.merchant_id=? AND ts.split_status='pending'
             ORDER BY ts.created_at ASC LIMIT ?"
        );
        $st->bindValue(1, $merchantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get split summary for a merchant.
 */
function getSplitSummary(int $merchantId, int $days = 30): array
{
    ensureSplitSettlementTable();
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));
    try {
        $st = getDB()->prepare(
            "SELECT
                COUNT(*) as total_splits,
                COALESCE(SUM(gross_amount),0) as total_gross,
                COALESCE(SUM(platform_fee),0) as total_fee,
                COALESCE(SUM(gst_on_fee),0) as total_gst,
                COALESCE(SUM(merchant_net),0) as total_net,
                SUM(CASE WHEN split_status='settled' THEN 1 ELSE 0 END) as settled_count,
                SUM(CASE WHEN split_status='pending' THEN 1 ELSE 0 END) as pending_count
             FROM transaction_splits WHERE merchant_id=? AND created_at >= ?"
        );
        $st->execute([$merchantId, $since]);
        return $st->fetch() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Update merchant's split config (admin action).
 */
function updateMerchantSplitConfig(int $merchantId, float $mdrRate, float $fixedFee): bool
{
    ensureSplitSettlementTable();
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_split_config (
            merchant_id INT PRIMARY KEY,
            mdr_rate DECIMAL(6,4) NOT NULL DEFAULT 0.0200,
            fixed_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        getDB()->prepare(
            "INSERT INTO merchant_split_config (merchant_id, mdr_rate, fixed_fee)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE mdr_rate=VALUES(mdr_rate), fixed_fee=VALUES(fixed_fee)"
        )->execute([$merchantId, $mdrRate, $fixedFee]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * F3: Create an idempotent partner transfer record.
 * Same idempotency_key = no duplicate. Key = payment_id + split attempt.
 */
function createPartnerTransfer(int $transactionId, int $merchantId, string $partnerKey, float $amount, string $transferType = 'merchant_leg', ?string $linkedAccountId = null): array
{
    ensureSplitSettlementTable();
    $idemKey = $transactionId . ':' . $transferType . ':' . $partnerKey;
    try {
        $st = getDB()->prepare(
            'INSERT INTO partner_transfers (transaction_id, merchant_id, partner_key, amount, transfer_type, linked_account_id, idempotency_key, status)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE amount=VALUES(amount)'
        );
        $st->execute([$transactionId, $merchantId, $partnerKey, $amount, $transferType, $linkedAccountId, $idemKey, 'pending']);
        $id = (int)getDB()->lastInsertId();
        if ($id === 0) {
            $st2 = getDB()->prepare('SELECT id, status FROM partner_transfers WHERE idempotency_key=?');
            $st2->execute([$idemKey]);
            $row = $st2->fetch();
            $id = (int)($row['id'] ?? 0);
        }
        return ['ok' => true, 'id' => $id, 'idempotency_key' => $idemKey];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * F3: Update partner transfer status (after partner API call or webhook).
 */
function updatePartnerTransferStatus(int $transferId, string $status, ?string $partnerTransferId = null, ?string $failureReason = null): void
{
    ensureSplitSettlementTable();
    try {
        getDB()->prepare(
            'UPDATE partner_transfers SET status=?, partner_transfer_id=?, failure_reason=? WHERE id=?'
        )->execute([$status, $partnerTransferId, mb_substr((string)$failureReason, 0, 500), $transferId]);
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * F3: Execute partner route/split after capture.
 * For route_mode partners: creates transfer records (actual API call deferred until partner SDK integration).
 * For standard_settle_mode: records transfer as 'pending' — partner settles per their cycle.
 *
 * F7: Blocks if partner_merchant_links missing or not active.
 */
function executePartnerRouteSplit(int $transactionId, int $merchantId, array $split, string $provider): array
{
    ensureSplitSettlementTable();

    // F7: Check linked account exists and is active
    if (!function_exists('getMerchantPartnerLinks')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $links = getMerchantPartnerLinks($merchantId);
    $partnerKey = '';
    $linkedAccountId = null;
    foreach ($links as $link) {
        if (($link['kyc_status'] ?? '') === 'live' || ($link['kyc_status'] ?? '') === 'active') {
            $partnerKey = (string)$link['partner_key'];
            $linkedAccountId = (string)($link['external_id'] ?? '');
            break;
        }
    }

    // F7: If no active linked account, fail safely
    if ($partnerKey === '') {
        return ['ok' => false, 'error' => 'No active partner linked account found. Split deferred — merchant settlement will follow partner standard cycle.'];
    }

    $settlementMode = getPartnerSettlementMode($partnerKey);
    $merchantNet = (float)($split['merchant_net'] ?? 0);
    $platformFee = (float)($split['platform_fee'] ?? 0);

    // F3: Create merchant_leg transfer record (idempotent)
    $merchantTransfer = createPartnerTransfer($transactionId, $merchantId, $partnerKey, $merchantNet, 'merchant_leg', $linkedAccountId);
    if (!$merchantTransfer['ok']) {
        return $merchantTransfer;
    }

    // F3: Create platform_leg transfer record if platform_fee > 0 (idempotent)
    if ($platformFee > 0) {
        createPartnerTransfer($transactionId, $merchantId, $partnerKey, $platformFee, 'platform_leg', $linkedAccountId);
    }

    if ($settlementMode === 'route_mode') {
        // F3: Route mode — partner API call would go here (Razorpay Route / Cashfree Easy Split / PayU split)
        // For now, mark as pending until partner SDK integration is complete.
        // When integrated: call partner transfer API, update status to 'processed' or 'failed'
        updatePartnerTransferStatus((int)$merchantTransfer['id'], 'pending');
        return ['ok' => true, 'mode' => 'route_mode', 'transfer_id' => (int)$merchantTransfer['id'], 'note' => 'Route transfer queued — partner API call pending integration.'];
    } else {
        // F3: Standard settle mode — partner settles to merchant per their cycle
        updatePartnerTransferStatus((int)$merchantTransfer['id'], 'pending');
        return ['ok' => true, 'mode' => 'standard_settle_mode', 'transfer_id' => (int)$merchantTransfer['id'], 'note' => 'Partner standard settlement cycle — no UniWeb CA holding.'];
    }
}

/**
 * F5: Get the pricing snapshot from a transaction (for refund reversal).
 */
function getTransactionSnapshot(int $transactionId): ?array
{
    try {
        $st = getDB()->prepare('SELECT amount, platform_fee, split_amount, mdr_m, mdr_p, partner_fee, pricing_snapshot FROM transactions WHERE id=?');
        $st->execute([$transactionId]);
        $row = $st->fetch();
        if (!$row) return null;
        $snapshot = null;
        if (!empty($row['pricing_snapshot'])) {
            $decoded = json_decode((string)$row['pricing_snapshot'], true);
            if (is_array($decoded)) $snapshot = $decoded;
        }
        // Fallback to columns if JSON missing
        if (!$snapshot) {
            $snapshot = [
                'gross' => (float)$row['amount'],
                'mdr_m' => (float)($row['mdr_m'] ?? 0),
                'mdr_p' => (float)($row['mdr_p'] ?? 0),
                'platform_fee' => (float)$row['platform_fee'],
                'merchant_net' => (float)$row['split_amount'],
                'partner_fee' => (float)($row['partner_fee'] ?? 0),
            ];
        }
        return $snapshot;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * F5: Calculate proportional refund split using original snapshot.
 * Returns how much to reverse from merchant_net and platform_fee legs.
 */
function calculateRefundReversalSplit(float $refundAmount, array $snapshot): array
{
    $gross = (float)($snapshot['gross'] ?? 0);
    $merchantNet = (float)($snapshot['merchant_net'] ?? 0);
    $platformFee = (float)($snapshot['platform_fee'] ?? 0);
    if ($gross <= 0) {
        return ['merchant_reversal' => $refundAmount, 'platform_reversal' => 0];
    }
    $ratio = $refundAmount / $gross;
    $merchantReversal = round($merchantNet * $ratio, 2);
    $platformReversal = round($platformFee * $ratio, 2);
    // F7: Ensure reversal sum doesn't exceed refund amount (1-paise rule, adjust platform)
    $sum = round($merchantReversal + $platformReversal, 2);
    if (abs($sum - $refundAmount) > 0.001) {
        $platformReversal = round($platformReversal + ($refundAmount - $sum), 2);
    }
    return [
        'merchant_reversal' => max(0, $merchantReversal),
        'platform_reversal' => max(0, $platformReversal),
        'ratio' => $ratio,
    ];
}

/**
 * F5: Record refund reversal in partner_transfers (idempotent).
 */
function recordRefundReversalTransfer(int $transactionId, int $merchantId, string $partnerKey, float $amount): array
{
    return createPartnerTransfer($transactionId, $merchantId, $partnerKey, $amount, 'refund_reversal');
}

/**
 * F6: Get failed partner transfers (for admin queue view).
 */
function getFailedPartnerTransfers(int $limit = 50): array
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare(
            'SELECT pt.*, t.txn_id, m.business_name
             FROM partner_transfers pt
             JOIN transactions t ON t.id = pt.transaction_id
             JOIN merchants m ON m.id = pt.merchant_id
             WHERE pt.status = ?
             ORDER BY pt.updated_at DESC LIMIT ?'
        );
        $st->bindValue(1, 'failed');
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * F6: Get platform fee report by day/merchant.
 */
function getPlatformFeeReport(int $days = 30): array
{
    $since = date('Y-m-d 00:00:00', time() - ($days * 86400));
    try {
        $st = getDB()->prepare(
            'SELECT DATE(t.created_at) as day, t.merchant_id, m.business_name,
                    COUNT(*) as txn_count,
                    COALESCE(SUM(t.amount),0) as gross,
                    COALESCE(SUM(t.platform_fee),0) as platform_fee,
                    COALESCE(SUM(t.split_amount),0) as merchant_net,
                    COALESCE(SUM(t.partner_fee),0) as partner_fee
             FROM transactions t
             JOIN merchants m ON m.id = t.merchant_id
             WHERE t.status = ? AND t.created_at >= ?
             GROUP BY DATE(t.created_at), t.merchant_id
             ORDER BY day DESC, platform_fee DESC'
        );
        $st->execute(['success', $since]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * F6: Get partner transfers for a transaction.
 */
function getTransactionPartnerTransfers(int $transactionId): array
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare('SELECT * FROM partner_transfers WHERE transaction_id=? ORDER BY id');
        $st->execute([$transactionId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * F7: Alert admin on repeated transfer failures (3+ failures for same merchant).
 */
function alertRepeatedTransferFailures(): void
{
    ensureSplitSettlementTable();
    try {
        $st = getDB()->prepare(
            'SELECT merchant_id, COUNT(*) as fail_count
             FROM partner_transfers
             WHERE status = ? AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
             GROUP BY merchant_id
             HAVING fail_count >= 3'
        );
        $st->execute(['failed']);
        $merchants = $st->fetchAll();
        foreach ($merchants as $m) {
            if (function_exists('logPlatformError')) {
                logPlatformError('error', 'Repeated partner transfer failures', [
                    'merchant_id' => (int)$m['merchant_id'],
                    'fail_count' => (int)$m['fail_count'],
                ]);
            }
        }
    } catch (Throwable $e) { /* non-fatal */ }
}
