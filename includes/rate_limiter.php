<?php
declare(strict_types=1);

/**
 * Rate Limiter — simple DB-based rate limiting for API endpoints.
 *
 * Tracks request counts per (api_key_hash, minute) and rejects if over limit.
 * Configurable per-scope limits (e.g., qr_create: 60/min, payment_link: 30/min).
 */

function ensureRateLimitTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(128) NOT NULL,
            scope VARCHAR(64) NOT NULL,
            window_start TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            count INT NOT NULL DEFAULT 1,
            UNIQUE KEY idx_id_scope_window (identifier, scope, window_start),
            INDEX idx_cleanup (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

/**
 * Check and increment rate limit. Returns true if allowed, false if rate limited.
 */
function checkRateLimit(string $identifier, string $scope, int $maxPerMinute = 60): bool
{
    ensureRateLimitTable();
    $identifier = mb_substr($identifier, 0, 128);
    $scope = mb_substr($scope, 0, 64);

    try {
        $db = getDB();
        $windowStart = date('Y-m-d H:i:00'); // truncate to minute

        // Try to increment existing counter
        $st = $db->prepare(
            "INSERT INTO api_rate_limits (identifier, scope, window_start, count)
             VALUES (?,?,?, 1)
             ON DUPLICATE KEY UPDATE count = count + 1"
        );
        $st->execute([$identifier, $scope, $windowStart]);

        // Check current count
        $st = $db->prepare(
            "SELECT count FROM api_rate_limits
             WHERE identifier=? AND scope=? AND window_start=?"
        );
        $st->execute([$identifier, $scope, $windowStart]);
        $count = (int)$st->fetchColumn();

        return $count <= $maxPerMinute;
    } catch (Throwable $e) {
        // On error, allow the request (fail open)
        return true;
    }
}

/**
 * Get rate limit config per scope.
 */
function getRateLimitConfig(): array
{
    return [
        'qr_create' => 60,
        'qr_batch' => 10,
        'payment_link' => 30,
        'transaction_query' => 120,
        'refund' => 10,
        'default' => 60,
    ];
}

/**
 * Get current rate limit usage for an identifier.
 */
function getRateLimitUsage(string $identifier, string $scope): array
{
    ensureRateLimitTable();
    try {
        $st = getDB()->prepare(
            "SELECT window_start, count FROM api_rate_limits
             WHERE identifier=? AND scope=? AND window_start >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY window_start DESC LIMIT 5"
        );
        $st->execute([$identifier, $scope]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Clean up old rate limit entries (call from cron).
 */
function cleanupRateLimitEntries(): int
{
    ensureRateLimitTable();
    try {
        $count = getDB()->exec("DELETE FROM api_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        return (int)$count;
    } catch (Throwable $e) {
        return 0;
    }
}
