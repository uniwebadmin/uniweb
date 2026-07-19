<?php
declare(strict_types=1);

function createRazorpayOrder(float $amount, string $receipt, array $notes = []): ?array
{
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
    $secret = getSetting('razorpay_key_secret', '');
    if (!$secret) return false;
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
    return hash_equals($expected, $signature);
}

function cashfreeApiBase(): string
{
    return getSetting('cashfree_environment', 'production') === 'sandbox'
        ? 'https://sandbox.cashfree.com/pg'
        : 'https://api.cashfree.com/pg';
}

function createCashfreeOrder(string $orderId, float $amount, string $customerPhone, string $customerEmail, string $returnUrl, string $linkId = ''): ?array
{
    $appId = getSetting('cashfree_app_id', '');
    $secret = getSetting('cashfree_secret_key', '');
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
    $appId = getSetting('cashfree_app_id', '');
    $secret = getSetting('cashfree_secret_key', '');
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
            gateway ENUM('razorpay','cashfree','payu','decentro','phonepe') NOT NULL,
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
}

function submitMerchantToGateway(int $merchantId, string $gateway, int $adminId, string $notes = ''): bool
{
    ensureGatewaySubmissionsTable();
    $db = getDB();
    $merchant = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $merchant->execute([$merchantId]);
    $m = $merchant->fetch();
    if (!$m) return false;

    $docs = $db->prepare('SELECT * FROM kyc_documents WHERE merchant_id = ?');
    $docs->execute([$merchantId]);
    $documents = $docs->fetchAll();

    $payload = json_encode(['merchant' => $m, 'documents' => $documents, 'gateway' => $gateway]);
    $db->prepare('INSERT INTO gateway_submissions (merchant_id, gateway, status, payload, admin_id, admin_notes) VALUES (?,?,?,?,?,?)')
        ->execute([$merchantId, $gateway, 'submitted', $payload, $adminId, $notes]);

    createNotification($merchantId, 'Gateway Submission', "Your KYC documents submitted to " . strtoupper($gateway) . " for onboarding.");
    return true;
}

function getActivePaymentGateway(): string
{
    $preferred = getSetting('active_payment_gateway', '');
    if ($preferred === 'razorpay' && getSetting('razorpay_key_id', '')) return 'razorpay';
    if ($preferred === 'cashfree' && getSetting('cashfree_app_id', '')) return 'cashfree';
    if (getSetting('razorpay_key_id', '')) return 'razorpay';
    if (getSetting('cashfree_app_id', '')) return 'cashfree';
    if (getSetting('payu_merchant_key', '')) return 'payu';
    return 'manual';
}

function isGatewayConfigured(string $gateway): bool
{
    return match ($gateway) {
        'razorpay' => (bool)getSetting('razorpay_key_id', '') && (bool)getSetting('razorpay_key_secret', ''),
        'cashfree' => (bool)getSetting('cashfree_app_id', '') && (bool)getSetting('cashfree_secret_key', ''),
        'payu' => (bool)getSetting('payu_merchant_key', '') && (bool)getSetting('payu_merchant_salt', ''),
        'phonepe' => (bool)getSetting('phonepe_merchant_id', '') && (bool)getSetting('phonepe_salt_key', ''),
        'axis' => (bool)(getSetting('axis_client_id', '') && getSetting('axis_client_secret', ''))
            || (bool)(getSetting('axis_api_key', '') && getSetting('axis_api_secret', '')),
        'decentro' => (bool)getSetting('decentro_client_id', '') && (bool)getSetting('decentro_client_secret', ''),
        default => false,
    };
}

function gatewayStatusLabel(string $gateway): string
{
    if (!isGatewayConfigured($gateway)) {
        return 'Not configured';
    }
    if ($gateway === (getSetting('active_payment_gateway', 'razorpay'))) {
        return 'Active primary';
    }
    return 'Configured';
}

/** @return array{ok:bool,message:string} */
function testRazorpayConnection(): array
{
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
    $appId = getSetting('cashfree_app_id', '');
    $secret = getSetting('cashfree_secret_key', '');
    if (!$appId || !$secret) {
        return ['ok' => false, 'message' => 'Cashfree App ID and Secret are required.'];
    }

    $env = getSetting('cashfree_environment', 'production');
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

    $env = getSetting('payu_environment', 'test');
    return [
        'ok' => true,
        'message' => 'PayU credentials saved (' . $env . ' mode). Run a ₹1 test payment to confirm hash and return URL.',
    ];
}

/** @return array{ok:bool,message:string} */
function testDecentroConnection(): array
{
    $clientId = getSetting('decentro_client_id', '');
    $clientSecret = getSetting('decentro_client_secret', '');
    if (!$clientId || !$clientSecret) {
        return ['ok' => false, 'message' => 'Decentro Client ID and Secret are required.'];
    }

    $base = function_exists('decentroBaseUrl') ? decentroBaseUrl() : rtrim(getSetting('decentro_base_url', ''), '/');
    if ($base === '') {
        return ['ok' => true, 'message' => 'Decentro credentials saved. Set base URL and use Admin → Decentro Demo to verify KYC API.'];
    }

    $ch = curl_init($base . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['ok' => false, 'message' => 'Decentro host unreachable: ' . $err];
    }
    if ($http >= 200 && $http < 500) {
        return ['ok' => true, 'message' => 'Decentro host reachable (' . $base . ', HTTP ' . $http . '). Use KYC demo for full API test.'];
    }
    return ['ok' => false, 'message' => 'Decentro host returned HTTP ' . $http . '. Check base URL in settings.'];
}

/** @return array{ok:bool,message:string} */
function testPhonePeConnection(): array
{
    $mid = trim(getSetting('phonepe_merchant_id', ''));
    $salt = trim(getSetting('phonepe_salt_key', ''));
    if ($mid === '' || $salt === '') {
        return ['ok' => false, 'message' => 'PhonePe Merchant ID and Salt Key are required.'];
    }
    return ['ok' => true, 'message' => 'PhonePe keys saved (Merchant ID present). Use sandbox/production env when integrating checkout.'];
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
        default => ['ok' => false, 'message' => 'Unknown gateway: ' . $gateway],
    };
}

// ─── PayU (Split Settlements + P2M) ─────────────────────────────────────────

function payuBaseUrl(): string
{
    return getSetting('payu_environment', 'test') === 'test'
        ? 'https://test.payu.in'
        : 'https://secure.payu.in';
}

function payuCredentials(): array
{
    return [
        'key' => getSetting('payu_merchant_key', ''),
        'salt' => getSetting('payu_merchant_salt', ''),
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
    $c = payuCredentials();
    if (!$c['salt'] || empty($post['hash'])) return false;
    $status = $post['status'] ?? '';
    $str = implode('|', [
        $c['salt'], $status, '', '', '', '', '',
        $post['udf5'] ?? '', $post['udf4'] ?? '', $post['udf3'] ?? '', $post['udf2'] ?? '', $post['udf1'] ?? '',
        $post['email'] ?? '', $post['firstname'] ?? '', $post['productinfo'] ?? '', $post['amount'] ?? '',
        $post['txnid'] ?? '', $post['key'] ?? '',
    ]);
    return hash_equals(strtolower($post['hash']), strtolower(hash('sha512', $str)));
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
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
    $appId = getSetting('cashfree_app_id', '');
    $secret = getSetting('cashfree_secret_key', '');
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

// Axis VA — see includes/axis.php
