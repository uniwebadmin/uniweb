<?php
declare(strict_types=1);

/**
 * Circuit Breaker — protects against cascading failures when a partner gateway
 * returns 429 (rate limited) or 5xx (server error) repeatedly.
 *
 * States:
 *   - CLOSED: Normal operation, requests pass through. Failures counted.
 *   - OPEN: Gateway is down, requests fail fast (no outbound call). Cooldown period.
 *   - HALF_OPEN: After cooldown, one probe request is allowed. If success → CLOSED, if fail → OPEN.
 *
 * Config (per gateway):
 *   - failure_threshold: failures in window before opening (default 5)
 *   - window_seconds: rolling window for failure count (default 300 = 5 min)
 *   - cooldown_seconds: how long to stay OPEN before HALF_OPEN probe (default 60)
 */

function ensureCircuitBreakerTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS circuit_breaker_state (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(32) NOT NULL UNIQUE,
            state ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed',
            failure_count INT NOT NULL DEFAULT 0,
            last_failure_at TIMESTAMP NULL DEFAULT NULL,
            opened_at TIMESTAMP NULL DEFAULT NULL,
            last_probe_at TIMESTAMP NULL DEFAULT NULL,
            config JSON DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function getCircuitBreakerConfig(string $gateway): array
{
    return [
        'failure_threshold' => 5,
        'window_seconds' => 300,
        'cooldown_seconds' => 60,
    ];
}

/**
 * Get current circuit breaker state for a gateway.
 * Automatically transitions OPEN → HALF_OPEN if cooldown has elapsed.
 */
function getCircuitBreakerState(string $gateway): string
{
    ensureCircuitBreakerTable();
    try {
        $st = getDB()->prepare("SELECT * FROM circuit_breaker_state WHERE gateway=?");
        $st->execute([$gateway]);
        $row = $st->fetch();
        if (!$row) return 'closed';

        $state = $row['state'];
        if ($state === 'open') {
            $config = getCircuitBreakerConfig($gateway);
            $openedAt = strtotime((string)$row['opened_at']);
            if ($openedAt && (time() - $openedAt) >= $config['cooldown_seconds']) {
                // Transition to half_open
                getDB()->prepare("UPDATE circuit_breaker_state SET state='half_open', last_probe_at=NOW() WHERE gateway=?")
                    ->execute([$gateway]);
                return 'half_open';
            }
        }
        return $state;
    } catch (Throwable $e) {
        return 'closed';
    }
}

/**
 * Record a successful call — resets failure count, closes circuit.
 */
function recordCircuitBreakerSuccess(string $gateway): void
{
    ensureCircuitBreakerTable();
    try {
        getDB()->prepare(
            "INSERT INTO circuit_breaker_state (gateway, state, failure_count, updated_at)
             VALUES (?, 'closed', 0, NOW())
             ON DUPLICATE KEY UPDATE state='closed', failure_count=0, updated_at=NOW()"
        )->execute([$gateway]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Record a failure — increments count, may open circuit.
 */
function recordCircuitBreakerFailure(string $gateway, ?int $httpCode = null): void
{
    ensureCircuitBreakerTable();
    $config = getCircuitBreakerConfig($gateway);

    try {
        $db = getDB();
        $st = $db->prepare("SELECT * FROM circuit_breaker_state WHERE gateway=?");
        $st->execute([$gateway]);
        $row = $st->fetch();

        if (!$row) {
            $db->prepare(
                "INSERT INTO circuit_breaker_state (gateway, state, failure_count, last_failure_at, updated_at)
                 VALUES (?, 'closed', 1, NOW(), NOW())"
            )->execute([$gateway]);
            return;
        }

        $state = $row['state'];
        $failCount = (int)$row['failure_count'] + 1;

        if ($state === 'half_open') {
            // Probe failed — back to open
            $db->prepare("UPDATE circuit_breaker_state SET state='open', failure_count=?, last_failure_at=NOW(), opened_at=NOW(), updated_at=NOW() WHERE gateway=?")
                ->execute([$failCount, $gateway]);
            return;
        }

        if ($state === 'closed' && $failCount >= $config['failure_threshold']) {
            // Threshold reached — open the circuit
            $db->prepare("UPDATE circuit_breaker_state SET state='open', failure_count=?, last_failure_at=NOW(), opened_at=NOW(), updated_at=NOW() WHERE gateway=?")
                ->execute([$failCount, $gateway]);
            // Also record in gateway health
            if (function_exists('recordGatewayOutcome')) {
                recordGatewayOutcome($gateway, false, "Circuit opened ({$failCount} failures, HTTP {$httpCode})");
            }
            return;
        }

        // Just increment
        $db->prepare("UPDATE circuit_breaker_state SET failure_count=?, last_failure_at=NOW(), updated_at=NOW() WHERE gateway=?")
            ->execute([$failCount, $gateway]);
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Check if a gateway should be allowed to handle traffic.
 * Returns true if circuit is closed or half_open (probe allowed).
 */
function isCircuitBreakerAllowed(string $gateway): bool
{
    $state = getCircuitBreakerState($gateway);
    return $state !== 'open';
}

/**
 * Get circuit breaker status for all gateways.
 */
function getCircuitBreakerStatus(): array
{
    ensureCircuitBreakerTable();
    $gateways = ['razorpay', 'cashfree', 'payu', 'axis', 'decentro'];
    $out = [];
    foreach ($gateways as $gw) {
        $state = getCircuitBreakerState($gw);
        try {
            $st = getDB()->prepare("SELECT failure_count, last_failure_at, opened_at FROM circuit_breaker_state WHERE gateway=?");
            $st->execute([$gw]);
            $row = $st->fetch();
        } catch (Throwable $e) { $row = null; }

        $out[$gw] = [
            'state' => $state,
            'failure_count' => $row ? (int)$row['failure_count'] : 0,
            'last_failure_at' => $row ? $row['last_failure_at'] : null,
            'opened_at' => $row ? $row['opened_at'] : null,
        ];
    }
    return $out;
}

/**
 * Manually reset a circuit breaker (admin action).
 */
function resetCircuitBreaker(string $gateway): bool
{
    ensureCircuitBreakerTable();
    try {
        getDB()->prepare(
            "INSERT INTO circuit_breaker_state (gateway, state, failure_count, updated_at)
             VALUES (?, 'closed', 0, NOW())
             ON DUPLICATE KEY UPDATE state='closed', failure_count=0, opened_at=NULL, updated_at=NOW()"
        )->execute([$gateway]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
