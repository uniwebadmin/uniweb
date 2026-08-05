<?php
declare(strict_types=1);

require_once __DIR__ . '/schema_ensure.php';

/**
 * Fraud / risk scoring + AML negative-list & PMLA-style screening.
 *
 * - Merchant risk score (0-100) based on KYC, age, chargeback ratio, watchlist.
 * - Transaction-level risk: blacklist, high value, suspicious velocity.
 * - AML flags raised automatically for high-risk and high-value txns.
 * - Negative-list / sanctions screening against aml_watchlist and blacklists.
 */

function ensureAmlWatchlistTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS aml_watchlist (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type ENUM('individual','entity','phone','email','upi','account') NOT NULL,
            value VARCHAR(255) NOT NULL,
            source VARCHAR(64) DEFAULT 'manual',
            reason VARCHAR(255) DEFAULT NULL,
            is_sanction TINYINT(1) DEFAULT 0,
            status ENUM('active','removed') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_value (type, value),
            INDEX idx_active (status, type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function ensureRiskScoresTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_risk_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            score INT NOT NULL DEFAULT 0,
            reasons JSON DEFAULT NULL,
            calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function ensureBlacklistTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS blacklists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            scope ENUM('merchant','customer') NOT NULL,
            target VARCHAR(255) NOT NULL,
            target_type ENUM('phone','email','merchant_id','customer_id','upi','ip') NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_scope_target (scope, target_type, target)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getAmlHighValueThreshold(): int
{
    return (int)getSetting('aml_high_value_threshold', '50000');
}

function setAmlHighValueThreshold(int $amount): void
{
    saveAutoAuditMeta('aml_high_value_threshold', (string)max(1, $amount));
}

function normalizeForWatchlist(string $value): string
{
    return strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $value));
}

