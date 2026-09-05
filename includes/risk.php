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
    $db = getDB();
    // Skip if an open flag already exists for same merchant + type (+ transaction when set)
    try {
        if ($flagType === 'kyc_pending' || $transactionId === null) {
            $check = $db->prepare('SELECT 1 FROM aml_flags WHERE merchant_id=? AND flag_type=? AND status="open" LIMIT 1');
            $check->execute([$merchantId, $flagType]);
        } else {
            $check = $db->prepare('SELECT 1 FROM aml_flags WHERE merchant_id=? AND flag_type=? AND transaction_id=? AND status="open" LIMIT 1');
            $check->execute([$merchantId, $flagType, $transactionId]);
        }
        if ($check->fetchColumn()) {
            return; // already flagged — do not duplicate
        }
    } catch (Throwable $e) { /* table may not exist yet — continue to insert */ }
    try {
        $db->prepare('INSERT INTO aml_flags (merchant_id, transaction_id, flag_type, severity, description) VALUES (?,?,?,?,?)')
            ->execute([$merchantId, $transactionId, $flagType, $severity, $description]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * 2.14: Auto-resolve open kyc_pending flags when merchant becomes KYC verified.
 */
function resolveKycPendingFlags(int $merchantId): int
{
    ensureAmlFlagsTable();
    try {
        $st = getDB()->prepare("UPDATE aml_flags SET status='cleared', description=CONCAT(description, ' [auto-resolved: KYC verified]') WHERE merchant_id=? AND flag_type='kyc_pending' AND status='open'");
        $st->execute([$merchantId]);
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * One open kyc_pending flag per unverified active merchant; clear flags after KYC verify.
 */
function syncKycPendingAmlFlags(): int
{
    ensureAmlFlagsTable();
    $cleared = 0;
    $opened = 0;
    try {
        $st = getDB()->prepare(
            "UPDATE aml_flags af
             INNER JOIN merchants m ON m.id=af.merchant_id AND m.kyc_status='verified'
             SET af.status='cleared', af.description=CONCAT(af.description, ' [auto-resolved: KYC verified]')
             WHERE af.flag_type='kyc_pending' AND af.status='open'"
        );
        $st->execute();
        $cleared = $st->rowCount();
    } catch (Throwable $e) { /* ok */ }
    try {
        $rows = getDB()->query(
            "SELECT m.id FROM merchants m
             WHERE m.kyc_status NOT IN ('verified') AND m.status='active'
               AND NOT EXISTS (
                   SELECT 1 FROM aml_flags af
                   WHERE af.merchant_id=m.id AND af.flag_type='kyc_pending' AND af.status='open'
               )"
        )->fetchAll();
        foreach ($rows as $row) {
            recordAmlFlag((int)$row['id'], null, 'kyc_pending', 'medium', 'Merchant operating with incomplete KYC');
            $opened++;
        }
    } catch (Throwable $e) { /* ok */ }
    return $opened + $cleared;
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
    $days = max(1, min(3650, $days));
    $st = getDB()->prepare("SELECT COUNT(*) FROM chargebacks WHERE merchant_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    $st->execute([$merchantId]);
    return (int)$st->fetchColumn();
}

function getSuccessTransactionCount(int $merchantId, int $days = 90): int
{
    $days = max(1, min(3650, $days));
    $st = getDB()->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status=\"success\" AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
    $st->execute([$merchantId]);
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
    $minutes = max(1, min(10080, $minutes));
    $st = getDB()->prepare(
        "SELECT COUNT(*) FROM velocity_events WHERE reference LIKE ? AND event_type=\"payment_fail\" AND created_at >= DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
    );
    $st->execute(['merchant:' . $merchantId . '%']);
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

/* ------------------------------------------------------------------ *
 *  Risk Engine — Velocity, Scoring, Auto-Actions
 *  Based on PDF Risk Engine Complete Specification
 * ------------------------------------------------------------------ */

function ensureRiskEngineTables(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS risk_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rule_name VARCHAR(128) NOT NULL,
            rule_type ENUM('velocity','amount','merchant','blacklist','time','custom') NOT NULL,
            scope ENUM('transaction','merchant') NOT NULL DEFAULT 'transaction',
            parameters JSON DEFAULT NULL,
            action ENUM('allow','flag','hold','block') NOT NULL DEFAULT 'flag',
            score_weight INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_type_active (rule_type, is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS risk_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            transaction_id INT DEFAULT NULL,
            merchant_id INT DEFAULT NULL,
            rule_id INT DEFAULT NULL,
            rule_name VARCHAR(128) NOT NULL,
            risk_score INT NOT NULL DEFAULT 0,
            action_taken ENUM('allow','flag','hold','block') NOT NULL DEFAULT 'allow',
            details JSON DEFAULT NULL,
            resolved TINYINT(1) DEFAULT 0,
            resolved_by INT DEFAULT NULL,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            resolution_note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_txn (transaction_id),
            INDEX idx_merchant (merchant_id),
            INDEX idx_action (action_taken),
            INDEX idx_resolved (resolved),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS risk_merchant_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            max_txn_amount DECIMAL(14,2) DEFAULT NULL,
            max_txn_count_hour INT DEFAULT NULL,
            max_txn_count_day INT DEFAULT NULL,
            max_volume_day DECIMAL(14,2) DEFAULT NULL,
            auto_hold_threshold INT DEFAULT 70,
            auto_block_threshold INT DEFAULT 85,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS risk_velocity_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fingerprint_type ENUM('upi','card','device','phone','email','ip') NOT NULL,
            fingerprint_value VARCHAR(255) NOT NULL,
            merchant_id INT DEFAULT NULL,
            txn_count_1h INT DEFAULT 0,
            txn_count_24h INT DEFAULT 0,
            txn_amount_24h DECIMAL(14,2) DEFAULT 0,
            last_txn_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_fp_type_value (fingerprint_type, fingerprint_value),
            INDEX idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed default rules if empty
        $count = (int)$db->query("SELECT COUNT(*) FROM risk_rules")->fetchColumn();
        if ($count === 0) {
            $defaults = [
                ['Same UPI velocity >5 in 1h', 'velocity', 'transaction', 15, 'flag'],
                ['Same UPI velocity >10 in 1h', 'velocity', 'transaction', 25, 'hold'],
                ['Same Card velocity >3 in 1h', 'velocity', 'transaction', 20, 'hold'],
                ['Same Device velocity >8 in 1h', 'velocity', 'transaction', 15, 'flag'],
                ['Same Phone velocity >5 in 1h', 'velocity', 'transaction', 15, 'flag'],
                ['Amount > ₹2 lakh single txn', 'amount', 'transaction', 25, 'hold'],
                ['Amount > ₹5 lakh single txn', 'amount', 'transaction', 40, 'block'],
                ['Amount spike >5x avg in 1h', 'amount', 'transaction', 20, 'flag'],
                ['New merchant >₹1L in first 7d', 'merchant', 'merchant', 20, 'flag'],
                ['New merchant >₹5L in first 7d', 'merchant', 'merchant', 35, 'hold'],
                ['Chargeback ratio >3%', 'merchant', 'merchant', 30, 'hold'],
                ['Chargeback ratio >5%', 'merchant', 'merchant', 50, 'block'],
                ['Blacklist match', 'blacklist', 'transaction', 100, 'block'],
                ['Night + high amount (11pm-5am)', 'time', 'transaction', 10, 'flag'],
            ];
            $st = $db->prepare("INSERT INTO risk_rules (rule_name, rule_type, scope, score_weight, action) VALUES (?,?,?,?,?)");
            foreach ($defaults as $d) {
                $st->execute($d);
            }
        }
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Get active risk rules.
 */
function getActiveRiskRules(): array
{
    ensureRiskEngineTables();
    $st = getDB()->prepare("SELECT * FROM risk_rules WHERE is_active=1 ORDER BY score_weight DESC");
    $st->execute();
    return $st->fetchAll();
}

/**
 * Get or create merchant risk limits.
 */
function getMerchantRiskLimits(int $merchantId): array
{
    ensureRiskEngineTables();
    $st = getDB()->prepare("SELECT * FROM risk_merchant_limits WHERE merchant_id=?");
    $st->execute([$merchantId]);
    $row = $st->fetch();
    if ($row) return $row;
    // Create default
    getDB()->prepare("INSERT INTO risk_merchant_limits (merchant_id) VALUES (?) ON DUPLICATE KEY UPDATE merchant_id=merchant_id")
        ->execute([$merchantId]);
    $st->execute([$merchantId]);
    return $st->fetch() ?: ['merchant_id' => $merchantId, 'max_txn_amount' => null, 'max_txn_count_hour' => null, 'max_txn_count_day' => null, 'max_volume_day' => null, 'auto_hold_threshold' => 70, 'auto_block_threshold' => 85];
}

/**
 * Update merchant risk limits.
 */
function updateMerchantRiskLimits(int $merchantId, array $limits): void
{
    ensureRiskEngineTables();
    getDB()->prepare(
        "INSERT INTO risk_merchant_limits (merchant_id, max_txn_amount, max_txn_count_hour, max_txn_count_day, max_volume_day, auto_hold_threshold, auto_block_threshold)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE max_txn_amount=VALUES(max_txn_amount), max_txn_count_hour=VALUES(max_txn_count_hour),
         max_txn_count_day=VALUES(max_txn_count_day), max_volume_day=VALUES(max_volume_day),
         auto_hold_threshold=VALUES(auto_hold_threshold), auto_block_threshold=VALUES(auto_block_threshold)"
    )->execute([
        $merchantId,
        $limits['max_txn_amount'] ?? null,
        $limits['max_txn_count_hour'] ?? null,
        $limits['max_txn_count_day'] ?? null,
        $limits['max_volume_day'] ?? null,
        $limits['auto_hold_threshold'] ?? 70,
        $limits['auto_block_threshold'] ?? 85,
    ]);
}

/**
 * Build customer fingerprint for velocity checks.
 */
function buildCustomerFingerprint(array $customer): array
{
    $fp = [];
    if (!empty($customer['upi'])) $fp['upi'] = strtolower(trim($customer['upi']));
    if (!empty($customer['card_last4'])) $fp['card'] = strtolower(trim($customer['card_last4']));
    if (!empty($customer['device_id'])) $fp['device'] = strtolower(trim($customer['device_id']));
    if (!empty($customer['phone'])) $fp['phone'] = preg_replace('/\D/', '', $customer['phone']);
    if (!empty($customer['email'])) $fp['email'] = strtolower(trim($customer['email']));
    $fp['ip'] = function_exists('velocityClientIp') ? velocityClientIp() : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return $fp;
}

/**
 * Check velocity for a fingerprint (same UPI/Card/Device/Phone/Email/IP).
 */
function checkFingerprintVelocity(string $type, string $value, ?int $merchantId = null): array
{
    ensureRiskEngineTables();
    $db = getDB();
    $st = $db->prepare(
        "SELECT txn_count_1h, txn_count_24h, txn_amount_24h, last_txn_at
         FROM risk_velocity_cache
         WHERE fingerprint_type=? AND fingerprint_value=? AND (?=0 OR merchant_id=?)"
    );
    $st->execute([$type, $value, $merchantId ?? 0, $merchantId ?? 0]);
    $row = $st->fetch();
    if (!$row) return ['count_1h' => 0, 'count_24h' => 0, 'amount_24h' => 0, 'last_txn' => null];

    // Refresh counts from actual transactions if cache is stale
    $lastTs = strtotime((string)($row['last_txn_at'] ?? ''));
    if (!$lastTs || (time() - $lastTs) > 300) {
        // Cache stale — recalculate from transactions
        $col = match($type) {
            'upi' => 'customer_upi',
            'phone' => 'customer_phone',
            'email' => 'customer_email',
            'ip' => 'customer_ip',
            'card' => 'card_last4',
            'device' => 'device_id',
            default => null,
        };
        if ($col) {
            try {
                $count1h = (int)$db->prepare("SELECT COUNT(*) FROM transactions WHERE {$col}=? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)" . ($merchantId ? " AND merchant_id=?" : ""));
                $count1h->execute($merchantId ? [$value, $merchantId] : [$value]);
                $row['txn_count_1h'] = (int)$count1h->fetchColumn();
            } catch (Throwable $e) {}
        }
    }
    return [
        'count_1h' => (int)($row['txn_count_1h'] ?? 0),
        'count_24h' => (int)($row['txn_count_24h'] ?? 0),
        'amount_24h' => (float)($row['txn_amount_24h'] ?? 0),
        'last_txn' => $row['last_txn_at'] ?? null,
    ];
}

/**
 * Update velocity cache after a transaction.
 */
function updateVelocityCache(string $type, string $value, ?int $merchantId, float $amount): void
{
    ensureRiskEngineTables();
    $db = getDB();
    $db->prepare(
        "INSERT INTO risk_velocity_cache (fingerprint_type, fingerprint_value, merchant_id, txn_count_1h, txn_count_24h, txn_amount_24h, last_txn_at)
         VALUES (?,?,?,?,1,?,NOW())
         ON DUPLICATE KEY UPDATE txn_count_1h=txn_count_1h+1, txn_count_24h=txn_count_24h+1, txn_amount_24h=txn_amount_24h+VALUES(txn_amount_24h), last_txn_at=NOW()"
    )->execute([$type, $value, $merchantId, $amount]);
}

/**
 * Calculate transaction risk score (0-100) based on PDF spec.
 * Scoring:
 *   New device +15, Large amount +10-25, Velocity +20,
 *   Blacklist +50, Night + high amount +10, Good history -10
 */
function calculateTransactionRiskScore(int $merchantId, float $amount, array $customer = []): array
{
    ensureRiskEngineTables();
    $score = 0;
    $reasons = [];
    $fingerprints = buildCustomerFingerprint($customer);

    // 1. Blacklist check (+50 or +100)
    if (isBlacklisted($merchantId, $customer)) {
        $score += 100;
        $reasons[] = 'Blacklist match (+100)';
    }

    // 2. Velocity checks
    foreach ($fingerprints as $type => $value) {
        if ($value === '' || $value === 'unknown') continue;
        $vel = checkFingerprintVelocity($type, $value, $merchantId);
        $thresholds = match($type) {
            'upi' => [5 => 15, 10 => 25],
            'card' => [3 => 20, 6 => 30],
            'device' => [8 => 15, 15 => 25],
            'phone' => [5 => 15, 10 => 20],
            'email' => [5 => 10, 10 => 15],
            'ip' => [10 => 10, 20 => 20],
            default => [5 => 10, 10 => 20],
        };
        foreach ($thresholds as $threshold => $points) {
            if ($vel['count_1h'] >= $threshold) {
                $score += $points;
                $reasons[] = ucfirst($type) . " velocity {$vel['count_1h']} in 1h (+{$points})";
                break;
            }
        }
    }

    // 3. Amount rules
    $threshold = getAmlHighValueThreshold();
    if ($amount >= 500000) {
        $score += 25;
        $reasons[] = "Amount >= ₹5L (+25)";
    } elseif ($amount >= 200000) {
        $score += 15;
        $reasons[] = "Amount >= ₹2L (+15)";
    } elseif ($amount >= $threshold) {
        $score += 10;
        $reasons[] = "Amount >= threshold ₹{$threshold} (+10)";
    }

    // 4. New merchant with high volume
    $mSt = getDB()->prepare("SELECT created_at FROM merchants WHERE id=?");
    $mSt->execute([$merchantId]);
    $merchant = $mSt->fetch();
    if ($merchant) {
        $ageDays = (int)floor((time() - strtotime((string)$merchant['created_at'])) / 86400);
        if ($ageDays < 7 && $amount >= 100000) {
            $score += 20;
            $reasons[] = "New merchant (<7d) + high amount (+20)";
        } elseif ($ageDays < 7 && $amount >= 500000) {
            $score += 35;
            $reasons[] = "New merchant (<7d) + very high amount (+35)";
        }
    }

    // 5. Night time + high amount (11pm-5am IST)
    $hour = (int)date('H');
    if (($hour >= 23 || $hour < 5) && $amount >= $threshold) {
        $score += 10;
        $reasons[] = "Night time + high amount (+10)";
    }

    // 6. Chargeback ratio
    $cbRatio = getChargebackRatio($merchantId, 90);
    if ($cbRatio > 0.05) {
        $score += 30;
        $reasons[] = "Chargeback ratio >5% (+30)";
    } elseif ($cbRatio > 0.03) {
        $score += 15;
        $reasons[] = "Chargeback ratio >3% (+15)";
    }

    // 7. Good history discount
    $successCount = getSuccessTransactionCount($merchantId, 90);
    if ($successCount > 100 && $cbRatio < 0.01) {
        $score -= 10;
        $reasons[] = "Good history (100+ txns, low chargeback) (-10)";
    }

    // 8. Merchant risk score contribution
    $mScore = getMerchantRiskScore($merchantId);
    if ($mScore >= 80) {
        $score += 20;
        $reasons[] = "Merchant risk score {$mScore} (+20)";
    } elseif ($mScore >= 60) {
        $score += 10;
        $reasons[] = "Merchant risk score {$mScore} (+10)";
    }

    $score = min(100, max(0, $score));
    return ['score' => $score, 'reasons' => $reasons, 'fingerprints' => $fingerprints];
}

/**
 * Determine auto-action based on score and merchant limits.
 * Score bands: 0-30 Allow, 31-60 Flag, 61-80 Hold, 81-100 Block
 */
function riskScoreToAction(int $score, int $merchantId): string
{
    $limits = getMerchantRiskLimits($merchantId);
    $holdThreshold = (int)($limits['auto_hold_threshold'] ?? 70);
    $blockThreshold = (int)($limits['auto_block_threshold'] ?? 85);

    if ($score >= $blockThreshold) return 'block';
    if ($score >= $holdThreshold) return 'hold';
    if ($score >= 31) return 'flag';
    return 'allow';
}

/**
 * Full transaction risk evaluation — replaces evaluateTransactionRisk.
 * Returns score, action, reasons, and logs a risk_event.
 */
function evaluateTransactionRiskFull(int $merchantId, float $amount, array $customer = [], ?int $transactionId = null): array
{
    ensureRiskEngineTables();
    $calc = calculateTransactionRiskScore($merchantId, $amount, $customer);
    $action = riskScoreToAction($calc['score'], $merchantId);

    // Check merchant-specific limits
    $limits = getMerchantRiskLimits($merchantId);
    if ($limits['max_txn_amount'] && $amount > (float)$limits['max_txn_amount']) {
        $action = 'hold';
        $calc['score'] = max($calc['score'], 70);
        $calc['reasons'][] = "Exceeds merchant max txn amount";
    }

    // Log risk event
    if ($action !== 'allow' || $transactionId) {
        try {
            getDB()->prepare(
                "INSERT INTO risk_events (transaction_id, merchant_id, rule_name, risk_score, action_taken, details)
                 VALUES (?,?,?,?,?,?)"
            )->execute([
                $transactionId,
                $merchantId,
                'Transaction risk evaluation',
                $calc['score'],
                $action,
                json_encode(['reasons' => $calc['reasons'], 'fingerprints' => $calc['fingerprints'] ?? []]),
            ]);
        } catch (Throwable $e) { /* ok */ }
    }

    // Update velocity cache
    if (!empty($calc['fingerprints'])) {
        foreach ($calc['fingerprints'] as $type => $value) {
            if ($value !== '' && $value !== 'unknown') {
                updateVelocityCache($type, $value, $merchantId, $amount);
            }
        }
    }

    // Record AML flag for high-risk
    if ($action === 'block') {
        recordAmlFlag($merchantId, $transactionId, 'risk_block', 'high', 'Blocked: ' . implode(', ', $calc['reasons']));
    } elseif ($action === 'hold') {
        recordAmlFlag($merchantId, $transactionId, 'risk_hold', 'high', 'Hold: ' . implode(', ', $calc['reasons']));
    } elseif ($action === 'flag') {
        recordAmlFlag($merchantId, $transactionId, 'risk_flag', 'medium', 'Flag: ' . implode(', ', $calc['reasons']));
    }

    return [
        'action' => $action,
        'score' => $calc['score'],
        'reasons' => $calc['reasons'],
        'fingerprints' => $calc['fingerprints'] ?? [],
    ];
}

/**
 * Get risk events with filters.
 */
function getRiskEvents(int $limit = 50, string $actionFilter = '', bool $unresolvedOnly = false): array
{
    ensureRiskEngineTables();
    $sql = "SELECT re.*, m.business_name, m.merchant_code
            FROM risk_events re
            LEFT JOIN merchants m ON m.id=re.merchant_id
            WHERE 1=1";
    $params = [];
    if ($actionFilter !== '') {
        $sql .= " AND re.action_taken=?";
        $params[] = $actionFilter;
    }
    if ($unresolvedOnly) {
        $sql .= " AND re.resolved=0";
    }
    $sql .= " ORDER BY re.created_at DESC LIMIT ?";
    $params[] = $limit;
    $st = getDB()->prepare($sql);
    foreach ($params as $i => $v) {
        $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $st->execute();
    return $st->fetchAll();
}

/**
 * Resolve a risk event.
 */
function resolveRiskEvent(int $eventId, ?int $adminId, string $note = ''): bool
{
    ensureRiskEngineTables();
    try {
        getDB()->prepare("UPDATE risk_events SET resolved=1, resolved_by=?, resolved_at=NOW(), resolution_note=? WHERE id=?")
            ->execute([$adminId, $note, $eventId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get risk rules for admin management.
 */
function getAllRiskRules(): array
{
    ensureRiskEngineTables();
    return getDB()->query("SELECT * FROM risk_rules ORDER BY is_active DESC, score_weight DESC")->fetchAll();
}

/**
 * Toggle risk rule active state.
 */
function toggleRiskRule(int $ruleId, bool $active): bool
{
    ensureRiskEngineTables();
    try {
        getDB()->prepare("UPDATE risk_rules SET is_active=? WHERE id=?")->execute([$active ? 1 : 0, $ruleId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Get risk engine stats for dashboard.
 */
function getRiskEngineStats(): array
{
    ensureRiskEngineTables();
    $db = getDB();
    $stats = [
        'total_events' => 0,
        'blocked' => 0,
        'held' => 0,
        'flagged' => 0,
        'unresolved' => 0,
        'active_rules' => 0,
    ];
    try {
        $stats['total_events'] = (int)$db->query("SELECT COUNT(*) FROM risk_events")->fetchColumn();
        $stats['blocked'] = (int)$db->query("SELECT COUNT(*) FROM risk_events WHERE action_taken='block'")->fetchColumn();
        $stats['held'] = (int)$db->query("SELECT COUNT(*) FROM risk_events WHERE action_taken='hold'")->fetchColumn();
        $stats['flagged'] = (int)$db->query("SELECT COUNT(*) FROM risk_events WHERE action_taken='flag'")->fetchColumn();
        $stats['unresolved'] = (int)$db->query("SELECT COUNT(*) FROM risk_events WHERE resolved=0")->fetchColumn();
        $stats['active_rules'] = (int)$db->query("SELECT COUNT(*) FROM risk_rules WHERE is_active=1")->fetchColumn();
    } catch (Throwable $e) {}
    return $stats;
}

/**
 * Check velocity on a specific QR code — how many transactions in the last N minutes.
 * High velocity on a single QR can indicate fraud or abuse.
 */
function checkQrVelocity(string $qrCode, int $merchantId, int $windowMinutes = 10): array
{
    $db = getDB();
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as volume
             FROM transactions t
             JOIN merchant_qr_codes q ON q.id = t.qr_code_id
             WHERE q.qr_code = ? AND t.merchant_id = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $st->execute([$qrCode, $merchantId, $windowMinutes]);
        $row = $st->fetch();
        return [
            'count' => (int)($row['count'] ?? 0),
            'volume' => (float)($row['volume'] ?? 0),
            'window_minutes' => $windowMinutes,
        ];
    } catch (Throwable $e) {
        return ['count' => 0, 'volume' => 0, 'window_minutes' => $windowMinutes];
    }
}

/**
 * Check velocity on a specific Virtual Account — how many payments in the last N minutes.
 */
function checkVaVelocity(string $vaNumber, int $merchantId, int $windowMinutes = 10): array
{
    $db = getDB();
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) as count, COALESCE(SUM(amount),0) as volume
             FROM transactions t
             WHERE t.va_number = ? AND t.merchant_id = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $st->execute([$vaNumber, $merchantId, $windowMinutes]);
        $row = $st->fetch();
        return [
            'count' => (int)($row['count'] ?? 0),
            'volume' => (float)($row['volume'] ?? 0),
            'window_minutes' => $windowMinutes,
        ];
    } catch (Throwable $e) {
        return ['count' => 0, 'volume' => 0, 'window_minutes' => $windowMinutes];
    }
}

/**
 * Evaluate QR/VA velocity risk — returns action and score.
 * Thresholds: >20 txns/10min = high risk, >10 = medium, >5 = low
 */
function evaluateQrVaVelocity(string $qrCode, ?string $vaNumber, int $merchantId): array
{
    $score = 0;
    $reasons = [];

    $qrVel = checkQrVelocity($qrCode, $merchantId, 10);
    if ($qrVel['count'] > 20) {
        $score += 30;
        $reasons[] = "High QR velocity: {$qrVel['count']} txns in 10min (+30)";
    } elseif ($qrVel['count'] > 10) {
        $score += 15;
        $reasons[] = "Medium QR velocity: {$qrVel['count']} txns in 10min (+15)";
    } elseif ($qrVel['count'] > 5) {
        $score += 5;
        $reasons[] = "Low QR velocity: {$qrVel['count']} txns in 10min (+5)";
    }

    if ($vaNumber) {
        $vaVel = checkVaVelocity($vaNumber, $merchantId, 10);
        if ($vaVel['count'] > 20) {
            $score += 30;
            $reasons[] = "High VA velocity: {$vaVel['count']} txns in 10min (+30)";
        } elseif ($vaVel['count'] > 10) {
            $score += 15;
            $reasons[] = "Medium VA velocity: {$vaVel['count']} txns in 10min (+15)";
        }
    }

    $action = match(true) {
        $score >= 50 => 'block',
        $score >= 30 => 'hold',
        $score >= 15 => 'flag',
        default => 'allow',
    };

    return ['score' => $score, 'action' => $action, 'reasons' => $reasons];
}

/**
 * Manual override of a risk event — admin can resolve/override with reason.
 */
function overrideRiskEvent(int $eventId, string $newAction, int $adminId, string $reason): bool
{
    ensureRiskEngineTables();
    if (!in_array($newAction, ['allow', 'flag', 'hold', 'block', 'dismiss'], true)) {
        return false;
    }
    try {
        getDB()->prepare(
            "UPDATE risk_events SET resolved=1, resolved_action=?, resolved_by=?, resolved_reason=?, resolved_at=NOW() WHERE id=?"
        )->execute([$newAction, $adminId, $reason, $eventId]);
        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('risk_override', 0, 'risk_event', (string)$eventId, "Override to {$newAction}: {$reason}");
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Fraud detection algorithms — pattern-based checks.
 */
function detectFraudPatterns(int $merchantId, int $days = 7): array
{
    $db = getDB();
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));
    $alerts = [];

    // 1. Rapid successive failures from same IP
    try {
        $st = $db->prepare(
            "SELECT customer_ip, COUNT(*) as fail_count
             FROM transactions
             WHERE merchant_id=? AND status='failed' AND created_at >= ?
             GROUP BY customer_ip HAVING fail_count >= 10
             ORDER BY fail_count DESC LIMIT 10"
        );
        $st->execute([$merchantId, $since]);
        foreach ($st->fetchAll() as $row) {
            $alerts[] = ['type' => 'rapid_failures', 'ip' => $row['customer_ip'], 'count' => (int)$row['fail_count'], 'severity' => 'high'];
        }
    } catch (Throwable $e) {}

    // 2. Unusual amount pattern (many txns just below a threshold)
    try {
        $st = $db->prepare(
            "SELECT COUNT(*) as count FROM transactions
             WHERE merchant_id=? AND status='success' AND amount BETWEEN 95000 AND 99999
             AND created_at >= ?"
        );
        $st->execute([$merchantId, $since]);
        $count = (int)$st->fetchColumn();
        if ($count >= 5) {
            $alerts[] = ['type' => 'threshold_avoidance', 'count' => $count, 'severity' => 'high', 'note' => 'Multiple txns just below ₹1L reporting threshold'];
        }
    } catch (Throwable $e) {}

    // 3. Multiple txns from different IPs but same device
    try {
        $st = $db->prepare(
            "SELECT device_id, COUNT(DISTINCT customer_ip) as ip_count, COUNT(*) as txn_count
             FROM transactions
             WHERE merchant_id=? AND status='success' AND device_id IS NOT NULL AND device_id != ''
             AND created_at >= ?
             GROUP BY device_id HAVING ip_count >= 3
             ORDER BY ip_count DESC LIMIT 10"
        );
        $st->execute([$merchantId, $since]);
        foreach ($st->fetchAll() as $row) {
            $alerts[] = ['type' => 'multi_ip_device', 'device_id' => $row['device_id'], 'ip_count' => (int)$row['ip_count'], 'txn_count' => (int)$row['txn_count'], 'severity' => 'medium'];
        }
    } catch (Throwable $e) {}

    // 4. Velocity spike — sudden increase in transaction volume
    try {
        $recent = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $recent->execute([$merchantId]);
        $recentCount = (int)$recent->fetchColumn();

        $baseline = $db->prepare("SELECT COALESCE(AVG(cnt),0) FROM (
            SELECT COUNT(*) as cnt FROM transactions
            WHERE merchant_id=? AND status='success'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            GROUP BY FLOOR(UNIX_TIMESTAMP(created_at)/3600)
        ) t");
        $baseline->execute([$merchantId]);
        $avgHourly = (float)$baseline->fetchColumn();

        if ($avgHourly > 0 && $recentCount > ($avgHourly * 5)) {
            $alerts[] = ['type' => 'volume_spike', 'recent' => $recentCount, 'average' => round($avgHourly, 1), 'multiplier' => round($recentCount / $avgHourly, 1), 'severity' => 'medium'];
        }
    } catch (Throwable $e) {}

    return $alerts;
}

/**
 * Send alert on high-risk events (called when a risk event is recorded).
 */
function alertHighRiskEvent(array $riskEvent): void
{
    if (!in_array($riskEvent['action'] ?? '', ['hold', 'block'], true)) {
        return;
    }

    $merchantId = (int)($riskEvent['merchant_id'] ?? 0);
    $action = $riskEvent['action'];
    $reason = $riskEvent['reason'] ?? 'High risk event detected';

    // Log to platform errors for admin visibility
    if (function_exists('logPlatformError')) {
        logPlatformError('warning', "High-risk event [{$action}] for merchant #{$merchantId}: {$reason}");
    }

    // Send webhook notification if configured
    try {
        $webhookUrl = getSetting('risk_alert_webhook_url', '');
        if ($webhookUrl !== '') {
            $payload = json_encode([
                'event' => 'high_risk_alert',
                'merchant_id' => $merchantId,
                'action' => $action,
                'reason' => $reason,
                'timestamp' => date('c'),
            ]);
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) { /* non-critical */ }
}

/**
 * Export risk report as CSV data (returns CSV string).
 */
function exportRiskReport(int $days = 30): string
{
    ensureRiskEngineTables();
    $since = date('Y-m-d H:i:s', time() - ($days * 86400));

    try {
        $st = getDB()->prepare(
            "SELECT re.id, re.merchant_id, m.business_name, re.event_type, re.action_taken,
                    re.score, re.reason, re.resolved, re.created_at
             FROM risk_events re
             LEFT JOIN merchants m ON m.id = re.merchant_id
             WHERE re.created_at >= ?
             ORDER BY re.created_at DESC LIMIT 5000"
        );
        $st->execute([$since]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }

    $csv = "ID,Merchant,Business,Event Type,Action,Score,Reason,Resolved,Created At\n";
    foreach ($rows as $row) {
        $csv .= sprintf(
            "%d,%s,%s,%s,%s,%s,%s,%s,%s\n",
            $row['id'],
            $row['merchant_id'],
            str_replace(',', '', $row['business_name'] ?? ''),
            $row['event_type'] ?? '',
            $row['action_taken'] ?? '',
            $row['score'] ?? 0,
            str_replace([',', "\n"], [' ', ' '], $row['reason'] ?? ''),
            $row['resolved'] ? 'Yes' : 'No',
            $row['created_at'] ?? ''
        );
    }

    return $csv;
}

/**
 * Shared Risk hub tabs (Rules / Flags / Engine) — PNL-ST03.
 */
function riskHubNavHtml(string $active): string
{
    $tabs = [
        'rules' => ['admin_risk.php', 'Rules'],
        'flags' => ['admin_aml.php', 'Flags'],
        'engine' => ['admin_risk_engine.php', 'Engine'],
    ];
    $html = '<div class="flex flex-wrap gap-2 mb-4 text-xs" role="navigation" aria-label="Risk hub">'
        . '<span class="text-gray-500 self-center mr-1">Risk hub:</span>';
    foreach ($tabs as $key => [$url, $label]) {
        $on = $active === $key;
        $cls = $on
            ? 'px-3 py-1.5 rounded-lg bg-brand-600/20 text-brand-400 border border-brand-500/30'
            : 'px-3 py-1.5 rounded-lg text-gray-400 hover:text-white border border-gray-800';
        $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" class="' . $cls . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
    }
    return $html . '</div>';
}
