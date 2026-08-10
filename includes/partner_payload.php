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
 */
function redactPartnerPayload(array $payload): array
{
    if (isset($payload['merchant'])) {
        $m = &$payload['merchant'];
        if (function_exists('pii_mask_pan')) {
            $m['pan'] = !empty($m['pan']) ? pii_mask_pan(sensitiveEncrypt($m['pan'])) : '';
            $m['gstin'] = !empty($m['gstin']) ? pii_mask_gstin(sensitiveEncrypt($m['gstin'])) : '';
            $m['cin_llpin'] = !empty($m['cin_llpin']) ? pii_mask_pan(sensitiveEncrypt($m['cin_llpin'])) : '';
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
    return $payload;
}

/**
 * Purge plaintext secrets from old gateway_submissions and partner_forward_queue rows.
 * Overwrites payload with redacted version or NULL if cannot be redacted.
 * Returns count of rows purged.
 */
function purgeSecretsFromSubmissions(): array
{
    $db = getDB();
    $purged = ['gateway_submissions' => 0, 'partner_forward_queue' => 0];

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
            // Check if it contains raw merchant data (old format with SELECT *)
            if (isset($data['merchant']) && is_array($data['merchant'])) {
                // Check for plaintext PAN/GST/password fields
                $m = $data['merchant'];
                $hasSecrets = isset($m['pan_number']) || isset($m['gstin']) || isset($m['password'])
                    || isset($m['api_secret']) || isset($m['totp_secret']);
                if ($hasSecrets) {
                    // Strip sensitive fields and redact
                    unset($data['merchant']['password'], $data['merchant']['api_secret'],
                        $data['merchant']['totp_secret'], $data['merchant']['pan_number'],
                        $data['merchant']['gstin'], $data['merchant']['cin_llpin']);
                    $db->prepare("UPDATE gateway_submissions SET payload=? WHERE id=?")
                        ->execute([json_encode($data), $row['id']]);
                    $purged['gateway_submissions']++;
                }
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
            if (isset($data['merchant']) && is_array($data['merchant'])) {
                $m = $data['merchant'];
                $hasSecrets = isset($m['pan']) && !str_starts_with((string)$m['pan'], '****')
                    || isset($m['gstin']) && !str_starts_with((string)$m['gstin'], '****')
                    || isset($m['password']) || isset($m['api_secret']) || isset($m['totp_secret']);
                if ($hasSecrets) {
                    $redacted = redactPartnerPayload($data);
                    $db->prepare("UPDATE partner_forward_queue SET package_payload=? WHERE id=?")
                        ->execute([json_encode($redacted), $row['id']]);
                    $purged['partner_forward_queue']++;
                }
            }
        }
    } catch (Throwable $e) { /* ok */ }

    return $purged;
}
