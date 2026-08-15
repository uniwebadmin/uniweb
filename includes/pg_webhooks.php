<?php
declare(strict_types=1);

/** Payment gateway webhook helpers — idempotent fulfillment + audit log */

function ensurePgWebhookTables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS pg_webhook_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            gateway VARCHAR(32) NOT NULL,
            event_type VARCHAR(64) DEFAULT NULL,
            reference VARCHAR(128) DEFAULT NULL,
            link_id VARCHAR(64) DEFAULT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'received',
            payload MEDIUMTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_gateway_created (gateway, created_at),
            INDEX idx_reference (reference)
        )");
    } catch (Throwable $e) { /* ok */ }
}

function logPgWebhook(string $gateway, string $status, ?string $eventType, ?string $reference, ?string $linkId, string $payload): void
{
    ensurePgWebhookTables();
    try {
        getDB()->prepare('INSERT INTO pg_webhook_logs (gateway, event_type, reference, link_id, status, payload) VALUES (?,?,?,?,?,?)')
            ->execute([$gateway, $eventType, $reference, $linkId, $status, $payload]);
    } catch (Throwable $e) { /* ok */ }
}

function loadPaymentLinkRow(string $linkId): ?array
{
    if ($linkId === '') {
        return null;
    }
    $stmt = getDB()->prepare("SELECT pl.*, m.id AS merchant_id, m.commission_rate, m.collection_mode, m.business_name, m.account_mode, m.kyc_status
        FROM payment_links pl JOIN merchants m ON pl.merchant_id = m.id WHERE pl.link_id = ? LIMIT 1");
    $stmt->execute([$linkId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function paymentReferenceExists(string $reference): bool
{
    if ($reference === '') {
        return false;
    }
    $stmt = getDB()->prepare('SELECT id FROM transactions WHERE utr = ? LIMIT 1');
    $stmt->execute([$reference]);
    return (bool)$stmt->fetch();
}

function fulfillGatewayPayment(string $gateway, string $linkId, string $reference, string $method, float $amount): array
{
    return [
        'ok' => false,
        'error' => 'legacy_fulfillment_disabled',
        'message' => 'A bound order plus signed and server-verified provider event is required.',
    ];
}

function verifyRazorpayWebhookSignature(string $rawBody, string $signature): bool
{
    $secret = '';
    if (function_exists('getPartnerSetting')) {
        $secret = trim((string)getPartnerSetting('razorpay', 'razorpay_webhook_secret', ''));
        if ($secret === '') {
            $secret = trim((string)getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
        }
    }
    if (!$secret || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function verifyCashfreeWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
{
    $secret = function_exists('cashfreeSecretKey') ? cashfreeSecretKey() : getSetting('cashfree_secret_key', '');
    if (!$secret || $signature === '' || $timestamp === '') {
        return false;
    }
    if (!ctype_digit($timestamp)) {
        return false;
    }
    $timestampSeconds = (int)$timestamp;
    if ($timestampSeconds > 20000000000) {
        $timestampSeconds = (int)floor($timestampSeconds / 1000);
    }
    if (abs(time() - $timestampSeconds) > 300) {
        return false;
    }
    $expected = base64_encode(hash_hmac('sha256', $timestamp . $rawBody, $secret, true));
    return hash_equals($expected, $signature);
}

function parseCashfreeLinkIdFromOrder(string $orderId): string
{
    if (preg_match('/^CF_([^_]+)_/i', $orderId, $m)) {
        return $m[1];
    }
    return '';
}

function pgWebhookUrl(string $gateway): string
{
    return APP_URL . '/' . $gateway . '_webhook.php';
}

function ensurePaymentLinkAnalytics(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec('ALTER TABLE payment_links ADD COLUMN view_count INT NOT NULL DEFAULT 0');
    } catch (Throwable $e) { /* column exists */ }
}

function incrementPaymentLinkView(int $linkId): void
{
    ensurePaymentLinkAnalytics();
    try {
        getDB()->prepare('UPDATE payment_links SET view_count = view_count + 1 WHERE id = ?')->execute([$linkId]);
    } catch (Throwable $e) { /* ok */ }
}

function getPgWebhookLogs(int $limit = 50, ?string $gateway = null): array
{
    ensurePgWebhookTables();
    $sql = 'SELECT * FROM pg_webhook_logs';
    $params = [];
    if ($gateway) {
        $sql .= ' WHERE gateway = ?';
        $params[] = $gateway;
    }
    $sql .= ' ORDER BY created_at DESC LIMIT ' . max(1, min(200, $limit));
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Re-attempt fulfillment from a stored webhook log row (admin retry). */
function reprocessPgWebhookLog(int $logId): array
{
    ensurePgWebhookTables();
    $st = getDB()->prepare('SELECT * FROM pg_webhook_logs WHERE id = ?');
    $st->execute([$logId]);
    $log = $st->fetch();
    if (!$log) {
        return ['ok' => false, 'error' => 'Webhook log not found'];
    }

    $gateway = (string)$log['gateway'];
    $linkId = (string)($log['link_id'] ?? '');
    $reference = (string)($log['reference'] ?? '');
    $amount = 0.0;
    $parsed = json_decode((string)($log['payload'] ?? ''), true);

    if (is_array($parsed)) {
        if ($gateway === 'razorpay' && isset($parsed['payload'])) {
            $entity = $parsed['payload']['payment']['entity'] ?? $parsed['payload']['order']['entity'] ?? [];
            $linkId = $linkId ?: (string)($entity['notes']['link_id'] ?? '');
            $reference = $reference ?: (string)($entity['id'] ?? '');
            $amount = isset($entity['amount']) ? ((float)$entity['amount'] / 100) : 0.0;
        } elseif ($gateway === 'cashfree') {
            $order = $parsed['data']['order'] ?? $parsed['order'] ?? $parsed['data'] ?? $parsed;
            $orderId = (string)($order['order_id'] ?? $parsed['data']['order_id'] ?? '');
            $linkId = $linkId ?: (string)($order['order_tags']['link_id'] ?? $parsed['data']['order_tags']['link_id'] ?? parseCashfreeLinkIdFromOrder($orderId));
            $reference = $reference ?: $orderId;
            $amount = (float)($order['order_amount'] ?? $parsed['data']['order_amount'] ?? 0);
        } elseif ($gateway === 'payu') {
            $linkId = $linkId ?: (string)($parsed['udf1'] ?? '');
            $reference = $reference ?: (string)($parsed['mihpayid'] ?? $parsed['txnid'] ?? '');
            $amount = (float)($parsed['amount'] ?? 0);
        }
    }

    if ($linkId === '' || $reference === '') {
        return ['ok' => false, 'error' => 'Cannot retry — missing link_id or payment reference in log.'];
    }

    $result = fulfillGatewayPayment($gateway, $linkId, $reference, $gateway, $amount);
    logPgWebhook($gateway, $result['ok'] ? 'reprocessed' : 'retry_failed', (string)($log['event_type'] ?? 'retry'), $reference, $linkId, json_encode($result));
    if (isAdminLoggedIn()) {
        logStaffActivity('webhook_reprocessed', $gateway . ' ref ' . $reference, null, 'payment_link', $linkId);
    }
    return $result;
}

/** Retry all unmatched webhooks in report (capped). */
function reprocessUnmatchedWebhooks(int $days = 7, int $limit = 15): array
{
    $report = getPgReconciliationReport($days);
    $results = [];
    foreach (array_slice($report['unmatched_webhooks'], 0, max(1, min(50, $limit))) as $w) {
        $results[] = ['id' => (int)$w['id'], 'result' => reprocessPgWebhookLog((int)$w['id'])];
    }
    return $results;
}

function getPlatformHealth(): array
{
    $db = getDB();
    $success24h = 0;
    $failed24h = 0;
    try {
        $success24h = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
        $failed24h = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    $maintenance = getSetting('maintenance_mode', '0') === '1';
    $gateways = [
        'razorpay' => isGatewayConfigured('razorpay'),
        'cashfree' => isGatewayConfigured('cashfree'),
        'payu' => isGatewayConfigured('payu'),
        'axis' => isGatewayConfigured('axis'),
    ];
    $anyGateway = in_array(true, $gateways, true);

    return [
        'operational' => !$maintenance,
        'maintenance' => $maintenance,
        'gateways' => $gateways,
        'any_gateway' => $anyGateway,
        'success_24h' => $success24h,
        'failed_24h' => $failed24h,
        'version' => APP_VERSION,
    ];
}
