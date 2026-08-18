<?php
declare(strict_types=1);

/**
 * eSign Module — Aadhaar-based electronic signature for merchant agreements.
 * Supports partner APIs (Decentro/eMudhra) when keys configured.
 * Fallback: typed electronic signature (already existing) with enhanced audit.
 */

function ensureEsignTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS esign_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            esign_id VARCHAR(40) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            agreement_version VARCHAR(30) NOT NULL,
            document_hash VARCHAR(64) NOT NULL,
            pdf_filename VARCHAR(255) DEFAULT NULL,
            signer_name VARCHAR(190) NOT NULL,
            signer_aadhaar VARCHAR(20) DEFAULT NULL,
            signer_email VARCHAR(190) DEFAULT NULL,
            signer_phone VARCHAR(20) DEFAULT NULL,
            provider VARCHAR(60) NOT NULL DEFAULT 'internal',
            provider_txn_id VARCHAR(120) DEFAULT NULL,
            status ENUM('initiated','otp_sent','signed','failed','cancelled') NOT NULL DEFAULT 'initiated',
            otp_reference VARCHAR(60) DEFAULT NULL,
            signature_value TEXT DEFAULT NULL,
            signature_certificate TEXT DEFAULT NULL,
            signed_pdf_path VARCHAR(500) DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(500) DEFAULT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            signed_at DATETIME DEFAULT NULL,
            INDEX idx_esign_merchant (merchant_id, status),
            INDEX idx_esign_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { error_log('ensureEsignTable: ' . $e->getMessage()); }
}

function generateEsignId(): string
{
    return 'ESN-' . date('ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function getEsignProvider(): string
{
    if (!function_exists('isDecentroConfigured') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (function_exists('isDecentroConfigured') && isDecentroConfigured()) {
        return 'decentro';
    }
    $emudhraKey = trim(getSetting('emudhra_api_key', ''));
    if ($emudhraKey !== '') {
        return 'emudhra';
    }
    return 'internal';
}

function isEsignAvailable(): bool
{
    return getEsignProvider() !== 'internal' || true;
}

function initiateEsign(int $merchantId, string $agreementVersion, string $documentHash, array $signerInfo): array
{
    ensureEsignTable();
    $db = getDB();
    $esignId = generateEsignId();
    $provider = getEsignProvider();
    $ip = substr((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    if (str_contains($ip, ',')) $ip = trim(explode(',', $ip)[0]);

    try {
        $db->prepare('INSERT INTO esign_requests (esign_id, merchant_id, agreement_version, document_hash, signer_name, signer_aadhaar, signer_email, signer_phone, provider, status, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $esignId, $merchantId, $agreementVersion, $documentHash,
                $signerInfo['name'] ?? '', $signerInfo['aadhaar'] ?? null,
                $signerInfo['email'] ?? null, $signerInfo['phone'] ?? null,
                $provider, 'initiated', $ip,
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)
            ]);
        $requestId = (int)$db->lastInsertId();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Failed to initiate eSign: ' . $e->getMessage()];
    }

    if ($provider === 'decentro') {
        $res = decentroEsignInitiate($esignId, $documentHash, $signerInfo);
        if (!empty($res['ok'])) {
            $db->prepare('UPDATE esign_requests SET status=?, provider_txn_id=?, otp_reference=? WHERE id=?')
                ->execute(['otp_sent', $res['txn_id'] ?? null, $res['otp_ref'] ?? null, $requestId]);
            return ['ok' => true, 'esign_id' => $esignId, 'request_id' => $requestId, 'provider' => $provider, 'message' => 'OTP sent to Aadhaar-linked mobile.'];
        }
        $db->prepare('UPDATE esign_requests SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', $res['error'] ?? 'Initiate failed', $requestId]);
        return ['ok' => false, 'error' => $res['error'] ?? 'eSign initiate failed'];
    }

    if ($provider === 'emudhra') {
        $res = emudhraEsignInitiate($esignId, $documentHash, $signerInfo);
        if (!empty($res['ok'])) {
            $db->prepare('UPDATE esign_requests SET status=?, provider_txn_id=?, otp_reference=? WHERE id=?')
                ->execute(['otp_sent', $res['txn_id'] ?? null, $res['otp_ref'] ?? null, $requestId]);
            return ['ok' => true, 'esign_id' => $esignId, 'request_id' => $requestId, 'provider' => $provider, 'message' => 'OTP sent.'];
        }
        $db->prepare('UPDATE esign_requests SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', $res['error'] ?? 'Initiate failed', $requestId]);
        return ['ok' => false, 'error' => $res['error'] ?? 'eSign initiate failed'];
    }

    return ['ok' => true, 'esign_id' => $esignId, 'request_id' => $requestId, 'provider' => 'internal', 'message' => 'eSign initiated (internal mode). Use verifyOtp to complete.'];
}

function verifyEsignOtp(int $requestId, string $otp): array
{
    ensureEsignTable();
    $db = getDB();
    $st = $db->prepare('SELECT * FROM esign_requests WHERE id=?');
    $st->execute([$requestId]);
    $req = $st->fetch();
    if (!$req) return ['ok' => false, 'error' => 'eSign request not found.'];
    if (!in_array($req['status'], ['otp_sent', 'initiated'], true)) {
        return ['ok' => false, 'error' => "Request status is {$req['status']}, cannot verify OTP."];
    }

    $provider = $req['provider'];
    if ($provider === 'decentro') {
        $res = decentroEsignVerifyOtp($req['provider_txn_id'] ?? '', $otp);
        if (!empty($res['ok'])) {
            completeEsign($requestId, $res['signature'] ?? '', $res['certificate'] ?? '', $res['signed_pdf'] ?? null);
            return ['ok' => true, 'message' => 'eSign completed via Decentro. Signed PDF available.'];
        }
        $db->prepare('UPDATE esign_requests SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', $res['error'] ?? 'OTP verification failed', $requestId]);
        return ['ok' => false, 'error' => $res['error'] ?? 'OTP verification failed'];
    }

    if ($provider === 'emudhra') {
        $res = emudhraEsignVerifyOtp($req['provider_txn_id'] ?? '', $otp);
        if (!empty($res['ok'])) {
            completeEsign($requestId, $res['signature'] ?? '', $res['certificate'] ?? '', $res['signed_pdf'] ?? null);
            return ['ok' => true, 'message' => 'eSign completed via eMudhra. Signed PDF available.'];
        }
        $db->prepare('UPDATE esign_requests SET status=?, error_message=? WHERE id=?')
            ->execute(['failed', $res['error'] ?? 'OTP verification failed', $requestId]);
        return ['ok' => false, 'error' => $res['error'] ?? 'OTP verification failed'];
    }

    if (strlen(trim($otp)) >= 4) {
        completeEsign($requestId, hash('sha256', $req['esign_id'] . $otp), 'internal-typed-signature', null);
        return ['ok' => true, 'message' => 'eSign completed (internal mode).'];
    }

    return ['ok' => false, 'error' => 'Invalid OTP.'];
}

