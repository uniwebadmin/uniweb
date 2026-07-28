<?php
declare(strict_types=1);

function verifyDocument(string $type, string $number, int $merchantId): array
{
    $type = strtolower($type);
    $number = trim($number);
    $decentroKey = getSetting('decentro_client_id', '');
    $decentroSecret = getSetting('decentro_client_secret', '');

    // Try Decentro API if configured
    if ($decentroKey && $decentroSecret) {
        $result = decentroVerify($type, $number, $decentroKey, $decentroSecret);
        if ($result) {
            saveVerification($merchantId, $type, $number, $result['status'] ?? 'pending', json_encode($result));
            return $result;
        }
    }

    // Validate format locally + mark for manual review
    $valid = match($type) {
        'pan' => (bool)preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', strtoupper($number)),
        'aadhaar' => (bool)preg_match('/^\d{12}$/', preg_replace('/\s/', '', $number)),
        'gst' => (bool)preg_match('/^\d{2}[A-Z]{5}\d{4}[A-Z]{1}[A-Z0-9]{1}Z[A-Z0-9]{1}$/', strtoupper($number)),
        'cin' => (bool)preg_match('/^[A-Z]\d{5}[A-Z]{2}\d{4}[A-Z]{3}\d{6}$/', strtoupper($number)),
        'udyam' => strlen($number) >= 10,
        // DGFT IEC: 10-digit legacy OR 10-char alphanumeric (often PAN-based)
        'iec' => (bool)preg_match('/^(\d{10}|[A-Z]{5}\d{4}[A-Z]|[A-Z0-9]{10})$/i', strtoupper(preg_replace('/\s+/', '', $number) ?? '')),
        default => false,
    };

    $status = $valid ? 'submitted' : 'failed';
    saveVerification($merchantId, $type, $number, $status, json_encode(['message' => $valid ? 'Format valid — pending API verification' : 'Invalid format']));
    return ['success' => $valid, 'status' => $status, 'message' => $valid ? 'Submitted for verification' : 'Invalid document format'];
}

function decentroBaseUrl(): string
{
    return rtrim(getSetting('decentro_base_url', 'https://in.staging.decentro.tech'), '/');
}

function decentroVerify(string $type, string $number, string $clientId, string $clientSecret): ?array
{
    $base = decentroBaseUrl();
    $paths = [
        'pan' => '/kyc/public_registry/validate',
        'aadhaar' => '/kyc/aadhaar/otp',
        'gst' => '/kyc/public_registry/validate',
        'cin' => '/kyc/public_registry/validate',
        'bank' => '/core_banking/money_transfer/validate_account',
    ];
    if (!isset($paths[$type])) return null;

    $docType = match ($type) {
        'gst' => 'GSTIN',
        'cin' => 'CIN',
        'pan' => 'PAN',
        default => strtoupper($type),
    };

    $payload = [
        'reference_id' => 'UW' . time() . random_int(1000, 9999),
        'document_type' => $docType,
        'id_number' => $number,
        'consent' => true,
        'purpose' => 'KYC verification for UNIWEB merchant onboarding',
    ];
    if ($type === 'bank') {
        [$account, $ifsc] = array_pad(explode('|', $number, 2), 2, '');
        $payload = [
            'reference_id' => $payload['reference_id'],
            'beneficiary_account_number' => $account,
            'beneficiary_ifsc' => $ifsc,
            'validation_type' => 'pennydrop',
        ];
    }

    $headers = [
        'client_id: ' . $clientId,
        'client_secret: ' . $clientSecret,
        'Content-Type: application/json',
    ];
    $urn = getSetting('decentro_consumer_urn', '');
    if ($urn) $headers[] = 'consumer_urn: ' . $urn;

    $ch = curl_init($base . $paths[$type]);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        $data = json_decode($response, true);
        if (!is_array($data)) {
            return ['success' => false, 'status' => 'failed', 'message' => 'Provider returned invalid JSON'];
        }
        $flatStatus = strtoupper(trim((string)(
            $data['data']['status']
            ?? $data['data']['account_status']
            ?? $data['response_status']
            ?? $data['status']
            ?? ''
        )));
        if ($type === 'aadhaar') {
            $referenceId = trim((string)(
                $data['data']['decentroTxnId']
                ?? $data['decentroTxnId']
                ?? $data['data']['reference_id']
                ?? $payload['reference_id']
                ?? ''
            ));
            $status = in_array($flatStatus, ['VERIFIED', 'VALID'], true) ? 'verified' : 'otp_sent';
            return [
                'success' => true,
                'status' => $status,
                'reference_id' => $referenceId,
                'message' => $status === 'otp_sent'
                    ? 'OTP sent to Aadhaar-linked mobile. Enter the 6-digit OTP below.'
                    : 'Aadhaar verified successfully.',
                'data' => $data,
            ];
        }
        $authoritative = match ($type) {
            'bank' => in_array($flatStatus, ['VERIFIED', 'VALID', 'ACTIVE'], true)
                && trim((string)($data['data']['beneficiary_name'] ?? $data['data']['name'] ?? '')) !== '',
            'pan', 'gst', 'cin' => in_array($flatStatus, ['VERIFIED', 'VALID', 'ACTIVE'], true),
            default => false,
        };
        return [
            'success' => true,
            'status' => $authoritative ? 'verified' : 'submitted',
            'message' => $authoritative ? 'Provider verification confirmed' : 'Provider response received; manual review required',
            'data' => $data,
        ];
    }
    return null;
}

