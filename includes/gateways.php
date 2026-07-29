<?php
declare(strict_types=1);

require_once __DIR__ . '/rbl.php';

function createRazorpayOrder(float $amount, string $receipt, array $notes = []): ?array
{
    if (!isGatewayConfigured('razorpay')) {
        return null;
    }
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

function fetchRazorpayPayment(string $paymentId): ?array
{
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function fetchRazorpayRefund(string $paymentId, string $refundId): ?array
{
    $keyId = getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpay_key_secret', '');
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
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function razorpayXRequest(string $method, string $path, ?array $body = null, array $headers = []): ?array
{
    $keyId = getSetting('razorpayx_key_id', '') ?: getSetting('razorpay_key_id', '');
    $keySecret = getSetting('razorpayx_key_secret', '') ?: getSetting('razorpay_key_secret', '');
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
        return null;
    }
    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function createRazorpayXPayout(array $merchant, array $bank, float $amount, string $reference): ?array
{
    $platformAccount = getSetting('razorpayx_account_number', '');
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
                'account_number' => (string)$bank['account_number'],
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
    return getSetting('cashfree_environment', 'production') === 'sandbox'
        ? 'https://sandbox.cashfree.com/pg'
        : 'https://api.cashfree.com/pg';
}

function createCashfreeOrder(string $orderId, float $amount, string $customerPhone, string $customerEmail, string $returnUrl, string $linkId = ''): ?array
{
    if (!isGatewayConfigured('cashfree')) {
        return null;
    }
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

function fetchCashfreeOrderPayments(string $orderId): array
{
    $appId = getSetting('cashfree_app_id', '');
    $secret = getSetting('cashfree_secret_key', '');
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
            gateway ENUM('razorpay','cashfree','payu','decentro','phonepe','axis','rbl') NOT NULL,
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
        getDB()->exec("ALTER TABLE gateway_submissions MODIFY gateway ENUM('razorpay','cashfree','payu','decentro','phonepe','axis','rbl') NOT NULL");
    } catch (Throwable $e) { /* ok if already expanded */ }
}

function submitMerchantToGateway(int $merchantId, string $gateway, int $adminId, string $notes = ''): bool
{
    ensureGatewaySubmissionsTable();
    $db = getDB();
    $merchant = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $merchant->execute([$merchantId]);
    $m = $merchant->fetch();
    if (!$m) return false;

    $existing = $db->prepare("SELECT id FROM gateway_submissions WHERE merchant_id=? AND gateway=? AND status IN ('submitted','pending_review','approved') ORDER BY id DESC LIMIT 1");
    $existing->execute([$merchantId, $gateway]);
    if ($existing->fetchColumn()) {
        return true;
    }

    $docs = $db->prepare('SELECT * FROM kyc_documents WHERE merchant_id = ?');
    $docs->execute([$merchantId]);
    $documents = $docs->fetchAll();

    $payload = json_encode(['merchant' => $m, 'documents' => $documents, 'gateway' => $gateway]);
    $db->prepare('INSERT INTO gateway_submissions (merchant_id, gateway, status, payload, admin_id, admin_notes) VALUES (?,?,?,?,?,?)')
        ->execute([$merchantId, $gateway, 'submitted', $payload, $adminId, $notes]);

    createNotification($merchantId, 'Gateway Submission', "Your KYC documents submitted to " . strtoupper($gateway) . " for onboarding.");
    logComplianceAudit($merchantId, $adminId, 'gateway_forward', 'Forwarded to ' . strtoupper($gateway));
    return true;
}

/** Gateways a merchant can be forwarded to (matches gateway_submissions ENUM). */
function gatewaySubmissionAllowedGateways(): array
{
    return ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe', 'axis', 'rbl'];
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
    $preferred = trim((string)getSetting('active_payment_gateway', ''));
    // Prefer the admin-selected primary only when fully configured AND checkout-capable.
    if ($preferred !== ''
        && isGatewayConfigured($preferred)
        && gatewaySupportsLiveCheckout($preferred)) {
        return $preferred;
    }
    foreach (['razorpay', 'cashfree', 'payu'] as $gw) {
        if (isGatewayConfigured($gw)) {
            return $gw;
        }
    }
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
        'pinelabs' => (bool)getSetting('pinelabs_merchant_id', '') && (bool)getSetting('pinelabs_access_code', '') && (bool)getSetting('pinelabs_secure_key', ''),
        'worldline' => (bool)getSetting('worldline_merchant_id', '') && (bool)getSetting('worldline_access_key', '') && (bool)getSetting('worldline_secret_key', ''),
        'rbl' => isRblConfigured(),
        default => false,
    };
}

/** Gateways that can actually route a live checkout today (PhonePe / Pine Labs checkout is roadmap-only). */
function gatewaySupportsLiveCheckout(string $gateway): bool
{
    return in_array($gateway, ['razorpay', 'cashfree', 'payu'], true);
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

    $base = decentroV3ApiBase();
    if (getSetting('decentro_environment', 'sandbox') !== 'sandbox') {
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
    $mid = trim(getSetting('phonepe_merchant_id', ''));
    $salt = trim(getSetting('phonepe_salt_key', ''));
    if ($mid === '' || $salt === '') {
        return ['ok' => false, 'message' => 'PhonePe Merchant ID and Salt Key are required.'];
    }
    return ['ok' => true, 'message' => 'PhonePe keys saved. PhonePe checkout activates in a later release — live checkout currently routes through Razorpay, Cashfree, PayU or direct UPI.'];
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
    if (!isGatewayConfigured('cashfree')) {
        return null;
    }
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

/**
 * Pine Labs Plural — gated scaffold / sandbox stub.
 * Live checkout is NOT enabled (keys pending + roadmap). Sandbox createOrder
 * returns a simulated payload when keys are absent or env=sandbox.
 */
function pineLabsApiBase(): string
{
    return getSetting('pinelabs_environment', 'sandbox') === 'production'
        ? 'https://pluralpayments.com/api'
        : 'https://pluraluat.pinelabs.com/api';
}

/** @return array{ok:bool,message:string,sandbox?:bool,order?:array} */
function testPineLabsConnection(): array
{
    if (!isGatewayConfigured('pinelabs')) {
        return ['ok' => false, 'message' => 'Pine Labs keys pending — paste merchant id, access code, and secure key when received.'];
    }
    $env = getSetting('pinelabs_environment', 'sandbox');
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
        'sandbox' => getSetting('pinelabs_environment', 'sandbox') !== 'production',
        'message' => 'Pine Labs Plural scaffold order prepared. Live redirect activates when checkout routing is enabled.',
        'order_id' => $orderId,
        'redirect_url' => null,
    ];
}

// ─── Decentro v3 UPI P2M Collections ───────────────────────────────────────

function decentroV3ApiBase(): string
{
    return getSetting('decentro_environment', 'sandbox') === 'production'
        ? 'https://api.decentro.tech'
        : 'https://staging.api.decentro.tech';
}

function decentroV3Headers(): array
{
    $clientId = getSetting('decentro_client_id', '');
    $clientSecret = getSetting('decentro_client_secret', '');
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'client_id: ' . $clientId,
        'client_secret: ' . $clientSecret,
    ];
    $moduleSecret = getSetting('decentro_module_secret', '');
    if ($moduleSecret !== '') {
        $headers[] = 'module_secret: ' . $moduleSecret;
    }
    $providerSecret = getSetting('decentro_provider_secret', '');
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
    if (getSetting('decentro_environment', 'sandbox') === 'sandbox') {
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
    $consumerUrn ??= getSetting('decentro_consumer_urn', '');
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
    if (getSetting('decentro_environment', 'sandbox') === 'sandbox') {
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
