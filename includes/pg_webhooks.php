<?php
declare(strict_types=1);

/** Payment gateway webhook helpers — idempotent fulfillment + audit log */

if (!function_exists('cryptoTimingSafeEqual') && is_file(__DIR__ . '/crypto_compare.php')) {
    require_once __DIR__ . '/crypto_compare.php';
}
if (!function_exists('partnerWebhookSecretCandidates') && is_file(__DIR__ . '/webhook_secret_rotation.php')) {
    require_once __DIR__ . '/webhook_secret_rotation.php';
}

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

/** Safe audit row when signature/hash verification fails — no raw body or secrets. */
function logPgWebhookVerifyFailure(
    string $gateway,
    string $status,
    ?string $eventType = null,
    ?string $reference = null,
    ?string $linkId = null,
    array $context = []
): void {
    $context += [
        'verify' => $status,
        'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        'method' => substr((string)($_SERVER['REQUEST_METHOD'] ?? ''), 0, 10),
    ];
    logPgWebhook(
        $gateway,
        $status,
        $eventType,
        $reference,
        $linkId,
        json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}'
    );
    if (!function_exists('recordWebhookSignatureFailure') && is_file(__DIR__ . '/fraud_signals.php')) {
        require_once __DIR__ . '/fraud_signals.php';
    }
    if (function_exists('recordWebhookSignatureFailure')) {
        recordWebhookSignatureFailure($gateway, (string)($context['event_id'] ?? ''));
    }
}

/** Max webhook event age (seconds) from partner payload created_at — replay guard; 0 = disabled. */
function pgWebhookMaxEventAgeSeconds(): int
{
    return 86400;
}

/** Timestamp header skew window (seconds) — Cashfree-style; configurable 300–900. */
function pgWebhookTimestampSkewSeconds(): int
{
    return 300;
}

/** Basic abuse guard on inbound webhook routes — not sole security control. */
function pgWebhookAbuseBlocked(?string $partner = null): bool
{
    if (!function_exists('checkRateLimit') && is_file(__DIR__ . '/rate_limiter.php')) {
        require_once __DIR__ . '/rate_limiter.php';
    }
    if (!function_exists('checkRateLimit')) {
        return false;
    }
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
    $scope = 'pg_webhook_' . ($partner !== null && $partner !== '' ? strtolower(trim($partner)) : 'all');
    return !checkRateLimit($ip, $scope, 180);
}

/**
 * Reject webhook with JSON body — no secrets logged.
 *
 * @return never
 */
function pgWebhookRejectJson(string $gateway, string $reason, int $httpCode, ?string $eventType = null, ?string $reference = null): void
{
    logPgWebhookVerifyFailure($gateway, $reason, $eventType, $reference, null, [
        'http' => $httpCode,
    ]);
    if (!function_exists('jsonResponse')) {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    jsonResponse(['error' => $httpCode === 401 || $httpCode === 403 ? 'Invalid signature' : 'Rejected'], $httpCode);
}

/** Optional replay guard from partner-supplied created_at (unix seconds or ISO string). */
function pgWebhookEventTooOld(?string $createdAt): bool
{
    $maxAge = pgWebhookMaxEventAgeSeconds();
    if ($maxAge <= 0 || $createdAt === null || trim($createdAt) === '') {
        return false;
    }
    $ts = is_numeric($createdAt) ? (int)$createdAt : strtotime($createdAt);
    if ($ts === false || $ts <= 0) {
        return false;
    }
    if ((int)$ts > 20000000000) {
        $ts = (int)floor($ts / 1000);
    }
    return (time() - $ts) > $maxAge;
}

/** Read raw request body once — always before json_decode. */
function pgWebhookReadRawBody(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $cached = file_get_contents('php://input');
    if ($cached === false) {
        $cached = '';
    }
    return $cached;
}

/** Normalized inbound headers (lowercase keys). Never log values that look like secrets. */
function pgWebhookHeadersFromServer(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        $headers[$name] = is_string($value) ? $value : (string)$value;
    }
    return $headers;
}

/**
 * Central partner webhook signature verify — timing-safe compare inside adapters.
 *
 * Inbound policy (partner → UniWeb):
 * - Invalid signature / fatal parse → HTTP 401/403 (partner may retry; handler stays idempotent).
 * - Valid + duplicate event_id → HTTP 200 after durable dedup row exists.
 * - Valid + new → HTTP 200 via webhookFastAck after registerGatewayEvent + recordWebhookEvent persist.
 *
 * @param array<string,mixed>|null $parsedForm Required for PayU (reverse hash on form fields, not raw body).
 * @return array{ok:bool,scheme:string,http_code:int,reason:string}
 */
/**
 * Adapter entry — bool wrapper for verifyWebhookSignature(partner, rawBody, headers).
 *
 * @param array<string,mixed>|null $parsedForm PayU form fields when not JSON body.
 */
function verifyWebhookSignature(string $partnerKey, string $rawBody, array $headers, ?array $parsedForm = null): bool
{
    $normalized = [];
    foreach ($headers as $name => $value) {
        $normalized[strtolower(str_replace('_', '-', (string)$name))] = is_string($value) ? $value : (string)$value;
    }
    $result = pgWebhookVerifyPartner($partnerKey, $rawBody, $parsedForm, $normalized);
    return !empty($result['ok']);
}

