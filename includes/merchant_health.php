<?php
declare(strict_types=1);

/**
 * Merchant Health Score — overall merchant quality metric.
 * Components (0-100 each, weighted to 0-100 total):
 *   - KYC quality (25%): document completeness, verification status
 *   - Dispute rate (25%): chargeback ratio, dispute resolution
 *   - Volume (20%): transaction volume and growth
 *   - Settlement regularity (15%): on-time settlements, no delays
 *   - Support (15%): ticket resolution rate, response time
 */

function ensureMerchantHealthTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_health_scores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            health_score INT NOT NULL DEFAULT 0,
            kyc_quality_score INT NOT NULL DEFAULT 0,
            dispute_rate_score INT NOT NULL DEFAULT 0,
            volume_score INT NOT NULL DEFAULT 0,
            settlement_score INT NOT NULL DEFAULT 0,
            support_score INT NOT NULL DEFAULT 0,
            reasons JSON DEFAULT NULL,
            trend VARCHAR(8) DEFAULT NULL,
            calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_merchant (merchant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Calculate KYC quality score (0-100).
 */
function calculateKycQualityScore(int $merchantId): array
{
    $db = getDB();
    $score = 0;
    $reasons = [];

    try {
        $st = $db->prepare("SELECT kyc_status, kyc_documents, business_name, email, phone, pan_number, gst_number, bank_account_name, bank_account_number, bank_ifsc FROM merchants WHERE id=?");
        $st->execute([$merchantId]);
        $m = $st->fetch();
        if (!$m) return ['score' => 0, 'reasons' => ['Merchant not found']];

        // KYC status
        if ($m['kyc_status'] === 'verified') {
            $score += 40;
            $reasons[] = 'KYC verified (+40)';
        } elseif ($m['kyc_status'] === 'pending') {
            $score += 15;
            $reasons[] = 'KYC pending (+15)';
        }

        // Document completeness
        $docs = json_decode((string)($m['kyc_documents'] ?? '[]'), true) ?: [];
        $docCount = count($docs);
        if ($docCount >= 5) {
            $score += 20;
            $reasons[] = "5+ documents (+20)";
        } elseif ($docCount >= 3) {
            $score += 12;
            $reasons[] = "3+ documents (+12)";
        } elseif ($docCount >= 1) {
            $score += 5;
            $reasons[] = "1+ documents (+5)";
        }

        // Profile completeness
        $fields = ['business_name', 'email', 'phone', 'pan_number', 'gst_number', 'bank_account_name', 'bank_account_number', 'bank_ifsc'];
        $filled = 0;
        foreach ($fields as $f) {
            if (!empty($m[$f])) $filled++;
        }
        $completeness = (int)round($filled / count($fields) * 40);
        $score += $completeness;
        if ($completeness === 40) $reasons[] = "Complete profile (+40)";
        elseif ($completeness >= 30) $reasons[] = "Near-complete profile (+{$completeness})";
        else $reasons[] = "Incomplete profile (+{$completeness})";

    } catch (Throwable $e) {
        return ['score' => 0, 'reasons' => ['Error calculating KYC score']];
    }

    return ['score' => min(100, $score), 'reasons' => $reasons];
}

/**
 * Calculate dispute rate score (0-100, higher is better = fewer disputes).
 */
function calculateDisputeRateScore(int $merchantId): array
{
    $score = 100;
    $reasons = [];

    $cbRatio = getChargebackRatio($merchantId, 90);
    if ($cbRatio > 0.05) {
        $score = 20;
        $reasons[] = "Chargeback ratio >5% (score 20)";
    } elseif ($cbRatio > 0.03) {
        $score = 40;
        $reasons[] = "Chargeback ratio >3% (score 40)";
    } elseif ($cbRatio > 0.01) {
        $score = 70;
        $reasons[] = "Chargeback ratio >1% (score 70)";
    } elseif ($cbRatio > 0) {
        $score = 85;
        $reasons[] = "Low chargeback ratio (score 85)";
    } else {
        $reasons[] = "No chargebacks (score 100)";
    }

    // Open disputes count
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM chargebacks WHERE merchant_id=? AND status='open'");
        $st->execute([$merchantId]);
        $openDisputes = (int)$st->fetchColumn();
        if ($openDisputes > 5) {
            $score -= 15;
            $reasons[] = "{$openDisputes} open disputes (-15)";
        } elseif ($openDisputes > 0) {
            $score -= 5;
            $reasons[] = "{$openDisputes} open disputes (-5)";
        }
    } catch (Throwable $e) {}

    return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
}

/**
 * Calculate volume score (0-100).
 */
