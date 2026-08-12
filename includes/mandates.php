<?php
declare(strict_types=1);

/**
 * Recurring Mandate Engine (P3.1)
 *
 * Supports UPI Autopay and eNACH mandates for subscription/recurring billing.
 *
 * Flow:
 *   1. Merchant creates mandate registration link
 *   2. Customer approves mandate via UPI app / bank
 *   3. System polls/webhooks for mandate registration status
 *   4. Cron processes due debits on next_debit_date
 *   5. Failed debits retried with backoff
 */

function ensureMandateSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $db = getDB();
    $sqlFile = __DIR__ . '/../migrations/051_mandates.sql';
    if (is_file($sqlFile)) {
        try {
            foreach (migrationStatements(file_get_contents($sqlFile)) as $stmt) {
                $db->exec($stmt);
            }
        } catch (Throwable $e) { /* ok */ }
    }
    ensureMandateSchemaGColumns();
}

/**
 * G1: Extend mandates + mandate_debits with Block G columns.
 * plan_id links to subscription_plans; channel = upi/card/netbanking;
 * idempotency_key prevents duplicate registration/debit; raw_code + mapped_reason for fail diagnostics.
 */
function ensureMandateSchemaGColumns(): void
{
    $db = getDB();
    $cols = [
        'mandates:plan_id' => 'ALTER TABLE mandates ADD COLUMN plan_id INT DEFAULT NULL',
        'mandates:channel' => "ALTER TABLE mandates ADD COLUMN channel VARCHAR(20) DEFAULT NULL",
        'mandates:idempotency_key' => 'ALTER TABLE mandates ADD COLUMN idempotency_key VARCHAR(128) DEFAULT NULL',
        'mandates:auth_url' => 'ALTER TABLE mandates ADD COLUMN auth_url VARCHAR(500) DEFAULT NULL',
        'mandate_debits:idempotency_key' => 'ALTER TABLE mandate_debits ADD COLUMN idempotency_key VARCHAR(128) DEFAULT NULL',
        'mandate_debits:raw_code' => 'ALTER TABLE mandate_debits ADD COLUMN raw_code VARCHAR(100) DEFAULT NULL',
        'mandate_debits:mapped_reason' => 'ALTER TABLE mandate_debits ADD COLUMN mapped_reason VARCHAR(500) DEFAULT NULL',
        'mandate_debits:partner_payment_id' => 'ALTER TABLE mandate_debits ADD COLUMN partner_payment_id VARCHAR(128) DEFAULT NULL',
        'mandate_debits:retry_count' => 'ALTER TABLE mandate_debits ADD COLUMN retry_count INT NOT NULL DEFAULT 0',
        'mandate_debits:gateway_ref' => 'ALTER TABLE mandate_debits ADD COLUMN gateway_ref VARCHAR(128) DEFAULT NULL',
        'mandate_debits:gateway_response' => 'ALTER TABLE mandate_debits ADD COLUMN gateway_response JSON DEFAULT NULL',
        'mandate_debits:processed_at' => 'ALTER TABLE mandate_debits ADD COLUMN processed_at DATETIME DEFAULT NULL',
    ];
    foreach ($cols as $key => $sql) {
        try { $db->exec($sql); } catch (Throwable $e) { /* column exists */ }
    }
    // Add unique index on idempotency_key for mandate_debits (idempotent debit attempts)
    try { $db->exec('CREATE UNIQUE INDEX idx_debit_idem ON mandate_debits(idempotency_key)'); } catch (Throwable $e) { /* exists */ }
    // Add unique index on mandates idempotency_key (idempotent registration)
    try { $db->exec('CREATE UNIQUE INDEX idx_mandate_idem ON mandates(idempotency_key)'); } catch (Throwable $e) { /* exists */ }
}

function generateMandateRef(): string
{
    return 'MND-' . strtoupper(bin2hex(random_bytes(8)));
}

/**
 * Create a new mandate registration request.
 * G1: Extended with plan_id, channel, idempotency_key.
 * G3: Creates as pending_auth, idempotent on idempotency_key.
 */
