<?php
declare(strict_types=1);

/**
 * Light rule-based fraud signals — velocity + webhook signature failures → Admin / Error Log.
 * No auto-ban without Owner policy; end-user message stays generic.
 */

if (!function_exists('recordVelocityEvent') && is_file(__DIR__ . '/velocity_check.php')) {
    require_once __DIR__ . '/velocity_check.php';
}

function ensureFraudSignalTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS fraud_signal_flags (
            id INT AUTO_INCREMENT PRIMARY KEY,
            signal_type VARCHAR(40) NOT NULL,
            scope_key VARCHAR(120) NOT NULL,
            reference VARCHAR(190) DEFAULT NULL,
            count_window INT NOT NULL DEFAULT 0,
            severity ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
            detail VARCHAR(500) DEFAULT NULL,
            resolved TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_signal_open (signal_type, resolved, created_at),
            INDEX idx_scope (scope_key, signal_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function recordWebhookSignatureFailure(string $gateway, ?string $eventId = null): void
{
    $gateway = strtolower(trim($gateway));
    if ($gateway === '') {
        return;
    }
    $ip = function_exists('velocityClientIp') ? velocityClientIp() : substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
    if (function_exists('recordVelocityEvent')) {
        recordVelocityEvent('webhook_sig_fail', $gateway . ':' . ($eventId ?: 'unknown'), $ip);
    }
    $block = function_exists('checkVelocityBlock') ? checkVelocityBlock('webhook_sig_fail', $ip) : ['blocked' => false, 'count' => 0];
    $count = (int)($block['count'] ?? 0);
    if ($count >= 5) {
        ensureFraudSignalTable();
        $scope = 'ip:' . $ip;
        $detail = $gateway . ' webhook signature failures (' . $count . ' in window)';
        try {
            $db = getDB();
            $dup = $db->prepare(
                "SELECT id FROM fraud_signal_flags
                 WHERE signal_type='webhook_sig_fail' AND scope_key=? AND resolved=0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                 LIMIT 1"
            );
            $dup->execute([$scope]);
            if (!$dup->fetchColumn()) {
                $db->prepare(
                    'INSERT INTO fraud_signal_flags (signal_type, scope_key, reference, count_window, severity, detail)
                     VALUES (?,?,?,?,?,?)'
                )->execute([
                    'webhook_sig_fail',
                    $scope,
                    $gateway,
                    $count,
                    $count >= 15 ? 'high' : 'medium',
                    mb_substr($detail, 0, 500),
                ]);
                if (function_exists('logPlatformError')) {
                    logPlatformError('warning', 'Repeated payment webhook signature failures.', [
                        'gateway' => $gateway,
                        'ip_scope' => $scope,
                        'count' => $count,
                    ]);
                }
            }
        } catch (Throwable $e) { /* ok */ }
    }
}

/** Generic end-user block copy — no "AI fraud" marketing. */
function fraudBlockUserMessage(): string
{
    return 'This payment cannot be completed right now. Please try again later or contact support.';
}

/** @return array<int, array<string,mixed>> */
function listOpenFraudSignals(int $limit = 50): array
{
    ensureFraudSignalTable();
    try {
        $st = getDB()->prepare(
            'SELECT * FROM fraud_signal_flags WHERE resolved=0 ORDER BY created_at DESC LIMIT ?'
        );
        $st->bindValue(1, max(1, min(200, $limit)), PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