/**
 * Look up bank/branch details for an IFSC using the free public Razorpay IFSC
 * directory (no API key, no cost). Results are cached in-process. Returns null
 * on invalid format or lookup failure so callers can degrade gracefully.
 * @return array{ifsc:string,bank:string,branch:string,city:string,district:string,state:string}|null
 */
function lookupIfsc(string $ifsc): ?array
{
    static $cache = [];
    $ifsc = strtoupper(trim($ifsc));
    if (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        return null;
    }
    if (isset($cache[$ifsc])) {
        return $cache[$ifsc];
    }
    $response = null;
    if (function_exists('curl_init')) {
        $ch = curl_init('https://ifsc.razorpay.com/' . rawurlencode($ifsc));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'UniWeb/1.0',
        ]);
        $out = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300 && $out) {
            $response = $out;
        }
    }
    if ($response === null) {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
        $out = @file_get_contents('https://ifsc.razorpay.com/' . rawurlencode($ifsc), false, $ctx);
        if ($out !== false && $out !== '') {
            $response = $out;
        }
    }
    if ($response === null) {
        return null;
    }
    $data = json_decode($response, true);
    if (!is_array($data) || empty($data['BANK'])) {
        return null;
    }
    $result = [
        'ifsc' => $ifsc,
        'bank' => (string)($data['BANK'] ?? ''),
        'branch' => (string)($data['BRANCH'] ?? ''),
        'city' => (string)($data['CITY'] ?? ''),
        'district' => (string)($data['DISTRICT'] ?? ''),
        'state' => (string)($data['STATE'] ?? ''),
    ];
    $cache[$ifsc] = $result;
    return $result;
}

function verifyBankAccount(string $accountNumber, string $ifsc, int $merchantId): array
{
    $result = verifyDocument('bank', $accountNumber . '|' . $ifsc, $merchantId);
    if (($result['status'] ?? '') === 'verified') {
        $result['account_holder'] = trim((string)(
            $result['data']['data']['beneficiary_name']
            ?? $result['data']['data']['name']
            ?? $result['data']['beneficiary_name']
            ?? $result['data']['name']
            ?? ''
        ));
        return $result;
    }

    // Penny drop simulation / manual review
    saveVerification($merchantId, 'bank', $accountNumber, 'submitted', json_encode(['ifsc' => $ifsc, 'message' => 'Bank verification submitted']));
    return ['success' => true, 'status' => 'submitted', 'message' => 'Bank account submitted for penny-drop verification'];
}

/**
 * Fast-track KYC: when a document is authoritatively verified against the
 * government registry (Decentro), skip manual file upload + admin queue and
 * auto-approve the matching kyc_documents row. Only fires for types that map
 * 1:1 to a KYC requirement key (pan/aadhaar/gst) — CIN/bank stay manual since
 * they don't map to a single certificate type. Merchant-level KYC verification
 * (maker-checker) is untouched; this only clears the document-level step.
 */
function autoApproveVerifiedKycDoc(int $merchantId, string $type, string $number): void
{
    if (!in_array($type, ['pan', 'aadhaar', 'gst'], true)) {
        return;
    }
    try {
        $db = getDB();
        $existing = $db->prepare("SELECT id FROM kyc_documents WHERE merchant_id=? AND doc_type=? AND status='approved' LIMIT 1");
        $existing->execute([$merchantId, $type]);
        if ($existing->fetch()) {
            return;
        }
        $db->prepare(
            "INSERT INTO kyc_documents (merchant_id,doc_type,file_name,file_path,storage_key,sha256,mime_type,file_size,scan_status,status,reviewed_at,retention_until)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),DATE_ADD(CURDATE(),INTERVAL 8 YEAR))"
        )->execute([
            $merchantId,
            $type,
            'registry_ekyc_' . $type,
            '',
            '',
            hash('sha256', $type . ':' . $number . ':' . $merchantId),
            'application/json',
            0,
            'clean',
            'approved',
        ]);
        $db->prepare("UPDATE merchants SET kyc_status=IF(kyc_status='pending','submitted',kyc_status), onboarding_state=IF(onboarding_state='pending','submitted',onboarding_state), onboarding_submitted_at=COALESCE(onboarding_submitted_at,NOW()) WHERE id=?")
            ->execute([$merchantId]);
        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('kyc_doc_auto_approved_registry', $merchantId, 'merchant', (string)$merchantId, ucfirst($type) . ' auto-approved via registry e-KYC (DigiLocker/Decentro)');
        }
        if (function_exists('createNotification')) {
            createNotification($merchantId, 'Fast KYC Verified', ucfirst($type) . ' verified instantly via DigiLocker/Aadhaar e-KYC — no document upload needed.');
        }
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('error', 'autoApproveVerifiedKycDoc failed: ' . $e->getMessage(), ['merchant_id' => $merchantId, 'type' => $type]);
        }
    }
}

