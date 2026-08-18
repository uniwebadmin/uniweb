<?php
declare(strict_types=1);

/** Axis Bank Corporate Collections — UAT/Live VA + webhook */

function axisCredentials(): array
{
    if (!function_exists('axisPartnerSetting') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $clientId = function_exists('axisPartnerSetting')
        ? axisPartnerSetting('axis_client_id', axisPartnerSetting('axis_api_key', ''))
        : '';
    $clientSecret = function_exists('axisPartnerSetting')
        ? axisPartnerSetting('axis_client_secret', axisPartnerSetting('axis_api_secret', ''))
        : '';
    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'app_name' => axisPartnerSetting('axis_app_name', 'UNIWEB Collection API'),
        'application_id' => axisPartnerSetting('axis_application_id', ''),
        'oauth_redirect' => axisPartnerSetting('axis_oauth_redirect', APP_URL),
        'channel_id' => axisPartnerSetting('axis_channel_id', ''),
        'corporate_id' => axisPartnerSetting('axis_corporate_id', ''),
        'master_account' => axisPartnerSetting('axis_master_account', ''),
    ];
}

function axisUatBases(): array
{
    if (!function_exists('axisPartnerSetting') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $custom = function_exists('axisPartnerSetting') ? trim(axisPartnerSetting('axis_base_url', '')) : '';
    if ($custom !== '') {
        return [rtrim($custom, '/')];
    }
    return [
        'https://sakshamuat.axisbank.co.in',
        'https://sakshamuat.axis.bank.in',
    ];
}

function axisApiBase(): string
{
    if (!function_exists('partnerActiveEnvironment') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $isProd = function_exists('partnerActiveEnvironment')
        && partnerActiveEnvironment('axis', 'uat') === 'production';
    if ($isProd) {
        $custom = function_exists('axisPartnerSetting') ? trim(axisPartnerSetting('axis_base_url', '')) : '';
        return $custom !== '' ? rtrim($custom, '/') : 'https://api.axis.bank.in';
    }
    return axisUatBases()[0];
}

function axisAllowMock(): bool
{
    return getSetting('axis_allow_mock', '0') === '1';
}

function axisLogApi(string $endpoint, string $method, ?string $request, ?string $response, int $httpCode, ?int $merchantId = null, string $status = 'ok'): void
{
    // D10: mask PII before writing to log table
    if (!function_exists('maskPiiInString') && is_file(__DIR__ . '/partner_payload.php')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    if (function_exists('maskPiiInString')) {
        $request = maskPiiInString($request);
        $response = maskPiiInString($response);
    }
    try {
        getDB()->prepare('INSERT INTO axis_api_logs (endpoint, method, request_body, response_body, http_code, merchant_id, status) VALUES (?,?,?,?,?,?,?)')
            ->execute([$endpoint, $method, $request, $response, $httpCode, $merchantId, $status]);
    } catch (Throwable $e) {
        // table may not exist yet
    }
}

function axisGetRecentLogs(int $limit = 30): array
{
    try {
        $stmt = getDB()->prepare('SELECT * FROM axis_api_logs ORDER BY created_at DESC LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function axisServerPublicIp(): string
{
    foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $url) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $ip = @file_get_contents($url, false, $ctx);
        if ($ip && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }
    }
    return $_SERVER['SERVER_ADDR'] ?? 'unknown';
}

function axisHttpRequestOnce(string $url, string $method, array $headers, ?string $body, int $timeout): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    return [
        'body' => $response === false ? '' : $response,
        'http_code' => $httpCode,
        'error' => $error,
        'errno' => $errno,
    ];
}

/**
 * Same as axisHttpRequestOnce() but retries transient failures (timeouts,
 * connection errors, 5xx, 429 rate-limited) with exponential backoff.
 * Client errors (4xx other than 429) are NOT retried — retrying those would
 * just repeat the same bad request.
 */
function axisHttpRequest(string $url, string $method, array $headers, ?string $body = null, int $timeout = 45, int $maxAttempts = 3): array
{
    $delayMs = 200;
    $result = ['body' => '', 'http_code' => 0, 'error' => '', 'errno' => 0];
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $result = axisHttpRequestOnce($url, $method, $headers, $body, $timeout);
        $transientNetworkError = $result['errno'] !== 0;
        $transientHttpStatus = $result['http_code'] === 429 || $result['http_code'] >= 500;
        if (!$transientNetworkError && !$transientHttpStatus) {
            return $result;
        }
        if ($attempt < $maxAttempts) {
            usleep($delayMs * 1000);
            $delayMs *= 2;
        }
    }
    return $result;
}

function axisClientHeaders(array $c): array
{
    return [
        'X-IBM-Client-Id: ' . $c['client_id'],
        'X-IBM-Client-Secret: ' . $c['client_secret'],
        'X-API-KEY: ' . $c['client_id'],
        'X-API-SECRET: ' . $c['client_secret'],
    ];
}

function axisTokenUrlCandidates(): array
{
    $custom = trim(getSetting('axis_token_url', ''));
    $paths = [
        '/gateway/api/v1/auth/token',
        '/gateway/api/v1/oauth/token',
        '/gateway/api/v1/get-token',
        '/gateway/api/v1/token/generation',
        '/gateway/api/v1/apiauthentication/token',
        '/gateway/api/v1/apisecurity/token',
        '/gateway/api/oauth/token',
        '/gateway/oauth/token',
        '/oauth/token',
        '/oauth2/token',
    ];
    $urls = [];
    if ($custom !== '') {
        $urls[] = $custom;
    }
    foreach (axisUatBases() as $base) {
        foreach ($paths as $path) {
            $urls[] = rtrim($base, '/') . $path;
        }
    }
    return array_values(array_unique($urls));
}

function axisExtractToken(?array $data): ?string
{
    if (!$data) return null;
    $candidates = [
        $data['access_token'] ?? null,
        $data['accessToken'] ?? null,
        $data['token'] ?? null,
        $data['Data']['accessToken'] ?? null,
        $data['Data']['token'] ?? null,
        $data['data']['accessToken'] ?? null,
    ];
    foreach ($candidates as $t) {
        if (is_string($t) && $t !== '') return $t;
    }
    return null;
}

function axisStoreToken(string $token, int $expiresIn = 300): void
{
    $db = getDB();
    $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute(['axis_access_token', $token, $token]);
    $exp = (string)(time() + max(60, $expiresIn - 30));
    $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute(['axis_token_expires', $exp, $exp]);
}

function axisTryTokenRequest(string $url, string $method, array $headers, ?string $body, array $c): ?string
{
    $res = axisHttpRequest($url, $method, $headers, $body);
    $logBody = $res['body'];
    if ($logBody === '' && $res['error'] !== '') {
        $logBody = 'CURL[' . $res['errno'] . ']: ' . $res['error'];
    }
    $ok = $res['http_code'] >= 200 && $res['http_code'] < 300;
    axisLogApi($url, $method, $body, $logBody, $res['http_code'], null, $ok ? 'token_ok' : 'token_fail');

    if (!$res['body']) return null;
    $data = json_decode($res['body'], true);
    if (!is_array($data)) return null;

    $token = axisExtractToken($data);
    if ($token) {
        $expires = (int)($data['expires_in'] ?? $data['expiresIn'] ?? $data['Data']['expiresIn'] ?? 300);
        axisStoreToken($token, $expires);
        return $token;
    }
    return null;
}

function axisGetAccessToken(bool $forceRefresh = false): ?string
{
    $c = axisCredentials();
    if (!$c['client_id'] || !$c['client_secret']) return null;

    if (!$forceRefresh) {
        $token = getSetting('axis_access_token', '');
        $exp = (int)getSetting('axis_token_expires', '0');
        if ($token && $exp > time()) return $token;
    }

    $jsonPayload = json_encode([
        'client_id' => $c['client_id'],
        'client_secret' => $c['client_secret'],
        'grant_type' => 'client_credentials',
    ]);
    $formPayload = http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $c['client_id'],
        'client_secret' => $c['client_secret'],
    ]);

    foreach (axisTokenUrlCandidates() as $url) {
        $baseHeaders = array_merge(['Accept: application/json'], axisClientHeaders($c));

        $attempts = [
            ['POST', array_merge($baseHeaders, ['Content-Type: application/json']), $jsonPayload],
            ['POST', array_merge($baseHeaders, ['Content-Type: application/x-www-form-urlencoded']), $formPayload],
            ['GET', $baseHeaders, null],
            ['POST', array_merge($baseHeaders, ['Content-Type: application/json']), '{}'],
        ];

        foreach ($attempts as [$method, $headers, $body]) {
            $token = axisTryTokenRequest($url, $method, $headers, $body, $c);
            if ($token) return $token;
        }
    }
    return null;
}

