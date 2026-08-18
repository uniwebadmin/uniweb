<?php
declare(strict_types=1);

/**
 * Partner payout HTTP helpers — RazorpayX + Cashfree Payouts.
 * Keys: Partner Registry only (razorpayx / cashfree payout fields).
 */

function payoutPartnerRequireControl(): void
{
    if (!function_exists('getPartnerSetting')) {
        require_once __DIR__ . '/partner_control.php';
    }
}

/** @return array<string, mixed>|null */
function payoutLoadMerchant(int $merchantId): ?array
{
    if ($merchantId <= 0) {
        return null;
    }
    try {
        $st = getDB()->prepare('SELECT * FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Build bank payload for RazorpayX from a payout_beneficiaries row.
 *
 * @param array<string, mixed> $beneficiary
 * @return array<string, mixed>
 */
function payoutBuildBankFromBeneficiary(array $beneficiary): array
{
    $accountNumber = (string)($beneficiary['account_number'] ?? '');
    if ($accountNumber !== '' && function_exists('sensitiveDecrypt')) {
        $dec = sensitiveDecrypt($accountNumber);
        if ($dec !== '') {
            $accountNumber = $dec;
        }
    }

    return [
        'id' => (int)($beneficiary['id'] ?? 0),
        'account_holder' => (string)($beneficiary['account_holder'] ?? ''),
        'account_number' => $accountNumber,
        'ifsc_code' => strtoupper(trim((string)($beneficiary['ifsc_code'] ?? ''))),
        'bank_name' => (string)($beneficiary['bank_name'] ?? ''),
        'razorpay_contact_id' => (string)($beneficiary['razorpay_contact_id'] ?? ''),
        'razorpay_fund_account_id' => (string)($beneficiary['razorpay_fund_account_id'] ?? ''),
    ];
}

function payoutReferenceFromJob(array $job): string
{
    $ref = trim((string)($job['job_id'] ?? ''));
    if ($ref !== '') {
        return $ref;
    }
    $orderId = (int)($job['payout_order_id'] ?? 0);
    if ($orderId > 0) {
        try {
            $st = getDB()->prepare('SELECT payout_id FROM payout_orders WHERE id=? LIMIT 1');
            $st->execute([$orderId]);
            $pid = trim((string)($st->fetchColumn() ?: ''));
            if ($pid !== '') {
                return $pid;
            }
        } catch (Throwable $e) { /* ok */ }
    }
    return 'PJOB' . (int)($job['id'] ?? 0);
}

function razorpayxPlatformAccountNumber(): string
{
    payoutPartnerRequireControl();
    return razorpayxAccountNumber();
}

/**
 * Dispatch via existing RazorpayX helper in gateways.php.
 *
 * @return array{ok:bool,partner_ref?:string,utr?:string,error?:string,pending?:bool}
 */
function razorpayxDispatchPayoutJob(array $job, array $beneficiary): array
{
    if (!function_exists('createRazorpayXPayout')) {
        require_once __DIR__ . '/gateways.php';
    }

    $merchantId = (int)($job['merchant_id'] ?? 0);
    $merchant = payoutLoadMerchant($merchantId);
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found for payout job.'];
    }

    $bank = payoutBuildBankFromBeneficiary($beneficiary);
    if ($bank['account_number'] === '' || $bank['ifsc_code'] === '') {
        return ['ok' => false, 'error' => 'Beneficiary bank account or IFSC is missing.'];
    }

    // Reuse Razorpay contact/fund ids from verified settlement bank when account matches.
    try {
        $bst = getDB()->prepare('SELECT * FROM bank_accounts WHERE merchant_id=? AND status="verified" ORDER BY id DESC LIMIT 1');
        $bst->execute([$merchantId]);
        $verified = $bst->fetch();
        if ($verified && function_exists('sensitiveDecrypt')) {
            $verifiedAcct = sensitiveDecrypt((string)($verified['account_number'] ?? ''));
            if ($verifiedAcct !== '' && $verifiedAcct === $bank['account_number']) {
                $bank = array_merge($bank, $verified);
            }
        }
    } catch (Throwable $e) { /* ok */ }

    $platformAccount = razorpayxPlatformAccountNumber();
    if ($platformAccount === '') {
        return [
            'ok' => false,
            'error' => 'RazorpayX platform account number (razorpayx_account_number) is required in Partner Registry before live payout.',
        ];
    }

    $amount = (float)($job['amount'] ?? 0);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Payout amount must be greater than zero.'];
    }

    $reference = payoutReferenceFromJob($job);
    $resp = createRazorpayXPayout($merchant, $bank, $amount, $reference);
    if (!is_array($resp) || trim((string)($resp['id'] ?? '')) === '') {
        return [
            'ok' => false,
            'error' => 'RazorpayX payout API rejected the request. Verify RazorpayX keys, platform account number, and beneficiary IFSC/account.',
        ];
    }

    $status = strtolower((string)($resp['status'] ?? 'processing'));
    if ($status === 'failed' || $status === 'reversed') {
        $reason = (string)($resp['failure_reason'] ?? $resp['status_details']['reason'] ?? 'Payout failed at RazorpayX');
        return ['ok' => false, 'error' => $reason];
    }

    $partnerRef = (string)$resp['id'];
    $utr = trim((string)($resp['utr'] ?? $resp['reference_id'] ?? ''));

    return [
        'ok' => true,
        'partner_ref' => $partnerRef,
        'utr' => $utr !== '' ? $utr : $partnerRef,
        'pending' => in_array($status, ['queued', 'pending', 'processing'], true),
    ];
}

/** @return array{status:string,utr?:string,error?:string} */
function razorpayxCheckPayoutStatus(array $job): array
{
    $partnerRef = trim((string)($job['partner_ref'] ?? ''));
    if ($partnerRef === '') {
        return ['status' => 'pending', 'error' => 'No RazorpayX payout id on job.'];
    }
    if (!function_exists('fetchRazorpayXPayout')) {
        require_once __DIR__ . '/gateways.php';
    }
    $resp = fetchRazorpayXPayout($partnerRef);
    if (!is_array($resp)) {
        return ['status' => 'pending', 'error' => 'Could not fetch RazorpayX payout status.'];
    }
    $status = strtolower((string)($resp['status'] ?? 'pending'));
    if ($status === 'processed') {
        return ['status' => 'success', 'utr' => (string)($resp['utr'] ?? $resp['reference_id'] ?? $partnerRef)];
    }
    if (in_array($status, ['failed', 'reversed', 'cancelled', 'rejected'], true)) {
        return [
            'status' => 'failed',
            'error' => (string)($resp['failure_reason'] ?? $resp['status_details']['reason'] ?? 'Payout failed at RazorpayX'),
        ];
    }
    return ['status' => 'pending', 'utr' => (string)($resp['utr'] ?? '')];
}

function cashfreePayoutApiBase(): string
{
    payoutPartnerRequireControl();
    $env = function_exists('getPartnerEnvironment')
        ? getPartnerEnvironment('cashfree', 'sandbox')
        : 'sandbox';
    return $env === 'production' ? 'https://payout-api.cashfree.com' : 'https://payout-gamma.cashfree.com';
}

/** @return array{client_id:string,client_secret:string} */
function cashfreePayoutCredentials(): array
{
    payoutPartnerRequireControl();
    return [
        'client_id' => trim(getPartnerSetting('cashfree', 'cashfree_payout_client_id', '')),
        'client_secret' => trim(getPartnerSetting('cashfree', 'cashfree_payout_client_secret', '')),
    ];
}

/**
 * @return array{ok:bool,http:int,data:?array,error:string}
 */
function cashfreePayoutRequest(string $method, string $path, ?array $body = null, ?string $bearerToken = null): array
{
    $creds = cashfreePayoutCredentials();
    if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
        return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'Cashfree Payout client id/secret missing in Partner Registry.'];
    }

    $url = rtrim(cashfreePayoutApiBase(), '/') . '/' . ltrim($path, '/');
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if ($bearerToken !== null && $bearerToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $bearerToken;
    } else {
        $headers[] = 'X-Client-Id: ' . $creds['client_id'];
        $headers[] = 'X-Client-Secret: ' . $creds['client_secret'];
    }

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 45,
    ];
    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);
    $raw = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'http' => $http, 'data' => null, 'error' => $curlErr];
    }

    $data = json_decode($raw, true);
    $parsed = is_array($data) ? $data : null;
    $apiStatus = strtolower((string)($parsed['status'] ?? ''));
    $ok = $http >= 200 && $http < 300 && ($apiStatus === '' || $apiStatus === 'success' || $apiStatus === 'ok');

    if ($ok) {
        return ['ok' => true, 'http' => $http, 'data' => $parsed, 'error' => ''];
    }

    $message = (string)($parsed['message'] ?? $parsed['error'] ?? $raw);
    if ($message === '') {
        $message = 'Cashfree Payout API HTTP ' . $http;
    }

    return ['ok' => false, 'http' => $http, 'data' => $parsed, 'error' => $message];
}

