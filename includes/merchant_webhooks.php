<?php
declare(strict_types=1);

function ensureMerchantWebhookEngine(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    foreach ([
        'ALTER TABLE merchants ADD COLUMN webhook_url VARCHAR(500) NULL',
        'ALTER TABLE merchants ADD COLUMN webhook_signing_secret VARCHAR(64) NULL',
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) { /* ok */ }
    }
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS merchant_webhook_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            event_type VARCHAR(64) NOT NULL,
            payload MEDIUMTEXT,
            response_code INT NULL,
            response_body TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (merchant_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function merchantWebhookSecret(array $merchant): string
{
    $secret = trim((string)($merchant['webhook_signing_secret'] ?? ''));
    if ($secret !== '') {
        return $secret;
    }
    return (string)($merchant['api_secret'] ?? $merchant['test_api_secret'] ?? '');
}

function signMerchantWebhookPayload(string $payload, string $secret): string
{
    return hash_hmac('sha256', $payload, $secret);
}

function verifyMerchantWebhookSignature(string $payload, string $signature, string $secret): bool
{
    if ($signature === '' || $secret === '') {
        return false;
    }
    $expected = signMerchantWebhookPayload($payload, $secret);
    return hash_equals($expected, $signature);
}

function merchantWebhookDeliveryOk(?int $code): bool
{
    return $code !== null && $code >= 200 && $code < 300;
}

/** @return array{ok:bool,code:int,body:string,error?:string} */
function postMerchantWebhook(string $url, string $event, string $payload, string $signature): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-UniWeb-Event: ' . $event,
            'X-UniWeb-Signature: ' . $signature,
            'User-Agent: UniWeb-Webhook/1.0',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'code' => $code ?: 0, 'body' => $body, 'error' => $err];
    }
    return ['ok' => merchantWebhookDeliveryOk($code ?: null), 'code' => $code, 'body' => $body];
}

function logMerchantWebhookDelivery(int $merchantId, string $event, string $payload, int $code, string $body): void
{
    try {
        getDB()->prepare('INSERT INTO merchant_webhook_logs (merchant_id, event_type, payload, response_code, response_body) VALUES (?,?,?,?,?)')
            ->execute([$merchantId, $event, $payload, $code ?: null, $body !== '' ? substr($body, 0, 2000) : null]);
    } catch (Throwable $e) { /* ok */ }
}

