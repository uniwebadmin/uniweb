<?php
declare(strict_types=1);

if (!function_exists('logQrEvent')) {
    /**
     * Record a QR-level event for analytics/audit.
     * Safe to call from any page that has loaded config.php + a DB connection.
     */
    function logQrEvent(PDO $db, int $qrId, int $merchantId, string $eventType, ?array $data = null): void
    {
        $validTypes = ['scan','payment','share','print','download','enable','disable','edit','duplicate','delete','expired'];
        if (!in_array($eventType, $validTypes, true)) {
            return;
        }
        try {
            $db->prepare('INSERT INTO qr_code_events (qr_code_id, merchant_id, event_type, event_data) VALUES (?,?,?,?)')
                ->execute([$qrId, $merchantId, $eventType, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'qr_event_log_failed: ' . $e->getMessage());
            }
        }
    }
}