function calculateVolumeScore(int $merchantId): array
{
    $score = 0;
    $reasons = [];

    try {
        $db = getDB();
        // 30-day volume
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$merchantId]);
        $vol30 = (float)$st->fetchColumn();

        // 90-day volume
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $st->execute([$merchantId]);
        $vol90 = (float)$st->fetchColumn();

        if ($vol90 >= 1000000) {
            $score = 100;
            $reasons[] = "₹10L+ volume in 90d (score 100)";
        } elseif ($vol90 >= 500000) {
            $score = 80;
            $reasons[] = "₹5L+ volume in 90d (score 80)";
        } elseif ($vol90 >= 100000) {
            $score = 60;
            $reasons[] = "₹1L+ volume in 90d (score 60)";
        } elseif ($vol90 >= 10000) {
            $score = 40;
            $reasons[] = "₹10K+ volume in 90d (score 40)";
        } elseif ($vol90 > 0) {
            $score = 20;
            $reasons[] = "Low volume <₹10K (score 20)";
        } else {
            $score = 0;
            $reasons[] = "No volume in 90d (score 0)";
        }

        // Growth trend (30d vs previous 30d)
        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE merchant_id=? AND status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$merchantId]);
        $prevVol30 = (float)$st->fetchColumn();

        if ($prevVol30 > 0 && $vol30 > $prevVol30) {
            $score = min(100, $score + 10);
            $reasons[] = "Growing volume (+10)";
        } elseif ($prevVol30 > 0 && $vol30 < $prevVol30 * 0.5) {
            $score = max(0, $score - 10);
            $reasons[] = "Declining volume (-10)";
        }

    } catch (Throwable $e) {
        return ['score' => 0, 'reasons' => ['Error calculating volume score']];
    }

    return ['score' => $score, 'reasons' => $reasons];
}

/**
 * Calculate settlement regularity score (0-100).
 */
