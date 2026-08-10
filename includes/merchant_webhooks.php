<?php
declare(strict_types=1);

function ensureMerchantWebhookEngine(): void
{
    // Schema changes are versioned under migrations/. Request-time DDL is forbidden.
}

function merchantWebhookSecret(array $merchant): string
{
    $secret = trim((string)($merchant['webhook_signing_secret'] ?? ''));
    if ($secret !== '') {
        return $secret;
    }
    return '';
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

function publicWebhookDestination(string $url): array
{
    $parts = parse_url($url);
    if (!$parts
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || (!empty($parts['port']) && (int)$parts['port'] !== 443)
    ) {
        return ['ok' => false, 'error' => 'Webhook URL must be a public HTTPS URL on port 443.'];
    }
    $host = strtolower((string)$parts['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local')) {
        return ['ok' => false, 'error' => 'Private webhook hosts are not allowed.'];
    }
    $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
    $ips = [];
    foreach ($records as $record) {
        $ip = $record['ip'] ?? $record['ipv6'] ?? null;
        if ($ip !== null) {
            $ips[] = $ip;
        }
    }
    if (!$ips) {
        return ['ok' => false, 'error' => 'Webhook host does not resolve.'];
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['ok' => false, 'error' => 'Webhook host resolves to a private or reserved address.'];
        }
    }
    return ['ok' => true, 'host' => $host, 'ip' => $ips[0], 'port' => 443];
}

