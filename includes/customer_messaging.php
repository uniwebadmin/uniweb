<?php
declare(strict_types=1);

function ensureMerchantOutreachLogs(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_outreach_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sent_by_admin_id INT NULL,
            merchant_id INT NOT NULL,
            channel VARCHAR(16) NOT NULL,
            subject VARCHAR(200) NULL,
            message_body TEXT NOT NULL,
            link_url VARCHAR(500) NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'sent',
            reference_type VARCHAR(32) NULL,
            reference_id VARCHAR(64) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (merchant_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function buildWhatsAppLink(string $phone, string $message, string $link = ''): string
{
    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) === 10) {
        $phone = '91' . $phone;
    }
    $text = $message;
    if ($link !== '') {
        $text .= "\n\n" . $link;
    }
    return 'https://wa.me/' . $phone . '?text=' . rawurlencode($text);
}

function sendMerchantOutreach(int $merchantId, string $message, string $channel, array $extra = []): array
{
    ensureMerchantOutreachLogs();
    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'Message is required.'];
    }
    if (!in_array($channel, ['email', 'whatsapp'], true)) {
        return ['ok' => false, 'error' => 'Invalid channel.'];
    }

    $st = getDB()->prepare('SELECT * FROM merchants WHERE id = ? AND status != ?');
    $st->execute([$merchantId, 'deleted']);
    $m = $st->fetch();
    if (!$m) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    $subject = trim((string)($extra['subject'] ?? 'Update from ' . APP_NAME));
    $link = trim((string)($extra['link_url'] ?? ''));
    $refType = $extra['reference_type'] ?? null;
    $refId = $extra['reference_id'] ?? null;
    $adminId = (int)($extra['sent_by_admin_id'] ?? ($_SESSION['admin_id'] ?? 0));

    $status = 'failed';
    $waUrl = null;

    if ($channel === 'email') {
        $email = trim((string)($m['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Merchant has no valid email on file.'];
        }
        $body = "Hi " . ($m['business_name'] ?? $m['name'] ?? 'Merchant') . ",\n\n" . $message;
        if ($link !== '') {
            $body .= "\n\nLink: " . $link;
        }
        $body .= "\n\n— " . COMPANY_LEGAL_NAME . "\n" . APP_URL;
        $status = sendPlatformEmail($email, $subject, $body) ? 'sent' : 'failed';
    } else {
        $phone = preg_replace('/\D/', '', (string)($m['phone'] ?? ''));
        if (strlen($phone) < 10) {
            return ['ok' => false, 'error' => 'Merchant has no valid mobile number on file.'];
        }
        $waUrl = buildWhatsAppLink($phone, $message, $link);
        if (getSetting('whatsapp_enabled', '0') === '1') {
            $waText = $message . ($link ? "\n\n" . $link : '');
            $status = sendWhatsAppReminder($phone, $waText) ? 'sent' : 'wa_link';
        } else {
            $status = 'wa_link';
        }
    }

    try {
        getDB()->prepare('INSERT INTO merchant_outreach_logs (sent_by_admin_id, merchant_id, channel, subject, message_body, link_url, status, reference_type, reference_id) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$adminId ?: null, $merchantId, $channel, $subject, $message, $link ?: null, $status, $refType, $refId]);
    } catch (Throwable $e) { /* ok */ }

    logStaffActivity('merchant_' . $channel, mb_substr($message, 0, 200), $merchantId, $refType, $refId);

    return [
        'ok' => $status === 'sent' || $status === 'wa_link',
        'status' => $status,
        'whatsapp_url' => $waUrl,
        'channel' => $channel,
    ];
}

function getMerchantOutreachLogs(int $merchantId, int $limit = 20): array
{
    ensureMerchantOutreachLogs();
    $st = getDB()->prepare('SELECT o.*, a.name AS staff_name FROM merchant_outreach_logs o LEFT JOIN admins a ON a.id=o.sent_by_admin_id WHERE o.merchant_id=? ORDER BY o.created_at DESC LIMIT ?');
    $st->bindValue(1, $merchantId, PDO::PARAM_INT);
    $st->bindValue(2, max(1, min(50, $limit)), PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function packLinkIssueTemplate(array $link, string $payUrl): string
{
    $label = $link['link_label'] ?? $link['payment_method'] ?? 'Payment link';
    return "Issue with payment link {$label} ({$link['link_id']}): Please check and fix. Amount: " . formatMoney((float)$link['amount']) . ". Link: {$payUrl}";
}
