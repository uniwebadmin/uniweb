<?php
declare(strict_types=1);

/**
 * Intelligent routing (score-based) for checkout partner selection.
 * DEFAULT OFF — Owner enables via Gateway Settings → intelligent_routing_enabled.
 *
 * Strategies: fixed | rules | score (no fake neural claims).
 * ML extension: weights file hook documented in intelligentRoutingMlWeights().
 */

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

function intelligentRoutingEnabled(): bool
{
    if (!function_exists('getSetting')) {
        return false;
    }
    return getSetting('intelligent_routing_enabled', '0') === '1';
}

function intelligentRoutingStrategy(): string
{
    $s = strtolower(trim((string)getSetting('intelligent_routing_strategy', 'score')));
    return in_array($s, ['fixed', 'rules', 'score'], true) ? $s : 'score';
}

/** Rolling window for live success-rate signal (hours). Default 7 days. */
function intelligentRoutingSuccessRateWindowHours(): int
{
    if (!function_exists('getSetting')) {
        return 168;
    }
    $h = (int)getSetting('intelligent_routing_success_window_hours', '168');
    return max(1, min(720, $h));
}

function intelligentRoutingMinSampleSize(): int
{
    if (!function_exists('getSetting')) {
        return 5;
    }
    return max(3, min(50, (int)getSetting('intelligent_routing_min_sample', '5')));
}

/** Optional ML weights JSON path (future hook — safe defaults when missing). */
function intelligentRoutingMlWeights(): array
{
    $raw = trim((string)getSetting('intelligent_routing_ml_weights', ''));
    if ($raw === '') {
        return ['success_rate' => 0.5, 'latency' => 0.2, 'health' => 0.3];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success_rate' => 0.5, 'latency' => 0.2, 'health' => 0.3];
}

/** Human-readable strategy doc for Admin (no fake ML / multi-PG claims). */
function intelligentRoutingStrategyDoc(): string
{
    return match (intelligentRoutingStrategy()) {
        'fixed' => 'Fixed — merchant/default partner only. No score ranking.',
        'rules' => 'Rules — method + amount band first; score tie-break when no rule matches.',
        default => 'Score — live success-rate (rolling window) + gateway health + latency proxy. Failover tries next healthy partner only.',
    };
}

/** Checkout partners that use order-API create (PayU uses separate form redirect). */
function intelligentRoutingOrderApiPartners(): array
{
    return ['razorpay', 'cashfree'];
}

function intelligentRoutingOrderCreateTimeoutSeconds(): int
{
    if (!function_exists('getSetting')) {
        return 12;
    }
    return max(5, min(30, (int)getSetting('intelligent_routing_order_timeout_seconds', '12')));
}

/**
 * Partners with Registry keys + method support + circuit breaker open.
 *
 * @return list<string>
 */
function intelligentRoutingUsablePartners(int $merchantId, string $method): array
{
    if (!function_exists('phase11EligibleCheckoutPartners')) {
        require_once __DIR__ . '/smart_routing.php';
    }
    $method = strtolower(trim($method));
    $orderApi = intelligentRoutingOrderApiPartners();
    $usable = [];
    foreach (phase11EligibleCheckoutPartners($merchantId, $method) as $partner) {
        if (!in_array($partner, $orderApi, true)) {
            continue;
        }
        if (!function_exists('isGatewayConfigured') || !isGatewayConfigured($partner)) {
            continue;
        }
        if (function_exists('isCircuitBreakerAllowed') && !isCircuitBreakerAllowed($partner)) {
            continue;
        }
        $usable[] = $partner;
    }
    return $usable;
}

/**
 * Usable partners that pass short-term health check (failover targets only).
 *
 * @return list<string>
 */
function intelligentRoutingHealthyPartners(int $merchantId, string $method): array
{
    if (!function_exists('isGatewayHealthy')) {
        require_once __DIR__ . '/smart_routing.php';
    }
    return array_values(array_filter(
        intelligentRoutingUsablePartners($merchantId, $method),
        static fn(string $p): bool => isGatewayHealthy($p)
    ));
}

/**
 * Platform/merchant readiness for honest degrade + Admin warnings.
 *
 * @return array{usable_count:int,healthy_count:int,failover_capable:bool,usable:list<string>,healthy:list<string>}
 */
function intelligentRoutingReadiness(int $merchantId = 0, string $method = 'card'): array
{
    $method = strtolower(trim($method));
    $usable = intelligentRoutingUsablePartners($merchantId, $method);
    $healthy = intelligentRoutingHealthyPartners($merchantId, $method);
    return [
        'usable_count' => count($usable),
        'healthy_count' => count($healthy),
        'failover_capable' => count($healthy) >= 2,
        'usable' => $usable,
        'healthy' => $healthy,
    ];
}

function isIntelligentGatewayOrderCreateSuccess(string $gateway, ?array $response): bool
{
    if (!is_array($response)) {
        return false;
    }
    $gateway = strtolower(trim($gateway));
    return match ($gateway) {
        'razorpay' => !empty($response['id']),
        'cashfree' => !empty($response['payment_session_id']),
        default => false,
    };
}

function ensureIntelligentRouteDecisionLogTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS intelligent_route_decisions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT DEFAULT NULL,
            link_id VARCHAR(64) DEFAULT NULL,
            txn_id VARCHAR(40) DEFAULT NULL,
            method_key VARCHAR(32) DEFAULT NULL,
            amount DECIMAL(12,2) DEFAULT NULL,
            chosen_partner VARCHAR(24) DEFAULT NULL,
            strategy VARCHAR(24) NOT NULL DEFAULT 'score',
            reason_code VARCHAR(64) NOT NULL,
            reason VARCHAR(255) NOT NULL,
            scores_json TEXT DEFAULT NULL,
            candidates_json TEXT DEFAULT NULL,
            engine_on TINYINT(1) NOT NULL DEFAULT 0,
            outcome ENUM('selected','failover','fallback_fixed','none','error','attempt_failed') NOT NULL DEFAULT 'selected',
            attempt_index TINYINT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ir_time (created_at),
            INDEX idx_ir_partner (chosen_partner, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec("ALTER TABLE intelligent_route_decisions MODIFY outcome ENUM('selected','failover','fallback_fixed','none','error','attempt_failed') NOT NULL DEFAULT 'selected'");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec('ALTER TABLE intelligent_route_decisions ADD COLUMN attempt_index TINYINT UNSIGNED DEFAULT NULL');
    } catch (Throwable $e) { /* ok */ }
}

