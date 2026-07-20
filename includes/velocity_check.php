<?php
declare(strict_types=1);

/**
 * VIP Feature — Velocity Check (fraud prevention)
 * If the same IP racks up too many failed payment attempts in a short window,
 * auto-block further attempts for a cooldown period. Cheap, dependency-free
 * defense against card/UPI brute-force and bot abuse — useful signal for bank/PG review.
 */

function ensureVelocityCheckTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS velocity_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(64) NOT NULL,
            event_type VARCHAR(32) NOT NULL,
            reference VARCHAR(128) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ip_type_time (ip_address, event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function velocityClientIp(): string
{
    $candidates = [$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
    foreach ($candidates as $c) {
        $c = trim(explode(',', (string)$c)[0]);
        if ($c !== '' && filter_var($c, FILTER_VALIDATE_IP)) {
            return $c;
        }
    }
    return 'unknown';
}

/** Default policy: 10 failures in 5 minutes -> blocked for 15 minutes. */
function velocityPolicy(string $type): array
{
    $policies = [
        'payment_fail' => ['window_minutes' => 5, 'max_attempts' => 10, 'cooldown_minutes' => 15],
        'login_fail' => ['window_minutes' => 5, 'max_attempts' => 8, 'cooldown_minutes' => 15],
        'otp_fail' => ['window_minutes' => 10, 'max_attempts' => 6, 'cooldown_minutes' => 20],
        'qr_link' => ['window_minutes' => 1, 'max_attempts' => 1000000, 'cooldown_minutes' => 0],
        // QR path no longer uses this policy for blocks (see qr_pay.php / checkout.php).
        // Kept extremely high so any legacy caller cannot throttle ₹100 × 10 lakh traffic.
    ];
    return $policies[$type] ?? ['window_minutes' => 5, 'max_attempts' => 10, 'cooldown_minutes' => 15];
}

function recordVelocityEvent(string $type, ?string $reference = null, ?string $ip = null): void
{
    ensureVelocityCheckTable();
    $ip = $ip ?: velocityClientIp();
    try {
        getDB()->prepare('INSERT INTO velocity_events (ip_address, event_type, reference) VALUES (?,?,?)')
            ->execute([$ip, $type, $reference]);
    } catch (Throwable $e) { /* ok */ }
}

/** @return array{blocked:bool,count:int,retry_after_minutes:int} */
function checkVelocityBlock(string $type, ?string $ip = null): array
{
    ensureVelocityCheckTable();
    $ip = $ip ?: velocityClientIp();
    $policy = velocityPolicy($type);
    try {
        $st = getDB()->prepare('SELECT COUNT(*) FROM velocity_events WHERE ip_address = ? AND event_type = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)');
        $st->execute([$ip, $type, $policy['window_minutes']]);
        $count = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return ['blocked' => false, 'count' => 0, 'retry_after_minutes' => 0];
    }

    if ($count >= $policy['max_attempts']) {
        try {
            $st2 = getDB()->prepare('SELECT MAX(created_at) FROM velocity_events WHERE ip_address = ? AND event_type = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)');
            $st2->execute([$ip, $type, $policy['window_minutes']]);
            $lastAt = $st2->fetchColumn();
            $cooldownEnds = $lastAt ? strtotime((string)$lastAt) + ($policy['cooldown_minutes'] * 60) : time();
            $remaining = max(1, (int)ceil(($cooldownEnds - time()) / 60));
        } catch (Throwable $e) {
            $remaining = $policy['cooldown_minutes'];
        }
        return ['blocked' => true, 'count' => $count, 'retry_after_minutes' => $remaining];
    }

    return ['blocked' => false, 'count' => $count, 'retry_after_minutes' => 0];
}

function velocityBlockMessage(string $type): string
{
    $labels = [
        'payment_fail' => 'Too many failed payment attempts from this network. For security, please try again in a few minutes.',
        'login_fail' => 'Too many failed login attempts. For security, please try again in a few minutes.',
        'otp_fail' => 'Too many incorrect OTP attempts. Please try again in a few minutes.',
        'qr_link' => 'Too many payment links from this QR scan. Please wait a few minutes and try again.',
    ];
    return $labels[$type] ?? 'Too many attempts. Please try again later.';
}