function cashfreePayoutAuthorizeToken(): ?string
{
    $res = cashfreePayoutRequest('POST', '/payout/v1/authorize');
    if (!$res['ok']) {
        return null;
    }
    return trim((string)($res['data']['data']['token'] ?? '')) ?: null;
}

function cashfreePayoutBeneficiaryId(int $merchantId, int $beneficiaryId): string
{
    return 'UW' . $merchantId . 'B' . $beneficiaryId;
}

/**
 * Ensure beneficiary exists at Cashfree; returns beneId.
 */
function cashfreeEnsureBeneficiary(array $merchant, array $beneficiary, string $token): array
{
    $merchantId = (int)($merchant['id'] ?? 0);
    $beneficiaryId = (int)($beneficiary['id'] ?? 0);
    $beneId = cashfreePayoutBeneficiaryId($merchantId, $beneficiaryId);
    $bank = payoutBuildBankFromBeneficiary($beneficiary);

    if ($bank['account_number'] === '' || $bank['ifsc_code'] === '') {
        return ['ok' => false, 'error' => 'Beneficiary bank account or IFSC is missing.'];
    }

    $payload = [
        'beneId' => $beneId,
        'name' => $bank['account_holder'] !== '' ? $bank['account_holder'] : (string)($merchant['business_name'] ?? 'Beneficiary'),
        'email' => (string)($merchant['email'] ?? COMPANY_SUPPORT_EMAIL),
        'phone' => preg_replace('/\D/', '', (string)($merchant['phone'] ?? '')) ?: '9999999999',
        'bankAccount' => $bank['account_number'],
        'ifsc' => $bank['ifsc_code'],
        'address1' => 'India',
        'city' => (string)($merchant['city'] ?? 'NA'),
        'state' => (string)($merchant['state'] ?? 'NA'),
        'pincode' => (string)($merchant['pincode'] ?? '110001'),
    ];

    $add = cashfreePayoutRequest('POST', '/payout/v1/addBeneficiary', $payload, $token);
    if ($add['ok']) {
        return ['ok' => true, 'bene_id' => $beneId];
    }

    // Already registered is acceptable for idempotent re-dispatch.
    $msg = strtolower($add['error']);
    if (str_contains($msg, 'already') || str_contains($msg, 'exist')) {
        return ['ok' => true, 'bene_id' => $beneId];
    }

    return ['ok' => false, 'error' => 'Cashfree addBeneficiary failed: ' . $add['error']];
}

