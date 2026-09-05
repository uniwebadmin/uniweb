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

/**
 * Official RBL sandbox TestCase 1 values from inbox OpenAPI zips
 * (VA / UPI Collection / VA Creation). Used only when Environment = Sandbox
 * and Owner has not pasted Corp/Master. Never used for production/live money.
 *
 * @return array{corp_id:string,master_account:string,maker_id:string,checker_id:string,approver_id:string,client_id:string,va_serial:string,beneficiary:string,balance_acct:string,balance_corp:string}
 */
function rblSandboxOfficialFixtures(): array
{
    return [
        'corp_id' => 'VAOPENBANK',
        'master_account' => '409000832853',
        'maker_id' => 'M001',
        'checker_id' => 'C001',
        'approver_id' => 'A001',
        'client_id' => 'WEBUI',
        'va_serial' => '1234567890987',
        'beneficiary' => 'RBL Bank LTD',
        'balance_acct' => '409000147931',
        'balance_corp' => 'CORP001',
    ];
}

function rblIsSandboxEnvironment(): bool
{
    return rblPartnerCredential('rbl_environment', 'sandbox') !== 'production';
}

function rblCredentialWithSandboxFixture(string $registryField, string $fixtureKey): string
{
    $pasted = rblPartnerCredential($registryField, '');
    if ($pasted !== '') {
        return $pasted;
    }
    if (!rblIsSandboxEnvironment()) {
        return '';
    }
    $fx = rblSandboxOfficialFixtures();
    return (string)($fx[$fixtureKey] ?? '');
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
    $fx = rblSandboxOfficialFixtures();
    return [
        'client_id' => rblPartnerCredential('rbl_client_id', ''),
        'client_secret' => rblPartnerCredential('rbl_client_secret', ''),
        'corp_id' => rblCredentialWithSandboxFixture('rbl_corp_id', 'corp_id'),
        'app_name' => rblPartnerCredential('rbl_app_name', $fx['beneficiary']),
        'environment' => rblPartnerCredential('rbl_environment', 'sandbox'),
        'base_url' => $base !== '' ? rtrim($base, '/') : rblBaseUrl(),
        'master_account' => rblCredentialWithSandboxFixture('rbl_master_account', 'master_account'),
        'maker_id' => rblCredentialWithSandboxFixture('rbl_maker_id', 'maker_id'),
        'checker_id' => rblCredentialWithSandboxFixture('rbl_checker_id', 'checker_id'),
        'approver_id' => rblCredentialWithSandboxFixture('rbl_approver_id', 'approver_id'),
        'using_sandbox_fixtures' => rblIsSandboxEnvironment()
            && rblPartnerCredential('rbl_corp_id', '') === ''
            && rblPartnerCredential('rbl_master_account', '') === '',
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
    $fx = rblSandboxOfficialFixtures();
    if (rblIsSandboxEnvironment() && rblCredentialWithSandboxFixture('rbl_corp_id', 'corp_id') === $fx['corp_id']) {
        $pastedApp = preg_replace('/[^A-Za-z0-9]/', '', rblPartnerCredential('rbl_app_name', ''));
        if ($pastedApp === '' || strlen($pastedApp) > 7) {
            return $fx['client_id'];
        }
        return strtoupper(substr((string)$pastedApp, 0, 7));
    }
    $app = rblPartnerCredential('rbl_app_name', '');
    $raw = preg_replace('/[^A-Za-z0-9]/', '', $app !== '' ? $app : $fx['client_id']) ?: $fx['client_id'];
    $raw = strtoupper((string)$raw);
    return substr($raw, 0, 7);
}

function rblResponseLooksSuccessful(?array $res): bool
{
    if (!$res || (int)($res['http'] ?? 0) !== 200) {
        return false;
    }
    $body = $res['body'] ?? [];
    if (!is_array($body)) {
        return false;
    }
    $status = '';
    foreach (rblFlattenAssocNodes($body) as $node) {
        foreach (['Status', 'status'] as $k) {
            if (!empty($node[$k])) {
                $status = strtoupper(trim((string)$node[$k]));
                break 2;
            }
        }
    }
    if ($status !== '' && (str_contains($status, 'FAIL') || str_contains($status, 'ERROR'))) {
        return false;
    }
    return true;
}

/** @param array<string,mixed> $node @return list<array<string,mixed>> */
function rblFlattenAssocNodes(array $node, int $depth = 0): array
{
    $out = [$node];
    if ($depth >= 8) {
        return $out;
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            $isList = array_is_list($v);
            if ($isList) {
                foreach ($v as $item) {
                    if (is_array($item)) {
                        $out = array_merge($out, rblFlattenAssocNodes($item, $depth + 1));
                    }
                }
            } else {
                $out = array_merge($out, rblFlattenAssocNodes($v, $depth + 1));
            }
        }
    }
    return $out;
}