function logIntelligentRouteDecision(
    ?string $partner,
    string $reasonCode,
    string $reason,
    array $candidates,
    string $outcome,
    int $merchantId = 0,
    ?string $linkId = null,
    string $method = 'card',
    ?float $amount = null,
    ?array $scores = null,
    ?string $txnId = null,
    ?int $attemptIndex = null
): void {
    ensureIntelligentRouteDecisionLogTable();
    try {
        getDB()->prepare(
            'INSERT INTO intelligent_route_decisions
             (merchant_id,link_id,txn_id,method_key,amount,chosen_partner,strategy,reason_code,reason,scores_json,candidates_json,engine_on,outcome,attempt_index)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $merchantId > 0 ? $merchantId : null,
            $linkId,
            $txnId,
            $method,
            $amount,
            $partner,
            intelligentRoutingStrategy(),
            mb_substr($reasonCode, 0, 64),
            mb_substr($reason, 0, 255),
            $scores !== null ? json_encode($scores, JSON_UNESCAPED_SLASHES) : null,
            json_encode($candidates, JSON_UNESCAPED_SLASHES),
            intelligentRoutingEnabled() ? 1 : 0,
            $outcome,
            $attemptIndex,
        ]);
    } catch (Throwable $e) { /* ok */ }
}

/** @return list<array{row:array<string,mixed>}> */
function getIntelligentRouteDecisionLog(int $limit = 20): array
{
    ensureIntelligentRouteDecisionLogTable();
    $limit = max(1, min(100, $limit));
    try {
        $st = getDB()->prepare(
            'SELECT * FROM intelligent_route_decisions ORDER BY id DESC LIMIT ?'
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Short scores line for Admin log table. */
function formatIntelligentRouteScoresForAdmin(?string $scoresJson): string
{
    if ($scoresJson === null || trim($scoresJson) === '') {
        return '—';
    }
    $decoded = json_decode($scoresJson, true);
    if (!is_array($decoded) || $decoded === []) {
        return '—';
    }
    $parts = [];
    foreach ($decoded as $partner => $score) {
        $parts[] = (string)$partner . '=' . (is_numeric($score) ? number_format((float)$score, 2) : (string)$score);
    }
    return implode(', ', $parts);
}

/**
 * @return array{partner:?string,reason_code:string,reason:string,ranked:list<string>,scores:array<string,float>,strategy:string}
 */
function intelligentChoosePartner(array $context): array
{
    $merchantId = (int)($context['merchant_id'] ?? 0);
    $method = strtolower(trim((string)($context['method'] ?? 'card')));
    $amount = isset($context['amount']) ? (float)$context['amount'] : null;
    $preferred = strtolower(trim((string)($context['preferred_partner'] ?? '')));
    $strategy = intelligentRoutingStrategy();

    if (!function_exists('phase11EligibleCheckoutPartners')) {
        require_once __DIR__ . '/smart_routing.php';
    }

    $eligible = phase11EligibleCheckoutPartners($merchantId, $method);
    $eligible = array_values(array_intersect($eligible, intelligentRoutingOrderApiPartners()));
    if ($eligible === []) {
        return [
            'partner' => null,
            'reason_code' => 'no_eligible',
            'reason' => 'No configured checkout partners for this merchant/method.',
            'ranked' => [],
            'scores' => [],
            'strategy' => $strategy,
        ];
    }

    if ($strategy === 'fixed') {
        $fixed = $preferred !== '' && in_array($preferred, $eligible, true) ? $preferred : $eligible[0];
        return [
            'partner' => $fixed,
            'reason_code' => 'fixed_default',
            'reason' => 'Fixed strategy — merchant/default partner.',
            'ranked' => [$fixed],
            'scores' => [$fixed => 1.0],
            'strategy' => $strategy,
        ];
    }

    $scores = intelligentScorePartners($eligible, $method, $amount);
    arsort($scores, SORT_NUMERIC);
    $ranked = array_keys($scores);

    if ($strategy === 'rules') {
        $rulesPick = intelligentRulesPickPartner($eligible, $method, $amount, $scores);
        if ($rulesPick !== null) {
            return [
                'partner' => $rulesPick,
                'reason_code' => 'rules_match',
                'reason' => 'Rules engine matched partner for method/amount band.',
                'ranked' => $ranked,
                'scores' => $scores,
                'strategy' => $strategy,
            ];
        }
    }

    $best = $ranked[0] ?? null;
    if ($best === null || ($scores[$best] ?? 0) <= 0) {
        return [
            'partner' => null,
            'reason_code' => 'all_unhealthy',
            'reason' => 'All eligible partners unhealthy or missing keys.',
            'ranked' => $ranked,
            'scores' => $scores,
            'strategy' => $strategy,
        ];
    }

    return [
        'partner' => $best,
        'reason_code' => 'score_top',
        'reason' => 'Score-based pick (success-rate + health + latency weights).',
        'ranked' => $ranked,
        'scores' => $scores,
        'strategy' => $strategy,
    ];
}

/** @param list<string> $partners @return array<string,float> */
function intelligentScorePartners(array $partners, string $method, ?float $amount): array
{
    $weights = intelligentRoutingMlWeights();
    if (!function_exists('gatewayHealthSummary')) {
        require_once __DIR__ . '/smart_routing.php';
    }
    $health = gatewayHealthSummary();
    $scores = [];
    foreach ($partners as $gw) {
        if (!function_exists('isGatewayConfigured') || !isGatewayConfigured($gw)) {
            continue;
        }
        if (function_exists('isCircuitBreakerAllowed') && !isCircuitBreakerAllowed($gw)) {
            continue;
        }
        $successRate = intelligentPartnerSuccessRate($gw, $method);
        $healthOk = !empty($health[$gw]['healthy']) ? 1.0 : 0.3;
        $latencyScore = intelligentPartnerLatencyScore($gw);
        $amountBand = intelligentAmountBandBonus($gw, $amount);
        $score = ($weights['success_rate'] ?? 0.5) * $successRate
            + ($weights['health'] ?? 0.3) * $healthOk
            + ($weights['latency'] ?? 0.2) * $latencyScore
            + $amountBand;
        $scores[$gw] = round(max(0.01, $score), 4);
    }
    return $scores;
}

function intelligentPartnerSuccessRate(string $partner, string $method): float
{
    $partner = strtolower(trim($partner));
    $hours = intelligentRoutingSuccessRateWindowHours();
    $minSample = intelligentRoutingMinSampleSize();
    try {
        $st = getDB()->prepare(
            "SELECT
                SUM(CASE WHEN t.status='success' THEN 1 ELSE 0 END) AS ok,
                COUNT(*) AS total
             FROM transactions t
             WHERE COALESCE(t.is_test, 0) = 0
               AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
               AND (
                    t.payment_method = ?
                    OR t.payment_method LIKE ?
                    OR t.payment_method LIKE ?
                    OR EXISTS (
                        SELECT 1 FROM payment_order_transactions pot
                        JOIN payment_orders po ON po.id = pot.payment_order_id
                        WHERE pot.transaction_id = t.id AND po.provider = ?
                    )
               )"
        );
        $likeMethod = $partner . '%';
        $st->execute([$hours, $partner, $likeMethod, $partner . '-%', $partner]);
        $row = $st->fetch();
        $total = (int)($row['total'] ?? 0);
        if ($total < $minSample) {
            return 0.75;
        }
        return round((int)($row['ok'] ?? 0) / max(1, $total), 4);
    } catch (Throwable $e) {
        return 0.75;
    }
}

function intelligentPartnerLatencyScore(string $partner): float
{
    if (!function_exists('isGatewayHealthy')) {
        require_once __DIR__ . '/smart_routing.php';
    }
    return isGatewayHealthy($partner) ? 1.0 : 0.2;
}

function intelligentAmountBandBonus(string $partner, ?float $amount): float
{
    if ($amount === null || $amount <= 0) {
        return 0.0;
    }
    if ($amount >= 50000 && in_array($partner, ['razorpay', 'cashfree'], true)) {
        return 0.05;
    }
    if ($amount < 500 && $partner === 'payu') {
        return 0.03;
    }
    return 0.0;
}

/** @param array<string,float> $scores */
function intelligentRulesPickPartner(array $eligible, string $method, ?float $amount, array $scores): ?string
{
    if ($method === 'upi' && in_array('razorpay', $eligible, true)) {
        return 'razorpay';
    }
    if ($amount !== null && $amount >= 100000) {
        foreach (['cashfree', 'razorpay', 'payu'] as $p) {
            if (in_array($p, $eligible, true) && ($scores[$p] ?? 0) > 0) {
                return $p;
            }
        }
    }
    return null;
}

/**
 * Map checkout tab key to routing method bucket for scoring.
 */
function intelligentRoutingMethodBucket(string $payKey): string
{
    $payKey = strtolower(trim($payKey));
    return match ($payKey) {
        'upi', 'payu_upi', 'upi_p2m' => 'upi',
        'dc', 'cc', 'razorpay', 'cashfree' => 'card',
        'nb' => 'netbanking',
        'emi' => 'emi',
        'wallet' => 'wallet',
        default => 'card',
    };
}

/**
 * Intelligent routing checkout order creation — only when intelligent_routing_enabled=1.
 * When OFF: caller must use fixed / Phase 11 path unchanged.
 *
 * @return array{razorpay:?array,cashfree:?array,payu:?array,routed_to:?string,diverted:bool,intelligent:bool,reason:string,method:string}
 */
function createCardOrderWithIntelligentRouting(float $amount, array $link, string $returnUrl, string $checkoutPayKey = 'card'): array
{
    $merchantId = (int)($link['merchant_id'] ?? 0);
    $linkId = (string)($link['link_id'] ?? '');
    $methodBucket = intelligentRoutingMethodBucket($checkoutPayKey);
    $order = [
        'razorpay' => null,
        'cashfree' => null,
        'payu' => null,
        'routed_to' => null,
        'diverted' => false,
        'intelligent' => false,
        'reason' => '',
        'method' => $methodBucket,
    ];

    if (!intelligentRoutingEnabled()) {
        $order['reason'] = 'Intelligent routing OFF — use fixed or Phase 11 path.';
        return $order;
    }

    $order['intelligent'] = true;
    $readiness = intelligentRoutingReadiness($merchantId, $methodBucket);
    $preferred = strtolower(trim((string)($link['gateway_code'] ?? '')));
    $selectionScores = null;
    $selectionReasonCode = 'single_partner_fixed';
    $selectionReason = '';
    $attemptList = [];

    if ($readiness['usable_count'] === 0) {
        $order['reason'] = 'No usable collect partners (Registry keys + circuit breaker).';
        logIntelligentRouteDecision(
            null,
            'no_usable',
            $order['reason'],
            [],
            'none',
            $merchantId,
            $linkId,
            $methodBucket,
            $amount,
            null
        );
        return $order;
    }

    if ($readiness['usable_count'] < 2) {
        $fixed = $preferred !== '' && in_array($preferred, $readiness['usable'], true)
            ? $preferred
            : $readiness['usable'][0];
        $fixedReason = 'Single partner with keys — fixed path (failover needs 2+ healthy partners).';
        $selectionReason = $fixedReason;
        $selectionScores = [$fixed => 1.0];
        logIntelligentRouteDecision(
            $fixed,
            'single_partner_fixed',
            $fixedReason,
            $readiness['usable'],
            'fallback_fixed',
            $merchantId,
            $linkId,
            $methodBucket,
            $amount,
            [$fixed => 1.0]
        );
        $attemptList = [$fixed];
    } else {
        $selection = intelligentChoosePartner([
            'merchant_id' => $merchantId,
            'method' => $methodBucket,
            'amount' => $amount,
            'preferred_partner' => $preferred,
        ]);
        $ranked = $selection['ranked'];

        if ($selection['partner'] === null || $ranked === []) {
            $order['reason'] = $selection['reason'];
            logIntelligentRouteDecision(
                null,
                $selection['reason_code'],
                $selection['reason'],
                $ranked,
                'none',
                $merchantId,
                $linkId,
                $methodBucket,
                $amount,
                $selection['scores']
            );
            return $order;
        }

        $attemptList = [$ranked[0]];
        foreach (array_slice($ranked, 1) as $gw) {
            if (in_array($gw, $readiness['healthy'], true)) {
                $attemptList[] = $gw;
            }
        }
        $selectionScores = $selection['scores'];
        $selectionReasonCode = $selection['reason_code'];
        $selectionReason = $selection['reason'];
        if (count($attemptList) === 1 && !$readiness['failover_capable']) {
            logIntelligentRouteDecision(
                $attemptList[0],
                'no_failover_target',
                'Primary selected — no second healthy partner for failover.',
                $ranked,
                'fallback_fixed',
                $merchantId,
                $linkId,
                $methodBucket,
                $amount,
                $selection['scores']
            );
        }
    }

    $cbAvailable = function_exists('isCircuitBreakerAllowed');
    $timeoutSec = intelligentRoutingOrderCreateTimeoutSeconds();

    $tryOrder = function (string $gw) use ($link, $returnUrl, $cbAvailable, $timeoutSec) {
        if (!isGatewayConfigured($gw)) {
            return ['ok' => false, 'detail' => 'not_configured'];
        }
        if ($cbAvailable && !isCircuitBreakerAllowed($gw)) {
            return ['ok' => false, 'detail' => 'circuit_open'];
        }
        $started = microtime(true);
        try {
            $res = createBoundGatewayCheckoutOrder($link, $gw, $returnUrl);
        } catch (Throwable $e) {
            if (function_exists('recordGatewayOutcome')) {
                recordGatewayOutcome($gw, false, $e->getMessage());
            }
            if ($cbAvailable && function_exists('recordCircuitBreakerFailure')) {
                recordCircuitBreakerFailure($gw);
            }
            return ['ok' => false, 'detail' => 'exception', 'error' => $e->getMessage()];
        }
        if ((microtime(true) - $started) > $timeoutSec) {
            if (function_exists('recordGatewayOutcome')) {
                recordGatewayOutcome($gw, false, 'timeout');
            }
            if ($cbAvailable && function_exists('recordCircuitBreakerFailure')) {
                recordCircuitBreakerFailure($gw);
            }
            return ['ok' => false, 'detail' => 'timeout'];
        }
        $ok = isIntelligentGatewayOrderCreateSuccess($gw, is_array($res) ? $res : null);
        if (function_exists('recordGatewayOutcome')) {
            recordGatewayOutcome($gw, $ok, $ok ? null : 'no_response');
        }
        if ($cbAvailable) {
            if ($ok && function_exists('recordCircuitBreakerSuccess')) {
                recordCircuitBreakerSuccess($gw);
            } elseif (!$ok && function_exists('recordCircuitBreakerFailure')) {
                recordCircuitBreakerFailure($gw);
            }
        }
        return $ok ? ['ok' => true, 'response' => $res] : ['ok' => false, 'detail' => 'no_response'];
    };

    $attempted = [];
    foreach ($attemptList as $idx => $gw) {
        $attempted[] = $gw;
        $result = $tryOrder($gw);
        if (!empty($result['ok']) && !empty($result['response'])) {
            $order[$gw] = $result['response'];
            $order['routed_to'] = $gw;
            $order['diverted'] = $idx > 0;
            $reason = $idx === 0
                ? ($selectionReason !== '' ? $selectionReason : 'Fixed partner — ' . $gw)
                : ('Failover to ' . $gw . ' after ' . implode(', ', array_slice($attemptList, 0, $idx)) . ' failed');
            $order['reason'] = $reason;
            logIntelligentRouteDecision(
                $gw,
                $idx === 0 ? $selectionReasonCode : 'failover',
                $reason,
                $attemptList,
                $idx === 0 ? 'selected' : 'failover',
                $merchantId,
                $linkId,
                $methodBucket,
                $amount,
                $selectionScores,
                null,
                $idx
            );
            return $order;
        }
        $failDetail = (string)($result['detail'] ?? 'failed');
        $failMsg = $failDetail === 'timeout'
            ? 'Order create timed out for ' . $gw . ' — trying next healthy partner.'
            : 'Order create failed for ' . $gw . ' (' . $failDetail . ') — trying next eligible partner.';
        logIntelligentRouteDecision(
            $gw,
            $failDetail === 'timeout' ? 'order_create_timeout' : 'order_create_failed',
            $failMsg,
            $attemptList,
            'attempt_failed',
            $merchantId,
            $linkId,
            $methodBucket,
            $amount,
            $selectionScores,
            null,
            $idx
        );
    }

    $failReason = 'All eligible partners failed order create: ' . implode(', ', $attempted);
    $order['reason'] = $failReason;
    logIntelligentRouteDecision(
        null,
        'order_create_failed',
        $failReason,
        $attemptList,
        'error',
        $merchantId,
        $linkId,
        $methodBucket,
        $amount,
        $selectionScores
    );
    return $order;
}
