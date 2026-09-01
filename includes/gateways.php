<?php
declare(strict_types=1);

require_once __DIR__ . '/rbl.php';
if (is_file(__DIR__ . '/rbl_workflow.php')) {
    require_once __DIR__ . '/rbl_workflow.php';
}
if (!function_exists('isGatewayActive')) {
    require_once __DIR__ . '/payment_methods.php';
}
if (!function_exists('getPartnerSetting') && is_file(__DIR__ . '/partner_control.php')) {
    require_once __DIR__ . '/partner_control.php';
}
if (!function_exists('cryptoTimingSafeEqual') && is_file(__DIR__ . '/crypto_compare.php')) {
    require_once __DIR__ . '/crypto_compare.php';
}

function createRazorpayOrder(float $amount, string $receipt, array $notes = []): ?array
{
    if (!isGatewayConfigured('razorpay')) {
        return null;
    }
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret) return null;

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => (int)($amount * 100),
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => $notes,
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function verifyRazorpayPayment(string $orderId, string $paymentId, string $signature): bool
{
    $secret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$secret) return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    return hash_equals($expected, $signature);
}

function fetchRazorpayPayment(string $paymentId): ?array
{
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret || $paymentId === '') {
        return null;
    }
    $ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || $response === '') {
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function createRazorpayRefund(string $paymentId, float $amount, string $receipt): ?array
{
    if (function_exists('pgOutboundCircuitBlocked')) {
        $blocked = pgOutboundCircuitBlocked('razorpay', 'refund_create');
        if ($blocked !== null) {
            return null;
        }
    }
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret || $paymentId === '' || $amount <= 0) {
        return null;
    }
    $ch = curl_init('https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId) . '/refund');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode([
            'amount' => (int)round($amount * 100),
            'speed' => 'normal',
            'receipt' => $receipt,
            'notes' => ['uniweb_refund_id' => $receipt],
        ]),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || $response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('razorpay', false, $http ?: null);
        }
        return null;
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('razorpay', true, $http);
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function fetchRazorpayRefund(string $paymentId, string $refundId): ?array
{
    if (function_exists('pgOutboundCircuitBlocked')) {
        $blocked = pgOutboundCircuitBlocked('razorpay', 'refund_status');
        if ($blocked !== null) {
            return null;
        }
    }
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret || $paymentId === '' || $refundId === '') {
        return null;
    }
    $url = 'https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId) . '/refunds/' . rawurlencode($refundId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || $response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('razorpay', false, $http ?: null);
        }
        return null;
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('razorpay', true, $http);
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function razorpayXRequest(string $method, string $path, ?array $body = null, array $headers = []): ?array
{
    $keyId = getPartnerSetting('razorpayx', 'razorpayx_key_id', '') ?: getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpayx', 'razorpayx_key_secret', '') ?: getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret) {
        return null;
    }
    $ch = curl_init('https://api.razorpay.com/v1' . $path);
    $httpHeaders = array_merge(['Content-Type: application/json'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => $httpHeaders,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || $response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('razorpay', false, $http ?: null);
        }
        return null;
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('razorpay', true, $http);
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function createRazorpayXPayout(array $merchant, array $bank, float $amount, string $reference): ?array
{
    if (!function_exists('razorpayxPlatformAccountNumber')) {
        require_once __DIR__ . '/payout_partner_api.php';
    }
    $platformAccount = razorpayxPlatformAccountNumber();
    if ($platformAccount === '' || $amount <= 0) {
        return null;
    }
    $db = getDB();
    $contactId = trim((string)($bank['razorpay_contact_id'] ?? ''));
    if ($contactId === '') {
        $contact = razorpayXRequest('POST', '/contacts', [
            'name' => (string)($bank['account_holder'] ?? $merchant['business_name'] ?? 'Merchant'),
            'email' => (string)($merchant['email'] ?? COMPANY_SUPPORT_EMAIL),
            'contact' => (string)($merchant['phone'] ?? ''),
            'type' => 'vendor',
            'reference_id' => (string)($merchant['merchant_code'] ?? ('merchant_' . $merchant['id'])),
            'notes' => ['merchant_id' => (string)$merchant['id']],
        ]);
        $contactId = (string)($contact['id'] ?? '');
        if ($contactId === '') {
            return null;
        }
        $db->prepare('UPDATE bank_accounts SET razorpay_contact_id=? WHERE id=?')->execute([$contactId, (int)$bank['id']]);
    }
    $fundAccountId = trim((string)($bank['razorpay_fund_account_id'] ?? ''));
    if ($fundAccountId === '') {
        $fund = razorpayXRequest('POST', '/fund_accounts', [
            'contact_id' => $contactId,
            'account_type' => 'bank_account',
            'bank_account' => [
                'name' => (string)$bank['account_holder'],
                'ifsc' => strtoupper((string)$bank['ifsc_code']),
                'account_number' => sensitiveDecrypt((string)$bank['account_number']),
            ],
        ]);
        $fundAccountId = (string)($fund['id'] ?? '');
        if ($fundAccountId === '') {
            return null;
        }
        $db->prepare('UPDATE bank_accounts SET razorpay_fund_account_id=? WHERE id=?')->execute([$fundAccountId, (int)$bank['id']]);
    }
    return razorpayXRequest('POST', '/payouts', [
        'account_number' => $platformAccount,
        'fund_account_id' => $fundAccountId,
        'amount' => (int)round($amount * 100),
        'currency' => 'INR',
        'mode' => $amount <= 500000 ? 'IMPS' : 'NEFT',
        'purpose' => 'payout',
        'queue_if_low_balance' => true,
        'reference_id' => $reference,
        'narration' => 'UniWeb Settlement',
        'notes' => ['settlement_batch' => $reference, 'merchant_id' => (string)$merchant['id']],
    ], ['X-Payout-Idempotency: ' . hash('sha256', $reference)]);
}

function fetchRazorpayXPayout(string $payoutId): ?array
{
    return $payoutId !== '' ? razorpayXRequest('GET', '/payouts/' . rawurlencode($payoutId)) : null;
}

function cashfreeApiBase(): string
{
    return getPartnerEnvironment('cashfree', 'production') === 'sandbox'
        ? 'https://sandbox.cashfree.com/pg'
        : 'https://api.cashfree.com/pg';
}

function createCashfreeOrder(string $orderId, float $amount, string $customerPhone, string $customerEmail, string $returnUrl, string $linkId = ''): ?array
{
    if (!isGatewayConfigured('cashfree')) {
        return null;
    }
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret) return null;

    $phone = preg_replace('/\D/', '', $customerPhone);
    if (strlen($phone) === 10) $phone = '91' . $phone;
    if (!$phone) $phone = '919837456654';
    if (!$customerEmail) $customerEmail = COMPANY_SUPPORT_EMAIL;

    $body = [
        'order_id' => $orderId,
        'order_amount' => round($amount, 2),
        'order_currency' => 'INR',
        'customer_details' => [
            'customer_id' => 'cust_' . $phone,
            'customer_phone' => $phone,
            'customer_email' => $customerEmail,
        ],
        'order_meta' => ['return_url' => $returnUrl],
    ];
    if ($linkId !== '') {
        $body['order_tags'] = ['link_id' => $linkId];
    }

    $ch = curl_init(cashfreeApiBase() . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function fetchCashfreeOrder(string $orderId): ?array
{
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret) return null;

    $ch = curl_init(cashfreeApiBase() . '/orders/' . rawurlencode($orderId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

function fetchCashfreeOrderPayments(string $orderId): array
{
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret || $orderId === '') {
        return [];
    }
    $ch = curl_init(cashfreeApiBase() . '/orders/' . rawurlencode($orderId) . '/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || $response === '') {
        return [];
    }
    $data = json_decode($response, true);
    return is_array($data) ? array_values($data) : [];
}

function createCashfreeRefund(string $orderId, float $amount, string $refundId, string $note = ''): ?array
{
    if (function_exists('pgOutboundCircuitBlocked')) {
        $blocked = pgOutboundCircuitBlocked('cashfree', 'refund_create');
        if ($blocked !== null) {
            return null;
        }
    }
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret || $orderId === '' || $amount <= 0 || $refundId === '') {
        return null;
    }
    $body = [
        'refund_amount' => round($amount, 2),
        'refund_id' => $refundId,
        'refund_note' => $note !== '' ? mb_substr($note, 0, 255) : ('UniWeb refund ' . $refundId),
    ];
    $ch = curl_init(cashfreeApiBase() . '/orders/' . rawurlencode($orderId) . '/refunds');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http < 200 || $http >= 300 || $response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('cashfree', false, $http ?: null);
        }
        if (function_exists('logPartnerErrorAndMap') && is_file(__DIR__ . '/partner_error_mapping.php')) {
            require_once __DIR__ . '/partner_error_mapping.php';
            logPartnerErrorAndMap('cashfree', 'create_refund', $response ?: ['http' => $http], $http);
        }
        return null;
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('cashfree', true, $http);
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function fetchCashfreeRefund(string $orderId, string $refundId): ?array
{
    if (function_exists('pgOutboundCircuitBlocked')) {
        $blocked = pgOutboundCircuitBlocked('cashfree', 'refund_status');
        if ($blocked !== null) {
            return null;
        }
    }
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret || $orderId === '' || $refundId === '') {
        return null;
    }
    $ch = curl_init(cashfreeApiBase() . '/orders/' . rawurlencode($orderId) . '/refunds/' . rawurlencode($refundId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || $response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('cashfree', false, $http ?: null);
        }
        return null;
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('cashfree', true, $http);
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

/** PayU postservice — shared for refund + status (no secrets in return value). */
function payuPostserviceRequest(string $command, string $var1, string $var2 = '', string $var3 = ''): ?array
{
    if (function_exists('pgOutboundCircuitBlocked')) {
        $op = str_contains($command, 'refund') ? 'refund_create' : 'refund_status';
        $blocked = pgOutboundCircuitBlocked('payu', $op);
        if ($blocked !== null) {
            return null;
        }
    }
    $c = payuCredentials();
    if ($c['key'] === '' || $c['salt'] === '' || $command === '' || $var1 === '') {
        return null;
    }
    if (!function_exists('partnerApiExecuteWithRetry') && is_file(__DIR__ . '/partner_api_retry.php')) {
        require_once __DIR__ . '/partner_api_retry.php';
    }
    $hashStr = $c['key'] . '|' . $command . '|' . $var1 . '|' . $c['salt'];
    $hash = strtolower(hash('sha512', $hashStr));
    $post = ['key' => $c['key'], 'command' => $command, 'var1' => $var1, 'hash' => $hash];
    if ($var2 !== '') {
        $post['var2'] = $var2;
    }
    if ($var3 !== '') {
        $post['var3'] = $var3;
    }
    $url = payuBaseUrl() . '/merchant/postservice?form=2';
    $exec = function_exists('partnerApiExecuteWithRetry')
        ? partnerApiExecuteWithRetry(static function () use ($url, $post): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => http_build_query($post),
                CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
                CURLOPT_TIMEOUT => 30,
            ]);
            $response = (string)curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            return ['http' => $http, 'body' => $response, 'curl_error' => $err !== '' ? $err : null];
        })
        : null;
    if (is_array($exec)) {
        $response = (string)($exec['body'] ?? '');
        $http = (int)($exec['http'] ?? 0);
    } else {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = (string)curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($response === '') {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('payu', false, $http ?: null);
        }
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('payu', false, $http ?: null);
        }
        if (function_exists('logPartnerErrorAndMap') && is_file(__DIR__ . '/partner_error_mapping.php')) {
            require_once __DIR__ . '/partner_error_mapping.php';
            logPartnerErrorAndMap('payu', $command, mb_substr($response, 0, 500), $http);
        }
        return null;
    }
    $payuOk = in_array(strtolower((string)($decoded['status'] ?? '')), ['success', '1'], true);
    if (function_exists('pgOutboundCircuitRecord')) {
        if ($payuOk && ($http >= 200 && $http < 300)) {
            pgOutboundCircuitRecord('payu', true, $http);
        } elseif (function_exists('pgOutboundCircuitCountsAsFailure') && pgOutboundCircuitCountsAsFailure($http ?: null)) {
            pgOutboundCircuitRecord('payu', false, $http ?: null);
        }
    }
    if (!$payuOk) {
        if (function_exists('logPartnerErrorAndMap') && is_file(__DIR__ . '/partner_error_mapping.php')) {
            require_once __DIR__ . '/partner_error_mapping.php';
            logPartnerErrorAndMap('payu', $command, $decoded, $http);
        }
    }
    return $decoded;
}