function addAmlWatchlistEntry(string $type, string $value, string $source = 'manual', string $reason = '', bool $isSanction = false): bool
{
    ensureAmlWatchlistTable();
    if ($value === '' || !in_array($type, ['individual','entity','phone','email','upi','account'], true)) {
        return false;
    }
    $norm = normalizeForWatchlist($value);
    try {
        getDB()->prepare(
            'INSERT INTO aml_watchlist (type, value, source, reason, is_sanction, status) VALUES (?,?,?,?,?,?)'
        )->execute([$type, $norm, $source, $reason, $isSanction ? 1 : 0, 'active']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function removeAmlWatchlistEntry(int $id): bool
{
    ensureAmlWatchlistTable();
    try {
        getDB()->prepare('UPDATE aml_watchlist SET status="removed" WHERE id=?')->execute([$id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getAmlWatchlistEntries(int $limit = 200): array
{
    ensureAmlWatchlistTable();
    $st = getDB()->prepare('SELECT * FROM aml_watchlist ORDER BY is_sanction DESC, id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function addBlacklistEntry(string $scope, string $targetType, string $target, string $reason = ''): bool
{
    ensureBlacklistTable();
    if ($target === '' || !in_array($scope, ['merchant','customer'], true) || !in_array($targetType, ['phone','email','merchant_id','customer_id','upi','ip'], true)) {
        return false;
    }
    try {
        getDB()->prepare('INSERT INTO blacklists (scope, target, target_type, reason) VALUES (?,?,?,?)')
            ->execute([$scope, $target, $targetType, $reason]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function removeBlacklistEntry(int $id): bool
{
    ensureBlacklistTable();
    try {
        getDB()->prepare('DELETE FROM blacklists WHERE id=?')->execute([$id]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getBlacklistEntries(int $limit = 200): array
{
    ensureBlacklistTable();
    $st = getDB()->prepare('SELECT * FROM blacklists ORDER BY id DESC LIMIT ?');
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function isBlacklisted(int $merchantId, array $customer = []): bool
{
    ensureBlacklistTable();
    $db = getDB();
    $st = $db->prepare('SELECT 1 FROM blacklists WHERE scope="merchant" AND target_type="merchant_id" AND target=? LIMIT 1');
    $st->execute([(string)$merchantId]);
    if ($st->fetch()) {
        return true;
    }
    foreach (['phone' => $customer['phone'] ?? '', 'email' => $customer['email'] ?? '', 'upi' => $customer['upi'] ?? ''] as $type => $val) {
        if ($val === '') {
            continue;
        }
        $st = $db->prepare('SELECT 1 FROM blacklists WHERE scope="customer" AND target_type=? AND target=? LIMIT 1');
        $st->execute([$type, $val]);
        if ($st->fetch()) {
            return true;
        }
    }
    return false;
}

function recordAmlFlag(int $merchantId, ?int $transactionId, string $flagType, string $severity, string $description): void
{
    ensureAmlFlagsTable();
    try {
        getDB()->prepare('INSERT INTO aml_flags (merchant_id, transaction_id, flag_type, severity, description) VALUES (?,?,?,?,?)')
            ->execute([$merchantId, $transactionId, $flagType, $severity, $description]);
    } catch (Throwable $e) { /* ok */ }
}

function screenAmlWatchlist(int $merchantId, ?int $transactionId, array $customer = []): array
{
    ensureAmlWatchlistTable();
    $db = getDB();
    $merchant = $db->prepare('SELECT business_name, email, phone FROM merchants WHERE id=?');
    $merchant->execute([$merchantId]);
    $m = $merchant->fetch();
    if (!$m) {
        return [];
    }

    $needles = [
        'entity'  => normalizeForWatchlist($m['business_name'] ?? ''),
        'email'   => normalizeForWatchlist($m['email'] ?? ''),
        'phone'   => normalizeForWatchlist($m['phone'] ?? ''),
    ];
    foreach (['email','phone','upi'] as $k) {
        if (!empty($customer[$k])) {
            $needles[$k] = normalizeForWatchlist($customer[$k]);
        }
    }

    $matches = [];
    foreach ($needles as $type => $value) {
        if ($value === '') {
            continue;
        }
        $st = $db->prepare('SELECT * FROM aml_watchlist WHERE status="active" AND type=? AND value=?');
        $st->execute([$type, $value]);
        foreach ($st->fetchAll() as $row) {
            $matches[] = $row;
            $sanction = $row['is_sanction'] ? ' (SANCTION)' : '';
            recordAmlFlag($merchantId, $transactionId, 'watchlist_match', $row['is_sanction'] ? 'high' : 'medium', 'Match on ' . $type . $sanction . ': ' . $row['reason']);
        }
    }
    return $matches;
}

function getChargebackCount(int $merchantId, int $days = 90): int
{
    $st = getDB()->prepare('SELECT COUNT(*) FROM chargebacks WHERE merchant_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $st->execute([$merchantId, $days]);
    return (int)$st->fetchColumn();
}

function getSuccessTransactionCount(int $merchantId, int $days = 90): int
{
    $st = getDB()->prepare('SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status="success" AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $st->execute([$merchantId, $days]);
    return (int)$st->fetchColumn();
}

function getChargebackRatio(int $merchantId, int $days = 90): float
{
    $success = getSuccessTransactionCount($merchantId, $days);
    $chargebacks = getChargebackCount($merchantId, $days);
    if ($success < 1) {
        return 0.0;
    }
    return round($chargebacks / $success, 4);
}

function getMerchantFailureVelocity(int $merchantId, int $minutes = 60): int
{
    $st = getDB()->prepare(
        'SELECT COUNT(*) FROM velocity_events WHERE reference LIKE ? AND event_type="payment_fail" AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)'
    );
    $st->execute(['merchant:' . $merchantId . '%', $minutes]);
    return (int)$st->fetchColumn();
}

function calculateMerchantRiskScore(int $merchantId): array
{
    ensureRiskScoresTable();
    $db = getDB();
    $m = $db->prepare('SELECT status, kyc_status, created_at FROM merchants WHERE id=?');
    $m->execute([$merchantId]);
    $merchant = $m->fetch();
    if (!$merchant) {
        return ['score' => 0, 'reasons' => ['Merchant not found']];
    }

    $score = 0;
    $reasons = [];

    if ($merchant['status'] !== 'active') {
        $score += 80;
        $reasons[] = 'merchant not active';
    }
    if ($merchant['kyc_status'] !== 'verified') {
        $score += 30;
        $reasons[] = 'incomplete KYC';
    }

    $ageDays = (int)floor((time() - strtotime((string)$merchant['created_at'])) / 86400);
    if ($ageDays < 30) {
        $score += 20;
        $reasons[] = 'merchant less than 30 days old';
    } elseif ($ageDays < 90) {
        $score += 10;
        $reasons[] = 'merchant less than 90 days old';
    }

    $threshold = getAmlHighValueThreshold();
    $highTx = $db->prepare('SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status="success" AND amount>=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    $highTx->execute([$merchantId, $threshold]);
    if ((int)$highTx->fetchColumn() > 0) {
        $score += 15;
        $reasons[] = 'high-value transaction in last 7 days';
    }

    $ratio = getChargebackRatio($merchantId, 90);
    if ($ratio > 0.05) {
        $score += 60;
        $reasons[] = 'chargeback ratio above 5%';
    } elseif ($ratio > 0.01) {
        $score += 40;
        $reasons[] = 'chargeback ratio above 1%';
    }

    if (isBlacklisted($merchantId, [])) {
        $score += 100;
        $reasons[] = 'merchant is blacklisted';
    }

    $failVel = getMerchantFailureVelocity($merchantId, 60);
    if ($failVel > 20) {
        $score += 25;
        $reasons[] = 'high payment-failure velocity';
    } elseif ($failVel > 10) {
        $score += 10;
        $reasons[] = 'elevated payment-failure velocity';
    }

    $score = min(100, max(0, $score));
    return ['score' => $score, 'reasons' => $reasons];
}

function updateMerchantRiskScore(int $merchantId): int
{
    ensureRiskScoresTable();
    $calc = calculateMerchantRiskScore($merchantId);
    try {
        getDB()->prepare(
            'INSERT INTO merchant_risk_scores (merchant_id, score, reasons) VALUES (?,?,?) ON DUPLICATE KEY UPDATE score=?, reasons=?'
        )->execute([$merchantId, $calc['score'], json_encode($calc['reasons']), $calc['score'], json_encode($calc['reasons'])]);
    } catch (Throwable $e) { /* ok */ }
    return $calc['score'];
}

function getMerchantRiskScore(int $merchantId): int
{
    ensureRiskScoresTable();
    $st = getDB()->prepare('SELECT score FROM merchant_risk_scores WHERE merchant_id=?');
    $st->execute([$merchantId]);
    $row = $st->fetch();
    if ($row) {
        return (int)$row['score'];
    }
    return updateMerchantRiskScore($merchantId);
}

function getRiskyMerchants(int $limit = 50): array
{
    ensureRiskScoresTable();
    $st = getDB()->prepare(
        'SELECT m.id, m.merchant_code, m.business_name, m.kyc_status, m.status, mrs.score, mrs.reasons
         FROM merchants m LEFT JOIN merchant_risk_scores mrs ON mrs.merchant_id=m.id
         WHERE m.status != "deleted" ORDER BY mrs.score DESC LIMIT ?'
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function evaluateTransactionRisk(int $merchantId, float $amount, array $customer = []): array
{
    $score = getMerchantRiskScore($merchantId);

    if (isBlacklisted($merchantId, $customer)) {
        return ['action' => 'block', 'score' => $score, 'reason' => 'Blacklist match'];
    }

    $reason = [];
    if ($score >= 80) {
        $reason[] = 'merchant risk score ' . $score;
    }
    if ($amount >= getAmlHighValueThreshold()) {
        $reason[] = 'high-value transaction >= ₹' . number_format(getAmlHighValueThreshold());
    }

    $matches = screenAmlWatchlist($merchantId, null, $customer);
    if ($matches) {
        $sanction = (int)array_reduce($matches, fn($carry, $m) => $carry + ($m['is_sanction'] ? 1 : 0), 0);
        if ($sanction) {
            return ['action' => 'block', 'score' => $score, 'reason' => 'Sanctions-list match'];
        }
        $reason[] = 'watchlist match';
    }

    if ($score >= 80) {
        return ['action' => 'review', 'score' => $score, 'reason' => implode(', ', $reason)];
    }
    if ($amount >= getAmlHighValueThreshold()) {
        return ['action' => 'flag', 'score' => $score, 'reason' => implode(', ', $reason)];
    }
    return ['action' => 'allow', 'score' => $score, 'reason' => ''];
}

function recordTransactionRisk(int $transactionId, int $merchantId, float $amount, array $customer = []): void
{
    $risk = evaluateTransactionRisk($merchantId, $amount, $customer);
    if ($risk['action'] === 'allow') {
        return;
    }
    if ($risk['action'] === 'block') {
        recordAmlFlag($merchantId, $transactionId, 'risk_block', 'high', 'Blocked: ' . $risk['reason']);
        return;
    }
    if ($risk['action'] === 'review') {
        recordAmlFlag($merchantId, $transactionId, 'risk_review', 'high', 'Review: ' . $risk['reason'] . ' (score ' . $risk['score'] . ')');
        return;
    }
    // flag
    recordAmlFlag($merchantId, $transactionId, 'high_value', 'medium', 'High-value transaction: ₹' . number_format($amount) . ' | ' . $risk['reason']);
}

function recalculateRiskScoresForAll(): int
{
    ensureRiskScoresTable();
    $db = getDB();
    $st = $db->query('SELECT id FROM merchants WHERE status != "deleted"');
    $count = 0;
    foreach ($st->fetchAll() as $row) {
        updateMerchantRiskScore((int)$row['id']);
        $count++;
    }
    return $count;
}