/** @return array{ok:bool,code:int,message:string} */
function dispatchMerchantWebhook(int $merchantId, string $event, array $data): array
{
    ensureMerchantWebhookEngine();
    $db = getDB();
    $st = $db->prepare('SELECT id, webhook_url, webhook_signing_secret, api_secret, test_api_secret FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    if (!$merchant) {
        return ['ok' => false, 'code' => 0, 'message' => 'Merchant not found'];
    }
    $url = trim((string)($merchant['webhook_url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'code' => 0, 'message' => 'Webhook URL not configured'];
    }

    $payload = json_encode([
        'event' => $event,
        'timestamp' => time(),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'code' => 0, 'message' => 'Failed to encode payload'];
    }

    $secret = merchantWebhookSecret($merchant);
    $signature = signMerchantWebhookPayload($payload, $secret);
    $result = postMerchantWebhook($url, $event, $payload, $signature);
    logMerchantWebhookDelivery($merchantId, $event, $payload, $result['code'], $result['body']);

    $msg = $result['ok']
        ? 'Delivered HTTP ' . $result['code']
        : ($result['error'] ?? 'Delivery failed HTTP ' . ($result['code'] ?: '0'));
    return ['ok' => $result['ok'], 'code' => $result['code'], 'message' => $msg];
}

function notifyMerchantPaymentSuccess(int $merchantId, array $txn, ?string $linkId = null): void
{
    $wantWebhook = !function_exists('merchantWantsNotify') || merchantWantsNotify($merchantId, 'payment_success', 'webhook');
    if ($wantWebhook) {
        dispatchMerchantWebhook($merchantId, 'payment.success', [
            'txn_id' => $txn['txn_id'] ?? '',
            'amount' => (float)($txn['amount'] ?? 0),
            'status' => $txn['status'] ?? 'success',
            'payment_method' => $txn['payment_method'] ?? '',
            'utr' => $txn['utr'] ?? '',
            'link_id' => $linkId,
        ]);
    }
    $amt = formatMoney((float)($txn['amount'] ?? 0));
    $txnId = (string)($txn['txn_id'] ?? '');
    notifyMerchantEmail(
        $merchantId,
        'Payment received — ' . $amt,
        "A payment of {$amt} was successful.\nTransaction ID: {$txnId}\nUTR: " . ($txn['utr'] ?? '—') . ($linkId ? "\nLink: {$linkId}" : ''),
        'payment_success'
    );
}

/** @return array{total:int,failed:int,last_at:?string,url:?string,configured:bool} */
function getMerchantWebhookSummary(int $merchantId): array
{
    ensureMerchantWebhookEngine();
    $db = getDB();
    $st = $db->prepare('SELECT webhook_url FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $url = trim((string)($st->fetchColumn() ?: ''));

    $row = $db->prepare("SELECT COUNT(*) AS total,
        SUM(CASE WHEN response_code IS NULL OR response_code < 200 OR response_code >= 300 THEN 1 ELSE 0 END) AS failed,
        MAX(created_at) AS last_at
        FROM merchant_webhook_logs WHERE merchant_id = ?");
    $row->execute([$merchantId]);
    $stats = $row->fetch() ?: [];

    return [
        'total' => (int)($stats['total'] ?? 0),
        'failed' => (int)($stats['failed'] ?? 0),
        'last_at' => $stats['last_at'] ?? null,
        'url' => $url !== '' ? $url : null,
        'configured' => $url !== '' && filter_var($url, FILTER_VALIDATE_URL),
    ];
}

/** @return list<array<string,mixed>> */
function getMerchantWebhookLogs(int $merchantId, int $limit = 30): array
{
    ensureMerchantWebhookEngine();
    $limit = max(1, min(100, $limit));
    $st = getDB()->prepare("SELECT id, event_type, response_code, response_body, created_at FROM merchant_webhook_logs WHERE merchant_id = ? ORDER BY id DESC LIMIT {$limit}");
    $st->execute([$merchantId]);
    return $st->fetchAll() ?: [];
}

/** @return array{ok:bool,message:string,code?:int} */
function retryMerchantWebhookLog(int $logId, int $merchantId): array
{
    ensureMerchantWebhookEngine();
    $st = getDB()->prepare('SELECT l.*, m.webhook_url, m.webhook_signing_secret, m.api_secret, m.test_api_secret
        FROM merchant_webhook_logs l
        JOIN merchants m ON m.id = l.merchant_id
        WHERE l.id = ? AND l.merchant_id = ?');
    $st->execute([$logId, $merchantId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Webhook log not found'];
    }

    $url = trim((string)($row['webhook_url'] ?? ''));
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'message' => 'Webhook URL not configured'];
    }

    $payload = (string)($row['payload'] ?? '');
    if ($payload === '') {
        return ['ok' => false, 'message' => 'Empty payload in log'];
    }

    $event = (string)($row['event_type'] ?? 'retry');
    $secret = merchantWebhookSecret($row);
    $signature = signMerchantWebhookPayload($payload, $secret);
    $result = postMerchantWebhook($url, $event, $payload, $signature);
    logMerchantWebhookDelivery($merchantId, $event . '.retry', $payload, $result['code'], $result['body']);

    return [
        'ok' => $result['ok'],
        'code' => $result['code'],
        'message' => $result['ok'] ? 'Retry delivered HTTP ' . $result['code'] : ($result['error'] ?? 'Retry failed HTTP ' . ($result['code'] ?: '0')),
    ];
}

/** @return array{ok:bool,message:string,code?:int} */
function sendMerchantWebhookTest(int $merchantId): array
{
    return dispatchMerchantWebhook($merchantId, 'webhook.test', [
        'message' => 'UniWeb webhook test — verify signature on your server',
        'merchant_id' => $merchantId,
    ]);
}