/**
 * @return array{ok:bool,partner_ref?:string,utr?:string,error?:string,pending?:bool}
 */
function cashfreeDispatchPayoutJob(array $job, array $beneficiary): array
{
    $merchantId = (int)($job['merchant_id'] ?? 0);
    $merchant = payoutLoadMerchant($merchantId);
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found for payout job.'];
    }

    $amount = round((float)($job['amount'] ?? 0), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Payout amount must be greater than zero.'];
    }

    $token = cashfreePayoutAuthorizeToken();
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => 'Cashfree Payout authorize failed. Check payout client id/secret and IP whitelist.'];
    }

    $bene = cashfreeEnsureBeneficiary($merchant, $beneficiary, $token);
    if (empty($bene['ok'])) {
        return ['ok' => false, 'error' => (string)($bene['error'] ?? 'Could not register beneficiary at Cashfree.')];
    }

    $transferId = payoutReferenceFromJob($job);
    $transferMode = $amount <= 500000 ? 'imps' : 'neft';
    $transfer = cashfreePayoutRequest('POST', '/payout/v1/requestTransfer', [
        'beneId' => $bene['bene_id'],
        'amount' => (string)$amount,
        'transferId' => $transferId,
        'transferMode' => $transferMode,
    ], $token);

    if (!$transfer['ok']) {
        return ['ok' => false, 'error' => 'Cashfree requestTransfer failed: ' . $transfer['error']];
    }

    $data = $transfer['data']['data'] ?? $transfer['data'] ?? [];
    $reference = trim((string)($data['referenceId'] ?? $data['transferId'] ?? $transferId));
    $utr = trim((string)($data['utr'] ?? $data['bankReferenceNo'] ?? ''));
    $status = strtolower((string)($data['transferStatus'] ?? $data['status'] ?? 'pending'));

    if (in_array($status, ['failed', 'rejected', 'reversed'], true)) {
        return ['ok' => false, 'error' => (string)($data['statusDescription'] ?? 'Transfer failed at Cashfree.')];
    }

    return [
        'ok' => true,
        'partner_ref' => $reference,
        'utr' => $utr !== '' ? $utr : $reference,
        'pending' => !in_array($status, ['success', 'completed', 'processed'], true),
    ];
}