function createPayuRefund(string $paymentId, float $amount, string $tokenId): ?array
{
    if ($paymentId === '' || $amount <= 0 || $tokenId === '') {
        return null;
    }
    $amountStr = number_format($amount, 2, '.', '');
    $response = payuPostserviceRequest('cancel_refund_transaction', $paymentId, $tokenId, $amountStr);
    if (!$response) {
        return null;
    }
    $status = strtolower((string)($response['status'] ?? ''));
    if ($status !== 'success' && $status !== '1') {
        return null;
    }
    $response['provider_refund_id'] = $tokenId;
    $response['amount'] = $amount;
    return $response;
}

function fetchPayuRefundStatus(string $paymentId, string $tokenId): ?array
{
    if ($paymentId === '' || $tokenId === '') {
        return null;
    }
    return payuPostserviceRequest('check_action_status', $paymentId, $tokenId);
}

function ensureGatewaySubmissionsTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS gateway_submissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            gateway VARCHAR(40) NOT NULL,
            status ENUM('draft','submitted','approved','rejected','pending_review') DEFAULT 'submitted',
            payload LONGTEXT,
            admin_id INT,
            admin_notes TEXT,
            gateway_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id),
            INDEX idx_gateway (gateway)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
    try {
        getDB()->exec("ALTER TABLE gateway_submissions MODIFY gateway VARCHAR(40) NOT NULL");
    } catch (Throwable $e) { /* ok if already varchar */ }
}

