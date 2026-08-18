<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 *  RBL Bank Open Banking adapter (UAT / production ready structure)
 *  Supported rails: VA, VA Creation, UPI Collection, Blob VA statement,
 *  Account balance, Corporate single payment, Bulk payment.
 *  Keys: Partner Registry → RBL → Keys (not gateway_settings plaintext).
 * ------------------------------------------------------------------ */

function rblPartnerCredential(string $field, string $default = ''): string
{
    if (function_exists('getPartnerSetting')) {
        $v = trim(getPartnerSetting('rbl', $field, ''));
        if ($v !== '') {
            return $v;
        }
    }
    return trim((string)getSetting($field, $default));
}

function rblBaseUrl(): string
{
    return rblPartnerCredential('rbl_environment', 'sandbox') === 'production'
        ? 'https://api.rbl.bank.in/api/v1'
        : 'https://apisandbox.rbl.bank.in/sandbox/api/v1';
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
        'base_url' => $base !== '' ? $base : rblBaseUrl(),
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

/** Generic RBL API call. Auth is client_id + client_secret as query params. */
function rblApiRequest(string $method, string $path, ?array $body = null, int $timeout = 30): ?array
{
    $c = rblCredentials();
    if (!$c['client_id'] || !$c['client_secret']) {
        return null;
    }
    $base = $c['base_url'] ?: rblBaseUrl();
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
        return ['http' => 0, 'error' => $err, 'body' => []];
    }
    $data = json_decode($response, true);
    return ['http' => $http, 'error' => '', 'body' => is_array($data) ? $data : []];
}

/** @return array{ok:bool,message:string} */
function testRblConnection(): array
{
    if (!isRblConfigured()) {
        return ['ok' => false, 'message' => 'RBL Client ID and Client Secret are required in Partner Registry.'];
    }
    $c = rblCredentials();
    if ($c['corp_id'] === '' || $c['master_account'] === '') {
        return ['ok' => false, 'message' => 'RBL Corp ID and Master Account must be set in Partner Registry before probe (no demo defaults).'];
    }
    $paths = ['/virtual/account', '/upi/collection', '/va/create', '/blob/va'];
    foreach ($paths as $p) {
        $tx = 'TXN' . strtoupper(bin2hex(random_bytes(4)));
        $body = [
            'create_VA' => [
                'Header' => [
                    'TranID' => $tx,
                    'Corp_ID' => $c['corp_id'],
                    'Maker_ID' => $c['maker_id'] !== '' ? $c['maker_id'] : 'MAKER',
                    'Checker_ID' => $c['checker_id'] !== '' ? $c['checker_id'] : 'CHECKER',
                    'Approver_ID' => $c['approver_id'] !== '' ? $c['approver_id'] : 'APPROVER',
                ],
                'Body' => [
                    'Account_No' => $c['master_account'],
                    'Client_Id' => 'WEBUI',
                    'VA_SerialNo' => '1234567890987',
                    'VA_Beneficiary' => $c['app_name'] ?: 'UniWeb',
                ],
            ],
        ];
        $res = rblApiRequest('POST', $p, $body, 15);
        $http = (int)($res['http'] ?? 0);
        if ($http === 200) {
            return ['ok' => true, 'message' => 'RBL ' . $p . ' connected (HTTP 200).'];
        }
        if ($http === 403) {
            return ['ok' => false, 'message' => 'RBL ' . $p . ' returned 403 — product subscription not yet approved.'];
        }
    }
    return ['ok' => false, 'message' => 'RBL sandbox not reachable or all probe endpoints returned non-200.'];
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
    return [
        'create_VA' => [
            'Header' => rblDefaultHeader(),
            'Body' => [
                'Account_No' => $accountNo ?: $c['master_account'],
                'Client_Id' => 'WEBUI',
                'VA_SerialNo' => $serial,
                'VA_Beneficiary' => $beneficiaryName,
            ],
        ],
    ];
}

function rblCreateVirtualAccount(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    return rblApiRequest('POST', '/virtual/account', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblCreateVaV2(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    return rblApiRequest('POST', '/virtual/v2/account', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblCreateVaV1(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    return rblApiRequest('POST', '/va/create', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblUpiCollection(string $serial, string $beneficiaryName, ?string $accountNo = null): ?array
{
    return rblApiRequest('POST', '/upi/collection', rblVaBody($serial, $beneficiaryName, $accountNo));
}

function rblFetchAccountBalance(string $accountId): ?array
{
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