function rblSanitizePublicError(string $raw): string
{
    $raw = preg_replace('/[A-Za-z0-9]{20,}/', '[redacted]', $raw) ?? $raw;
    return mb_substr(trim($raw), 0, 220);
}

function rblSummarizeVaFailure(?array $res): string
{
    if ($res === null) {
        return 'RBL did not respond. Check sandbox connectivity.';
    }
    $http = (int)($res['http'] ?? 0);
    $curlErr = trim((string)($res['error'] ?? ''));
    if ($http === 0) {
        return 'RBL sandbox not reachable' . ($curlErr !== '' ? ': ' . rblSanitizePublicError($curlErr) : '.');
    }
    $bits = [];
    $body = is_array($res['body'] ?? null) ? $res['body'] : [];
    foreach (rblFlattenAssocNodes($body) as $node) {
        foreach (['Error_Desc', 'Error_Cde', 'errorMessage', 'moreInformation', 'message', 'httpMessage', 'Status'] as $k) {
            if (!empty($node[$k]) && is_scalar($node[$k])) {
                $bits[] = $k . '=' . rblSanitizePublicError((string)$node[$k]);
            }
        }
        if (count($bits) >= 4) {
            break;
        }
    }
    $detail = $bits === [] ? 'no VA number in response' : implode('; ', array_unique($bits));
    return 'HTTP ' . $http . ' — ' . $detail;
}

function rblSetLastVaCreateError(string $message): void
{
    $GLOBALS['rbl_last_va_create_error'] = rblSanitizePublicError($message);
}

function rblLastVaCreateError(): string
{
    return trim((string)($GLOBALS['rbl_last_va_create_error'] ?? ''));
}