function saveVerification(int $merchantId, string $type, string $number, string $status, string $response): void
{
    $db = getDB();
    $db->prepare('INSERT INTO kyc_verifications (merchant_id, doc_type, doc_number, status, api_response) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status), api_response=VALUES(api_response), updated_at=NOW()')
        ->execute([$merchantId, $type, $number, $status, $response]);
    if ($type === 'bank') {
        $db->prepare('UPDATE merchants SET bank_verification_status=? WHERE id=?')
            ->execute([$status === 'verified' ? 'verified' : 'submitted', $merchantId]);
    }
}

function getVerifications(int $merchantId): array
{
    $stmt = getDB()->prepare('SELECT * FROM kyc_verifications WHERE merchant_id = ? ORDER BY updated_at DESC');
    $stmt->execute([$merchantId]);
    return $stmt->fetchAll();
}

function confirmAadhaarOtp(int $merchantId, string $aadhaar, string $otp, string $referenceId): array
{
    $aadhaar = preg_replace('/\D/', '', $aadhaar);
    $otp = preg_replace('/\D/', '', $otp);
    $referenceId = trim($referenceId);
    if (strlen($aadhaar) !== 12 || strlen($otp) < 4) {
        return ['success' => false, 'status' => 'failed', 'message' => 'Enter valid Aadhaar number and OTP.'];
    }
    if ($referenceId === '') {
        $st = getDB()->prepare("SELECT api_response FROM kyc_verifications WHERE merchant_id=? AND doc_type='aadhaar' AND doc_number=? ORDER BY updated_at DESC LIMIT 1");
        $st->execute([$merchantId, $aadhaar]);
        $row = json_decode((string)$st->fetchColumn(), true);
        $referenceId = trim((string)($row['reference_id'] ?? ''));
    }
    if ($referenceId === '') {
        return ['success' => false, 'status' => 'failed', 'message' => 'Send OTP first using Verify, then enter OTP here.'];
    }

    $clientId = getSetting('decentro_client_id', '');
    $clientSecret = getSetting('decentro_client_secret', '');
    if (!$clientId || !$clientSecret) {
        saveVerification($merchantId, 'aadhaar', $aadhaar, 'submitted', json_encode(['otp' => 'received', 'reference_id' => $referenceId]));
        return ['success' => true, 'status' => 'submitted', 'message' => 'OTP recorded — pending registry API keys for automatic verification.'];
    }

    $payload = [
        'reference_id' => $referenceId,
        'otp' => $otp,
        'consent' => true,
        'purpose' => 'KYC verification for UNIWEB merchant onboarding',
    ];
    $headers = [
        'client_id: ' . $clientId,
        'client_secret: ' . $clientSecret,
        'Content-Type: application/json',
    ];
    $urn = getSetting('decentro_consumer_urn', '');
    if ($urn) {
        $headers[] = 'consumer_urn: ' . $urn;
    }
    $ch = curl_init(decentroBaseUrl() . '/kyc/aadhaar/otp/validate');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode >= 200 && $httpCode < 300 && $response) {
        $data = json_decode($response, true);
        $flatStatus = strtoupper(trim((string)(
            $data['data']['status'] ?? $data['response_status'] ?? $data['status'] ?? ''
        )));
        $verified = in_array($flatStatus, ['VERIFIED', 'VALID', 'SUCCESS'], true);
        $status = $verified ? 'verified' : 'failed';
        saveVerification($merchantId, 'aadhaar', $aadhaar, $status, json_encode(['reference_id' => $referenceId, 'confirm' => $data]));
        if ($verified) {
            getDB()->prepare('UPDATE merchants SET aadhaar_number=? WHERE id=?')->execute([$aadhaar, $merchantId]);
        }
        return [
            'success' => $verified,
            'status' => $status,
            'message' => $verified ? 'Aadhaar verified successfully.' : 'OTP verification failed. Request a new OTP and try again.',
            'data' => $data,
        ];
    }
    return ['success' => false, 'status' => 'failed', 'message' => 'OTP verification service unavailable. Try again shortly.'];
}
