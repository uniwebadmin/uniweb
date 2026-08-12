<?php
declare(strict_types=1);

/**
 * Rolling Reserve — hold a % of each successful transaction for new/high-risk merchants.
 * Release after configurable T+N days.
 *
 * Based on PDF Rolling Reserve specification.
 */

function ensureRollingReserveTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS rolling_reserve_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            hold_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            release_days INT NOT NULL DEFAULT 7,
            auto_release TINYINT(1) DEFAULT 1,
            applies_to ENUM('all','new_merchants','high_risk') NOT NULL DEFAULT 'all',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS rolling_reserve_holds (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            transaction_id INT NOT NULL,
            held_amount DECIMAL(14,2) NOT NULL,
            hold_percentage DECIMAL(5,2) NOT NULL,
            held_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            release_date DATE NOT NULL,
            released_at TIMESTAMP NULL DEFAULT NULL,
            released_by INT DEFAULT NULL,
            release_settlement_id INT DEFAULT NULL,
            status ENUM('held','released','manually_released','cancelled') NOT NULL DEFAULT 'held',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant_status (merchant_id, status),
            INDEX idx_release_date (release_date, status),
            INDEX idx_txn (transaction_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Get or create default rolling reserve config for a merchant.
 * Default: 5% hold for new merchants (< 90 days old), 0% for established.
 */
function getRollingReserveConfig(int $merchantId): array
{
    ensureRollingReserveTables();
    $db = getDB();
    $st = $db->prepare("SELECT * FROM rolling_reserve_config WHERE merchant_id=?");
    $st->execute([$merchantId]);
    $row = $st->fetch();
    if ($row) return $row;

    // Auto-determine defaults based on merchant age and risk score
    $mSt = $db->prepare("SELECT created_at FROM merchants WHERE id=?");
    $mSt->execute([$merchantId]);
    $merchant = $mSt->fetch();
    $ageDays = $merchant ? (int)floor((time() - strtotime((string)$merchant['created_at'])) / 86400) : 0;

    $riskScore = 0;
    if (function_exists('getMerchantRiskScore')) {
        $riskScore = getMerchantRiskScore($merchantId);
    }

    $holdPct = 0.0;
    $releaseDays = 7;
    $appliesTo = 'all';

    if ($ageDays < 90) {
        $holdPct = 5.0;
        $appliesTo = 'new_merchants';
    }
    if ($riskScore >= 60) {
        $holdPct = max($holdPct, 10.0);
        $releaseDays = 14;
        $appliesTo = 'high_risk';
    }

    try {
        $db->prepare(
            "INSERT INTO rolling_reserve_config (merchant_id, hold_percentage, release_days, auto_release, applies_to)
             VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE merchant_id=merchant_id"
        )->execute([$merchantId, $holdPct, $releaseDays, 1, $appliesTo]);
    } catch (Throwable $e) { /* ok */ }

    $st->execute([$merchantId]);
    return $st->fetch() ?: [
        'merchant_id' => $merchantId,
        'hold_percentage' => $holdPct,
        'release_days' => $releaseDays,
        'auto_release' => 1,
        'applies_to' => $appliesTo,
    ];
}

/**
 * Update rolling reserve config for a merchant.
 */
function updateRollingReserveConfig(int $merchantId, float $holdPct, int $releaseDays, bool $autoRelease = true, string $appliesTo = 'all'): void
{
    ensureRollingReserveTables();
    getDB()->prepare(
        "INSERT INTO rolling_reserve_config (merchant_id, hold_percentage, release_days, auto_release, applies_to)
         VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE hold_percentage=VALUES(hold_percentage), release_days=VALUES(release_days),
         auto_release=VALUES(auto_release), applies_to=VALUES(applies_to)"
    )->execute([$merchantId, $holdPct, $releaseDays, $autoRelease ? 1 : 0, $appliesTo]);
}

/**
 * Apply rolling reserve hold on a successful transaction.
 * Called after transaction success in collection.php.
 */
function applyRollingReserveHold(int $merchantId, int $transactionId, float $amount): ?array
{
    ensureRollingReserveTables();
    $config = getRollingReserveConfig($merchantId);
    $holdPct = (float)$config['hold_percentage'];
    if ($holdPct <= 0) return null;

    // Check if already held
    $db = getDB();
    $check = $db->prepare("SELECT id FROM rolling_reserve_holds WHERE transaction_id=? AND status='held'");
    $check->execute([$transactionId]);
    if ($check->fetch()) return null;

    $heldAmount = round($amount * $holdPct / 100, 2);
    $releaseDate = date('Y-m-d', strtotime("+{$config['release_days']} days"));

    try {
        $db->prepare(
            "INSERT INTO rolling_reserve_holds (merchant_id, transaction_id, held_amount, hold_percentage, release_date, status)
             VALUES (?,?,?,?,?,'held')"
        )->execute([$merchantId, $transactionId, $heldAmount, $holdPct, $releaseDate]);
        return [
            'held_amount' => $heldAmount,
            'hold_percentage' => $holdPct,
            'release_date' => $releaseDate,
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get total held amount for a merchant (affects available balance).
 */
function getMerchantReserveHeldAmount(int $merchantId): float
{
    ensureRollingReserveTables();
    $st = getDB()->prepare("SELECT COALESCE(SUM(held_amount),0) FROM rolling_reserve_holds WHERE merchant_id=? AND status='held'");
    $st->execute([$merchantId]);
    return (float)$st->fetchColumn();
}

/**
 * Auto-release eligible holds (release_date <= today).
 * Called by cron / auto_audit.
 */
function autoReleaseReserveHolds(): int
{
    ensureRollingReserveTables();
    $db = getDB();
    $today = date('Y-m-d');

    $st = $db->prepare(
        "SELECT h.id, h.merchant_id, h.held_amount, h.transaction_id
         FROM rolling_reserve_holds h
         JOIN rolling_reserve_config c ON c.merchant_id = h.merchant_id
         WHERE h.status='held' AND h.release_date <= ? AND c.auto_release=1"
    );
    $st->execute([$today]);
    $released = 0;
    foreach ($st->fetchAll() as $hold) {
        try {
            $db->prepare("UPDATE rolling_reserve_holds SET status='released', released_at=NOW() WHERE id=? AND status='held'")
                ->execute([(int)$hold['id']]);
            $released++;
        } catch (Throwable $e) { /* ok */ }
    }
    return $released;
}

/**
 * Manually release a hold.
 */
function manuallyReleaseHold(int $holdId, ?int $adminId, string $note = ''): bool
{
    ensureRollingReserveTables();
    try {
        getDB()->prepare("UPDATE rolling_reserve_holds SET status='manually_released', released_at=NOW(), released_by=? WHERE id=? AND status='held'")
            ->execute([$adminId, $holdId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Cancel a hold (e.g. for refunded transactions).
 */
function cancelReserveHold(int $transactionId): bool
{
    ensureRollingReserveTables();
    try {
        getDB()->prepare("UPDATE rolling_reserve_holds SET status='cancelled' WHERE transaction_id=? AND status='held'")
            ->execute([$transactionId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get holds for a merchant with filters.
 */
function getMerchantReserveHolds(int $merchantId, string $status = '', int $limit = 100): array
{
    ensureRollingReserveTables();
    $sql = "SELECT h.*, t.amount as txn_amount, t.txn_id as txn_ref
            FROM rolling_reserve_holds h
            LEFT JOIN transactions t ON t.id=h.transaction_id
            WHERE h.merchant_id=?";
    $params = [$merchantId];
    if ($status !== '') {
        $sql .= " AND h.status=?";
        $params[] = $status;
    }
    $sql .= " ORDER BY h.created_at DESC LIMIT ?";
    $params[] = $limit;
    $st = getDB()->prepare($sql);
    foreach ($params as $i => $v) {
        $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    return $st->fetchAll();
}

/**
 * Get all holds due for release today.
 */
function getHoldsDueForRelease(): array
{
    ensureRollingReserveTables();
    $today = date('Y-m-d');
    $st = getDB()->prepare(
        "SELECT h.*, m.business_name, m.merchant_code
         FROM rolling_reserve_holds h
         JOIN merchants m ON m.id=h.merchant_id
         WHERE h.status='held' AND h.release_date <= ?
         ORDER BY h.release_date ASC"
    );
    $st->execute([$today]);
    return $st->fetchAll();
}

/**
 * Get rolling reserve stats for admin dashboard.
 */
function getRollingReserveStats(): array
{
    ensureRollingReserveTables();
    $db = getDB();
    $stats = [
        'total_held' => 0.0,
        'total_released' => 0.0,
        'active_holds' => 0,
        'due_today' => 0,
        'merchants_with_reserve' => 0,
    ];
    try {
        $stats['total_held'] = (float)$db->query("SELECT COALESCE(SUM(held_amount),0) FROM rolling_reserve_holds WHERE status='held'")->fetchColumn();
        $stats['total_released'] = (float)$db->query("SELECT COALESCE(SUM(held_amount),0) FROM rolling_reserve_holds WHERE status IN ('released','manually_released')")->fetchColumn();
        $stats['active_holds'] = (int)$db->query("SELECT COUNT(*) FROM rolling_reserve_holds WHERE status='held'")->fetchColumn();
        $stats['due_today'] = (int)$db->query("SELECT COUNT(*) FROM rolling_reserve_holds WHERE status='held' AND release_date <= CURDATE()")->fetchColumn();
        $stats['merchants_with_reserve'] = (int)$db->query("SELECT COUNT(DISTINCT merchant_id) FROM rolling_reserve_holds WHERE status='held'")->fetchColumn();
    } catch (Throwable $e) {}
    return $stats;
}