function submitMerchantToGateway(int $merchantId, string $gateway, int $adminId, string $notes = '', string $forwardSource = 'manual'): bool
{
    ensureGatewaySubmissionsTable();

    if (!function_exists('gatewaySubmissionsInsertGate') && is_file(__DIR__ . '/gateway_submissions_workflow.php')) {
        require_once __DIR__ . '/gateway_submissions_workflow.php';
    }
    if (function_exists('gatewaySubmissionsInsertGate')) {
        $gate = gatewaySubmissionsInsertGate($merchantId, $gateway);
        if (empty($gate['ok'])) {
            error_log('submitMerchantToGateway blocked: ' . ($gate['error'] ?? 'gate'));
            return false;
        }
        $gateway = (string)($gate['key'] ?? $gateway);
    }

    $db = getDB();

    $existing = $db->prepare("SELECT id FROM gateway_submissions WHERE merchant_id=? AND gateway=? AND status IN ('submitted','pending_review','approved') ORDER BY id DESC LIMIT 1");
    $existing->execute([$merchantId, $gateway]);
    $existingId = (int)$existing->fetchColumn();
    if ($existingId > 0) {
        if (!function_exists('syncGatewaySubmissionToForwardQueue') && is_file(__DIR__ . '/partner_forward_queue.php')) {
            require_once __DIR__ . '/partner_forward_queue.php';
        }
        if (function_exists('syncGatewaySubmissionToForwardQueue')) {
            syncGatewaySubmissionToForwardQueue($merchantId, $gateway, $forwardSource, $existingId);
        }
        return true;
    }

    // Use the structured payload builder — never store raw PAN/GST/password/api_secret
    if (!function_exists('build_partner_onboarding_payload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $payloadArr = build_partner_onboarding_payload($merchantId);
    if (!function_exists('redactPartnerPayload')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    $payloadArr = redactPartnerPayload($payloadArr);
    $payloadArr['gateway'] = $gateway;
    $payload = json_encode($payloadArr);

    $db->prepare('INSERT INTO gateway_submissions (merchant_id, gateway, status, payload, admin_id, admin_notes) VALUES (?,?,?,?,?,?)')
        ->execute([$merchantId, $gateway, 'submitted', $payload, $adminId, $notes]);

    $submissionId = (int)$db->lastInsertId();

    if (!function_exists('syncGatewaySubmissionToForwardQueue') && is_file(__DIR__ . '/partner_forward_queue.php')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (function_exists('syncGatewaySubmissionToForwardQueue')) {
        syncGatewaySubmissionToForwardQueue($merchantId, $gateway, $forwardSource, $submissionId);
    }

    if (!function_exists('wiringKycForwardNotifyBody') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
        require_once __DIR__ . '/wiring_deep_link_workflow.php';
    }
    $gwBody = function_exists('wiringKycForwardNotifyBody')
        ? wiringKycForwardNotifyBody($gateway, 'gateway')
        : ('Your KYC documents were submitted to ' . ucfirst($gateway) . ' for onboarding.');
    createNotification($merchantId, 'Gateway Submission', $gwBody);
    logComplianceAudit($merchantId, $adminId, 'gateway_forward', 'Forwarded to ' . strtoupper($gateway));
    return true;
}

/** Gateways a merchant can be forwarded to — derived from getPartnerRegistry() flags. */
function gatewaySubmissionAllowedGateways(): array
{
    if (!function_exists('getGatewaySubmissionPartnerKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return getGatewaySubmissionPartnerKeys();
}

/** One-click forward to several gateways at once. Returns count forwarded. */
function submitMerchantToGateways(int $merchantId, array $gateways, int $adminId, string $notes = ''): int
{
    $allowed = gatewaySubmissionAllowedGateways();
    $done = 0;
    foreach (array_unique($gateways) as $g) {
        $g = (string)$g;
        if (in_array($g, $allowed, true) && submitMerchantToGateway($merchantId, $g, $adminId, $notes)) {
            $done++;
        }
    }
    return $done;
}

/** Latest submission per gateway for a merchant (status matrix). */
function getGatewaySubmissionMatrix(int $merchantId): array
{
    ensureGatewaySubmissionsTable();
    try {
        $stmt = getDB()->prepare(
            'SELECT gs.* FROM gateway_submissions gs
             INNER JOIN (
                 SELECT gateway, MAX(id) AS max_id
                 FROM gateway_submissions WHERE merchant_id = ? GROUP BY gateway
             ) latest ON gs.id = latest.max_id
             ORDER BY gs.gateway'
        );
        $stmt->execute([$merchantId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(string)$row['gateway']] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/** Admin updates a submission status; auto-notifies merchant on approve/reject. */
function updateGatewaySubmissionStatus(int $submissionId, string $status, int $adminId, string $response = ''): bool
{
    ensureGatewaySubmissionsTable();
    $allowed = ['draft', 'submitted', 'approved', 'rejected', 'pending_review'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    $db = getDB();
    $row = $db->prepare('SELECT * FROM gateway_submissions WHERE id = ?');
    $row->execute([$submissionId]);
    $sub = $row->fetch();
    if (!$sub) {
        return false;
    }
    $db->prepare('UPDATE gateway_submissions SET status = ?, gateway_response = ? WHERE id = ?')
        ->execute([$status, $response, $submissionId]);
    if (!function_exists('syncGatewaySubmissionToForwardQueue') && is_file(__DIR__ . '/partner_forward_queue.php')) {
        require_once __DIR__ . '/partner_forward_queue.php';
    }
    if (function_exists('syncGatewaySubmissionToForwardQueue')) {
        syncGatewaySubmissionToForwardQueue(
            (int)$sub['merchant_id'],
            (string)$sub['gateway'],
            'manual_status',
            $submissionId
        );
    }
    $gwLabel = strtoupper((string)$sub['gateway']);
    logComplianceAudit((int)$sub['merchant_id'], $adminId, 'gateway_status', $gwLabel . ' -> ' . $status . ($response !== '' ? (' (' . $response . ')') : ''));
    if (in_array($status, ['approved', 'rejected'], true)) {
        $msg = $status === 'approved'
            ? "Good news — your onboarding with {$gwLabel} is approved."
            : "Your {$gwLabel} onboarding needs attention. Our team will guide the next steps.";
        createNotification((int)$sub['merchant_id'], 'Gateway ' . ucfirst($status), $msg);
    }
    return true;
}

/**
 * Pre-filled onboarding email to a gateway for one merchant. Intentionally
 * carries only business identity (no Aadhaar/PAN/bank numbers) — sensitive KYC
 * is shared via the gateway's secure portal/API, never email.
 */
function gatewayOnboardingMailto(string $gateway, array $m): string
{
    $partners = function_exists('getBankingPartners') ? getBankingPartners() : [];
    $to = (string)($partners[$gateway]['email'] ?? '');
    if ($to === '') {
        return '#';
    }
    $company = defined('COMPANY_LEGAL_NAME') ? COMPANY_LEGAL_NAME : 'UniWeb';
    $site = defined('APP_URL') ? APP_URL : '';
    $biz = (string)(($m['business_name'] ?? '') !== '' ? $m['business_name'] : ($m['name'] ?? 'Merchant'));
    $code = (string)($m['merchant_code'] ?? '');
    $entity = (string)($m['business_entity_type'] ?? '');
    $subject = rawurlencode("Sub-merchant onboarding request — {$biz} via {$company}");
    $bodyLines = [
        'Dear ' . strtoupper($gateway) . ' Onboarding Team,',
        '',
        "{$company} ({$site}) requests sub-merchant onboarding for the following merchant on our platform:",
        '',
        "Business Name: {$biz}",
        "Merchant Code: {$code}",
        "Entity Type: {$entity}",
        '',
        'KYC documents are verified on our platform and will be shared ONLY via your secure onboarding portal or API. Please advise the preferred secure channel. We do not send sensitive documents over email.',
        '',
        'Regards,',
        $company,
        $site,
    ];
    $body = rawurlencode(implode("\n", $bodyLines));
    return "mailto:{$to}?subject={$subject}&body={$body}";
}

/* ------------------------------------------------------------------ *
 *  Compliance audit trail + KYC document versioning
 * ------------------------------------------------------------------ */

function ensureComplianceAuditTable(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS compliance_audit_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            admin_id INT DEFAULT NULL,
            action VARCHAR(48) NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_merchant (merchant_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }
}

function logComplianceAudit(int $merchantId, ?int $adminId, string $action, string $detail = ''): void
{
    ensureComplianceAuditTable();
    try {
        getDB()->prepare('INSERT INTO compliance_audit_log (merchant_id, admin_id, action, detail) VALUES (?,?,?,?)')
            ->execute([$merchantId, $adminId, substr($action, 0, 48), substr($detail, 0, 255)]);
    } catch (Throwable $e) { /* ok */ }
}

function getComplianceAudit(int $merchantId, int $limit = 40): array
{
    ensureComplianceAuditTable();
    try {
        $stmt = getDB()->prepare('SELECT * FROM compliance_audit_log WHERE merchant_id = ? ORDER BY id DESC LIMIT ?');
        $stmt->bindValue(1, $merchantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/** All KYC uploads grouped by doc_type, newest first = current version. */
function getMerchantKycDocumentVersions(int $merchantId): array
{
    try {
        $stmt = getDB()->prepare('SELECT id, doc_type, file_name, status, scan_status, file_size, created_at FROM kyc_documents WHERE merchant_id = ? ORDER BY doc_type ASC, id DESC');
        $stmt->execute([$merchantId]);
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(string)$row['doc_type']][] = $row;
        }
        return $grouped;
    } catch (Throwable $e) {
        return [];
    }
}

function getActivePaymentGateway(): string
{
    if (!function_exists('getCheckoutPgPartnerKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    // Platform setting is a template/fallback for new merchants (P1-03), not a global live override.
    $preferred = trim((string)getSetting('active_payment_gateway', ''));
    // Prefer the admin-selected primary only when fully configured AND checkout-capable.
    if ($preferred !== ''
        && isGatewayConfigured($preferred)
        && gatewaySupportsLiveCheckout($preferred)) {
        return $preferred;
    }
    foreach (getCheckoutPgPartnerKeys() as $gw) {
        if (isGatewayConfigured($gw)) {
            return $gw;
        }
    }
    return 'manual';
}

function isGatewayConfigured(string $gateway): bool
{
    if (!function_exists('isPartnerRegistryKey')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    // D4: If gateway is deactivated in registry, treat as not configured
    // regardless of whether credentials exist. This ensures disabled partners
    // are excluded from all checkout/QR/link paths that call isGatewayConfigured().
    if (function_exists('isGatewayActive') && !isGatewayActive($gateway)) {
        return false;
    }
    return match ($gateway) {
        'razorpay' => (bool)getPartnerSetting('razorpay', 'razorpay_key_id', '') && (bool)getPartnerSetting('razorpay', 'razorpay_key_secret', ''),
        'cashfree' => (bool)getPartnerSetting('cashfree', 'cashfree_app_id', '') && (bool)getPartnerSetting('cashfree', 'cashfree_secret_key', ''),
        'payu' => (bool)getPartnerSetting('payu', 'payu_merchant_key', '') && (bool)getPartnerSetting('payu', 'payu_merchant_salt', ''),
        'phonepe' => (bool)getPartnerSetting('phonepe', 'phonepe_merchant_id', '') && (bool)getPartnerSetting('phonepe', 'phonepe_salt_key', ''),
        'axis' => (bool)(getPartnerSetting('axis', 'axis_client_id', '') && getPartnerSetting('axis', 'axis_client_secret', '')),
        'decentro' => (bool)getPartnerSetting('decentro', 'decentro_client_id', '') && (bool)getPartnerSetting('decentro', 'decentro_client_secret', ''),
        'pinelabs' => (bool)getPartnerSetting('pinelabs', 'pinelabs_merchant_id', '') && (bool)getPartnerSetting('pinelabs', 'pinelabs_access_code', '') && (bool)getPartnerSetting('pinelabs', 'pinelabs_secure_key', ''),
        'worldline' => (bool)getPartnerSetting('worldline', 'worldline_merchant_id', '') && (bool)getPartnerSetting('worldline', 'worldline_access_key', '') && (bool)getPartnerSetting('worldline', 'worldline_secret_key', ''),
        'digio' => (bool)getPartnerSetting('digio', 'digio_client_id', '') && (bool)getPartnerSetting('digio', 'digio_client_secret', ''),
        'rbl' => (function_exists('isRblOperational') ? isRblOperational() : isRblConfigured()),
        default => function_exists('isPartnerRegistryKey') && isPartnerRegistryKey($gateway)
            ? partnerHasSavedCredentials($gateway)
            : false,
    };
}

/** Gateways that can actually route a live checkout today (PhonePe / Pine Labs checkout is roadmap-only). */
function gatewaySupportsLiveCheckout(string $gateway): bool
{
    if (!function_exists('partnerHasRegistryFlag')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return partnerHasRegistryFlag($gateway, 'checkout_pg');
}

function gatewayStatusLabel(string $gateway): string
{
    if (!isGatewayConfigured($gateway)) {
        return 'Keys pending';
    }
    if ($gateway === 'phonepe' || $gateway === 'pinelabs') {
        return 'Keys saved · checkout on roadmap';
    }
    if ($gateway === (getSetting('active_payment_gateway', 'razorpay'))) {
        return 'New-merchant template';
    }
    return 'Configured';
}

/** @return array{ok:bool,message:string} */
function testRazorpayConnection(): array
{
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret) {
        return ['ok' => false, 'message' => 'Razorpay Key ID and Secret are required.'];
    }

    $ch = curl_init('https://api.razorpay.com/v1/orders?count=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'message' => 'Razorpay connection failed: ' . $err];
    }
    if ($http === 200) {
        $mode = str_starts_with($keyId, 'rzp_live_') ? 'live' : 'test';
        return ['ok' => true, 'message' => 'Razorpay API connected (' . $mode . ' keys, HTTP 200).'];
    }

    $data = json_decode($response, true);
    $detail = is_array($data) ? ($data['error']['description'] ?? $data['error']['code'] ?? '') : '';
    return ['ok' => false, 'message' => 'Razorpay API error HTTP ' . $http . ($detail ? ': ' . $detail : '')];
}

/** @return array{ok:bool,message:string} */
function testCashfreeConnection(): array
{
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret) {
        return ['ok' => false, 'message' => 'Cashfree App ID and Secret are required.'];
    }

    $env = getPartnerEnvironment('cashfree', 'production');
    $ch = curl_init(cashfreeApiBase() . '/orders?order_limit=1');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'message' => 'Cashfree connection failed: ' . $err];
    }
    if ($http === 200) {
        return ['ok' => true, 'message' => 'Cashfree API connected (' . $env . ' env, HTTP 200).'];
    }

    $data = json_decode($response, true);
    $detail = is_array($data) ? ($data['message'] ?? $data['error'] ?? '') : '';
    if (is_array($detail)) {
        $detail = $detail['message'] ?? json_encode($detail);
    }
    return ['ok' => false, 'message' => 'Cashfree API error HTTP ' . $http . ($detail ? ': ' . $detail : '')];
}

/** @return array{ok:bool,message:string} */
function testPayuConnection(): array
{
    $c = payuCredentials();
    if (!$c['key'] || !$c['salt']) {
        return ['ok' => false, 'message' => 'PayU Merchant Key and Salt are required.'];
    }

    $env = getPartnerEnvironment('payu', 'test');
    return [
        'ok' => true,
        'message' => 'PayU credentials saved (' . $env . ' mode). Run a ₹1 test payment to confirm hash and return URL.',
    ];
}

/** @return array{ok:bool,message:string} */
function testDecentroConnection(): array
{
    $clientId = decentroClientId();
    $clientSecret = decentroClientSecret();
    if (!$clientId || !$clientSecret) {
        return ['ok' => false, 'message' => 'Decentro Client ID and Secret are required. Paste in Partner Registry → Keys.'];
    }

    $base = decentroV3ApiBase();
    if (getPartnerEnvironment('decentro', 'sandbox') !== 'sandbox') {
        return ['ok' => false, 'message' => 'Production Dynamic QR test is disabled to prevent creating a live payment request. Test the same credentials in sandbox first.'];
    }
    $referenceId = 'UWTEST' . date('YmdHis') . random_int(1000, 9999);
    $result = createDecentroDynamicQr(10.00, 'Payment', $referenceId, 5);

    if (!is_array($result)) {
        return ['ok' => false, 'message' => 'Decentro v3 API did not respond. Check base URL (' . $base . ') and network.'];
    }
    if (($result['api_status'] ?? '') === 'SUCCESS') {
        return ['ok' => true, 'message' => 'Decentro v3 Dynamic QR API connected (' . $base . ').'];
    }
    $message = is_string($result['message'] ?? null) ? $result['message'] : (is_string($result['response_key'] ?? null) ? $result['response_key'] : json_encode($result));
    return ['ok' => false, 'message' => 'Decentro v3 API: ' . $message];
}

/** @return array{ok:bool,message:string} */
function testPhonePeConnection(): array
{
    $mid = trim(getPartnerSetting('phonepe', 'phonepe_merchant_id', ''));
    $salt = trim(getPartnerSetting('phonepe', 'phonepe_salt_key', ''));
    if ($mid === '' || $salt === '') {
        return ['ok' => false, 'message' => 'PhonePe Merchant ID and Salt Key are required. Paste in Partner Registry → Keys.'];
    }
    return ['ok' => true, 'message' => 'PhonePe keys saved. PhonePe checkout activates in a later release — live checkout currently routes through platform checkout or direct UPI.'];
}

/** @return array{ok:bool,message:string} */
function testGatewayConnection(string $gateway): array
{
    return match ($gateway) {
        'razorpay' => testRazorpayConnection(),
        'cashfree' => testCashfreeConnection(),
        'payu' => testPayuConnection(),
        'phonepe' => testPhonePeConnection(),
        'decentro' => testDecentroConnection(),
        'pinelabs' => testPineLabsConnection(),
        'rbl' => testRblConnection(),
        default => ['ok' => false, 'message' => 'Unknown gateway: ' . $gateway],
    };
}

// ─── PayU (Split Settlements + P2M) ─────────────────────────────────────────

function payuBaseUrl(): string
{
    return getPartnerEnvironment('payu', 'test') === 'test'
        ? 'https://test.payu.in'
        : 'https://secure.payu.in';
}

function payuCredentials(): array
{
    return [
        'key' => function_exists('getPartnerSetting') ? getPartnerSetting('payu', 'payu_merchant_key', '') : '',
        'salt' => function_exists('getPartnerSetting') ? getPartnerSetting('payu', 'payu_merchant_salt', '') : '',
    ];
}

function generatePayUHash(string $key, string $txnid, string $amount, string $productinfo, string $firstname, string $email, string $salt, string $udf1 = '', string $udf2 = '', string $udf3 = '', string $udf4 = '', string $udf5 = '', string $splitRequest = ''): string
{
    $str = $key . '|' . $txnid . '|' . $amount . '|' . $productinfo . '|' . $firstname . '|' . $email
        . '|' . $udf1 . '|' . $udf2 . '|' . $udf3 . '|' . $udf4 . '|' . $udf5
        . '||||||' . $salt;
    if ($splitRequest !== '') {
        $str .= '|' . $splitRequest;
    }
    return strtolower(hash('sha512', $str));
}

function verifyPayUResponseHash(array $post): bool
{
    if (empty($post['hash'])) {
        return false;
    }
    if (!function_exists('partnerWebhookSecretCandidates') && is_file(__DIR__ . '/webhook_secret_rotation.php')) {
        require_once __DIR__ . '/webhook_secret_rotation.php';
    }
    $salts = function_exists('partnerWebhookSecretCandidates')
        ? partnerWebhookSecretCandidates('payu')
        : [];
    if ($salts === []) {
        $c = payuCredentials();
        if (!empty($c['salt'])) {
            $salts = [(string)$c['salt']];
        }
    }
    foreach ($salts as $salt) {
        if (verifyPayUResponseHashWithSalt($post, (string)$salt)) {
            return true;
        }
    }
    return false;
}

function verifyPayUResponseHashWithSalt(array $post, string $salt): bool
{
    if ($salt === '' || empty($post['hash'])) {
        return false;
    }
    $status = $post['status'] ?? '';
    $str = implode('|', [
        $salt, $status, '', '', '', '', '',
        $post['udf5'] ?? '', $post['udf4'] ?? '', $post['udf3'] ?? '', $post['udf2'] ?? '', $post['udf1'] ?? '',
        $post['email'] ?? '', $post['firstname'] ?? '', $post['productinfo'] ?? '', $post['amount'] ?? '',
        $post['txnid'] ?? '', $post['key'] ?? '',
    ]);
    return cryptoTimingSafeEqual(strtolower((string)$post['hash']), strtolower(hash('sha512', $str)));
}

function buildPayUSplitRequest(float $amount, array $merchant): string
{
    $split = calculateSplitBreakdown($amount, $merchant);
    $parentKey = payuCredentials()['key'];
    $childKey = $merchant['payu_child_key'] ?: $parentKey;
    $splitInfo = [];
    if ($split['merchant_net'] > 0) {
        $splitInfo[$childKey] = [
            'aggregatorSubTxnId' => 'M' . ($merchant['merchant_id'] ?? $merchant['id']),
            'aggregatorSubAmt' => number_format($split['merchant_net'], 2, '.', ''),
            'aggregatorCharges' => '0.00',
        ];
    }
    if ($split['platform_fee'] > 0 && $childKey !== $parentKey) {
        $splitInfo[$parentKey] = [
            'aggregatorSubTxnId' => 'PF' . ($merchant['merchant_id'] ?? $merchant['id']),
            'aggregatorSubAmt' => number_format($split['platform_fee'], 2, '.', ''),
            'aggregatorCharges' => '0.00',
        ];
    }
    if (empty($splitInfo)) {
        $splitInfo[$childKey] = [
            'aggregatorSubTxnId' => 'FULL',
            'aggregatorSubAmt' => number_format($amount, 2, '.', ''),
            'aggregatorCharges' => '0.00',
        ];
    }
    return json_encode(['type' => 'absolute', 'splitInfo' => $splitInfo], JSON_UNESCAPED_SLASHES);
}

function buildPayUPaymentForm(array $link, array $merchant, bool $withSplit = true, string $enforcePg = '', string $txnidSuffix = '', ?float $amountOverride = null): ?array
{
    if (!isGatewayConfigured('payu')) {
        return null;
    }
    $c = payuCredentials();
    if (!$c['key'] || !$c['salt']) return null;

    $payAmount = $amountOverride ?? (float)($link['amount'] ?? 0);
    if ($payAmount <= 0) return null;

    if (merchantAccountMode($merchant) === 'test') {
        $withSplit = false;
    }

    $txnid = 'PU' . preg_replace('/[^A-Za-z0-9]/', '', $link['link_id']) . ($txnidSuffix ?: 'X') . time();
    $amount = number_format($payAmount, 2, '.', '');
    $productinfo = preg_replace('/[^\x20-\x7E]/', '', $link['description'] ?: 'Payment') ?: 'Payment';
    $firstname = preg_replace('/[^\x20-\x7E]/', '', $link['customer_name'] ?? 'Customer') ?: 'Customer';
    $email = filter_var($link['customer_email'] ?? '', FILTER_VALIDATE_EMAIL) ? $link['customer_email'] : COMPANY_SUPPORT_EMAIL;
    $phone = preg_replace('/\D/', '', $link['customer_phone'] ?? '') ?: '9999999999';
    if (strlen($phone) === 10) $phone = '91' . $phone;
    $surl = APP_URL . '/payment_payu_return.php?status=success';
    $furl = APP_URL . '/payment_payu_return.php?status=failed';

    $splitJson = '';
    if ($withSplit) {
        $splitJson = buildPayUSplitRequest($payAmount, $merchant);
    }

    $hash = generatePayUHash(
        $c['key'], $txnid, $amount, $productinfo, $firstname, $email, $c['salt'],
        $link['link_id'], (string)($link['merchant_id'] ?? ''), '', '', '', $splitJson
    );

    $fields = [
        'key' => $c['key'],
        'txnid' => $txnid,
        'amount' => $amount,
        'productinfo' => $productinfo,
        'firstname' => $firstname,
        'email' => $email,
        'phone' => $phone,
        'surl' => $surl,
        'furl' => $furl,
        'hash' => $hash,
        'udf1' => $link['link_id'],
        'udf2' => (string)($link['merchant_id'] ?? ''),
        'service_provider' => 'payu_paisa',
    ];
    if ($splitJson !== '') {
        $fields['splitRequest'] = $splitJson;
    }
    if ($enforcePg !== '') {
        $fields['pg'] = $enforcePg;
    }
    return ['action' => payuBaseUrl() . '/_payment', 'fields' => $fields];
}

// ─── Razorpay Route (transfer on order) ─────────────────────────────────────

function createRazorpayOrderWithRoute(float $amount, string $receipt, array $merchant, array $notes = []): ?array
{
    if (!isGatewayConfigured('razorpay')) {
        return null;
    }
    $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
    $keySecret = getPartnerSetting('razorpay', 'razorpay_key_secret', '');
    if (!$keyId || !$keySecret) return null;

    $split = calculateSplitBreakdown($amount, $merchant);
    $body = [
        'amount' => (int)($amount * 100),
        'currency' => 'INR',
        'receipt' => $receipt,
        'notes' => $notes,
    ];
    $linked = $merchant['razorpay_linked_account_id'] ?? '';
    if ($linked && $split['merchant_net'] > 0) {
        $body['transfers'] = [[
            'account' => $linked,
            'amount' => (int)round($split['merchant_net'] * 100),
            'currency' => 'INR',
            'notes' => ['merchant_id' => (string)$merchant['id']],
        ]];
    }

    $ch = curl_init('https://api.razorpay.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

// ─── Cashfree Easy Split ────────────────────────────────────────────────────

function createCashfreeOrderWithSplit(string $orderId, float $amount, array $merchant, string $phone, string $email, string $returnUrl): ?array
{
    if (!isGatewayConfigured('cashfree')) {
        return null;
    }
    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if (!$appId || !$secret) return null;

    $split = calculateSplitBreakdown($amount, $merchant);
    $vendorId = $merchant['cashfree_vendor_id'] ?? '';
    $body = [
        'order_id' => $orderId,
        'order_amount' => round($amount, 2),
        'order_currency' => 'INR',
        'customer_details' => [
            'customer_id' => 'cust_' . preg_replace('/\D/', '', $phone),
            'customer_phone' => strlen($phone) === 10 ? '91' . $phone : $phone,
            'customer_email' => $email ?: COMPANY_SUPPORT_EMAIL,
        ],
        'order_meta' => ['return_url' => $returnUrl],
    ];
    if (preg_match('/^CF_([^_]+)_/i', $orderId, $m)) {
        $body['order_tags'] = ['link_id' => $m[1]];
    }
    if ($vendorId && $split['merchant_net'] > 0) {
        $body['order_splits'] = [[
            'vendor_id' => $vendorId,
            'amount' => $split['merchant_net'],
        ]];
    }

    $ch = curl_init(cashfreeApiBase() . '/orders');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? json_decode($response, true) : null;
}

/**
 * Pine Labs Plural — gated scaffold / sandbox stub.
 * Live checkout is NOT enabled (keys pending + roadmap). Sandbox createOrder
 * returns a simulated payload when keys are absent or env=sandbox.
 */
function pineLabsApiBase(): string
{
    return getPartnerEnvironment('pinelabs', 'sandbox') === 'production'
        ? 'https://pluralpayments.com/api'
        : 'https://pluraluat.pinelabs.com/api';
}

/** @return array{ok:bool,message:string,sandbox?:bool,order?:array} */
function testPineLabsConnection(): array
{
    if (!isGatewayConfigured('pinelabs')) {
        return ['ok' => false, 'message' => 'Pine Labs keys pending — paste merchant id, access code, and secure key when received.'];
    }
    $env = getPartnerEnvironment('pinelabs', 'sandbox');
    return [
        'ok' => true,
        'message' => 'Pine Labs credentials saved (' . $env . '). Checkout routing stays on roadmap until Plural adapter is activated.',
        'sandbox' => $env !== 'production',
    ];
}

/**
 * Sandbox stub for Plural order create. Never charges live money without keys
 * and never invents production credentials.
 * @return array{ok:bool,sandbox:bool,message:string,order_id?:string,redirect_url?:string}
 */
function pineLabsSandboxCreateOrder(array $link, array $merchant, float $amount): array
{
    if ($amount <= 0) {
        return ['ok' => false, 'sandbox' => true, 'message' => 'Invalid amount.'];
    }
    if (!isGatewayConfigured('pinelabs')) {
        $simId = 'PL_SIM_' . strtoupper(bin2hex(random_bytes(4)));
        return [
            'ok' => true,
            'sandbox' => true,
            'message' => 'Pine Labs sandbox stub (keys pending). Simulated order recorded — no live charge.',
            'order_id' => $simId,
            'redirect_url' => null,
        ];
    }
    // Keys present but live Plural HTTP adapter is still scaffolded — stay sandbox-safe.
    $orderId = 'PL_' . preg_replace('/[^A-Za-z0-9]/', '', (string)($link['link_id'] ?? 'ORD')) . substr((string)time(), -6);
    return [
        'ok' => true,
        'sandbox' => getPartnerEnvironment('pinelabs', 'sandbox') !== 'production',
        'message' => 'Pine Labs Plural scaffold order prepared. Live redirect activates when checkout routing is enabled.',
        'order_id' => $orderId,
        'redirect_url' => null,
    ];
}

// ─── Decentro v3 UPI P2M Collections ───────────────────────────────────────

function decentroV3ApiBase(): string
{
    return getPartnerEnvironment('decentro', 'sandbox') === 'production'
        ? 'https://api.decentro.tech'
        : 'https://staging.api.decentro.tech';
}

function decentroV3Headers(): array
{
    $clientId = decentroClientId();
    $clientSecret = decentroClientSecret();
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'client_id: ' . $clientId,
        'client_secret: ' . $clientSecret,
    ];
    $moduleSecret = decentroModuleSecret();
    if ($moduleSecret !== '') {
        $headers[] = 'module_secret: ' . $moduleSecret;
    }
    $providerSecret = decentroProviderSecret();
    if ($providerSecret !== '') {
        $headers[] = 'provider_secret: ' . $providerSecret;
    }
    return $headers;
}

function decentroV3Request(string $endpoint, array $payload): ?array
{
    $url = rtrim(decentroV3ApiBase(), '/') . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => decentroV3Headers(),
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_USERAGENT => APP_NAME . '/1.0',
        CURLOPT_TIMEOUT => 30,
    ];
    // Sandbox only: local Windows PHP often lacks a CA bundle, so staging calls fail
    // with SSL verify errors. Production keeps verification enabled by default.
    if (getPartnerEnvironment('decentro', 'sandbox') === 'sandbox') {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        return ['api_status' => 'NETWORK_ERROR', 'message' => $err];
    }
    return json_decode($response, true) ?: null;
}

/**
 * Create a Decentro v3 Dynamic UPI QR.
 * Docs: https://docs.decentro.tech/reference/payments_api-collectionsv3-dynamicqr
 *
 * @param float $amount Transaction amount in INR.
 * @param string $purpose_message Note shown to payer (5–50 chars, safe characters).
 * @param string $referenceId Unique platform reference.
 * @param int $expiryMinutes QR expiry in minutes (1–10).
 * @param string|null $consumerUrn Defaults to decentro_consumer_urn setting.
 * @param string|null $customUrl Optional https callback after payment.
 * @return array|null Decentro JSON response or null on network error.
 */
function createDecentroDynamicQr(float $amount, string $purpose_message, string $referenceId, int $expiryMinutes = 5, ?string $consumerUrn = null, ?string $customUrl = null): ?array
{
    $consumerUrn ??= decentroConsumerUrn();
    if ($consumerUrn === '' || $amount <= 0) {
        return null;
    }

    $cleanPurpose = preg_replace('/[^A-Za-z0-9 ]/', '', $purpose_message);
    $cleanPurpose = substr(trim((string)$cleanPurpose), 0, 50);
    if (strlen($cleanPurpose) < 5) {
        $cleanPurpose = 'Payment via UniWeb';
    }

    $referenceId = preg_replace('/[^A-Za-z0-9]/', '', $referenceId) ?: ('UW' . time());
    $expiryMinutes = max(1, min(10, $expiryMinutes));

    $payload = [
        'reference_id' => $referenceId,
        'consumer_urn' => $consumerUrn,
        'amount' => round($amount, 2),
        'purpose_message' => $cleanPurpose,
        'expiry_time' => $expiryMinutes,
    ];
    if ($customUrl !== null && $customUrl !== '') {
        $payload['custom_url'] = $customUrl;
    }

    return decentroV3Request('v3/payments/upi/qr', $payload);
}

function fetchDecentroTransactionStatus(string $decentroTxnId): ?array
{
    $url = rtrim(decentroV3ApiBase(), '/') . '/v3/payments/transaction/advance/status?decentro_txn_id=' . rawurlencode($decentroTxnId);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => decentroV3Headers(),
        CURLOPT_USERAGENT => APP_NAME . '/1.0',
        CURLOPT_TIMEOUT => 15,
    ];
    if (getPartnerEnvironment('decentro', 'sandbox') === 'sandbox') {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err !== '') {
        return null;
    }
    return json_decode($response, true) ?: null;
}

// Axis VA — see includes/axis.php

/**
 * Try payment across multiple gateways in smart priority order.
 * If first gateway fails, automatically tries the next one.
 *
 * @param int $merchantId Merchant ID
 * @param float $amount Payment amount
 * @param array $link Payment link data
 * @param array $extra Extra params (customer info, etc.)
 * @return array ['ok'=>bool, 'gateway_key'=>string, 'order'=>array|null, 'error'=>string]
 */
function tryGatewaysInOrder(int $merchantId, float $amount, array $link, array $extra = []): array
{
    if (!function_exists('getMerchantCheckoutGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }

    $gatewayOrder = getMerchantCheckoutGateways($merchantId);
    if (empty($gatewayOrder)) {
        $gatewayOrder = ['upi_p2m'];
    }

    $lastError = '';
    foreach ($gatewayOrder as $gwKey) {
        $startMs = microtime(true);

        // Try this gateway
        $result = null;
        switch ($gwKey) {
            case 'razorpay':
                if (isGatewayConfigured('razorpay')) {
                    $order = createRazorpayOrder($amount, $extra['receipt'] ?? ('link_' . $link['id']), $extra['notes'] ?? []);
                    $result = $order ? ['ok' => true, 'gateway_key' => 'razorpay', 'order' => $order] : ['ok' => false, 'error' => 'Razorpay order creation failed'];
                }
                break;
            case 'payu':
                if (isGatewayConfigured('payu')) {
                    $order = createPayuOrder($amount, $extra['receipt'] ?? ('link_' . $link['id']), $link, $extra);
                    $result = $order ? ['ok' => true, 'gateway_key' => 'payu', 'order' => $order] : ['ok' => false, 'error' => 'PayU order creation failed'];
                }
                break;
            case 'cashfree':
                if (isGatewayConfigured('cashfree')) {
                    $order = createCashfreeOrder($amount, $extra['receipt'] ?? ('link_' . $link['id']), $link, $extra);
                    $result = $order ? ['ok' => true, 'gateway_key' => 'cashfree', 'order' => $order] : ['ok' => false, 'error' => 'Cashfree order creation failed'];
                }
                break;
            case 'upi_p2m':
            case 'qr_code':
            default:
                // Direct UPI / QR — always works (no external gateway needed)
                $result = ['ok' => true, 'gateway_key' => $gwKey, 'order' => null];
                break;
        }

        $responseMs = (int)((microtime(true) - $startMs) * 1000);

        // Skip if gateway not configured (result still null)
        if ($result === null) {
            continue;
        }

        // Record health
        if (function_exists('recordGatewayAttempt')) {
            recordGatewayAttempt($gwKey, $result['ok'], $responseMs, $result['error'] ?? '');
        }

        if ($result['ok']) {
            return $result;
        }

        $lastError = $result['error'] ?? 'Unknown error';
        // Try next gateway
    }

    return ['ok' => false, 'gateway_key' => '', 'order' => null, 'error' => $lastError ?: 'All gateways failed'];
}
