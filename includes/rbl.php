<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 *  RBL Bank Open Banking adapter — sandbox-first (inbox API specs).
 *  Rails: VA, VA V2, VA Creation, UPI Collection, Blob VA, Account
 *  balance, Corporate single payment, Bulk payment.
 *  Keys: Partner Registry → RBL → Keys only (never gateway_settings).
 *  Live/production keys later — no fake LIVE, no demo Corp defaults.
 * ------------------------------------------------------------------ */

function rblPartnerCredential(string $field, string $default = ''): string
{
    if (!function_exists('getPartnerSetting') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (function_exists('getPartnerSetting')) {
        $v = trim(getPartnerSetting('rbl', $field, ''));
        if ($v !== '') {
            return $v;
        }
    }
    return $default;
}

/** Primary sandbox host from Virtual Account / UPI / VA Creation specs. */
function rblSandboxPrimaryBaseUrl(): string
{
    return 'https://apisandbox.rbl.bank.in/sandbox/api/v1';
}

/** Alternate sandbox host used by Blob VA / Bulk / Corporate Account specs. */
function rblSandboxAltBaseUrl(): string
{
    return 'https://apisandbox.rblbank.com/sandbox/api/v1';
}

function rblProductionBaseUrl(): string
{
    return 'https://api.rbl.bank.in/api/v1';
}

function rblBaseUrl(): string
{
    $custom = rblPartnerCredential('rbl_base_url', '');
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    return rblPartnerCredential('rbl_environment', 'sandbox') === 'production'
        ? rblProductionBaseUrl()
        : rblSandboxPrimaryBaseUrl();
}

/** Pick host for path — inbox OpenAPI servers differ by product. */
function rblBaseUrlForPath(string $path): string
{
    $custom = rblPartnerCredential('rbl_base_url', '');
    if ($custom !== '') {
        return rtrim($custom, '/');
    }
    if (rblPartnerCredential('rbl_environment', 'sandbox') === 'production') {
        return rblProductionBaseUrl();
    }
    $path = '/' . ltrim($path, '/');
    foreach (['/blob/va', '/payment/bulk', '/account/balance'] as $alt) {
        if (str_starts_with($path, $alt)) {
            return rblSandboxAltBaseUrl();
        }
    }
    return rblSandboxPrimaryBaseUrl();
}

function rblCredentials(): array
{
    $base = rblPartnerCredential('rbl_base_url', '');
    return [
        'client_id' => rblPartnerCredential('rbl_client_id', ''),
        'client_secret' => rblPartnerCredential('rbl_client_secret', ''),
        'corp_id' => rblPartnerCredential('rbl_corp_id', ''),
        'app_name' => rblPartnerCredential('rbl_app_name', 'UniWeb'),
        'environment' => rblPartnerCredential('rbl_environment', 'sandbox'),
        'base_url' => $base !== '' ? rtrim($base, '/') : rblBaseUrl(),
        'master_account' => rblPartnerCredential('rbl_master_account', ''),
        'maker_id' => rblPartnerCredential('rbl_maker_id', ''),
        'checker_id' => rblPartnerCredential('rbl_checker_id', ''),
        'approver_id' => rblPartnerCredential('rbl_approver_id', ''),
    ];
}

function isRblConfigured(): bool
{
    return rblPartnerCredential('rbl_client_id', '') !== '' && rblPartnerCredential('rbl_client_secret', '') !== '';
}

/**
 * Block money-moving RBL calls until Corp ID + Master Account are set (no demo defaults).
 *
 * @return array{http:int,error:string,body:array}|null null when allowed
 */
function rblOperationalGate(): ?array
{
    if (!function_exists('isRblOperational') && is_file(__DIR__ . '/rbl_workflow.php')) {
        require_once __DIR__ . '/rbl_workflow.php';
    }
    if (function_exists('isRblOperational') && !isRblOperational()) {
        $reason = function_exists('rblGateBlockedReason') ? rblGateBlockedReason() : 'RBL not operational — paste Corp ID and Master Account in Partner Registry.';
        return ['http' => 0, 'error' => $reason, 'body' => []];
    }
    return null;
}

/**
 * VA_SerialNo must be numeric, length 9–16 (sandbox OpenAPI test cases).
 */
function rblVaSerialForMerchant(int $merchantId): string
{
    $n = max(1, $merchantId);
    $serial = str_pad((string)$n, 12, '0', STR_PAD_LEFT);
    if (strlen($serial) > 16) {
        $serial = substr($serial, -16);
    }
    return $serial;
}

/** Client_Id: alphanumeric, max 7 chars per sandbox specs. */
function rblClientIdForRequest(): string
{
    $raw = preg_replace('/[^A-Za-z0-9]/', '', rblPartnerCredential('rbl_app_name', 'UniWeb')) ?: 'WEBUI';
    $raw = strtoupper((string)$raw);
    return substr($raw, 0, 7);
}

/** Parse RBL VA create response into checkout-friendly shape. */
function rblParseVaResponse(?array $res): ?array
{
    if (!$res || (int)($res['http'] ?? 0) !== 200) {
        return null;
    }
    $body = $res['body'] ?? [];
    if (!is_array($body)) {
        return null;
    }
    $candidates = [$body];
    foreach (['create_VA', 'Create_VA', 'createVA', 'response', 'Body', 'Acc_Stmt_DtRng_Res'] as $wrap) {
        if (!empty($body[$wrap]) && is_array($body[$wrap])) {
            $candidates[] = $body[$wrap];
            if (!empty($body[$wrap]['Body']) && is_array($body[$wrap]['Body'])) {
                $candidates[] = $body[$wrap]['Body'];
            }
            if (!empty($body[$wrap]['Header']) && is_array($body[$wrap]['Header'])) {
                $candidates[] = $body[$wrap]['Header'];
            }
        }
    }
    $fields = [
        'va_number' => ['Full_VA_Number', 'full_va_number', 'VA_Number', 'va_number', 'VirtualAccountNumber', 'AccountNumber', 'account_number'],
        'va_id' => ['VA_Id', 'va_id', 'VirtualAccountId', 'VA_Number', 'Id'],
        'ifsc' => ['IFSC', 'ifsc', 'IFSC_Code'],
        'upi_id' => ['UPI_Id', 'upi_id', 'VPA', 'vpa'],
    ];
    $out = [];
    foreach ($candidates as $node) {
        foreach ($fields as $canonical => $keys) {
            if (!empty($out[$canonical])) {
                continue;
            }
            foreach ($keys as $k) {
                if (!empty($node[$k])) {
                    $out[$canonical] = trim((string)$node[$k]);
                    break;
                }
            }
        }
    }
    if (empty($out['va_number'])) {
        return null;
    }
    $out['va_id'] = $out['va_id'] ?? $out['va_number'];
    return $out;
}

/**
 * Create merchant VA via RBL when operational — no fake defaults.
 *
 * @param array<string,mixed> $merchant
 * @return array<string,mixed>|null
 */
function createRblVirtualAccount(array $merchant): ?array
{
    $gate = rblOperationalGate();
    if ($gate !== null) {
        return null;
    }
    $merchantId = (int)($merchant['id'] ?? $merchant['merchant_id'] ?? 0);
    $name = trim((string)($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant'));
    if ($name === '') {
        $name = 'Merchant ' . $merchantId;
    }
    $name = mb_substr(preg_replace('/[^A-Za-z0-9 &\.\,\-\/\(\)]/', '', $name) ?: 'Merchant', 0, 100);
    $serial = rblVaSerialForMerchant($merchantId);
    $res = rblCreateVirtualAccount($serial, $name);
    $parsed = rblParseVaResponse($res);
    if ($parsed) {
        $parsed['_source'] = 'rbl_api';
        $parsed['_serial'] = $serial;
        return $parsed;
    }
    return null;
}

/** Generic RBL API call. Auth is client_id + client_secret as query params (OpenAPI). */
function rblApiRequest(string $method, string $path, ?array $body = null, int $timeout = 30): ?array
{
    $c = rblCredentials();
    if (!$c['client_id'] || !$c['client_secret']) {
        return null;
    }
    $path = '/' . ltrim($path, '/');
    $base = rblBaseUrlForPath($path);
    $url = rtrim($base, '/') . $path;
    $url .= (strpos($url, '?') === false ? '?' : '&')
        . 'client_id=' . rawurlencode($c['client_id'])
        . '&client_secret=' . rawurlencode($c['client_secret']);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => $c['environment'] !== 'sandbox',
    ];
    if ($body !== null && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    }
    curl_setopt_array($ch, $opts);

    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['http' => 0, 'error' => $err, 'body' => [], 'base' => $base];
    }
    $data = json_decode($response, true);
    return ['http' => $http, 'error' => '', 'body' => is_array($data) ? $data : [], 'base' => $base];
}

/**
 * Sandbox Test Connection — never seeds demo Corp IDs as saved defaults.
 *
 * @return array{ok:bool,message:string}
 */
function testRblConnection(): array
{
    if (!isRblConfigured()) {
        return ['ok' => false, 'message' => 'RBL Client ID and Client Secret are required in Partner Registry (Sandbox Keys tab).'];
    }
    $c = rblCredentials();

    // Phase 1: auth reachability — empty body → 400 if keys accepted, 401 if bad keys.
    $authProbe = rblApiRequest('POST', '/virtual/account', [], 15);
    $authHttp = (int)($authProbe['http'] ?? 0);
    if ($authHttp === 401) {
        return ['ok' => false, 'message' => 'RBL sandbox rejected Client ID/Secret (HTTP 401). Re-paste sandbox Key + Secret from portal.'];
    }
    if ($authHttp === 0) {
        $err = (string)($authProbe['error'] ?? 'unreachable');
        return ['ok' => false, 'message' => 'RBL sandbox not reachable: ' . mb_substr($err, 0, 120)];
    }
    if ($authHttp === 403) {
        return ['ok' => false, 'message' => 'RBL sandbox returned 403 — subscribe VA product on sandbox.rbl.bank.in for this app.'];
    }

    if ($c['corp_id'] === '' || $c['master_account'] === '') {
        return [
            'ok' => true,
            'message' => 'Sandbox Key/Secret accepted by RBL (HTTP ' . $authHttp . '). Paste Corp ID + Master Account to unlock VA/UPI create probes. Live keys later.',
        ];
    }

    // Phase 2: operational VA probe with Owner-pasted Corp + Master (no demo defaults).
    $paths = ['/virtual/account', '/virtual/v2/account', '/va/create', '/upi/collection'];
    foreach ($paths as $p) {
        $serial = rblVaSerialForMerchant((int)(time() % 1000000000));
        $res = rblApiRequest('POST', $p, rblVaBody($serial, $c['app_name'] ?: 'UniWeb'), 15);
        $http = (int)($res['http'] ?? 0);
        if ($http === 200) {
            return ['ok' => true, 'message' => 'RBL sandbox ' . $p . ' connected (HTTP 200).'];
        }
        if ($http === 403) {
            return ['ok' => false, 'message' => 'RBL ' . $p . ' returned 403 — product subscription not yet approved for this app.'];
        }
        if ($http === 401) {
            return ['ok' => false, 'message' => 'RBL ' . $p . ' unauthorized — check Client ID/Secret.'];
        }
    }
    return ['ok' => false, 'message' => 'RBL sandbox reachable but VA probes returned non-200. Check Corp ID / Master Account with RBL RM.'];
}

function rblNewTranId(): string
{
    return 'TXN' . strtoupper(bin2hex(random_bytes(6)));
}

function rblDefaultHeader(): array
{
    $c = rblCredentials();
    return [
        'TranID' => rblNewTranId(),
        'Corp_ID' => $c['corp_id'],
        'Maker_ID' => $c['maker_id'] !== '' ? $c['maker_id'] : 'MAKER',
        'Checker_ID' => $c['checker_id'] !== '' ? $c['checker_id'] : 'CHECKER',
        'Approver_ID' => $c['approver_id'] !== '' ? $c['approver_id'] : 'APPROVER',
    ];
}

function rblVaBody(string $serial, string $beneficiaryName, ?string $accountNo = null): array
{
    $c = rblCredentials();
    $serial = preg_replace('/\D/', '', $serial) ?: '1000000001';
    if (strlen($serial) < 9) {
        $serial = str_pad($serial, 9, '0', STR_PAD_LEFT);
    }
    if (strlen($serial) > 16) {
        $serial = substr($serial, -16);
    }
    $beneficiaryName = mb_substr(preg_replace('/[^A-Za-z0-9 &\.\,\-\/\(\)]/', '', $beneficiaryName) ?: 'UniWeb', 0, 100);
    return [
        'create_VA' => [
            'Header' => rblDefaultHeader(),
            'Body' => [
                'Account_No' => $accountNo ?: $c['master_account'],
                'Client_Id' => rblClientIdForRequest(),
                'VA_SerialNo' => $serial,
                'VA_Beneficiary' => $beneficiaryName,
            ],
        ],
    ];
}

function rblCreateVirtualAccount(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    return rblApiRequest('POST', '/virtual/account', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblCreateVaV2(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    return rblApiRequest('POST', '/virtual/v2/account', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblCreateVaV1(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    return rblApiRequest('POST', '/va/create', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblUpiCollection(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    return rblApiRequest('POST', '/upi/collection', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblFetchAccountBalance(string $accountId): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    $c = rblCredentials();
    $body = [
        'getAccountBalanceReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp ID' => $c['corp_id'],
                'AppIOveI_ID' => $c['approver_id'] !== '' ? $c['approver_id'] : 'APPROVER',
            ],
            'Body' => ['AcctId' => $accountId],
            'Signature' => ['Signature' => '12345'],
        ],
    ];
    return rblApiRequest('POST', '/account/balance', $body);
}

function rblBlobVaStatement(string $accountNo, string $fromDate, string $toDate): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    $c = rblCredentials();
    $body = [
        'FetchAccStmtReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp_ID' => $c['corp_id'],
                'account_no' => $accountNo,
                'from_date' => $fromDate,
                'to_date' => $toDate,
            ],
        ],
    ];
    return rblApiRequest('POST', '/blob/va', $body);
}

function rblCorporateSinglePayment(array $payment): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    $body = [
        'Single_Payment_Corp_Req' => [
            'Header' => rblDefaultHeader(),
            'Body' => $payment,
            'Signature' => ['Signature' => 'Signature'],
        ],
    ];
    return rblApiRequest('POST', '/corp/payment', $body);
}

function rblBulkPayment(array $debit, array $payments): ?array
{
    if (($gate = rblOperationalGate()) !== null) {
        return $gate;
    }
    $c = rblCredentials();
    $body = [
        'doBenIdMultiPaymentReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp_ID' => $c['corp_id'],
                'TotalTransactionsCount' => count($payments),
                'TotalAmount' => (string)array_sum(array_column($payments, 'Amount')),
            ],
            'Body' => array_merge($debit, ['Payment' => $payments]),
        ],
    ];
    return rblApiRequest('POST', '/payment/bulk', $body);
}

/** Subscribed sandbox products from Owner portal (docs alignment). */
function rblSandboxProductCatalog(): array
{
    return [
        ['path' => '/virtual/account', 'label' => 'Virtual Account API 1.0'],
        ['path' => '/virtual/v2/account', 'label' => 'Virtual Account API 2.0'],
        ['path' => '/va/create', 'label' => 'VA Creation API'],
        ['path' => '/upi/collection', 'label' => 'UPI Collection API'],
        ['path' => '/blob/va', 'label' => 'Blob VA Statement'],
        ['path' => '/account/balance', 'label' => 'Corporate Account Balance'],
        ['path' => '/corp/payment', 'label' => 'Corporate Single Payment'],
        ['path' => '/payment/bulk', 'label' => 'Bulk Payment'],
    ];
}
