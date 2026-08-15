<?php
declare(strict_types=1);

/**
 * Merchant in-app notifications with optional event_key dedup.
 * Lives outside gitignored config.php so live does not depend on config drift.
 */
function notifyMerchant(int $merchantId, string $title, string $body, ?string $eventKey = null): void
{
    if ($merchantId <= 0 || $title === '') {
        return;
    }
    try {
        $db = getDB();
        if ($eventKey !== null && $eventKey !== '') {
            $dup = $db->prepare('SELECT id FROM notifications WHERE merchant_id=? AND event_key=? LIMIT 1');
            $dup->execute([$merchantId, $eventKey]);
            if ($dup->fetch()) {
                return;
            }
        } else {
            $dup = $db->prepare('SELECT id FROM notifications WHERE merchant_id=? AND title=? AND is_read=0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1');
            $dup->execute([$merchantId, $title]);
            if ($dup->fetch()) {
                return;
            }
        }
        $db->prepare('INSERT INTO notifications (merchant_id, title, message, is_read, event_key, created_at) VALUES (?,?,?,?,?,NOW())')
            ->execute([$merchantId, $title, $body, 0, $eventKey]);
    } catch (Throwable $e) {
        try {
            getDB()->prepare('INSERT INTO notifications (merchant_id, title, message, is_read, created_at) VALUES (?,?,?,0,NOW())')
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

if (!function_exists('createNotification')) {
    function createNotification(int $merchantId, string $title, string $body, ?string $eventKey = null): void
    {
        notifyMerchant($merchantId, $title, $body, $eventKey);
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
