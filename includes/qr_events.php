<?php
declare(strict_types=1);

if (!function_exists('logQrEvent')) {
    /**
     * Record a QR-level event for analytics/audit.
     * Safe to call from any page that has loaded config.php + a DB connection.
     */
    function logQrEvent(PDO $db, int $qrId, int $merchantId, string $eventType, ?array $data = null): void
    {
        $validTypes = ['scan','payment','share','print','download','enable','disable','edit','duplicate','delete','expired','expiry_alert','low_scan_alert'];
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

if (!function_exists('runQrHealthAlerts')) {
    /**
     * Notify merchants about QR codes that are (a) about to expire, or (b)
     * active for 14+ days with zero scans. Each QR is alerted at most once per
     * condition — dedup is done by checking for a prior expiry_alert/low_scan_alert
     * event, so this is safe to call on every cron run (every ~10 min).
     */
    function runQrHealthAlerts(): array
    {
        $notified = ['expiry' => 0, 'low_scan' => 0];
        if (function_exists('ensureMerchantQrCodes')) {
            ensureMerchantQrCodes();
        }
        $db = getDB();

        try {
            $expiring = $db->query("SELECT q.id, q.merchant_id, q.label, q.expires_at
                FROM merchant_qr_codes q
                WHERE q.status='active' AND q.expires_at IS NOT NULL
                  AND q.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)
                  AND NOT EXISTS (
                      SELECT 1 FROM qr_code_events e
                      WHERE e.qr_code_id = q.id AND e.event_type = 'expiry_alert'
                        AND e.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
                  )")->fetchAll();
            foreach ($expiring as $qr) {
                $hours = max(1, (int)round((strtotime((string)$qr['expires_at']) - time()) / 3600));
                if (function_exists('createNotification')) {
                    createNotification((int)$qr['merchant_id'], 'QR expiring soon',
                        'Your QR "' . $qr['label'] . '" expires in about ' . $hours . ' hour(s). Update its expiry in QR Codes if you want to keep collecting.');
                }
                logQrEvent($db, (int)$qr['id'], (int)$qr['merchant_id'], 'expiry_alert', ['hours_left' => $hours]);
                $notified['expiry']++;
            }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'qr_expiry_alert_failed: ' . $e->getMessage());
            }
        }

        try {
            $lowScan = $db->query("SELECT q.id, q.merchant_id, q.label
                FROM merchant_qr_codes q
                WHERE q.status='active' AND q.scan_count = 0 AND q.qr_type != 'instant_upi'
                  AND q.created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)
                  AND NOT EXISTS (
                      SELECT 1 FROM qr_code_events e
                      WHERE e.qr_code_id = q.id AND e.event_type = 'low_scan_alert'
                  )")->fetchAll();
            foreach ($lowScan as $qr) {
                if (function_exists('createNotification')) {
                    createNotification((int)$qr['merchant_id'], 'QR not scanned yet',
                        'Your QR "' . $qr['label'] . '" has not been scanned in 14+ days. Print/share it from QR Codes, or disable it if it is no longer used.');
                }
                logQrEvent($db, (int)$qr['id'], (int)$qr['merchant_id'], 'low_scan_alert');
                $notified['low_scan']++;
            }
        } catch (Throwable $e) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'qr_low_scan_alert_failed: ' . $e->getMessage());
            }
        }

        return $notified;
    }
}