/** @return array{ok:bool,code:int,body:string,error?:string} */
function postMerchantWebhook(string $url, string $event, string $eventId, string $payload, string $signature): array
{
    $destination = publicWebhookDestination($url);
    if (empty($destination['ok'])) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $destination['error']];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-UniWeb-Event: ' . $event,
            'X-UniWeb-Event-Id: ' . $eventId,
            'X-UniWeb-Signature: ' . $signature,
            'User-Agent: UniWeb-Webhook/1.0',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RESOLVE => [$destination['host'] . ':' . $destination['port'] . ':' . $destination['ip']],
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
    // D10: mask PII before writing to log table
    if (!function_exists('maskPiiInString') && is_file(__DIR__ . '/partner_payload.php')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    if (function_exists('maskPiiInString')) {
        $payload = maskPiiInString($payload);
        $body = maskPiiInString($body);
    }
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
    $destination = $url !== '' ? publicWebhookDestination($url) : ['ok' => false];
    if ($url === '' || empty($destination['ok'])) {
        return ['ok' => false, 'code' => 0, 'message' => 'Webhook URL not configured'];
    }

    $eventId = generateId('EVT');
    $payload = json_encode([
        'id' => $eventId,
        'event' => $event,
        'created_at' => gmdate('c'),
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) {
        return ['ok' => false, 'code' => 0, 'message' => 'Failed to encode payload'];
    }

    $secret = merchantWebhookSecret($merchant);
    if ($secret === '') {
        return ['ok' => false, 'code' => 0, 'message' => 'Webhook signing secret is not configured'];
    }
    try {
        $db->prepare(
            'INSERT INTO merchant_webhook_deliveries
             (event_id,merchant_id,event_type,destination_url,payload,payload_hash,status,next_attempt_at)
             VALUES (?,?,?,?,?,?,"queued",NOW())'
        )->execute([$eventId, $merchantId, $event, $url, $payload, hash('sha256', $payload)]);
    } catch (Throwable $e) {
        return ['ok' => false, 'code' => 0, 'message' => 'Could not queue webhook'];
    }
    return ['ok' => true, 'code' => 202, 'message' => 'Queued', 'event_id' => $eventId];
}

function processMerchantWebhookQueue(int $limit = 25): array
{
    if (!financialTablesReady()) {
        return ['processed' => 0, 'delivered' => 0, 'failed' => 0];
    }
    $db = getDB();
    $limit = max(1, min(100, $limit));
    $processed = 0;
    $delivered = 0;
    $failed = 0;
    for ($i = 0; $i < $limit; $i++) {
        $db->beginTransaction();
        try {
            $st = $db->query(
                "SELECT d.*,m.webhook_signing_secret
                 FROM merchant_webhook_deliveries d JOIN merchants m ON m.id=d.merchant_id
                 WHERE d.status IN ('queued','retry')
                   AND d.next_attempt_at<=NOW()
                   AND (d.locked_at IS NULL OR d.locked_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE))
                 ORDER BY d.id ASC LIMIT 1 FOR UPDATE"
            );
            $delivery = $st->fetch();
            if (!$delivery) {
                $db->commit();
                break;
            }
            $db->prepare("UPDATE merchant_webhook_deliveries SET status='delivering',locked_at=NOW(),attempt_count=attempt_count+1 WHERE id=?")
                ->execute([(int)$delivery['id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            break;
        }

        $secret = trim((string)($delivery['webhook_signing_secret'] ?? ''));
        $signature = $secret !== '' ? signMerchantWebhookPayload((string)$delivery['payload'], $secret) : '';
        $result = $secret !== ''
            ? postMerchantWebhook((string)$delivery['destination_url'], (string)$delivery['event_type'], (string)$delivery['event_id'], (string)$delivery['payload'], $signature)
            : ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'Signing secret unavailable'];
        $processed++;
        $attempt = (int)$delivery['attempt_count'] + 1;
        if (!empty($result['ok'])) {
            $respBody = (string)$result['body'];
            if (function_exists('maskPiiInString')) {
                $respBody = maskPiiInString($respBody);
            }
            $db->prepare(
                "UPDATE merchant_webhook_deliveries
                 SET status='delivered',response_code=?,response_body=?,last_error=NULL,delivered_at=NOW(),locked_at=NULL
                 WHERE id=?"
            )->execute([(int)$result['code'], mb_substr($respBody, 0, 2000), (int)$delivery['id']]);
            $delivered++;
        } else {
            $delays = [60, 300, 1800, 7200, 21600, 43200, 86400];
            $dead = $attempt >= 8;
            $delay = $delays[min($attempt - 1, count($delays) - 1)];
            $respBody = (string)($result['body'] ?? '');
            if (function_exists('maskPiiInString')) {
                $respBody = maskPiiInString($respBody);
            }
            $db->prepare(
                "UPDATE merchant_webhook_deliveries
                 SET status=?,response_code=?,response_body=?,last_error=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL {$delay} SECOND),locked_at=NULL
                 WHERE id=?"
            )->execute([
                $dead ? 'dead' : 'retry',
                (int)($result['code'] ?? 0) ?: null,
                mb_substr($respBody, 0, 2000) ?: null,
                mb_substr((string)($result['error'] ?? 'HTTP delivery failed'), 0, 500),
                (int)$delivery['id'],
            ]);
            $failed++;
        }
    }
    return ['processed' => $processed, 'delivered' => $delivered, 'failed' => $failed];
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

    // QR-level notification + analytics event
    notifyQrPaymentSuccess($merchantId, $txn, $linkId);
}

/** Notify merchant on a payment that came through a specific QR code, and log the event. */
function notifyQrPaymentSuccess(int $merchantId, array $txn, ?string $linkId = null): void
{
    $db = getDB();
    $paymentLinkId = (int)($txn['payment_link_id'] ?? 0);
    if ($paymentLinkId <= 0) {
        return;
    }
    $qr = $db->prepare('SELECT q.*, m.business_name, m.phone, m.email
        FROM merchant_qr_codes q
        JOIN merchants m ON m.id=q.merchant_id
        JOIN payment_links pl ON pl.qr_code_id=q.id
        WHERE pl.id=? AND q.merchant_id=? LIMIT 1');
    $qr->execute([$paymentLinkId, $merchantId]);
    $row = $qr->fetch();
    if (!$row) {
        return;
    }

    $qrId = (int)$row['id'];
    $amt = formatMoney((float)($txn['amount'] ?? 0));
    $message = 'QR payment received: ' . $amt . ' on ' . ($row['label'] ?: $row['qr_code'])
        . "\nTxn: " . ($txn['txn_id'] ?? '—') . " | UTR: " . ($txn['utr'] ?? '—');

    // Always log the payment event for analytics/audit
    try {
        $db->prepare('INSERT INTO qr_code_events (qr_code_id, merchant_id, event_type, event_data) VALUES (?,?,?,?)')
            ->execute([
                $qrId,
                $merchantId,
                'payment',
                json_encode([
                    'txn_id' => $txn['txn_id'] ?? null,
                    'amount' => (float)($txn['amount'] ?? 0),
                    'payment_method' => $txn['payment_method'] ?? null,
                    'utr' => $txn['utr'] ?? null,
                    'link_id' => $linkId,
                ], JSON_UNESCAPED_UNICODE),
            ]);
    } catch (Throwable $e) {
        logPlatformError('warning', 'qr_payment_event_log_failed: ' . $e->getMessage());
    }

    if (empty($row['notify_on_pay'])) {
        return;
    }

    $channels = array_filter(explode(',', (string)($row['notify_channels'] ?? '')));
    $phone = trim((string)($row['phone'] ?? ''));
    $email = trim((string)($row['email'] ?? ''));
    foreach ($channels as $channel) {
        if ($channel === 'email' && $email !== '') {
            notifyMerchantEmail($merchantId, 'QR Payment — ' . $amt, $message, 'payment_success');
        }
        if ($channel === 'sms' && $phone !== '' && function_exists('sendSMS')) {
            sendSMS($phone, str_replace("\n", ' ', $message));
        }
        if ($channel === 'whatsapp' && $phone !== '' && function_exists('sendWhatsAppTextMessage')) {
            sendWhatsAppTextMessage($phone, $message);
        }
        if ($channel === 'telegram' && function_exists('sendTelegramMessage')) {
            sendTelegramMessage($message);
        }
    }
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
        SUM(CASE WHEN status IN ('retry','failed','dead') THEN 1 ELSE 0 END) AS failed,
        MAX(created_at) AS last_at
        FROM merchant_webhook_deliveries WHERE merchant_id = ?");
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
    $st = getDB()->prepare("SELECT id, event_type, response_code, response_body, status,attempt_count,last_error,created_at FROM merchant_webhook_deliveries WHERE merchant_id = ? ORDER BY id DESC LIMIT {$limit}");
    $st->execute([$merchantId]);
    return $st->fetchAll() ?: [];
}

/** @return array{ok:bool,message:string,code?:int} */
function retryMerchantWebhookLog(int $logId, int $merchantId): array
{
    ensureMerchantWebhookEngine();
    $st = getDB()->prepare('SELECT * FROM merchant_webhook_deliveries WHERE id=? AND merchant_id=?');
    $st->execute([$logId, $merchantId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Webhook log not found'];
    }

    getDB()->prepare("UPDATE merchant_webhook_deliveries SET status='queued',next_attempt_at=NOW(),locked_at=NULL WHERE id=?")
        ->execute([$logId]);
    return ['ok' => true, 'code' => 202, 'message' => 'Webhook queued for retry'];
}

/** @return array{ok:bool,message:string,code?:int} */
function sendMerchantWebhookTest(int $merchantId): array
{
    return dispatchMerchantWebhook($merchantId, 'webhook.test', [
        'message' => 'UniWeb webhook test — verify signature on your server',
        'merchant_id' => $merchantId,
    ]);
}