function axisApiRequest(string $endpoint, array $payload, string $method = 'POST', ?int $merchantId = null): ?array
{
    $c = axisCredentials();
    if (!$c['client_id'] || !$c['client_secret']) return null;

    $token = axisGetAccessToken();
    $url = str_starts_with($endpoint, 'http') ? $endpoint : rtrim(axisApiBase(), '/') . $endpoint;
    $body = json_encode($payload);

    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
    ], axisClientHeaders($c));

    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    if ($c['channel_id']) {
        $headers[] = 'X-Channel-Id: ' . $c['channel_id'];
    }

    $res = axisHttpRequest($url, $method, $headers, $method === 'GET' ? null : $body);
    $logResp = $res['body'];
    if ($logResp === '' && $res['error'] !== '') {
        $logResp = 'CURL[' . $res['errno'] . ']: ' . $res['error'];
    }
    $status = $res['http_code'] >= 200 && $res['http_code'] < 300 ? 'ok' : 'fail';
    axisLogApi($endpoint, $method, $body, $logResp, $res['http_code'], $merchantId, $status);

    if (!$res['body']) return ['_http_code' => $res['http_code'], '_error' => $res['error'], '_errno' => $res['errno']];
    $data = json_decode($res['body'], true) ?: [];
    $data['_http_code'] = $res['http_code'];
    $data['_raw'] = $res['body'];
    return $data;
}

