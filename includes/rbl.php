<?php
declare(strict_types=1);

/* ------------------------------------------------------------------ *
 *  RBL Bank Open Banking adapter (UAT / production ready structure)
 *  Supported rails: VA, VA Creation, UPI Collection, Blob VA statement,
 *  Account balance, Corporate single payment, Bulk payment.
 * ------------------------------------------------------------------ */

function rblBaseUrl(): string
{
    return getSetting('rbl_environment', 'sandbox') === 'production'
        ? 'https://api.rbl.bank.in/api/v1'
        : 'https://apisandbox.rbl.bank.in/sandbox/api/v1';
}

function rblCredentials(): array
{
    return [
        'client_id' => getSetting('rbl_client_id', ''),
        'client_secret' => getSetting('rbl_client_secret', ''),
        'corp_id' => getSetting('rbl_corp_id', 'VAOPENBANK'),
        'app_name' => getSetting('rbl_app_name', 'UniWeb'),
        'environment' => getSetting('rbl_environment', 'sandbox'),
        'base_url' => getSetting('rbl_base_url', rblBaseUrl()),
        'master_account' => getSetting('rbl_master_account', ''),
        'maker_id' => getSetting('rbl_maker_id', 'M001'),
        'checker_id' => getSetting('rbl_checker_id', 'C001'),
        'approver_id' => getSetting('rbl_approver_id', 'A001'),
    ];
}

function isRblConfigured(): bool
{
    return (bool)getSetting('rbl_client_id', '') && (bool)getSetting('rbl_client_secret', '');
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
        return ['ok' => false, 'message' => 'RBL Client ID and Client Secret are required.'];
    }
    // Use the shortest / most benign probe endpoint. The RBL specs are
    // template-heavy, so test whichever endpoint is first approved.
    $paths = ['/virtual/account', '/upi/collection', '/va/create', '/blob/va'];
    foreach ($paths as $p) {
        $tx = 'TXN' . strtoupper(bin2hex(random_bytes(4)));
        $body = [
            'create_VA' => [
                'Header' => [
                    'TranID' => $tx,
                    'Corp_ID' => getSetting('rbl_corp_id', 'VAOPENBANK'),
                    'Maker_ID' => getSetting('rbl_maker_id', 'M001'),
                    'Checker_ID' => getSetting('rbl_checker_id', 'C001'),
                    'Approver_ID' => getSetting('rbl_approver_id', 'A001'),
                ],
                'Body' => [
                    'Account_No' => getSetting('rbl_master_account', '409000832853'),
                    'Client_Id' => 'WEBUI',
                    'VA_SerialNo' => '1234567890987',
                    'VA_Beneficiary' => getSetting('rbl_app_name', 'UniWeb'),
                ],
            ],
        ];
        $res = rblApiRequest('POST', $p, $body, 15);
        $http = (int)($res['http'] ?? 0);

        // 200 = we have a real, working endpoint. 4xx/5xx = approved spec mismatch.
        // 403 still means product not activated (this is the current sandbox state).
        if ($http === 200) {
            return ['ok' => true, 'message' => 'RBL ' . $p . ' connected (HTTP 200).'];
        }
        if ($http === 403) {
            return ['ok' => false, 'message' => 'RBL ' . $p . ' returned 403 — product subscription not yet approved.'];
        }
    }
    return ['ok' => false, 'message' => 'RBL sandbox not reachable or all probe endpoints returned non-200.'];
}

/* ------------------------------------------------------------------ *
 *  VA / Collection endpoint helpers (stubs — fill after live test)
 * ------------------------------------------------------------------ */

function rblNewTranId(): string
{
    return 'TXN' . strtoupper(bin2hex(random_bytes(6)));
}

function rblDefaultHeader(): array
{
    return [
        'TranID' => rblNewTranId(),
        'Corp_ID' => getSetting('rbl_corp_id', 'VAOPENBANK'),
        'Maker_ID' => getSetting('rbl_maker_id', 'M001'),
        'Checker_ID' => getSetting('rbl_checker_id', 'C001'),
        'Approver_ID' => getSetting('rbl_approver_id', 'A001'),
    ];
}

function rblVaBody(string $serial, string $beneficiaryName, ?string $accountNo = null): array
{
    return [
        'create_VA' => [
            'Header' => rblDefaultHeader(),
            'Body' => [
                'Account_No' => $accountNo ?: getSetting('rbl_master_account', '409000832853'),
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
    $body = [
        'getAccountBalanceReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp ID' => getSetting('rbl_corp_id', 'VAOPENBANK'),
                'AppIOveI_ID' => getSetting('rbl_approver_id', 'A001'),
            ],
            'Body' => ['AcctId' => $accountId],
            'Signature' => ['Signature' => '12345'],
        ],
    ];
    return rblApiRequest('POST', '/account/balance', $body);
}

function rblBlobVaStatement(string $accountNo, string $fromDate, string $toDate): ?array
{
    $body = [
        'FetchAccStmtReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp_ID' => getSetting('rbl_corp_id', 'VAOPENBANK'),
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
    $body = [
        'doBenIdMultiPaymentReq' => [
            'Header' => [
                'TranID' => rblNewTranId(),
                'Corp_ID' => getSetting('rbl_corp_id', 'VAOPENBANK'),
                'TotalTransactionsCount' => count($payments),
                'TotalAmount' => (string)array_sum(array_column($payments, 'Amount')),
            ],
            'Body' => array_merge($debit, ['Payment' => $payments]),
        ],
    ];
    return rblApiRequest('POST', '/payment/bulk', $body);
}