function pgWebhookVerifyPartner(string $partner, string $rawBody, ?array $parsedForm = null, ?array $headers = null): array
{
    $partner = strtolower(trim($partner));
    if (pgWebhookAbuseBlocked($partner)) {
        return [
            'ok' => false,
            'scheme' => 'rate_limit',
            'http_code' => 429,
            'reason' => 'rate_limited',
        ];
    }
    $headers = $headers ?? pgWebhookHeadersFromServer();

    if ($partner === 'razorpay') {
        if ($rawBody === '') {
            return [
                'ok' => false,
                'scheme' => 'hmac_sha256_hex_body',
                'http_code' => 400,
                'reason' => 'empty_body',
            ];
        }
        $sig = (string)($headers['x-razorpay-signature'] ?? '');
        $ok = $sig !== '' && verifyRazorpayWebhookSignature($rawBody, $sig);
        return [
            'ok' => $ok,
            'scheme' => 'hmac_sha256_hex_body',
            'http_code' => 401,
            'reason' => $ok ? 'ok' : ($sig === '' ? 'missing_signature' : 'invalid_signature'),
        ];
    }

    if ($partner === 'cashfree') {
        if ($rawBody === '') {
            return [
                'ok' => false,
                'scheme' => 'hmac_sha256_b64_timestamp_body',
                'http_code' => 400,
                'reason' => 'empty_body',
            ];
        }
        $sig = (string)($headers['x-webhook-signature'] ?? '');
        $ts = (string)($headers['x-webhook-timestamp'] ?? '');
        $ok = verifyCashfreeWebhookSignature($rawBody, $sig, $ts);
        return [
            'ok' => $ok,
            'scheme' => 'hmac_sha256_b64_timestamp_body',
            'http_code' => 401,
            'reason' => $ok ? 'ok' : ($sig === '' || $ts === '' ? 'missing_signature' : 'invalid_signature_or_stale_timestamp'),
        ];
    }

    if ($partner === 'payu') {
        $form = $parsedForm ?? [];
        if ($form === [] && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $form = $decoded;
            }
        }
        if ($form === []) {
            $form = array_merge($_GET ?? [], $_POST ?? []);
        }
        $ok = $form !== [] && verifyPayUResponseHash($form);
        return [
            'ok' => $ok,
            'scheme' => 'reverse_sha512_form_hash',
            'http_code' => 401,
            'reason' => $ok ? 'ok' : 'invalid_hash',
        ];
    }

    if ($partner === 'decentro') {
        $sig = (string)($headers['x-decentro-signature'] ?? $headers['x-webhook-signature'] ?? $headers['decentro-signature'] ?? '');
        $ok = verifyDecentroWebhookSignature($rawBody, $sig);
        return [
            'ok' => $ok,
            'scheme' => 'hmac_sha256_hex_body',
            'http_code' => 401,
            'reason' => $ok ? 'ok' : 'invalid_signature',
        ];
    }

    if ($partner === 'axis') {
        $sig = (string)($headers['x-axis-signature'] ?? $headers['x-webhook-signature'] ?? '');
        $ok = verifyAxisWebhookSignature($rawBody, $sig);
        return [
            'ok' => $ok,
            'scheme' => 'hmac_sha256_hex_body',
            'http_code' => 401,
            'reason' => $ok ? 'ok' : ($sig === '' ? 'missing_signature' : 'invalid_signature'),
        ];
    }

    return [
        'ok' => false,
        'scheme' => 'unsupported',
        'http_code' => 403,
        'reason' => 'unsupported_partner',
    ];
}

function verifyDecentroWebhookSignature(string $rawBody, string $signature): bool
{
    if ($signature === '') {
        return function_exists('isDecentroSandboxEnvironment') && isDecentroSandboxEnvironment();
    }
    if (!function_exists('verifyWithPartnerWebhookSecrets')) {
        if (!function_exists('decentroClientSecret') && is_file(__DIR__ . '/partner_control.php')) {
            require_once __DIR__ . '/partner_control.php';
        }
        $clientSecret = function_exists('decentroClientSecret') ? decentroClientSecret() : '';
        if ($clientSecret === '') {
            return function_exists('isDecentroSandboxEnvironment') && isDecentroSandboxEnvironment();
        }
        return cryptoVerifyHmacSha256Hex($rawBody, $clientSecret, $signature);
    }
    return verifyWithPartnerWebhookSecrets('decentro', static function (string $secret) use ($rawBody, $signature): bool {
        return cryptoVerifyHmacSha256Hex($rawBody, $secret, $signature);
    });
}

function verifyAxisWebhookSignature(string $rawBody, string $signature): bool
{
    if ($signature === '') {
        return false;
    }
    return verifyWithPartnerWebhookSecrets('axis', static function (string $secret) use ($rawBody, $signature): bool {
        return cryptoVerifyHmacSha256Hex($rawBody, $secret, cryptoStripSha256Prefix($signature));
    });
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
    $signature = cryptoStripSha256Prefix($signature);
    if ($signature === '') {
        return false;
    }
    return verifyWithPartnerWebhookSecrets('razorpay', static function (string $secret) use ($rawBody, $signature): bool {
        return cryptoVerifyHmacSha256Hex($rawBody, $secret, $signature);
    });
}

function verifyCashfreeWebhookSignature(string $rawBody, string $signature, string $timestamp): bool
{
    if ($signature === '' || $timestamp === '') {
        return false;
    }
    if (!ctype_digit($timestamp)) {
        return false;
    }
    $timestampSeconds = (int)$timestamp;
    if ($timestampSeconds > 20000000000) {
        $timestampSeconds = (int)floor($timestampSeconds / 1000);
    }
    if (abs(time() - $timestampSeconds) > pgWebhookTimestampSkewSeconds()) {
        return false;
    }
    $secrets = function_exists('partnerWebhookSecretCandidates')
        ? partnerWebhookSecretCandidates('cashfree')
        : (function_exists('cashfreeSecretKey') ? [cashfreeSecretKey()] : []);
    return cryptoVerifyHmacSha256B64TimestampBodyAny($rawBody, $secrets, $timestamp, $signature);
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
