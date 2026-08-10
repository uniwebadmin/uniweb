<?php
declare(strict_types=1);

/**
 * D4 — Partner Onboarding Payload Builder
 *
 * Single entry point for building the package that gets forwarded to partners.
 * Contains refs (not raw secrets) for business profile, KYC docs, bank proof,
 * agreement status, and merchant contact.
 */

/**
 * Build a structured onboarding payload for partner submission.
 * Never includes raw Aadhaar full number — uses refs and masked values.
 *
 * @return array Structured payload (safe to JSON-encode and store in queue)
 */
function build_partner_onboarding_payload(int $merchantId): array
{
    $db = getDB();

    // 1. Business profile snapshot
    $st = $db->prepare('SELECT id, merchant_code, name, business_name, email, phone,
        business_type, business_entity_type, address, city, state, district, pincode, country,
        pan_number, gstin, cin_llpin, udyam_number, iec_number,
        kyc_status, onboarding_state, account_mode, status,
        website_url, android_app_url, ios_app_url,
        onboarding_submitted_at, created_at
        FROM merchants WHERE id=?');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    if (!$merchant) {
        return ['error' => 'Merchant not found'];
    }

    // Decrypt PII fields for payload (partner API needs full values, but we don't log them)
    $pan = '';
    $gstin = '';
    $cin = '';
    if (function_exists('isSensitiveEncrypted') && function_exists('sensitiveDecrypt')) {
        $pan = (string)$merchant['pan_number'];
        $pan = isSensitiveEncrypted($pan) ? sensitiveDecrypt($pan) : $pan;
        $gstin = (string)$merchant['gstin'];
        $gstin = isSensitiveEncrypted($gstin) ? sensitiveDecrypt($gstin) : $gstin;
        $cin = (string)$merchant['cin_llpin'];
        $cin = isSensitiveEncrypted($cin) ? sensitiveDecrypt($cin) : $cin;
    }

    // 2. KYC document references (storage paths, not raw file contents)
    $docSt = $db->prepare("SELECT id, doc_type, file_name, storage_key, status, scan_status, mime_type, file_size, sha256
        FROM kyc_documents WHERE merchant_id=? ORDER BY created_at DESC");
    $docSt->execute([$merchantId]);
    $allDocs = $docSt->fetchAll();

    // Latest per type
    $docsByType = [];
    foreach ($allDocs as $doc) {
        $t = $doc['doc_type'];
        if (!isset($docsByType[$t])) {
            $docsByType[$t] = [
                'doc_id' => (int)$doc['id'],
                'doc_type' => $doc['doc_type'],
                'file_name' => $doc['file_name'],
                'storage_key' => $doc['storage_key'],
                'status' => $doc['status'],
                'scan_status' => $doc['scan_status'],
                'mime_type' => $doc['mime_type'],
                'file_size' => (int)$doc['file_size'],
                'sha256' => $doc['sha256'],
            ];
        }
    }

    // 3. Bank proof reference
    $bankSt = $db->prepare("SELECT id, doc_type, status, account_holder, ifsc_code
        FROM kyc_verifications WHERE merchant_id=? AND doc_type='bank' ORDER BY created_at DESC LIMIT 1");
    $bankSt->execute([$merchantId]);
    $bank = $bankSt->fetch();

    // 4. Agreement acceptance status
    $agreementSt = $db->prepare("SELECT agreement_version, accepted_at, accepted_ip, signature_name, partner_names
        FROM merchant_agreement_acceptances WHERE merchant_id=? ORDER BY accepted_at DESC LIMIT 1");
    $agreementSt->execute([$merchantId]);
    $agreement = $agreementSt->fetch();

    // 5. eSign status
    $esignSt = $db->prepare("SELECT id, status, provider, initiated_at, completed_at
        FROM esign_requests WHERE merchant_id=? ORDER BY initiated_at DESC LIMIT 1");
    $esignSt->execute([$merchantId]);
    $esign = $esignSt->fetch();

    // Build payload — PII fields included for partner API but never logged in plain
    return [
        'merchant' => [
            'id' => (int)$merchant['id'],
            'merchant_code' => $merchant['merchant_code'],
            'name' => $merchant['name'],
            'business_name' => $merchant['business_name'],
            'email' => $merchant['email'],
            'phone' => $merchant['phone'],
            'business_type' => $merchant['business_type'],
            'business_entity_type' => $merchant['business_entity_type'],
            'address' => $merchant['address'],
            'city' => $merchant['city'],
            'state' => $merchant['state'],
            'district' => $merchant['district'],
            'pincode' => $merchant['pincode'],
            'country' => $merchant['country'],
            'website_url' => $merchant['website_url'],
            // PII — included for partner API submission, never logged
            'pan' => $pan,
            'gstin' => $gstin,
            'cin_llpin' => $cin,
            'udyam_number' => $merchant['udyam_number'],
            'iec_number' => $merchant['iec_number'],
        ],
        'kyc_documents' => array_values($docsByType),
        'bank_verification' => $bank ? [
            'verification_id' => (int)$bank['id'],
            'status' => $bank['status'],
            'account_holder' => $bank['account_holder'],
            'ifsc_code' => $bank['ifsc_code'],
        ] : null,
        'agreement' => $agreement ? [
            'version' => $agreement['agreement_version'],
            'accepted_at' => $agreement['accepted_at'],
            'accepted_ip' => $agreement['accepted_ip'],
            'signature_name' => $agreement['signature_name'],
            'partner_names' => $agreement['partner_names'],
        ] : null,
        'esign' => $esign ? [
            'esign_id' => (int)$esign['id'],
            'status' => $esign['status'],
            'provider' => $esign['provider'],
            'initiated_at' => $esign['initiated_at'],
            'completed_at' => $esign['completed_at'],
        ] : null,
        'onboarding' => [
            'kyc_status' => $merchant['kyc_status'],
            'onboarding_state' => $merchant['onboarding_state'],
            'account_mode' => $merchant['account_mode'],
            'submitted_at' => $merchant['onboarding_submitted_at'],
            'created_at' => $merchant['created_at'],
        ],
        'payload_built_at' => date('Y-m-d H:i:s'),
    ];
}

/**
 * D4: Get a redacted version of the payload for logging/display (no raw PII).
 * Must never contain: plain PAN/GSTIN/CIN/Aadhaar, password/hash, api_secret/api_key,
 * or full bank account numbers.
 */
function redactPartnerPayload(array $payload): array
{
    if (isset($payload['merchant'])) {
        $m = &$payload['merchant'];

        // Strip any sensitive fields that should never be in a stored payload
        unset(
            $m['password'], $m['password_hash'], $m['api_secret'], $m['api_key'],
            $m['totp_secret'], $m['aadhaar'], $m['aadhaar_number'],
            $m['pan_number'], $m['gstin'], $m['cin_llpin'],
            $m['bank_account_number'], $m['account_number'],
        );

        // Redact PAN
        if (!empty($m['pan'])) {
            if (function_exists('pii_mask_pan')) {
                $m['pan'] = pii_mask_pan($m['pan']);
            } else {
                $m['pan'] = '****' . substr((string)$m['pan'], -4);
            }
        }
        // Redact GSTIN
        if (!empty($m['gstin'])) {
            if (function_exists('pii_mask_gstin')) {
                $m['gstin'] = pii_mask_gstin($m['gstin']);
            } else {
                $m['gstin'] = '****' . substr((string)$m['gstin'], -4);
            }
        }
        // Redact CIN
        if (!empty($m['cin_llpin'])) {
            if (function_exists('pii_mask_pan')) {
                $m['cin_llpin'] = pii_mask_pan($m['cin_llpin']);
            } else {
                $m['cin_llpin'] = '****' . substr((string)$m['cin_llpin'], -4);
            }
        }

        // Mask phone partially
        if (!empty($m['phone'])) {
            $m['phone'] = '****' . substr($m['phone'], -4);
        }
        // Mask email partially
        if (!empty($m['email']) && str_contains($m['email'], '@')) {
            [$local, $domain] = explode('@', $m['email'], 2);
            $m['email'] = substr($local, 0, 2) . '***@' . $domain;
        }
    }

    // Redact bank verification — mask account holder name, keep only IFSC prefix
    if (isset($payload['bank_verification']) && is_array($payload['bank_verification'])) {
        $b = &$payload['bank_verification'];
        // Never store full account numbers — only last 4 if present
        if (!empty($b['account_number'])) {
            $b['account_number'] = '****' . substr((string)$b['account_number'], -4);
        }
        // Strip any account_holder that might contain sensitive info
        if (!empty($b['account_holder'])) {
            $b['account_holder'] = '****' . substr((string)$b['account_holder'], -2);
        }
    }

    // Redact any top-level sensitive keys that might have been added by old code paths
    foreach (['password', 'password_hash', 'api_secret', 'api_key', 'totp_secret', 'aadhaar', 'secret'] as $dangerousKey) {
        unset($payload[$dangerousKey]);
    }

    return $payload;
}

/**
 * Purge plaintext secrets from old gateway_submissions and partner_forward_queue rows.
 * Overwrites payload with redacted version or NULL if cannot be redacted.
 * Idempotent: safe to run multiple times — already-redacted rows are skipped.
 * Returns count of rows purged.
 */
function purgeSecretsFromSubmissions(): array
{
    $db = getDB();
    $purged = ['gateway_submissions' => 0, 'partner_forward_queue' => 0];

    // Helper: detect if a merchant sub-array contains plaintext secrets
    $hasPlaintextSecrets = static function (array $m): bool {
        // Password / api_secret / totp_secret — always dangerous
        if (isset($m['password']) || isset($m['password_hash'])
            || isset($m['api_secret']) || isset($m['api_key'])
            || isset($m['totp_secret']) || isset($m['secret'])) {
            return true;
        }
        // PAN: plaintext if not masked (doesn't start with ****)
        foreach (['pan', 'pan_number', 'gstin', 'cin_llpin', 'aadhaar', 'aadhaar_number'] as $field) {
            if (!empty($m[$field]) && !str_starts_with((string)$m[$field], '****') && !str_starts_with((string)$m[$field], '—')) {
                return true;
            }
        }
        // Full bank account number
        if (!empty($m['bank_account_number']) && !str_starts_with((string)$m['bank_account_number'], '****')) {
            return true;
        }
        return false;
    };

    // 1. Purge gateway_submissions
    try {
        $rows = $db->query("SELECT id, payload FROM gateway_submissions WHERE payload IS NOT NULL AND payload != ''")->fetchAll();
        foreach ($rows as $row) {
            $data = json_decode($row['payload'], true);
            if (!is_array($data)) {
                // Not JSON or corrupt — null it out
                $db->prepare("UPDATE gateway_submissions SET payload=NULL WHERE id=?")->execute([$row['id']]);
                $purged['gateway_submissions']++;
                continue;
            }
            $needsPurge = false;
            if (isset($data['merchant']) && is_array($data['merchant'])) {
                $needsPurge = $hasPlaintextSecrets($data['merchant']);
            }
            // Also check top-level dangerous keys
            foreach (['password', 'password_hash', 'api_secret', 'api_key', 'totp_secret', 'aadhaar', 'secret'] as $dk) {
                if (isset($data[$dk])) {
                    $needsPurge = true;
                    break;
                }
            }
            if ($needsPurge) {
                $redacted = redactPartnerPayload($data);
                $db->prepare("UPDATE gateway_submissions SET payload=? WHERE id=?")
                    ->execute([json_encode($redacted), $row['id']]);
                $purged['gateway_submissions']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    // 2. Purge partner_forward_queue
    try {
        $rows = $db->query("SELECT id, package_payload FROM partner_forward_queue WHERE package_payload IS NOT NULL AND package_payload != ''")->fetchAll();
        foreach ($rows as $row) {
            $data = json_decode($row['package_payload'], true);
            if (!is_array($data)) {
                $db->prepare("UPDATE partner_forward_queue SET package_payload=NULL WHERE id=?")->execute([$row['id']]);
                $purged['partner_forward_queue']++;
                continue;
            }
            $needsPurge = false;
            if (isset($data['merchant']) && is_array($data['merchant'])) {
                $needsPurge = $hasPlaintextSecrets($data['merchant']);
            }
            foreach (['password', 'password_hash', 'api_secret', 'api_key', 'totp_secret', 'aadhaar', 'secret'] as $dk) {
                if (isset($data[$dk])) {
                    $needsPurge = true;
                    break;
                }
            }
            if ($needsPurge) {
                $redacted = redactPartnerPayload($data);
                $db->prepare("UPDATE partner_forward_queue SET package_payload=? WHERE id=?")
                    ->execute([json_encode($redacted), $row['id']]);
                $purged['partner_forward_queue']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    return $purged;
}

/**
 * D10: Mask PII in a log body string (JSON or plain text).
 * Recursively walks JSON keys; falls back to regex on non-JSON strings.
 * Returns the masked string — never stores raw PAN/Aadhaar/account/secrets.
 */
function maskPiiInString(?string $body): ?string
{
    if ($body === null || $body === '') {
        return $body;
    }

    // Try JSON first
    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        $masked = maskPiiRecursive($decoded);
        return json_encode($masked, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    // Plain text: regex-mask common PII patterns
    return maskPiiRegex($body);
}

/**
 * Recursively mask PII fields in an array (JSON body).
 */
function maskPiiRecursive(array $data): array
{
    // Keys to fully redact (replace with [REDACTED])
    $redactKeys = [
        'password', 'password_hash', 'pass', 'pwd',
        'api_secret', 'api_key', 'secret', 'secret_key',
        'totp_secret', 'otp', 'token', 'access_token',
        'client_secret', 'merchant_salt', 'salt',
    ];

    // Keys to mask (keep last 4)
    $maskKeys = [
        'pan', 'pan_number', 'aadhaar', 'aadhaar_number', 'aadhaar_no',
        'account_number', 'bank_account_number', 'acct_no',
        'card_number', 'card_no', 'credit_card', 'debit_card',
        'cvv', 'cvv2',
    ];

    // Keys to partially mask (keep last 4, prefix ****)
    $partialMaskKeys = [
        'ifsc', 'gstin', 'cin_llpin', 'cin',
    ];

    foreach ($data as $key => &$value) {
        $lowerKey = strtolower((string)$key);

        if (is_array($value)) {
            $value = maskPiiRecursive($value);
            continue;
        }

        // Full redact
        if (in_array($lowerKey, $redactKeys, true)) {
            $value = '[REDACTED]';
            continue;
        }

        // Mask to last 4
        if (in_array($lowerKey, $maskKeys, true)) {
            $s = (string)$value;
            if (strlen($s) > 4) {
                $value = '****' . substr($s, -4);
            } else {
                $value = '****';
            }
            continue;
        }

        // Partial mask for IFSC/GSTIN/CIN
        if (in_array($lowerKey, $partialMaskKeys, true)) {
            $s = (string)$value;
            if (strlen($s) > 4) {
                $value = '****' . substr($s, -4);
            }
            continue;
        }

        // If value is a string, apply regex masking for embedded PII
        if (is_string($value)) {
            $value = maskPiiRegex($value);
        }
    }
    unset($value);

    return $data;
}

/**
 * Regex-mask PII patterns in a plain-text string.
 * Catches PAN (ABCDE1234F), Aadhaar (12-digit), card numbers (13-19 digit),
 * and key=value patterns for sensitive fields.
 */
function maskPiiRegex(string $text): string
{
    // Mask "key":"value" or "key": "value" patterns for sensitive keys
    $sensitiveKeys = ['password', 'pwd', 'pass', 'secret', 'api_secret', 'api_key', 'totp_secret', 'otp', 'token', 'client_secret', 'salt'];
    foreach ($sensitiveKeys as $sk) {
        $text = preg_replace(
            '/("?' . preg_quote($sk, '/') . '"?\s*[:=]\s*"?)([^"&,}\s]+)/i',
            '$1[REDACTED]',
            $text
        );
    }

    // PAN pattern: 5 letters + 4 digits + 1 letter (e.g. ABCDE1234F)
    $text = preg_replace('/\b([A-Z]{4})[A-Z0-9]{1}(\d{4})([A-Z])\b/', '$1****$3', $text);

    // Aadhaar: 12-digit number (with or without spaces/dashes)
    $text = preg_replace('/\b(\d{4})[\s-]?\d{4}[\s-]?(\d{4})\b/', '$1-XXXX-$2', $text);

    // Card number: 13-19 consecutive digits
    $text = preg_replace('/\b(\d{4})[\s-]?\d{4,6}[\s-]?\d{4,6}[\s-]?(\d{4})\b/', '$1************$2', $text);

    // Bank account: "account_number":"1234567890" → keep last 4
    $text = preg_replace(
        '/("(?:account_number|bank_account_number|acct_no|account_no)"\s*:\s*")(\d{4})(\d+)(\d{4})(")/i',
        '$1$2****$4$5',
        $text
    );

    return $text;
}

/**
 * D10: Mask PII in old log rows (idempotent cleanup).
 * Scans partner_api_logs and axis_api_logs for request_body/response_body
 * containing unmasked PII patterns and overwrites with masked versions.
 */
function purgePiiFromApiLogs(int $batchSize = 500): array
{
    $db = getDB();
    $purged = ['partner_api_logs' => 0, 'axis_api_logs' => 0];

    // PAN regex to detect unmasked values
    $panPattern = '/[A-Z]{5}\d{4}[A-Z]/';
    $aadhaarPattern = '/\b\d{12}\b/';

    // 1. partner_api_logs
    try {
        $rows = $db->query("SELECT id, request_body, response_body FROM partner_api_logs
            WHERE (request_body REGEXP '[A-Z]{5}[0-9]{4}[A-Z]'
                OR response_body REGEXP '[A-Z]{5}[0-9]{4}[A-Z]'
                OR request_body REGEXP '[0-9]{12}'
                OR response_body REGEXP '[0-9]{12}')
            ORDER BY id DESC LIMIT {$batchSize}")->fetchAll();
        foreach ($rows as $row) {
            $newReq = maskPiiInString($row['request_body']);
            $newResp = maskPiiInString($row['response_body']);
            if ($newReq !== $row['request_body'] || $newResp !== $row['response_body']) {
                $db->prepare('UPDATE partner_api_logs SET request_body=?, response_body=? WHERE id=?')
                    ->execute([$newReq, $newResp, $row['id']]);
                $purged['partner_api_logs']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    // 2. axis_api_logs
    try {
        $rows = $db->query("SELECT id, request_body, response_body FROM axis_api_logs
            WHERE (request_body REGEXP '[A-Z]{5}[0-9]{4}[A-Z]'
                OR response_body REGEXP '[A-Z]{5}[0-9]{4}[A-Z]'
                OR request_body REGEXP '[0-9]{12}'
                OR response_body REGEXP '[0-9]{12}')
            ORDER BY id DESC LIMIT {$batchSize}")->fetchAll();
        foreach ($rows as $row) {
            $newReq = maskPiiInString($row['request_body']);
            $newResp = maskPiiInString($row['response_body']);
            if ($newReq !== $row['request_body'] || $newResp !== $row['response_body']) {
                $db->prepare('UPDATE axis_api_logs SET request_body=?, response_body=? WHERE id=?')
                    ->execute([$newReq, $newResp, $row['id']]);
                $purged['axis_api_logs']++;
            }
        }
    } catch (Throwable $e) { /* ok */ }

    return $purged;
}