function createMandate(
    int $merchantId,
    float $maxAmount,
    string $frequency,
    string $startDate,
    ?string $endDate = null,
    ?int $maxDebits = null,
    ?string $customerName = null,
    ?string $customerEmail = null,
    ?string $customerPhone = null,
    ?string $customerUpiId = null,
    string $mandateType = 'upi_autopay',
    ?int $planId = null,
    string $channel = 'upi',
    ?string $idempotencyKey = null
): array {
    ensureMandateSchema();
    $db = getDB();

    // G3: Idempotency — if same key exists, return existing
    if ($idempotencyKey !== null) {
        try {
            $st = $db->prepare('SELECT id, mandate_ref, status FROM mandates WHERE idempotency_key=? LIMIT 1');
            $st->execute([$idempotencyKey]);
            $existing = $st->fetch();
            if ($existing) {
                return ['ok' => true, 'mandate_id' => (int)$existing['id'], 'mandate_ref' => $existing['mandate_ref'], 'duplicate' => true];
            }
        } catch (Throwable $e) { /* column may not exist yet */ }
    }

    $ref = generateMandateRef();
    $nextDebit = $startDate;
    $status = 'pending'; // G3: pending_auth = pending in existing enum

    try {
        $db->prepare(
            'INSERT INTO mandates
             (merchant_id, mandate_ref, customer_name, customer_email, customer_phone, customer_upi_id,
              mandate_type, status, max_amount, frequency, start_date, end_date, next_debit_date, max_debits,
              plan_id, channel, idempotency_key)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $merchantId, $ref, $customerName, $customerEmail, $customerPhone, $customerUpiId,
            $mandateType, $status, $maxAmount, $frequency, $startDate, $endDate, $nextDebit, $maxDebits,
            $planId, $channel, $idempotencyKey
        ]);

        $mandateId = (int)$db->lastInsertId();

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('mandate_created', $merchantId, 'mandate', (string)$mandateId, "Mandate $ref created for $maxAmount/$frequency via $channel");
        }

        return ['ok' => true, 'mandate_id' => $mandateId, 'mandate_ref' => $ref];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * G3: Register mandate with partner API (Razorpay/Cashfree/Decentro).
 * Only if merchant is live and channel method is enabled.
 * Returns auth_url for customer approval redirect.
 */