function axisParseVaResponse(?array $resp): ?array
{
    if (!$resp) return null;
    $data = $resp['Data'] ?? $resp['data'] ?? $resp;
    $va = $data['virtualAccountNumber'] ?? $data['vaNumber'] ?? $data['vanNumber'] ?? $resp['virtualAccountNumber'] ?? null;
    if (!$va) return null;
    return [
        'va_id' => $data['virtualAccountId'] ?? $data['vaId'] ?? $resp['id'] ?? '',
        'va_number' => $va,
        'ifsc' => $data['ifsc'] ?? $data['ifscCode'] ?? axisPartnerSetting('axis_va_ifsc', 'UTIB0000000'),
        'upi_id' => $data['upiId'] ?? $data['vpa'] ?? $data['upiVpa'] ?? '',
    ];
}

function axisBuildVaPayload(array $merchant): array
{
    $code = $merchant['merchant_code'] ?? ('UW' . $merchant['id']);
    $phone = preg_replace('/\D/', '', $merchant['phone'] ?? '');
    if (strlen($phone) === 10) $phone = '91' . $phone;

    $inner = [
        'merchantReference' => $code,
        'merchantReferenceId' => $code,
        'customerCode' => axisPartnerSetting('axis_corporate_id', $code),
        'accountName' => $merchant['business_name'] ?? $merchant['name'] ?? 'Merchant',
        'merchantName' => $merchant['business_name'] ?? $merchant['name'] ?? 'Merchant',
        'email' => $merchant['email'] ?? '',
        'emailId' => $merchant['email'] ?? '',
        'mobile' => $phone,
        'mobileNumber' => $phone,
        'description' => 'UniWeb VA — ' . $code,
        'channelId' => axisPartnerSetting('axis_channel_id', ''),
        'appName' => axisPartnerSetting('axis_app_name', 'UNIWEB Collection API'),
    ];

    return ['Data' => $inner, 'Risk' => new stdClass()];
}

function createAxisVirtualAccount(array $merchant): ?array
{
    $merchantId = (int)($merchant['id'] ?? $merchant['merchant_id'] ?? 0);
    $payload = axisBuildVaPayload($merchant);

    $endpoints = [
        '/gateway/api/v1/virtualaccounts/create',
        '/gateway/api/v1/collections/virtual-account/create',
        '/gateway/api/v1/van/create',
        '/gateway/api/v1/ecollection/van/create',
        '/gateway/api/v1/virtual-account/create',
    ];

    foreach ($endpoints as $ep) {
        $resp = axisApiRequest($ep, $payload, 'POST', $merchantId);
        $parsed = axisParseVaResponse($resp);
        if ($parsed) {
            $parsed['_source'] = 'axis_api';
            $parsed['_endpoint'] = $ep;
            return $parsed;
        }
    }

    if (axisAllowMock()) {
        $code = $merchant['merchant_code'] ?? ('UW' . $merchantId);
        $suffix = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $code), 0, 8));
        return [
            'va_id' => 'MOCK_' . $suffix,
            'va_number' => 'AXIS' . str_pad((string)$merchantId, 10, '0', STR_PAD_LEFT),
            'ifsc' => axisPartnerSetting('axis_va_ifsc', 'UTIB0000000'),
            'upi_id' => strtolower($suffix) . '@axisbank',
            '_source' => 'mock',
        ];
    }

    return null;
}

