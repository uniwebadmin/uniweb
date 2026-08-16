<?php
declare(strict_types=1);

/**
 * Merchant in-app notifications with event_key dedup and optional archive.
 * Lives outside gitignored config.php so live does not depend on config drift.
 */
function ensureNotificationSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!function_exists('schemaExecQuiet')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    schemaExecQuiet('ALTER TABLE notifications ADD COLUMN event_key VARCHAR(120) DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE notifications ADD COLUMN archived_at DATETIME DEFAULT NULL');
    schemaExecQuiet('ALTER TABLE notifications ADD INDEX idx_notif_event (merchant_id, event_key)');
    schemaExecQuiet('ALTER TABLE notifications ADD INDEX idx_notif_archived (merchant_id, archived_at)');
}

function notifyMerchant(int $merchantId, string $title, string $body, ?string $eventKey = null): void
{
    if ($merchantId <= 0 || $title === '') {
        return;
    }
    ensureNotificationSchema();
    $eventKey = $eventKey !== null ? trim($eventKey) : '';
    if ($eventKey === '') {
        $eventKey = null;
    } else {
        $eventKey = mb_substr($eventKey, 0, 120);
    }
    try {
        $db = getDB();
        if ($eventKey !== null) {
            $dup = $db->prepare('SELECT id FROM notifications WHERE merchant_id=? AND event_key=? LIMIT 1');
            $dup->execute([$merchantId, $eventKey]);
            if ($dup->fetch()) {
                return;
            }
        } else {
            $dup = $db->prepare('SELECT id FROM notifications WHERE merchant_id=? AND title=? AND is_read=0 AND (archived_at IS NULL) AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
            $dup->execute([$merchantId, $title]);
            if ($dup->fetch()) {
                return;
            }
        }
        $db->prepare('INSERT INTO notifications (merchant_id, title, message, is_read, event_key, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$merchantId, $title, $body, 0, $eventKey]);
    } catch (Throwable $e) {
        try {
            $db = getDB();
            $dup = $db->prepare('SELECT id FROM notifications WHERE merchant_id=? AND title=? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
            $dup->execute([$merchantId, $title]);
            if ($dup->fetch()) {
                return;
            }
            $db->prepare('INSERT INTO notifications (merchant_id, title, message, is_read, created_at) VALUES (?,?,?,0,NOW())')
                ->execute([$merchantId, $title, $body]);
        } catch (Throwable $e2) {
            /* notifications table may not be ready */
        }
    }
    if (function_exists('onMerchantNotificationCreated')) {
        try {
            onMerchantNotificationCreated($merchantId, $title, $body);
        } catch (Throwable $e) {
            /* WhatsApp / channel fan-out must never break the request */
        }
    }
}

/**
 * Mark old read notifications as archived (hidden from the default inbox).
 */
function archiveOldNotifications(int $merchantId = 0, int $days = 90): int
{
    ensureNotificationSchema();
    $days = max(7, min(365, $days));
    try {
        if ($merchantId > 0) {
            $st = getDB()->prepare(
                "UPDATE notifications SET archived_at=NOW()
                 WHERE merchant_id=? AND archived_at IS NULL AND is_read=1
                   AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            );
            $st->execute([$merchantId]);
        } else {
            $st = getDB()->query(
                "UPDATE notifications SET archived_at=NOW()
                 WHERE archived_at IS NULL AND is_read=1
                   AND created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
            );
        }
        return $st ? $st->rowCount() : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

if (!function_exists('createNotification')) {
    function createNotification(int $merchantId, string $title, string $body, ?string $eventKey = null): void
    {
        notifyMerchant($merchantId, $title, $body, $eventKey);
    }
} else {
    // CR-01: live config.php may still define the old 3-arg body without event_key.
    // We cannot redefine it in PHP — flag once for ops.
    static $uniwebStaleCreateNotificationLogged = false;
    if (!$uniwebStaleCreateNotificationLogged && function_exists('logPlatformError')) {
        try {
            $ref = new ReflectionFunction('createNotification');
            $file = str_replace('\\', '/', (string)$ref->getFileName());
            if ($file !== '' && str_ends_with($file, '/config.php')) {
                $uniwebStaleCreateNotificationLogged = true;
                logPlatformError(
                    'stale_createNotification',
                    'createNotification is defined in config.php (stale). Remove that function and keep includes/notifications.php — see REMIND_CR01.',
                    ['file' => $file, 'params' => $ref->getNumberOfParameters()]
                );
            }
        } catch (Throwable $e) {
            /* ignore */
        }
    }
}

if (!function_exists('notificationActionUrl')) {
    function notificationActionUrl(array $row): string
    {
        $title = strtolower((string)($row['title'] ?? ''));
        if (str_contains($title, 'kyc')) {
            return 'kyc.php';
        }
        if (str_contains($title, 'payment') || str_contains($title, 'settlement')) {
            return 'transactions.php';
        }
        return 'dashboard.php';
    }
}