function completeEsign(int $requestId, string $signature, string $certificate, ?string $signedPdfPath): void
{
    $db = getDB();
    $db->prepare('UPDATE esign_requests SET status=?, signature_value=?, signature_certificate=?, signed_pdf_path=?, signed_at=NOW() WHERE id=?')
        ->execute(['signed', $signature, $certificate, $signedPdfPath, $requestId]);

    $st = $db->prepare('SELECT * FROM esign_requests WHERE id=?');
    $st->execute([$requestId]);
    $req = $st->fetch();

    if ($req && function_exists('recordImmutableAudit')) {
        recordImmutableAudit(
            'esign_completed',
            (int)$req['merchant_id'],
            'agreement',
            $req['agreement_version'],
            "eSign via {$req['provider']}: esign_id={$req['esign_id']}"
        );
    }

    if ($req && function_exists('createNotification')) {
        createNotification((int)$req['merchant_id'], 'Agreement eSigned', "Your Merchant Agreement v{$req['agreement_version']} has been digitally signed via {$req['provider']}.");
    }
}

function cancelEsign(int $requestId): array
{
    ensureEsignTable();
    try {
        getDB()->prepare("UPDATE esign_requests SET status='cancelled' WHERE id=? AND status IN ('initiated','otp_sent')")
            ->execute([$requestId]);
        return ['ok' => true, 'message' => 'eSign request cancelled.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getEsignRequest(int $requestId): ?array
{
    ensureEsignTable();
    try {
        $st = getDB()->prepare('SELECT * FROM esign_requests WHERE id=?');
        $st->execute([$requestId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) { return null; }
}

function getMerchantEsignRequests(int $merchantId, int $limit = 20): array
{
    ensureEsignTable();
    try {
        $st = getDB()->prepare('SELECT * FROM esign_requests WHERE merchant_id=? ORDER BY id DESC LIMIT ' . (int)$limit);
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) { return []; }
}

function getEsignStats(): array
{
    ensureEsignTable();
    try {
        $db = getDB();
        $total = (int)$db->query("SELECT COUNT(*) FROM esign_requests")->fetchColumn();
        $signed = (int)$db->query("SELECT COUNT(*) FROM esign_requests WHERE status='signed'")->fetchColumn();
        $pending = (int)$db->query("SELECT COUNT(*) FROM esign_requests WHERE status IN ('initiated','otp_sent')")->fetchColumn();
        $failed = (int)$db->query("SELECT COUNT(*) FROM esign_requests WHERE status='failed'")->fetchColumn();
        return ['total' => $total, 'signed' => $signed, 'pending' => $pending, 'failed' => $failed];
    } catch (Throwable $e) { return ['total' => 0, 'signed' => 0, 'pending' => 0, 'failed' => 0]; }
}

function decentroEsignInitiate(string $esignId, string $documentHash, array $signerInfo): array
{
    if (!function_exists('decentroClientId') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $apiKey = decentroClientId();
    $apiSecret = decentroClientSecret();
    $baseUrl = decentroBaseUrl();

    $payload = json_encode([
        'reference_id' => $esignId,
        'document_hash' => $documentHash,
        'signer_name' => $signerInfo['name'] ?? '',
        'signer_aadhaar' => $signerInfo['aadhaar'] ?? '',
        'signer_email' => $signerInfo['email'] ?? '',
        'signer_mobile' => $signerInfo['phone'] ?? '',
        'callback_url' => getSetting('site_url', '') . '/esign_callback.php',
    ]);

    $ch = curl_init($baseUrl . '/v2/kyc/esign/initiate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $apiKey,
            'client_secret: ' . $apiSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) return ['ok' => false, 'error' => $curlError];
    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['transaction_id'])) {
        return [
            'ok' => true,
            'txn_id' => $data['data']['transaction_id'],
            'otp_ref' => $data['data']['otp_reference'] ?? null,
        ];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}

function decentroEsignVerifyOtp(string $txnId, string $otp): array
{
    if (!function_exists('decentroClientId') && is_file(__DIR__ . '/partner_control.php')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $apiKey = decentroClientId();
    $apiSecret = decentroClientSecret();
    $baseUrl = decentroBaseUrl();

    $ch = curl_init($baseUrl . '/v2/kyc/esign/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['transaction_id' => $txnId, 'otp' => $otp]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'client_id: ' . $apiKey,
            'client_secret: ' . $apiSecret,
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['data']['signature'])) {
        return [
            'ok' => true,
            'signature' => $data['data']['signature'],
            'certificate' => $data['data']['certificate'] ?? '',
            'signed_pdf' => $data['data']['signed_pdf_url'] ?? null,
        ];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}

function emudhraEsignInitiate(string $esignId, string $documentHash, array $signerInfo): array
{
    $apiKey = trim(getSetting('emudhra_api_key', ''));
    $baseUrl = trim(getSetting('emudhra_base_url', 'https://api.emudhra.com'));

    $ch = curl_init($baseUrl . '/v1/esign/initiate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'reference_id' => $esignId,
            'document_hash' => $documentHash,
            'signer_name' => $signerInfo['name'] ?? '',
            'signer_aadhaar' => $signerInfo['aadhaar'] ?? '',
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['transactionId'])) {
        return ['ok' => true, 'txn_id' => $data['transactionId'], 'otp_ref' => $data['otpReference'] ?? null];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}

function emudhraEsignVerifyOtp(string $txnId, string $otp): array
{
    $apiKey = trim(getSetting('emudhra_api_key', ''));
    $baseUrl = trim(getSetting('emudhra_base_url', 'https://api.emudhra.com'));

    $ch = curl_init($baseUrl . '/v1/esign/verify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['transactionId' => $txnId, 'otp' => $otp]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($httpCode === 200 && isset($data['signature'])) {
        return ['ok' => true, 'signature' => $data['signature'], 'certificate' => $data['certificate'] ?? '', 'signed_pdf' => $data['signedPdfUrl'] ?? null];
    }
    return ['ok' => false, 'error' => $data['message'] ?? "HTTP {$httpCode}"];
}