function axisDnsProbe(): array
{
    $hosts = [
        'sakshamuat.axisbank.co.in' => 'UAT API (official)',
        'apiportal.axis.bank.in' => 'Developer Portal',
    ];
    $rows = [];
    foreach ($hosts as $host => $label) {
        $ip = @gethostbyname($host);
        $ok = $ip !== $host;
        $rows[] = [
            'host' => $host,
            'label' => $label,
            'resolved' => $ok,
            'ip' => $ok ? $ip : null,
        ];
    }
    $baseHost = parse_url(axisApiBase(), PHP_URL_HOST) ?: 'sakshamuat.axisbank.co.in';
    $baseIp = @gethostbyname($baseHost);
    return [
        'rows' => $rows,
        'base_host' => $baseHost,
        'base_resolved' => $baseIp !== $baseHost,
        'base_ip' => $baseIp !== $baseHost ? $baseIp : null,
        'server_ip' => axisServerPublicIp(),
    ];
}

function axisDiagnoseTokenFailure(): array
{
    $dns = axisDnsProbe();
    $logs = axisGetRecentLogs(8);
    $tokenLogs = array_values(array_filter($logs, fn($l) => str_contains($l['status'] ?? '', 'token')));
    $last = $tokenLogs[0] ?? null;
    $http = (int)($last['http_code'] ?? 0);
    $resp = (string)($last['response_body'] ?? '');

    if (!$dns['base_resolved'] || str_contains($resp, 'CURL[6]') || str_contains($resp, 'Could not resolve')) {
        $hint = 'DNS error — the Hostinger server cannot resolve ' . $dns['base_host'] . '. Whitelist the server IP in the Axis portal (' . $dns['server_ip'] . ') and ask Hostinger support to check outbound DNS/firewall access. Expected host: sakshamuat.axisbank.co.in';
    } elseif (str_contains($resp, 'CURL[7]') || str_contains($resp, 'Connection refused')) {
        $hint = 'Connection refused — check the IP whitelist and firewall. Whitelist the server IP in the Axis portal.';
    } elseif (str_contains($resp, 'CURL[60]') || str_contains($resp, 'SSL')) {
        $hint = 'SSL error — Axis UAT may require a two-way SSL client certificate (Portal → Security Features).';
    } elseif ($http === 0) {
        $hint = 'No HTTP response — whitelist this server IP: ' . axisServerPublicIp();
    } elseif ($http === 401 || $http === 403) {
        $hint = '401/403 — verify the Client ID/Secret, subscribe to the Virtual Account API, and whitelist IP: ' . axisServerPublicIp();
    } elseif ($http === 404) {
        $hint = '404 — the token URL is incorrect. Copy the exact axis_token_url from the subscribed API documentation.';
    } elseif (str_contains($resp, 'encrypt') || str_contains($resp, 'JWE')) {
        $hint = 'JWE encryption is mandatory in UAT — configure the encryption keys from the portal documentation.';
    } else {
        $hint = 'Subscribe to "API Authentication Token Generation" and "Virtual Account" in the portal. Whitelist IP: ' . axisServerPublicIp();
    }

    return [
        'server_ip' => axisServerPublicIp(),
        'dns' => $dns,
        'last_http' => $http,
        'last_response' => mb_substr($resp, 0, 300),
        'hint' => $hint,
    ];
}

function axisTestConnection(): array
{
    $c = axisCredentials();
    $dns = axisDnsProbe();
    $diag = axisDiagnoseTokenFailure();
    $result = [
        'configured' => (bool)($c['client_id'] && $c['client_secret']),
        'base_url' => axisApiBase(),
        'app_name' => $c['app_name'],
        'environment' => partnerActiveEnvironment('axis', 'uat'),
        'server_ip' => $diag['server_ip'],
        'dns' => $dns,
        'token' => null,
        'token_ok' => false,
        'message' => '',
        'diagnosis' => $diag,
    ];

    if (!$result['configured']) {
        $result['message'] = 'Axis Client ID / Secret missing in Partner Registry → Keys.';
        return $result;
    }

    $token = axisGetAccessToken(true);
    $result['token'] = $token ? substr($token, 0, 12) . '…' : null;
    $result['token_ok'] = (bool)$token;
    $result['diagnosis'] = axisDiagnoseTokenFailure();
    $result['message'] = $token
        ? 'Token generated — API calls will appear on Axis Developer Portal.'
        : $result['diagnosis']['hint'];

    return $result;
}

function axisWebhookUrl(): string
{
    return APP_URL . '/axis_webhook.php';
}
