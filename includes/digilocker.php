<?php
declare(strict_types=1);

/**
 * DigiLocker / Aadhaar Fetch Module
 * 
 * Fetches government-issued documents from DigiLocker API (via Decentro or direct DigiLocker API).
 * Used for merchant KYC: Aadhaar, PAN, Driving License, Class 10/12 certificates, etc.
 * 
 * Flow:
 * 1. initiateDigilockerAuth() — redirect user to DigiLocker consent screen
 * 2. digilockerCallback() — receive auth code, exchange for access token
 * 3. fetchDigilockerDocuments() — pull issued documents using access token
 * 4. saveDigilockerDocAsKyc() — auto-create KYC document from fetched data
 */

function ensureDigilockerTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS digilocker_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id VARCHAR(40) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            provider VARCHAR(30) NOT NULL DEFAULT 'digilocker',
            auth_code VARCHAR(200) DEFAULT NULL,
            access_token VARCHAR(500) DEFAULT NULL,
            token_expires_at DATETIME DEFAULT NULL,
            holder_name VARCHAR(190) DEFAULT NULL,
            holder_dob VARCHAR(20) DEFAULT NULL,
            holder_gender VARCHAR(10) DEFAULT NULL,
            status ENUM('initiated','authorized','fetched','failed','expired') NOT NULL DEFAULT 'initiated',
            documents_fetched INT NOT NULL DEFAULT 0,
            error_message VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            authorized_at DATETIME DEFAULT NULL,
            INDEX idx_dl_merchant (merchant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ensureDigilockerTable: ' . $e->getMessage()); }

    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS digilocker_documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            session_id INT NOT NULL,
            merchant_id INT NOT NULL,
            doc_type VARCHAR(60) NOT NULL,
            doc_name VARCHAR(200) DEFAULT NULL,
            doc_number VARCHAR(100) DEFAULT NULL,
            doc_uri VARCHAR(500) DEFAULT NULL,
            issuer_id VARCHAR(100) DEFAULT NULL,
            issuer_name VARCHAR(200) DEFAULT NULL,
            issued_at DATE DEFAULT NULL,
            raw_data TEXT DEFAULT NULL,
            saved_as_kyc TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_dld_session (session_id),
            INDEX idx_dld_merchant (merchant_id, doc_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ensureDigilockerTable docs: ' . $e->getMessage()); }
}

function getDigilockerProvider(): string
{
    $decentroKey = trim(getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $decentroSecret = trim(getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    if ($decentroKey !== '' && $decentroSecret !== '') {
        return 'decentro';
    }
    $dlClientId = trim(getSetting('digilocker_client_id', ''));
    $dlClientSecret = trim(getSetting('digilocker_client_secret', ''));
    if ($dlClientId !== '' && $dlClientSecret !== '') {
        return 'digilocker';
    }
    return 'none';
}

function isDigilockerAvailable(): bool
{
    return getDigilockerProvider() !== 'none';
}

function generateDigilockerSessionId(): string
{
    return 'DGL-' . date('ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function initiateDigilockerAuth(int $merchantId, string $callbackUrl): array
{
    ensureDigilockerTable();
    $provider = getDigilockerProvider();
    if ($provider === 'none') {
        return ['ok' => false, 'error' => 'DigiLocker not configured. Add Decentro or DigiLocker API keys in Partner Registry → Keys.'];
    }

    $sessionId = generateDigilockerSessionId();
    $db = getDB();
    try {
        $db->prepare('INSERT INTO digilocker_sessions (session_id, merchant_id, provider, status) VALUES (?,?,?,?)')
            ->execute([$sessionId, $merchantId, $provider, 'initiated']);
        $id = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to create session: ' . $e->getMessage()];
    }

    if ($provider === 'decentro') {
        $redirectUrl = decentroDigilockerAuthUrl($sessionId, $callbackUrl);
        if ($redirectUrl) {
            return ['ok' => true, 'session_id' => $sessionId, 'id' => $id, 'redirect_url' => $redirectUrl, 'provider' => 'decentro'];
        }
        return ['ok' => false, 'error' => 'Failed to generate Decentro DigiLocker auth URL.'];
    }

    if ($provider === 'digilocker') {
        $redirectUrl = digilockerDirectAuthUrl($sessionId, $callbackUrl);
        if ($redirectUrl) {
            return ['ok' => true, 'session_id' => $sessionId, 'id' => $id, 'redirect_url' => $redirectUrl, 'provider' => 'digilocker'];
        }
        return ['ok' => false, 'error' => 'Failed to generate DigiLocker auth URL.'];
    }

    return ['ok' => false, 'error' => 'Unknown provider.'];
}

function decentroDigilockerAuthUrl(string $sessionId, string $callbackUrl): ?string
{
    $clientId = trim(getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $base = decentroBaseUrl();
    $params = http_build_query([
        'client_id' => $clientId,
        'response_type' => 'code',
        'redirect_uri' => $callbackUrl,
        'state' => $sessionId,
        'scope' => 'digilocker',
    ]);
    return $base . '/kyc/digilocker/authorize?' . $params;
}

function digilockerDirectAuthUrl(string $sessionId, string $callbackUrl): ?string
{
    $clientId = trim(getSetting('digilocker_client_id', ''));
    $base = 'https://api.digitallocker.gov.in/public/oauth2/authorize';
    $params = http_build_query([
        'client_id' => $clientId,
        'response_type' => 'code',
        'redirect_uri' => $callbackUrl,
        'state' => $sessionId,
    ]);
    return $base . '?' . $params;
}

function digilockerCallback(string $sessionId, string $authCode, string $callbackUrl): array
{
    ensureDigilockerTable();
    $db = getDB();
    $st = $db->prepare('SELECT * FROM digilocker_sessions WHERE session_id=?');
    $st->execute([$sessionId]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'error' => 'Session not found.'];

    $provider = $session['provider'];
    $tokenResult = null;

    if ($provider === 'decentro') {
        $tokenResult = decentroDigilockerExchangeToken($authCode, $callbackUrl);
    } elseif ($provider === 'digilocker') {
        $tokenResult = digilockerDirectExchangeToken($authCode, $callbackUrl);
    }

    if (!$tokenResult || empty($tokenResult['access_token'])) {
        $db->prepare('UPDATE digilocker_sessions SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', $tokenResult['error'] ?? 'Token exchange failed', $session['id']]);
        return ['ok' => false, 'error' => $tokenResult['error'] ?? 'Token exchange failed.'];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + (int)($tokenResult['expires_in'] ?? 3600));
    $db->prepare('UPDATE digilocker_sessions SET status=?, auth_code=?, access_token=?, token_expires_at=?, holder_name=?, holder_dob=?, authorized_at=NOW() WHERE id=?')
        ->execute([
            'authorized', $authCode, $tokenResult['access_token'], $expiresAt,
            $tokenResult['holder_name'] ?? null, $tokenResult['holder_dob'] ?? null,
            $session['id']
        ]);

    return ['ok' => true, 'session_id' => $sessionId, 'message' => 'DigiLocker authorized. Fetch documents next.'];
}

function decentroDigilockerExchangeToken(string $authCode, string $callbackUrl): ?array
{
    $clientId = trim(getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim(getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));
    $base = decentroBaseUrl();

    $ch = curl_init($base . '/kyc/digilocker/access/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'code' => $authCode,
            'redirect_uri' => $callbackUrl,
        ]),
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
    if ($httpCode === 200 && isset($data['data']['access_token'])) {
        return [
            'access_token' => $data['data']['access_token'],
            'expires_in' => $data['data']['expires_in'] ?? 3600,
            'holder_name' => $data['data']['holder_name'] ?? null,
            'holder_dob' => $data['data']['holder_dob'] ?? null,
        ];
    }
    return ['error' => $data['message'] ?? "HTTP {$httpCode}"];
}

function digilockerDirectExchangeToken(string $authCode, string $callbackUrl): ?array
{
    $clientId = trim(getSetting('digilocker_client_id', ''));
    $clientSecret = trim(getSetting('digilocker_client_secret', ''));

    $ch = curl_init('https://api.digitallocker.gov.in/public/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $authCode,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $callbackUrl,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['access_token'])) {
        return [
            'access_token' => $data['access_token'],
            'expires_in' => $data['expires_in'] ?? 3600,
            'holder_name' => $data['name'] ?? null,
            'holder_dob' => $data['dob'] ?? null,
        ];
    }
    return ['error' => $data['error_description'] ?? "HTTP {$httpCode}"];
}

function fetchDigilockerDocuments(int $sessionId): array
{
    ensureDigilockerTable();
    $db = getDB();
    $st = $db->prepare('SELECT * FROM digilocker_sessions WHERE id=?');
    $st->execute([$sessionId]);
    $session = $st->fetch();
    if (!$session) return ['ok' => false, 'error' => 'Session not found.'];
    if ($session['status'] !== 'authorized') return ['ok' => false, 'error' => 'Session not authorized.'];

    $provider = $session['provider'];
    $accessToken = $session['access_token'];
    $docs = [];

    if ($provider === 'decentro') {
        $docs = decentroDigilockerGetDocs($accessToken);
    } elseif ($provider === 'digilocker') {
        $docs = digilockerDirectGetDocs($accessToken);
    }

    if ($docs === null) {
        $db->prepare('UPDATE digilocker_sessions SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', 'Failed to fetch documents', $sessionId]);
        return ['ok' => false, 'error' => 'Failed to fetch documents.'];
    }

    $saved = 0;
    foreach ($docs as $doc) {
        try {
            $db->prepare('INSERT INTO digilocker_documents (session_id, merchant_id, doc_type, doc_name, doc_number, doc_uri, issuer_id, issuer_name, issued_at, raw_data) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $sessionId, $session['merchant_id'],
                    $doc['type'] ?? 'unknown', $doc['name'] ?? null,
                    $doc['number'] ?? null, $doc['uri'] ?? null,
                    $doc['issuer_id'] ?? null, $doc['issuer_name'] ?? null,
                    $doc['issued_at'] ?? null,
                    json_encode($doc),
                ]);
            $saved++;
        } catch (Throwable $e) { error_log('digilocker doc insert: ' . $e->getMessage()); }
    }

    $db->prepare('UPDATE digilocker_sessions SET status=?, documents_fetched=? WHERE id=?')
        ->execute(['fetched', $saved, $sessionId]);

    return ['ok' => true, 'fetched' => $saved, 'message' => "{$saved} documents fetched from DigiLocker."];
}

function decentroDigilockerGetDocs(string $accessToken): ?array
{
    $base = decentroBaseUrl();
    $clientId = trim(getSetting('decentro_client_id', '') ?: getSetting('decentro_api_key', ''));
    $clientSecret = trim(getSetting('decentro_client_secret', '') ?: getSetting('decentro_api_secret', ''));

    $ch = curl_init($base . '/kyc/digilocker/issued/documents');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $clientId,
            'client_secret: ' . $clientSecret,
            'Authorization: Bearer ' . $accessToken,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['items'])) {
        return array_map(fn($item) => [
            'type' => $item['type'] ?? 'unknown',
            'name' => $item['name'] ?? null,
            'number' => $item['uri'] ?? null,
            'uri' => $item['uri'] ?? null,
            'issuer_id' => $item['issuer_id'] ?? null,
            'issuer_name' => $item['issuer_name'] ?? null,
            'issued_at' => $item['issued_at'] ?? null,
        ], $data['data']['items']);
    }
    return null;
}