function registerMandateWithPartner(int $mandateId): array
{
    ensureMandateSchema();
    $db = getDB();

    $st = $db->prepare('SELECT * FROM mandates WHERE id=?');
    $st->execute([$mandateId]);
    $mandate = $st->fetch();
    if (!$mandate) {
        return ['ok' => false, 'error' => 'Mandate not found.'];
    }

    $merchantId = (int)$mandate['merchant_id'];

    // G3: Check merchant is live
    $mst = $db->prepare('SELECT account_mode FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch();
    if (!$merchant || $merchant['account_mode'] !== 'live') {
        return ['ok' => false, 'error' => 'Merchant must be in live mode to register mandates.'];
    }

    // G3: Check channel method is enabled for an active partner
    $channel = (string)($mandate['channel'] ?? 'upi');
    $partnerConfigured = false;
    if ($channel === 'upi' || $mandate['mandate_type'] === 'upi_autopay') {
        $partnerConfigured = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== ''
            || trim((string)getPartnerSetting('cashfree', 'cashfree_app_id', '')) !== ''
            || trim((string)(getPartnerSetting('decentro', 'decentro_client_id', '') ?: getSetting('decentro_api_key', ''))) !== '';
    } elseif ($channel === 'netbanking' || $mandate['mandate_type'] === 'enach') {
        $partnerConfigured = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== ''
            || trim((string)(getPartnerSetting('decentro', 'decentro_client_id', '') ?: getSetting('decentro_api_key', ''))) !== '';
    } elseif ($channel === 'card') {
        $partnerConfigured = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== ''
            || trim((string)getPartnerSetting('cashfree', 'cashfree_app_id', '')) !== '';
    }

    if (!$partnerConfigured) {
        // G3: No partner configured — keep as pending, merchant can still see it
        return ['ok' => false, 'error' => 'No partner gateway configured for channel ' . $channel . '. Mandate stays pending until partner keys are added.'];
    }

    // G3: Call partner registration API based on configured gateway
    $authUrl = null;
    $gatewayMandateId = null;
    $mandateType = $mandate['mandate_type'] ?? 'upi_autopay';

    if ($mandateType === 'upi_autopay') {
        if (trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== '') {
            $result = razorpayMandateRegistration($mandate);
        } elseif (trim((string)getPartnerSetting('decentro', 'decentro_client_id', '')) !== '') {
            $result = decentroMandateRegistration($mandate);
        } else {
            $result = ['ok' => false, 'error' => 'No UPI Autopay partner configured.'];
        }
    } elseif ($mandateType === 'enach') {
        if (trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== '') {
            $result = razorpayMandateRegistration($mandate);
        } elseif (trim((string)getPartnerSetting('decentro', 'decentro_client_id', '')) !== '') {
            $result = decentroEnachRegistration($mandate);
        } else {
            $result = ['ok' => false, 'error' => 'No eNACH partner configured.'];
        }
    } else {
        $result = ['ok' => false, 'error' => 'Unsupported mandate type.'];
    }

    if (!empty($result['ok'])) {
        $authUrl = $result['auth_url'] ?? null;
        $gatewayMandateId = $result['gateway_mandate_id'] ?? null;

        // G3: Update mandate with gateway details + auth_url
        $db->prepare('UPDATE mandates SET auth_url=?, gateway_mandate_id=?, gateway=? WHERE id=?')
            ->execute([$authUrl, $gatewayMandateId, $result['gateway'] ?? 'unknown', $mandateId]);

        return ['ok' => true, 'auth_url' => $authUrl, 'gateway_mandate_id' => $gatewayMandateId];
    }

    // G3: Partner rejected → mark failed with mapped reason
    $rawError = $result['error'] ?? 'Unknown error';
    $mappedReason = mapMandateFailureReason($rawError, $mandateType);
    $db->prepare("UPDATE mandates SET status='failed', failure_reason=? WHERE id=?")
        ->execute([$mappedReason, $mandateId]);

    return ['ok' => false, 'error' => $mappedReason, 'raw_error' => $rawError];
}

/**
 * G3: Razorpay mandate registration (UPI Autopay / eNACH).
 */
function razorpayMandateRegistration(array $mandate): array
{
    $keyId = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', ''));
    $keySecret = trim((string)getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
    $amountPaise = (int)round((float)$mandate['max_amount'] * 100);

    $payload = json_encode([
        'customer' => [
            'name' => $mandate['customer_name'] ?? '',
            'email' => $mandate['customer_email'] ?? '',
            'contact' => $mandate['customer_phone'] ?? '',
        ],
        'amount' => $amountPaise,
        'currency' => 'INR',
        'method' => $mandate['mandate_type'] === 'enach' ? 'nach' : 'upi',
        'frequency' => $mandate['frequency'] ?? 'monthly',
        'notes' => [
            'merchant_mandate_ref' => $mandate['mandate_ref'] ?? '',
        ],
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/mandates');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['id'])) {
        return ['ok' => true, 'gateway_mandate_id' => $data['id'], 'auth_url' => $data['auth_url'] ?? null, 'gateway' => 'razorpay'];
    }
    return ['ok' => false, 'error' => $data['error']['description'] ?? "HTTP {$httpCode}"];
}

/**
 * G3: Decentro UPI Autopay mandate registration.
 */
function decentroMandateRegistration(array $mandate): array
{
    $clientId = trim((string)getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim((string)getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    $base = decentroBaseUrl();

    $payload = json_encode([
        'reference_id' => $mandate['mandate_ref'] ?? '',
        'customer_vpa' => $mandate['customer_upi_id'] ?? '',
        'amount' => (float)$mandate['max_amount'],
        'frequency' => $mandate['frequency'] ?? 'monthly',
        'purpose' => 'Recurring mandate registration',
    ]);

    $ch = curl_init($base . '/collections/upi/autopay/register');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $clientId,
            'client_secret: ' . $clientSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['mandate_id'])) {
        return ['ok' => true, 'gateway_mandate_id' => $data['data']['mandate_id'], 'auth_url' => $data['data']['auth_url'] ?? null, 'gateway' => 'decentro'];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}

/**
 * G3: Decentro eNACH mandate registration.
 */
function decentroEnachRegistration(array $mandate): array
{
    $clientId = trim((string)getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim((string)getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    $base = decentroBaseUrl();

    $payload = json_encode([
        'reference_id' => $mandate['mandate_ref'] ?? '',
        'amount' => (float)$mandate['max_amount'],
        'frequency' => $mandate['frequency'] ?? 'monthly',
        'customer_name' => $mandate['customer_name'] ?? '',
        'customer_email' => $mandate['customer_email'] ?? '',
        'customer_phone' => $mandate['customer_phone'] ?? '',
    ]);

    $ch = curl_init($base . '/collections/enach/register');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $clientId,
            'client_secret: ' . $clientSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['mandate_id'])) {
        return ['ok' => true, 'gateway_mandate_id' => $data['data']['mandate_id'], 'auth_url' => $data['data']['auth_url'] ?? null, 'gateway' => 'decentro'];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}

/**
 * G3/G4: Map mandate failure reason to user-friendly message.
 * Uses same reason mapping as one-time payments where applicable.
 */
function mapMandateFailureReason(string $rawError, string $mandateType = 'upi_autopay'): string
{
    $raw = strtolower($rawError);
    $maps = [
        'insufficient balance' => 'Customer has insufficient balance in linked account.',
        'mandate revoked' => 'Customer has revoked the mandate in their UPI app or bank.',
        'mandate expired' => 'Mandate has expired. Customer needs to re-authorise.',
        'mandate cancelled' => 'Mandate was cancelled by customer or partner.',
        'invalid vpa' => 'Customer UPI ID is invalid or not registered for Autopay.',
        'customer not found' => 'Customer not found on partner network.',
        'auth failed' => 'Customer authorisation failed at partner/bank end.',
        'limit exceeded' => 'UPI Autopay per-transaction limit exceeded (max ₹2000 for autopay, ₹1 lakh for eNACH).',
        'network error' => 'Network error connecting to partner. Will retry automatically.',
        'timeout' => 'Partner API timed out. Will retry automatically.',
    ];
    foreach ($maps as $pattern => $message) {
        if (str_contains($raw, $pattern)) {
            return $message;
        }
    }
    return $rawError;
}

/**
 * G3: Cancel mandate with partner API (not just local DB).
 */
function cancelMandateWithPartner(int $mandateId, string $reason): array
{
    ensureMandateSchema();
    $db = getDB();

    $st = $db->prepare('SELECT * FROM mandates WHERE id=?');
    $st->execute([$mandateId]);
    $mandate = $st->fetch();
    if (!$mandate) {
        return ['ok' => false, 'error' => 'Mandate not found.'];
    }

    $gatewayMandateId = (string)($mandate['gateway_mandate_id'] ?? '');
    $gateway = (string)($mandate['gateway'] ?? '');

    // G3: Call partner cancel API if gateway mandate ID exists
    if ($gatewayMandateId !== '') {
        if ($gateway === 'razorpay' && trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== '') {
            $keyId = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', ''));
            $keySecret = trim((string)getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
            $ch = curl_init('https://api.razorpay.com/v1/mandates/' . urlencode($gatewayMandateId) . '/cancel');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['reason' => $reason]),
                CURLOPT_USERPWD => $keyId . ':' . $keySecret,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } elseif ($gateway === 'decentro' && trim((string)(getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''))) !== '') {
            $clientId = trim((string)getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
            $clientSecret = trim((string)getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
            $base = decentroBaseUrl();
            $ch = curl_init($base . '/collections/upi/autopay/cancel');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode(['mandate_id' => $gatewayMandateId, 'reason' => $reason]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'client_id: ' . $clientId, 'client_secret: ' . $clientSecret],
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    // Update local status
    cancelMandate($mandateId, $reason);
    return ['ok' => true];
}

/**
 * G3: Pause mandate (local only — partner pause API varies by provider).
 */
function pauseMandate(int $mandateId, int $merchantId): bool
{
    ensureMandateSchema();
    try {
        getDB()->prepare("UPDATE mandates SET status='paused' WHERE id=? AND merchant_id=? AND status IN ('active','registered')")
            ->execute([$mandateId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * G3: Resume a paused mandate.
 */
function resumeMandate(int $mandateId, int $merchantId): bool
{
    ensureMandateSchema();
    try {
        $st = getDB()->prepare('SELECT frequency, next_debit_date FROM mandates WHERE id=? AND merchant_id=? AND status=?');
        $st->execute([$mandateId, $merchantId, 'paused']);
        $m = $st->fetch();
        if (!$m) return false;
        $nextDebit = calculateNextDebitDate(date('Y-m-d'), $m['frequency']);
        getDB()->prepare("UPDATE mandates SET status='active', next_debit_date=? WHERE id=? AND merchant_id=?")
            ->execute([$nextDebit, $mandateId, $merchantId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Update mandate status (e.g., after gateway confirms registration).
 */
if (!function_exists('updateMandateStatus')) {
function updateMandateStatus(int $mandateId, string $status, ?string $gatewayMandateId = null, ?array $gatewayResponse = null): bool
{
    ensureMandateSchema();
    $db = getDB();

    $validStatuses = ['pending', 'registered', 'active', 'paused', 'cancelled', 'failed', 'expired'];
    if (!in_array($status, $validStatuses, true)) {
        return false;
    }

    try {
        $db->prepare("UPDATE mandates SET status = ?, gateway_mandate_id = COALESCE(?, gateway_mandate_id), gateway_response = COALESCE(?, gateway_response) WHERE id = ?")
            ->execute([
                $status,
                $gatewayMandateId,
                $gatewayResponse ? json_encode($gatewayResponse) : null,
                $mandateId,
            ]);

        if ($status === 'active' || $status === 'registered') {
            $st = $db->prepare('SELECT merchant_id, mandate_ref FROM mandates WHERE id = ?');
            $st->execute([$mandateId]);
            $m = $st->fetch();
            if ($m && function_exists('createNotification')) {
                createNotification(
                    (int)$m['merchant_id'],
                    'Mandate Registered',
                    'Your mandate ' . $m['mandate_ref'] . ' has been registered. Recurring debits will start as scheduled.'
                );
            }
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}
}

/**
 * Calculate next debit date based on frequency.
 */
function calculateNextDebitDate(string $currentDate, string $frequency): ?string
{
    $ts = strtotime($currentDate);
    if ($ts === false) {
        return null;
    }

    return match ($frequency) {
        'daily' => date('Y-m-d', strtotime('+1 day', $ts)),
        'weekly' => date('Y-m-d', strtotime('+1 week', $ts)),
        'monthly' => date('Y-m-d', strtotime('+1 month', $ts)),
        'quarterly' => date('Y-m-d', strtotime('+3 months', $ts)),
        'halfyearly' => date('Y-m-d', strtotime('+6 months', $ts)),
        'yearly' => date('Y-m-d', strtotime('+1 year', $ts)),
        'onetime', 'as_presented' => null,
        default => null,
    };
}

/**
 * Process due mandate debits — called by cron.
 * G4: Idempotent debit attempts with idempotency_key, mapped failure reasons, retry policy (max 2 retries with 1-day gap).
 * G6: On success, applies F snapshot (M, P, merchant_net, platform_fee) via captureVerifiedPaymentOrder.
 */
function processDueMandateDebits(): array
{
    ensureMandateSchema();
    $db = getDB();

    $summary = ['checked' => 0, 'debited' => 0, 'failed' => 0, 'skipped' => 0, 'retried' => 0];

    try {
        $st = $db->prepare(
            "SELECT * FROM mandates
             WHERE status = 'active'
               AND next_debit_date <= CURDATE()
               AND (end_date IS NULL OR end_date >= CURDATE())
               AND (max_debits IS NULL OR debit_count < max_debits)
             ORDER BY next_debit_date ASC
             LIMIT 100"
        );
        $st->execute();
        $mandates = $st->fetchAll();
    } catch (Throwable $e) {
        return $summary;
    }

    foreach ($mandates as $mandate) {
        $summary['checked']++;
        $mandateId = (int)$mandate['id'];
        $merchantId = (int)$mandate['merchant_id'];
        $amount = (float)$mandate['max_amount'];

        // G4: Create idempotency key for this debit attempt
        $idemKey = 'debit-' . $mandateId . '-' . $mandate['next_debit_date'];

        // G4: Idempotency check — if debit already exists for this mandate+date, skip
        try {
            $existSt = $db->prepare('SELECT id FROM mandate_debits WHERE mandate_id=? AND idempotency_key=? LIMIT 1');
            $existSt->execute([$mandateId, $idemKey]);
            if ($existSt->fetch()) {
                $summary['skipped']++;
                continue;
            }
        } catch (Throwable $e) { /* column may not exist */ }

        // Create debit record with idempotency key
        try {
            $db->prepare(
                'INSERT INTO mandate_debits (mandate_id, merchant_id, amount, status, attempted_at, idempotency_key)
                 VALUES (?,?,?,\'pending\',NOW(),?)'
            )->execute([$mandateId, $merchantId, $amount, $idemKey]);
            $debitId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            $summary['skipped']++;
            continue;
        }

        // Attempt the debit via gateway
        $debitResult = attemptMandateDebit($mandate, $debitId);

        if ($debitResult['ok']) {
            $summary['debited']++;
            // G6: Apply F snapshot — record payment with split breakdown
            recordMandateDebitPayment($mandate, $debitId, $amount, $debitResult['gateway_ref'] ?? null);
            // Update mandate counters
            $nextDebit = calculateNextDebitDate($mandate['next_debit_date'], $mandate['frequency']);
            $db->prepare(
                "UPDATE mandates
                 SET total_debited = total_debited + ?,
                     debit_count = debit_count + 1,
                     last_debit_date = CURDATE(),
                     last_debit_amount = ?,
                     next_debit_date = ?,
                     status = CASE WHEN max_debits IS NOT NULL AND debit_count + 1 >= max_debits THEN 'expired' ELSE status END
                 WHERE id = ?"
            )->execute([$amount, $amount, $nextDebit, $mandateId]);
        } else {
            $summary['failed']++;
            $rawError = $debitResult['error'] ?? 'Unknown';
            $mappedReason = mapMandateFailureReason($rawError, $mandate['mandate_type'] ?? 'upi_autopay');

            // G4: Store raw_code + mapped_reason
            try {
                $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason=?, raw_code=?, mapped_reason=? WHERE id=?")
                    ->execute([$mappedReason, substr($rawError, 0, 100), $mappedReason, $debitId]);
            } catch (Throwable $e) {
                $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason=? WHERE id=?")
                    ->execute([$mappedReason, $debitId]);
            }

            // G4: Retry policy — max 2 retries with 1-day gap (documented)
            // Retry only for transient failures (network/timeout), not for permanent failures (revoked/insufficient balance)
            $isTransient = str_contains(strtolower($rawError), 'network') || str_contains(strtolower($rawError), 'timeout');
            $retryCount = 0;
            try {
                $retryCount = (int)($db->query("SELECT retry_count FROM mandate_debits WHERE id={$debitId}")->fetchColumn() ?: 0);
            } catch (Throwable $e) {}

            if ($isTransient && $retryCount < 2) {
                try {
                    $db->prepare("UPDATE mandate_debits SET status='retried', retry_count=retry_count+1 WHERE id=?")
                        ->execute([$debitId]);
                    $summary['retried']++;
                } catch (Throwable $e) {}
            }

            // If too many consecutive failures, pause the mandate
            $failCount = getMandateConsecutiveFailures($mandateId);
            if ($failCount >= 3) {
                updateMandateStatus($mandateId, 'paused');
                if (function_exists('createNotification')) {
                    createNotification(
                        $merchantId,
                        'Mandate Paused',
                        'Mandate ' . $mandate['mandate_ref'] . ' has been paused after 3 consecutive failed debit attempts. ' . $mappedReason
                    );
                }
            } else {
                // Notify merchant of failure
                if (function_exists('createNotification')) {
                    createNotification(
                        $merchantId,
                        'Mandate Debit Failed',
                        'Debit of ' . formatMoney($amount) . ' for mandate ' . $mandate['mandate_ref'] . ' failed: ' . $mappedReason
                    );
                }
            }
        }
    }

    return $summary;
}

/**
 * G6: Record a successful mandate debit as a payment with F snapshot.
 * Applies calculateSplitBreakdown (M, P, merchant_net, platform_fee) and posts ledger entries.
 */
function recordMandateDebitPayment(array $mandate, int $debitId, float $amount, ?string $gatewayRef): void
{
    $db = getDB();
    $merchantId = (int)$mandate['merchant_id'];
    $txnRef = generateId('TXN');

    // G6: Calculate split using F snapshot model
    $split = null;
    if (function_exists('calculateSplitBreakdown')) {
        $link = [
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'commission_rate' => 0,
            'collection_mode' => 'upi_autopay',
        ];
        $split = calculateSplitBreakdown($amount, $link);
    }

    $platformFee = $split['platform_fee'] ?? 0;
    $merchantNet = $split['merchant_net'] ?? $amount;
    $mdrM = $split['mdr_m'] ?? 0;
    $mdrP = $split['mdr_p'] ?? 0;
    $partnerFee = $split['partner_fee'] ?? 0;
    $pricingSnapshot = $split['pricing_snapshot'] ?? null;

    // Insert transaction with snapshot fields
    try {
        $db->prepare(
            'INSERT INTO transactions
             (txn_id, transaction_id, merchant_id, amount, status, payment_method, description, utr, is_test, collection_mode, wallet_credited,
              platform_fee, split_amount, mdr_m, mdr_p, partner_fee, pricing_snapshot)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $txnRef, $txnRef, $merchantId, $amount, 'success', 'upi_autopay',
            'Recurring debit for ' . ($mandate['mandate_ref'] ?? ''),
            $gatewayRef ?? '', 0, 'upi_autopay', 0,
            $platformFee, $merchantNet, $mdrM, $mdrP, $partnerFee, $pricingSnapshot,
        ]);
        $txnId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        // Fallback without snapshot columns
        try {
            $db->prepare(
                'INSERT INTO transactions
                 (txn_id, transaction_id, merchant_id, amount, status, payment_method, description, is_test, collection_mode, wallet_credited, platform_fee, split_amount)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $txnRef, $txnRef, $merchantId, $amount, 'success', 'upi_autopay',
                'Recurring debit for ' . ($mandate['mandate_ref'] ?? ''),
                0, 'upi_autopay', 0, $platformFee, $merchantNet,
            ]);
            $txnId = (int)$db->lastInsertId();
        } catch (Throwable $e2) {
            return;
        }
    }

    // Link debit to transaction
    try {
        $db->prepare('UPDATE mandate_debits SET transaction_id=?, partner_payment_id=? WHERE id=?')
            ->execute([$txnId, $gatewayRef, $debitId]);
    } catch (Throwable $e) {}

    // G6: Post balanced ledger entries (same as one-time capture)
    if (function_exists('postBalancedJournal')) {
        try {
            postBalancedJournal(
                'mandate_debit_capture',
                $txnRef,
                [
                    ['account' => 'provider_receivable', 'debit' => $amount, 'credit' => 0],
                    ['account' => 'merchant_payable', 'debit' => 0, 'credit' => $merchantNet],
                    ['account' => 'platform_fee_revenue', 'debit' => 0, 'credit' => $platformFee],
                ],
                $merchantId,
                'Recurring debit capture for ' . ($mandate['mandate_ref'] ?? '')
            );
        } catch (Throwable $e) {}
    }

    // G6: Execute partner route split if partner supports it
    if (function_exists('executePartnerRouteSplit')) {
        try {
            executePartnerRouteSplit($txnId, $merchantId, $amount, $split ?? null);
        } catch (Throwable $e) {}
    }

    // Notify merchant
    if (function_exists('createNotification')) {
        createNotification(
            $merchantId,
            'Recurring Payment Received',
            formatMoney($amount) . ' debited via mandate ' . ($mandate['mandate_ref'] ?? '') . '. Net: ' . formatMoney($merchantNet)
        );
    }
}

/**
 * Attempt a single mandate debit via gateway.
 * Routes to the correct partner adapter based on which gateway keys are configured.
 * Supports: Razorpay Subscriptions, Cashfree Recurring, Decentro UPI Autopay.
 */
function attemptMandateDebit(array $mandate, int $debitId): array
{
    $db = getDB();

    $st = $db->prepare('SELECT * FROM mandate_debits WHERE id=?');
    $st->execute([$debitId]);
    $debit = $st->fetch();
    if (!$debit) {
        return ['ok' => false, 'error' => 'Debit record not found.', 'debit_id' => $debitId];
    }

    $db->prepare("UPDATE mandate_debits SET status='processing', attempted_at=NOW() WHERE id=?")->execute([$debitId]);

    $adapter = resolveMandateDebitAdapter($mandate);
    if (!$adapter) {
        $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason='No mandate debit adapter configured' WHERE id=?")->execute([$debitId]);
        return ['ok' => false, 'error' => 'No mandate debit adapter configured. Add partner gateway keys.', 'debit_id' => $debitId];
    }

    $result = $adapter($mandate, $debit);

    if (!empty($result['ok'])) {
        $db->prepare("UPDATE mandate_debits SET status='success', gateway_ref=?, gateway_response=?, processed_at=NOW() WHERE id=?")
            ->execute([
                substr((string)($result['gateway_ref'] ?? ''), 0, 120),
                json_encode($result['raw'] ?? $result),
                $debitId,
            ]);
        return ['ok' => true, 'gateway_ref' => $result['gateway_ref'] ?? null, 'debit_id' => $debitId];
    }

    $rawError = $result['error'] ?? 'Unknown error';
    $mappedReason = mapMandateFailureReason($rawError, $mandate['mandate_type'] ?? 'upi_autopay');
    try {
        $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason=?, raw_code=?, mapped_reason=?, gateway_response=? WHERE id=?")
            ->execute([
                $mappedReason,
                substr($rawError, 0, 100),
                $mappedReason,
                json_encode($result['raw'] ?? $result),
                $debitId,
            ]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE mandate_debits SET status='failed', failure_reason=?, gateway_response=? WHERE id=?")
            ->execute([
                $mappedReason,
                json_encode($result['raw'] ?? $result),
                $debitId,
            ]);
    }
    return ['ok' => false, 'error' => $rawError, 'debit_id' => $debitId];
}

function resolveMandateDebitAdapter(array $mandate): ?callable
{
    $mandateType = $mandate['mandate_type'] ?? 'upi_autopay';
    $gatewayMandateId = $mandate['gateway_mandate_id'] ?? '';

    if (!$gatewayMandateId) {
        return null;
    }

    if ($mandateType === 'upi_autopay') {
        if (trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== '') {
            return 'razorpayMandateDebitAdapter';
        }
        if (trim((string)getPartnerSetting('cashfree', 'cashfree_app_id', '')) !== '') {
            return 'cashfreeMandateDebitAdapter';
        }
        if (trim((string)getPartnerSetting('decentro', 'decentro_client_id', '') ?: getSetting('decentro_api_key', '')) !== '') {
            return 'decentroMandateDebitAdapter';
        }
    }

    if ($mandateType === 'enach') {
        if (trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== '') {
            return 'razorpayMandateDebitAdapter';
        }
        if (trim((string)getPartnerSetting('decentro', 'decentro_client_id', '') ?: getSetting('decentro_api_key', '')) !== '') {
            return 'decentroEnachDebitAdapter';
        }
    }

    return null;
}

function razorpayMandateDebitAdapter(array $mandate, array $debit): array
{
    $keyId = trim((string)getPartnerSetting('razorpay', 'razorpay_key_id', ''));
    $keySecret = trim((string)getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
    $gatewayMandateId = $mandate['gateway_mandate_id'] ?? '';
    $amount = (float)$mandate['max_amount'];
    $amountPaise = (int)round($amount * 100);

    $payload = json_encode([
        'amount' => $amountPaise,
        'currency' => 'INR',
        'mandate_id' => $gatewayMandateId,
        'notes' => [
            'merchant_mandate_ref' => $mandate['mandate_ref'] ?? '',
            'debit_id' => (string)($debit['id'] ?? ''),
        ],
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/payments');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Network error: ' . $curlError, 'raw' => null];
    }

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['id'])) {
        return ['ok' => true, 'gateway_ref' => $data['id'], 'raw' => $data];
    }

    return ['ok' => false, 'error' => $data['error']['description'] ?? "HTTP {$httpCode}", 'raw' => $data];
}

function cashfreeMandateDebitAdapter(array $mandate, array $debit): array
{
    $appId = trim((string)getSetting('cashfree_app_id', ''));
    $secretKey = trim((string)getSetting('cashfree_secret_key', ''));
    $baseUrl = trim(getSetting('cashfree_base_url', 'https://api.cashfree.com'));
    $gatewayMandateId = $mandate['gateway_mandate_id'] ?? '';
    $amount = (float)$mandate['max_amount'];

    $payload = json_encode([
        'mandate_id' => $gatewayMandateId,
        'amount' => $amount,
        'reference_id' => $mandate['mandate_ref'] . '-' . ($debit['id'] ?? ''),
    ]);

    $ch = curl_init($baseUrl . '/v2/mandates/debit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-version: 2022-09-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secretKey,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['cf_payment_id'])) {
        return ['ok' => true, 'gateway_ref' => (string)$data['cf_payment_id'], 'raw' => $data];
    }

    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}", 'raw' => $data];
}

function decentroMandateDebitAdapter(array $mandate, array $debit): array
{
    $clientId = trim((string)getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim((string)getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    $base = decentroBaseUrl();
    $gatewayMandateId = $mandate['gateway_mandate_id'] ?? '';
    $amount = (float)$mandate['max_amount'];

    $payload = json_encode([
        'reference_id' => $mandate['mandate_ref'] . '-' . ($debit['id'] ?? ''),
        'mandate_id' => $gatewayMandateId,
        'amount' => $amount,
        'purpose' => 'Recurring debit for ' . ($mandate['mandate_ref'] ?? ''),
    ]);

    $ch = curl_init($base . '/collections/upi/autopay/debit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $clientId,
            'client_secret: ' . $clientSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['transaction_id'])) {
        return ['ok' => true, 'gateway_ref' => $data['data']['transaction_id'], 'raw' => $data];
    }

    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}", 'raw' => $data];
}

function decentroEnachDebitAdapter(array $mandate, array $debit): array
{
    $clientId = trim((string)getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim((string)getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    $base = decentroBaseUrl();
    $gatewayMandateId = $mandate['gateway_mandate_id'] ?? '';
    $amount = (float)$mandate['max_amount'];

    $payload = json_encode([
        'reference_id' => $mandate['mandate_ref'] . '-' . ($debit['id'] ?? ''),
        'mandate_id' => $gatewayMandateId,
        'amount' => $amount,
    ]);

    $ch = curl_init($base . '/collections/enach/debit');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $clientId,
            'client_secret: ' . $clientSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['transaction_id'])) {
        return ['ok' => true, 'gateway_ref' => $data['data']['transaction_id'], 'raw' => $data];
    }

    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}", 'raw' => $data];
}

/**
 * Get consecutive failure count for a mandate.
 */
function getMandateConsecutiveFailures(int $mandateId): int
{
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) FROM mandate_debits
             WHERE mandate_id = ?
               AND status = 'failed'
               AND id > COALESCE(
                 (SELECT MAX(id) FROM mandate_debits WHERE mandate_id = ? AND status = 'success'),
                 0
               )"
        );
        $st->execute([$mandateId, $mandateId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get mandates for a merchant.
 */
function getMerchantMandates(int $merchantId, int $limit = 50): array
{
    ensureMandateSchema();
    try {
        $st = getDB()->prepare(
            'SELECT * FROM mandates WHERE merchant_id = ? ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$merchantId, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get mandate debit history.
 */
function getMandateDebits(int $mandateId, int $limit = 100): array
{
    ensureMandateSchema();
    try {
        $st = getDB()->prepare(
            'SELECT * FROM mandate_debits WHERE mandate_id = ? ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$mandateId, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Cancel a mandate.
 */
function cancelMandate(int $mandateId, string $reason): bool
{
    ensureMandateSchema();
    $db = getDB();
    try {
        $st = $db->prepare('SELECT merchant_id, mandate_ref FROM mandates WHERE id = ?');
        $st->execute([$mandateId]);
        $m = $st->fetch();
        if (!$m) {
            return false;
        }

        $db->prepare("UPDATE mandates SET status = 'cancelled', failure_reason = ? WHERE id = ? AND status IN ('active','paused','registered','pending')")
            ->execute([$reason, $mandateId]);

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('mandate_cancelled', (int)$m['merchant_id'], 'mandate', (string)$mandateId, $reason);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * G5: Update mandate status from partner webhook.
 * Idempotent — looks up by gateway_mandate_id, only updates if status changed.
 * Maps partner statuses to local enum: active, cancelled, failed, paused, expired.
 */
function updateMandateStatusFromWebhook(string $gateway, string $gatewayMandateId, string $partnerStatus, array $entity = []): void
{
    ensureMandateSchema();
    $db = getDB();

    $st = $db->prepare('SELECT id, status, merchant_id, mandate_ref FROM mandates WHERE gateway_mandate_id=? AND gateway=? LIMIT 1');
    $st->execute([$gatewayMandateId, $gateway]);
    $mandate = $st->fetch();
    if (!$mandate) {
        return;
    }

    $currentStatus = strtolower((string)$mandate['status']);
    $newStatus = match ($partnerStatus) {
        'active', 'authorised', 'approved', 'registered' => 'active',
        'cancelled', 'revoked' => 'cancelled',
        'failed', 'rejected' => 'failed',
        'paused', 'suspended' => 'paused',
        'expired' => 'expired',
        default => null,
    };

    if ($newStatus === null || $newStatus === $currentStatus) {
        return;
    }

    updateMandateStatus((int)$mandate['id'], $newStatus, null, $entity);

    if (function_exists('createNotification')) {
        $msg = match ($newStatus) {
            'active' => 'Mandate ' . $mandate['mandate_ref'] . ' is now active. Recurring debits will start as scheduled.',
            'cancelled' => 'Mandate ' . $mandate['mandate_ref'] . ' has been cancelled.',
            'failed' => 'Mandate ' . $mandate['mandate_ref'] . ' registration failed.',
            'paused' => 'Mandate ' . $mandate['mandate_ref'] . ' has been paused.',
            'expired' => 'Mandate ' . $mandate['mandate_ref'] . ' has expired.',
            default => 'Mandate ' . $mandate['mandate_ref'] . ' status updated to ' . $newStatus,
        };
        createNotification((int)$mandate['merchant_id'], 'Mandate Status Update', $msg);
    }
}