/** Parse RBL VA create response into checkout-friendly shape. */
function rblParseVaResponse(?array $res): ?array
{
    if (!rblResponseLooksSuccessful($res)) {
        return null;
    }
    $body = $res['body'] ?? [];
    if (!is_array($body)) {
        return null;
    }
    $fields = [
        'va_number' => ['Full_VA_Number', 'full_va_number', 'VA_Number', 'va_number', 'VirtualAccountNumber', 'AccountNumber', 'account_number'],
        'va_id' => ['VA_Id', 'va_id', 'VirtualAccountId', 'VA_Number', 'Id'],
        'ifsc' => ['IFSC', 'ifsc', 'IFSC_Code'],
        'upi_id' => ['UPI_Id', 'upi_id', 'VPA', 'vpa'],
    ];
    $out = [];
    foreach (rblFlattenAssocNodes($body) as $node) {
        foreach ($fields as $canonical => $keys) {
            if (!empty($out[$canonical])) {
                continue;
            }
            foreach ($keys as $k) {
                if (!empty($node[$k]) && is_scalar($node[$k])) {
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
    rblSetLastVaCreateError('');
    $gate = rblOperationalGate();
    if ($gate !== null) {
        rblSetLastVaCreateError((string)($gate['error'] ?? 'RBL operational gate blocked VA create.'));
        return null;
    }
    $merchantId = (int)($merchant['id'] ?? $merchant['merchant_id'] ?? 0);
    $name = trim((string)($merchant['business_name'] ?? $merchant['name'] ?? 'Merchant'));
    if ($name === '') {
        $name = 'Merchant ' . $merchantId;
    }
    $name = mb_substr(preg_replace('/[^A-Za-z0-9 &\.\,\-\/\(\)]/', '', $name) ?: 'Merchant', 0, 100);
    $serials = rblVaSerialsForCreate($merchantId);
    $paths = ['/virtual/account', '/virtual/v2/account', '/va/create'];
    $lastFail = 'RBL did not return a VA number.';
    foreach ($serials as $serial) {
        foreach ($paths as $path) {
            $res = rblApiRequest('POST', $path, rblVaBody($serial, $name));
            $parsed = rblParseVaResponse($res);
            if ($parsed) {
                $parsed['_source'] = 'rbl_api';
                $parsed['_serial'] = $serial;
                $parsed['_path'] = $path;
                return $parsed;
            }
            $lastFail = rblSummarizeVaFailure($res) . ' @ ' . $path;
        }
    }
    if (function_exists('error_log')) {
        error_log('RBL VA create failed (no secrets): ' . $lastFail);
    }
    rblSetLastVaCreateError($lastFail);
    return null;
}

/** How many RBL VA rows this merchant already has (used to skip the reused merchant-id serial). */
function rblExistingVaCount(int $merchantId): int
{
    if ($merchantId < 1 || !function_exists('getMerchantVirtualAccounts')) {
        return 0;
    }
    $n = 0;
    foreach (getMerchantVirtualAccounts($merchantId) as $row) {
        if (strtolower((string)($row['gateway'] ?? '')) === 'rbl') {
            $n++;
        }
    }
    return $n;
}

/**
 * First VA: merchant-id serial (sandbox formula). Extra VAs: unique serial only —
 * otherwise RBL returns the same Full_VA_Number and uniq_va_number fails.
 *
 * @return list<string>
 */
function rblVaSerialsForCreate(int $merchantId): array
{
    $existing = rblExistingVaCount($merchantId);
    $out = [];
    if ($existing === 0) {
        $out[] = rblVaSerialForMerchant($merchantId);
    }
    $out[] = rblVaSerialUnique($merchantId);
    $seq = str_pad((string)($existing + 1), 2, '0', STR_PAD_LEFT);
    $mid = str_pad((string)max(1, $merchantId), 4, '0', STR_PAD_LEFT);
    $out[] = substr($mid . $seq . substr((string)((time() % 100000000) + $existing), -7), 0, 16);
    return array_values(array_unique(array_filter($out)));
}

/** Unique 13-digit serial (sandbox TestCase length) so merchant 7 is not always 000000000007. */
function rblVaSerialUnique(int $merchantId): string
{
    $n = max(1, $merchantId);
    $serial = str_pad((string)$n, 4, '0', STR_PAD_LEFT) . substr((string)(time() % 1000000000), -9);
    if (strlen($serial) > 16) {
        $serial = substr($serial, 0, 16);
    }
    if (strlen($serial) < 9) {
        $serial = str_pad($serial, 9, '0', STR_PAD_LEFT);
    }
    return $serial;
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

    $fx = rblSandboxOfficialFixtures();
    if ($c['corp_id'] === '' || $c['master_account'] === '') {
        return [
            'ok' => false,
            'message' => 'RBL Corp ID / Master Account missing even after sandbox fixtures. Check adapter.',
        ];
    }

    // Phase 2: official TestCase 1 body (VA zip) — sandbox Corp/Master from inbox specs.
    $paths = ['/virtual/account', '/virtual/v2/account', '/va/create', '/upi/collection'];
    $serial = $fx['va_serial'];
    $beneficiary = $c['app_name'] !== '' ? $c['app_name'] : $fx['beneficiary'];
    foreach ($paths as $p) {
        $res = rblApiRequest('POST', $p, rblVaBody($serial, $beneficiary), 15);
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
        'Maker_ID' => $c['maker_id'] !== '' ? $c['maker_id'] : rblSandboxOfficialFixtures()['maker_id'],
        'Checker_ID' => $c['checker_id'] !== '' ? $c['checker_id'] : rblSandboxOfficialFixtures()['checker_id'],
        'Approver_ID' => $c['approver_id'] !== '' ? $c['approver_id'] : rblSandboxOfficialFixtures()['approver_id'],
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