function calculateSettlementScore(int $merchantId): array
{
    $score = 100;
    $reasons = [];

    try {
        $db = getDB();
        // Pending settlements older than 3 days
        $st = $db->prepare("SELECT COUNT(*) FROM settlements WHERE merchant_id=? AND status IN ('pending','processing') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
        $st->execute([$merchantId]);
        $aged = (int)$st->fetchColumn();
        if ($aged > 5) {
            $score -= 30;
            $reasons[] = "{$aged} aged pending settlements (-30)";
        } elseif ($aged > 0) {
            $score -= 10;
            $reasons[] = "{$aged} aged pending settlements (-10)";
        }

        // Failed settlements
        $st = $db->prepare("SELECT COUNT(*) FROM settlements WHERE merchant_id=? AND status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $st->execute([$merchantId]);
        $failed = (int)$st->fetchColumn();
        if ($failed > 3) {
            $score -= 20;
            $reasons[] = "{$failed} failed settlements (-20)";
        } elseif ($failed > 0) {
            $score -= 10;
            $reasons[] = "{$failed} failed settlements (-10)";
        }

        if ($aged === 0 && $failed === 0) {
            $reasons[] = "All settlements healthy (score 100)";
        }
    } catch (Throwable $e) {}

    return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
}

/**
 * Calculate support ticket score (0-100).
 */
function calculateSupportScore(int $merchantId): array
{
    $score = 100;
    $reasons = [];

    try {
        $db = getDB();
        // Open tickets
        $st = $db->prepare("SELECT COUNT(*) FROM support_tickets WHERE merchant_id=? AND status='open'");
        $st->execute([$merchantId]);
        $open = (int)$st->fetchColumn();
        if ($open > 5) {
            $score -= 20;
            $reasons[] = "{$open} open tickets (-20)";
        } elseif ($open > 0) {
            $score -= 5;
            $reasons[] = "{$open} open tickets (-5)";
        }

        // Avg resolution time
        $st = $db->prepare("SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) FROM support_tickets WHERE merchant_id=? AND status='closed' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $st->execute([$merchantId]);
        $avgHours = $st->fetchColumn();
        if ($avgHours) {
            $avgH = (float)$avgHours;
            if ($avgH <= 24) {
                $reasons[] = "Fast resolution <24h (score 100)";
            } elseif ($avgH <= 72) {
                $score -= 10;
                $reasons[] = "Resolution 1-3 days (-10)";
            } else {
                $score -= 25;
                $reasons[] = "Slow resolution >3 days (-25)";
            }
        }
    } catch (Throwable $e) {}

    return ['score' => max(0, min(100, $score)), 'reasons' => $reasons];
}

/**
 * Calculate overall merchant health score (0-100).
 */
function calculateMerchantHealthScore(int $merchantId): array
{
    ensureMerchantHealthTable();
    $kyc = calculateKycQualityScore($merchantId);
    $dispute = calculateDisputeRateScore($merchantId);
    $volume = calculateVolumeScore($merchantId);
    $settlement = calculateSettlementScore($merchantId);
    $support = calculateSupportScore($merchantId);

    // Weighted average
    $health = (int)round(
        $kyc['score'] * 0.25 +
        $dispute['score'] * 0.25 +
        $volume['score'] * 0.20 +
        $settlement['score'] * 0.15 +
        $support['score'] * 0.15
    );

    $allReasons = array_merge($kyc['reasons'], $dispute['reasons'], $volume['reasons'], $settlement['reasons'], $support['reasons']);

    return [
        'health_score' => $health,
        'kyc_quality_score' => $kyc['score'],
        'dispute_rate_score' => $dispute['score'],
        'volume_score' => $volume['score'],
        'settlement_score' => $settlement['score'],
        'support_score' => $support['score'],
        'reasons' => $allReasons,
    ];
}

/**
 * Update merchant health score in DB.
 */
function updateMerchantHealthScore(int $merchantId): int
{
    ensureMerchantHealthTable();
    $calc = calculateMerchantHealthScore($merchantId);

    // Determine trend
    $prevScore = null;
    try {
        $st = getDB()->prepare("SELECT health_score FROM merchant_health_scores WHERE merchant_id=?");
        $st->execute([$merchantId]);
        $prev = $st->fetchColumn();
        if ($prev !== false) $prevScore = (int)$prev;
    } catch (Throwable $e) {}

    $trend = 'stable';
    if ($prevScore !== null) {
        if ($calc['health_score'] > $prevScore + 5) $trend = 'up';
        elseif ($calc['health_score'] < $prevScore - 5) $trend = 'down';
    }

    try {
        getDB()->prepare(
            "INSERT INTO merchant_health_scores (merchant_id, health_score, kyc_quality_score, dispute_rate_score, volume_score, settlement_score, support_score, reasons, trend)
             VALUES (?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE health_score=VALUES(health_score), kyc_quality_score=VALUES(kyc_quality_score),
             dispute_rate_score=VALUES(dispute_rate_score), volume_score=VALUES(volume_score),
             settlement_score=VALUES(settlement_score), support_score=VALUES(support_score),
             reasons=VALUES(reasons), trend=VALUES(trend)"
        )->execute([
            $merchantId,
            $calc['health_score'],
            $calc['kyc_quality_score'],
            $calc['dispute_rate_score'],
            $calc['volume_score'],
            $calc['settlement_score'],
            $calc['support_score'],
            json_encode($calc['reasons']),
            $trend,
        ]);
    } catch (Throwable $e) { /* ok */ }

    return $calc['health_score'];
}

/**
 * Get merchant health score from DB (or calculate if missing).
 */
function getMerchantHealthScore(int $merchantId): array
{
    ensureMerchantHealthTable();
    $st = getDB()->prepare("SELECT * FROM merchant_health_scores WHERE merchant_id=?");
    $st->execute([$merchantId]);
    $row = $st->fetch();
    if ($row) return $row;
    updateMerchantHealthScore($merchantId);
    $st->execute([$merchantId]);
    return $st->fetch() ?: calculateMerchantHealthScore($merchantId);
}

/**
 * Get all merchant health scores ranked.
 */
function getMerchantHealthRanking(int $limit = 50): array
{
    ensureMerchantHealthTable();
    $st = getDB()->prepare(
        "SELECT mhs.*, m.business_name, m.merchant_code, m.kyc_status, m.status
         FROM merchant_health_scores mhs
         JOIN merchants m ON m.id = mhs.merchant_id
         WHERE m.status != 'deleted'
         ORDER BY mhs.health_score DESC LIMIT ?"
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

/**
 * Recalculate health scores for all merchants.
 */
function recalculateAllHealthScores(): int
{
    ensureMerchantHealthTable();
    $st = getDB()->query("SELECT id FROM merchants WHERE status != 'deleted'");
    $count = 0;
    foreach ($st->fetchAll() as $row) {
        updateMerchantHealthScore((int)$row['id']);
        $count++;
    }
    return $count;
}

/**
 * Get health score distribution.
 */
function getHealthScoreDistribution(): array
{
    ensureMerchantHealthTable();
    $dist = ['excellent' => 0, 'good' => 0, 'fair' => 0, 'poor' => 0, 'critical' => 0];
    try {
        $rows = getDB()->query("SELECT health_score FROM merchant_health_scores")->fetchAll();
        foreach ($rows as $r) {
            $s = (int)$r['health_score'];
            if ($s >= 80) $dist['excellent']++;
            elseif ($s >= 60) $dist['good']++;
            elseif ($s >= 40) $dist['fair']++;
            elseif ($s >= 20) $dist['poor']++;
            else $dist['critical']++;
        }
    } catch (Throwable $e) {}
    return $dist;
}
