<?php
declare(strict_types=1);

/**
 * VIP Feature — Smart Routing.
 * Tracks live health of each card/netbanking gateway (Razorpay/Cashfree/PayU) and
 * automatically prefers a healthy one when the primary gateway's API is down,
 * instead of failing the checkout outright.
 */

function ensureGatewayHealthTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_health_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(24) NOT NULL,
            outcome ENUM('ok','fail') NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gw_time (gateway, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function recordGatewayOutcome(string $gateway, bool $ok, ?string $detail = null): void
{
    ensureGatewayHealthTable();
    try {
        getDB()->prepare('INSERT INTO gateway_health_events (gateway, outcome, detail) VALUES (?,?,?)')
            ->execute([$gateway, $ok ? 'ok' : 'fail', $detail ? mb_substr($detail, 0, 255) : null]);
    } catch (Throwable $e) { /* ok */ }
}

/** Unhealthy = 3+ consecutive/recent failures in the last 10 minutes with no success since. */
function isGatewayHealthy(string $gateway): bool
{
    ensureGatewayHealthTable();
    try {
        $st = getDB()->prepare("SELECT outcome FROM gateway_health_events WHERE gateway = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE) ORDER BY id DESC LIMIT 3");
        $st->execute([$gateway]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) < 3) {
            return true;
        }
        foreach ($rows as $r) {
            if ($r === 'ok') {
                return true;
            }
        }
        return false; // last 3 events in window were all failures
    } catch (Throwable $e) {
        return true;
    }
}

function gatewayHealthSummary(): array
{
    ensureGatewayHealthTable();
    $out = [];
    foreach (['razorpay', 'cashfree', 'payu'] as $gw) {
        $out[$gw] = [
            'configured' => isGatewayConfigured($gw),
            'healthy' => isGatewayHealthy($gw),
        ];
    }
    return $out;
}

/**
 * Try Razorpay order; on failure/timeout, auto-divert to Cashfree, then flags
 * PayU as the manual fallback tab. Returns which gateway actually produced a usable order.
 */
function createCardOrderWithSmartRouting(float $amount, array $link, string $returnUrl): array
{
    $preferred = isGatewayHealthy('razorpay') ? 'razorpay' : 'cashfree';
    $order = ['razorpay' => null, 'cashfree' => null, 'routed_to' => null, 'diverted' => false];

    $tryOrder = function (string $gw) use ($link, $returnUrl) {
        if (!isGatewayConfigured($gw)) {
            return null;
        }
        try {
            $res = createBoundGatewayCheckoutOrder($link, $gw, $returnUrl);
        } catch (Throwable $e) {
            recordGatewayOutcome($gw, false, $e->getMessage());
            return null;
        }
        $ok = is_array($res) && ($gw === 'razorpay' ? !empty($res['id']) : !empty($res['payment_session_id']));
        recordGatewayOutcome($gw, $ok, $ok ? null : 'no_response');
        return $ok ? $res : null;
    };

    $result = $tryOrder($preferred);
    if ($result) {
        $order[$preferred] = $result;
        $order['routed_to'] = $preferred;
        return $order;
    }

    $fallback = $preferred === 'razorpay' ? 'cashfree' : 'razorpay';
    $result2 = $tryOrder($fallback);
    if ($result2) {
        $order[$fallback] = $result2;
        $order['routed_to'] = $fallback;
        $order['diverted'] = true;
        return $order;
    }

    return $order;
}