function digilockerDirectGetDocs(string $accessToken): ?array
{
    $ch = curl_init('https://api.digitallocker.gov.in/public/oauth2/user/issued/documents');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['items'])) {
        return array_map(fn($item) => [
            'type' => $item['type'] ?? 'unknown',
            'name' => $item['name'] ?? null,
            'number' => $item['uri'] ?? null,
            'uri' => $item['uri'] ?? null,
            'issuer_id' => $item['issuer_id'] ?? null,
            'issuer_name' => $item['issuer_name'] ?? null,
            'issued_at' => $item['issued_at'] ?? null,
        ], $data['items']);
    }
    return null;
}

function saveDigilockerDocAsKyc(int $docId): array
{
    ensureDigilockerTable();
    $db = getDB();
    $st = $db->prepare('SELECT * FROM digilocker_documents WHERE id=?');
    $st->execute([$docId]);
    $doc = $st->fetch();
    if (!$doc) return ['ok' => false, 'error' => 'Document not found.'];

    $docType = strtolower(trim($doc['doc_type']));
    $kycType = match($docType) {
        'aadhaar' => 'aadhaar',
        'pan' => 'pan',
        'driving license', 'drivinglicence' => 'driving_license',
        'class 10', 'class10' => 'class10',
        'class 12', 'class12' => 'class12',
        default => null,
    };

    if ($kycType === null) {
        return ['ok' => false, 'error' => "Document type '{$docType}' not mapped to a KYC requirement."];
    }

    if (function_exists('autoApproveVerifiedKycDoc') && in_array($kycType, ['pan', 'aadhaar', 'gst'], true)) {
        autoApproveVerifiedKycDoc((int)$doc['merchant_id'], $kycType, $doc['doc_number'] ?? '');
    }

    $db->prepare('UPDATE digilocker_documents SET saved_as_kyc=1 WHERE id=?')->execute([$docId]);

    return ['ok' => true, 'message' => "Document saved as KYC: {$kycType}"];
}

function getMerchantDigilockerSessions(int $merchantId, int $limit = 10): array
{
    ensureDigilockerTable();
    try {
        $st = getDB()->prepare('SELECT * FROM digilocker_sessions WHERE merchant_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function getDigilockerSessionDocs(int $sessionId): array
{
    ensureDigilockerTable();
    try {
        $st = getDB()->prepare('SELECT * FROM digilocker_documents WHERE session_id=? ORDER BY id ASC');
        $st->execute([$sessionId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}