/** @return array{status:string,utr?:string,error?:string} */
function cashfreeCheckPayoutStatus(array $job): array
{
    $transferId = trim((string)($job['partner_ref'] ?? ''));
    if ($transferId === '') {
        $transferId = payoutReferenceFromJob($job);
    }

    $token = cashfreePayoutAuthorizeToken();
    if ($token === null || $token === '') {
        return ['status' => 'pending', 'error' => 'Cashfree authorize failed during status check.'];
    }

    $res = cashfreePayoutRequest('GET', '/payout/v1/getTransferStatus?transferId=' . rawurlencode($transferId), null, $token);
    if (!$res['ok']) {
        return ['status' => 'pending', 'error' => $res['error']];
    }

    $data = $res['data']['data'] ?? $res['data'] ?? [];
    $status = strtolower((string)($data['transferStatus'] ?? $data['status'] ?? 'pending'));
    if (in_array($status, ['success', 'completed', 'processed'], true)) {
        return ['status' => 'success', 'utr' => (string)($data['utr'] ?? $data['bankReferenceNo'] ?? $transferId)];
    }
    if (in_array($status, ['failed', 'rejected', 'reversed'], true)) {
        return ['status' => 'failed', 'error' => (string)($data['statusDescription'] ?? 'Transfer failed at Cashfree.')];
    }
    return ['status' => 'pending'];
}

function resolveDefaultPayoutAdapterName(): ?string
{
    payoutPartnerRequireControl();
    $rzxKey = trim(getPartnerSetting('razorpayx', 'razorpayx_key_id', ''));
    $rzxSecret = trim(getPartnerSetting('razorpayx', 'razorpayx_key_secret', ''));
    if ($rzxKey !== '' && $rzxSecret !== '' && !str_contains(strtolower($rzxKey), 'pending')) {
        return 'razorpayx';
    }
    $cfId = trim(getPartnerSetting('cashfree', 'cashfree_payout_client_id', ''));
    $cfSecret = trim(getPartnerSetting('cashfree', 'cashfree_payout_client_secret', ''));
    if ($cfId !== '' && $cfSecret !== '' && !str_contains(strtolower($cfId), 'pending')) {
        return 'cashfree';
    }
    return null;
}
