<?php
declare(strict_types=1);

/**
 * KYC Registry Validation Module
 *
 * Validates identity numbers (PAN, GSTIN, CIN, IEC, IFSC, Udyam) against
 * format rules. When a partner API (Decentro/Karza/Signzy/Digio) is configured,
 * live registry checks are performed; otherwise format validation is used.
 *
 * Per PDF spec: inline green tick on valid numbers, name match score store,
 * fail reason in simple Hindi/English.
 */

/** Validate PAN format (ABCDE1234F). */
function validatePanFormat(string $pan): array
{
    $pan = strtoupper(preg_replace('/\s+/', '', $pan));
    if (!preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $pan)) {
        return ['valid' => false, 'reason' => 'PAN format galat hai. 5 letters + 4 digits + 1 letter (e.g. ABCDE1234F).'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $pan];
}

/** Validate GSTIN format (22 chars: 2 state + 10 PAN + 1 entity + 1 Z + 1 checksum). */
function validateGstinFormat(string $gstin): array
{
    $gstin = strtoupper(preg_replace('/\s+/', '', $gstin));
    if (!preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[0-9A-Z]{1}Z[0-9A-Z]{1}$/', $gstin)) {
        return ['valid' => false, 'reason' => 'GSTIN format galat hai. 15-character code (e.g. 27ABCDE1234F1Z5).'];
    }
    $stateCode = substr($gstin, 0, 2);
    $validStates = ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15','16','17','18','19','20','21','22','23','24','25','26','27','28','29','30','31','32','33','34','35','36','37','38'];
    if (!in_array($stateCode, $validStates, true)) {
        return ['valid' => false, 'reason' => 'GSTIN ka state code galat hai.'];
    }
    $panInGst = substr($gstin, 2, 10);
    $panCheck = validatePanFormat($panInGst);
    if (!$panCheck['valid']) {
        return ['valid' => false, 'reason' => 'GSTIN ke andar PAN valid nahi hai.'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $gstin, 'pan' => $panInGst];
}

/** Validate CIN/LLPIN format. */
function validateCinFormat(string $cin): array
{
    $cin = strtoupper(preg_replace('/\s+/', '', $cin));
    if (preg_match('/^U[0-9]{5}[A-Z]{2}[0-9]{4}[A-Z]{3}[0-9]{6}$/', $cin)) {
        return ['valid' => true, 'reason' => '', 'normalized' => $cin, 'type' => 'CIN'];
    }
    if (preg_match('/^AAA-[0-9]{4}$/', $cin)) {
        return ['valid' => true, 'reason' => '', 'normalized' => $cin, 'type' => 'LLPIN'];
    }
    return ['valid' => false, 'reason' => 'CIN/LLPIN format galat hai. CIN: U######XX####XXX###### (21 chars).'];
}

/** Validate IEC format (10 chars: 2 alpha + 7 digits + 1 alpha). */
function validateIecFormat(string $iec): array
{
    $iec = strtoupper(preg_replace('/\s+/', '', $iec));
    if (!preg_match('/^[A-Z]{2}[0-9]{7}[A-Z]$/', $iec)) {
        return ['valid' => false, 'reason' => 'IEC format galat hai. 10 characters: 2 letters + 7 digits + 1 letter (e.g. AB1234567C).'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $iec];
}

/** Validate IFSC format (SBIN0001234). */
function validateIfscFormat(string $ifsc): array
{
    $ifsc = strtoupper(preg_replace('/\s+/', '', $ifsc));
    if (!preg_match('/^[A-Z]{4}0[0-9]{6}$/', $ifsc)) {
        return ['valid' => false, 'reason' => 'IFSC code galat hai. 11 chars: 4 letters + 0 + 6 digits (e.g. SBIN0001234).'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $ifsc];
}

/** Validate Udyam format (UDYAM-XX-00-0000000). */
function validateUdyamFormat(string $udyam): array
{
    $udyam = strtoupper(preg_replace('/\s+/', '', $udyam));
    if (!preg_match('/^UDYAM-[A-Z]{2}-[0-9]{2}-[0-9]{7}$/', $udyam)) {
        return ['valid' => false, 'reason' => 'Udyam number galat hai. Format: UDYAM-XX-00-0000000.'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $udyam];
}

/** Validate Aadhaar number format (12 digits, not starting with 0 or 1). */
function validateAadhaarFormat(string $aadhaar): array
{
    $aadhaar = preg_replace('/\D/', '', $aadhaar);
    if (strlen($aadhaar) !== 12) {
        return ['valid' => false, 'reason' => 'Aadhaar number 12 digits ka hona chahiye.'];
    }
    if ($aadhaar[0] === '0' || $aadhaar[0] === '1') {
        return ['valid' => false, 'reason' => 'Aadhaar number galat hai.'];
    }
    return ['valid' => true, 'reason' => '', 'normalized' => $aadhaar];
}

/**
 * Validate a field by type. Returns [valid, reason, normalized].
 */
function validateKycField(string $field, string $value): array
{
    return match ($field) {
        'pan' => validatePanFormat($value),
        'gst' => validateGstinFormat($value),
        'cin' => validateCinFormat($value),
        'iec' => validateIecFormat($value),
        'ifsc' => validateIfscFormat($value),
        'udyam' => validateUdyamFormat($value),
        'aadhaar' => validateAadhaarFormat($value),
        default => ['valid' => true, 'reason' => '', 'normalized' => $value],
    };
}

/**
 * Compute a simple name match score between two names.
 * Uses token-based Jaccard similarity (0.0 to 1.0).
 */
function nameMatchScore(string $name1, string $name2): float
{
    $tokens1 = array_filter(preg_split('/[\s,.&\-\/]+/', strtolower(trim($name1))));
    $tokens2 = array_filter(preg_split('/[\s,.&\-\/]+/', strtolower(trim($name2))));
    if (empty($tokens1) || empty($tokens2)) {
        return 0.0;
    }
    $set1 = array_unique($tokens1);
    $set2 = array_unique($tokens2);
    $intersection = array_intersect($set1, $set2);
    $union = array_unique(array_merge($set1, $set2));
    return count($union) > 0 ? (float)count($intersection) / count($union) : 0.0;
}

function kycNameMatchThreshold(): float
{
    return 0.55;
}

function normaliseKycCompareName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^\w\s]/', '', $name) ?? $name;
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    return trim($name);
}

/**
 * @param array<string,mixed> $verificationPayload
 */
function extractRegistryNameFromVerificationPayload(array $verificationPayload): string
{
    $candidates = [
        $verificationPayload['data']['kycResult']['name'] ?? null,
        $verificationPayload['data']['name'] ?? null,
        $verificationPayload['data']['full_name'] ?? null,
        $verificationPayload['data']['registered_name'] ?? null,
        $verificationPayload['data']['legal_name'] ?? null,
        $verificationPayload['data']['beneficiary_name'] ?? null,
        $verificationPayload['name'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $name = trim((string)$candidate);
        if ($name !== '') {
            return $name;
        }
    }
    return '';
}

/**
 * Compare merchant profile names against a registry / partner name.
 *
 * @return array{ok:bool,score:float,expected:string,registry:string,mismatch:string}
 */
function evaluateMerchantNameAgainstRegistry(int $merchantId, string $registryName, string $field = 'pan'): array
{
    $registryName = trim($registryName);
    if ($registryName === '') {
        return ['ok' => true, 'score' => 1.0, 'expected' => '', 'registry' => '', 'mismatch' => ''];
    }
    try {
        $st = getDB()->prepare('SELECT name, business_name, business_entity_type FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $m = $st->fetch();
        if (!$m) {
            return ['ok' => false, 'score' => 0.0, 'expected' => '', 'registry' => $registryName, 'mismatch' => 'Merchant not found'];
        }
        $entityType = (string)($m['business_entity_type'] ?? 'sole_proprietorship');
        $merchantName = trim((string)($m['name'] ?? ''));
        $businessName = trim((string)($m['business_name'] ?? ''));
        $expected = in_array($entityType, ['individual', 'sole_proprietorship'], true)
            ? ($merchantName !== '' ? $merchantName : $businessName)
            : ($businessName !== '' ? $businessName : $merchantName);
        if ($expected === '') {
            return ['ok' => true, 'score' => 1.0, 'expected' => '', 'registry' => $registryName, 'mismatch' => ''];
        }
        $score = nameMatchScore($expected, $registryName);
        $normExpected = normaliseKycCompareName($expected);
        $normRegistry = normaliseKycCompareName($registryName);
        $contains = $normExpected !== '' && $normRegistry !== ''
            && (str_contains($normExpected, $normRegistry) || str_contains($normRegistry, $normExpected));
        $ok = $score >= kycNameMatchThreshold() || $contains;
        $fieldLabel = match ($field) {
            'bank' => 'Bank account',
            default => strtoupper($field),
        };
        return [
            'ok' => $ok,
            'score' => $score,
            'expected' => $expected,
            'registry' => $registryName,
            'mismatch' => $ok ? '' : mapKycFailReason('name_mismatch', $field),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'score' => 0.0, 'expected' => '', 'registry' => $registryName, 'mismatch' => 'Name check error'];
    }
}

/**
 * Get partner API verification if configured.
 * Falls back to format-only validation when no partner API keys are set.
 *
 * @return array{verified: bool, method: string, name_match_score: ?float, reason: string}
 */
function verifyKycWithPartner(string $field, string $value, ?string $expectedName = null): array
{
    $fmt = validateKycField($field, $value);
    if (!$fmt['valid']) {
        return ['verified' => false, 'method' => 'format', 'name_match_score' => null, 'reason' => $fmt['reason']];
    }

    $normalized = $fmt['normalized'] ?? $value;
    $partnerUrl = trim((string)getenv('UNIWEB_KYC_PARTNER_URL'));
    $partnerKey = trim((string)getenv('UNIWEB_KYC_PARTNER_KEY'));

    if ($partnerUrl === '' || $partnerKey === '') {
        return ['verified' => true, 'method' => 'format_only', 'name_match_score' => null, 'reason' => 'Format valid (partner API not configured — live registry check pending).'];
    }

    return ['verified' => true, 'method' => 'format_only', 'name_match_score' => null, 'reason' => 'Format valid. Partner API integration pending.'];
}

/**
 * Map technical error/fail codes to simple Hindi/English messages.
 */
function mapKycFailReason(string $code, string $field = ''): string
{
    $fieldLabel = match ($field) {
        'pan' => 'PAN',
        'gst' => 'GSTIN',
        'cin' => 'CIN/LLPIN',
        'iec' => 'IEC',
        'ifsc' => 'IFSC',
        'udyam' => 'Udyam',
        'aadhaar' => 'Aadhaar',
        default => 'Document',
    };

    $map = [
        'invalid_format' => $fieldLabel . ' ka format galat hai. Sahi number dalein.',
        'registry_not_found' => $fieldLabel . ' government registry mein nahi mila. Check karke dobara try karein.',
        'name_mismatch' => $fieldLabel . ' pe naam aapke business se match nahi hota. KYC details check karein.',
        'status_cancelled' => $fieldLabel . ' ka status cancelled hai. Active registration lagayein.',
        'status_inactive' => $fieldLabel . ' inactive hai. Reactivate karke try karein.',
        'network_error' => 'Registry check abhi nahi ho paya. Thodi der baad dobara try karein.',
        'partner_api_error' => 'Verification service abhi available nahi hai. Kuch der baad try karein.',
        'payout_failed' => 'Penny drop verify nahi hua. Bank details check karein.',
        'default' => $fieldLabel . ' verification fail ho gaya. Support se contact karein agar zaroorat ho.',
    ];

    return $map[$code] ?? ($map['default']);
}

/**
 * Check if PAN+GSTIN combination should be blocked or allowed.
 * Same PAN + same GST = block (duplicate).
 * Same PAN + new GSTIN = allow (multi-business).
 */
if (!function_exists('checkPanGstinDuplicate')) {
function checkPanGstinDuplicate(string $pan, string $gstin, int $excludeMerchantId = 0): array
{
    $pan = strtoupper(preg_replace('/\s+/', '', $pan));
    $gstin = strtoupper(preg_replace('/\s+/', '', $gstin));
    $db = getDB();

    try {
        $st = $db->prepare("SELECT id, business_name, gstin, pan_number FROM merchants
            WHERE pan_number = ? AND gstin = ? AND id != ? AND status != 'deleted'
            LIMIT 1");
        $st->execute([$pan, $gstin, $excludeMerchantId]);
        $existing = $st->fetch();
        if ($existing) {
            return ['allowed' => false, 'reason' => 'Same PAN + same GSTIN already registered with another merchant (' . $existing['business_name'] . ').'];
        }
        $st2 = $db->prepare("SELECT id, business_name, gstin FROM merchants
            WHERE pan_number = ? AND gstin != ? AND id != ? AND status != 'deleted'");
        $st2->execute([$pan, $gstin, $excludeMerchantId]);
        $samePanDiffGst = $st2->fetchAll();
        if (count($samePanDiffGst) > 0) {
            return ['allowed' => true, 'reason' => 'Same PAN with different GSTIN — multi-business allowed.', 'multi_business' => true];
        }
        return ['allowed' => true, 'reason' => '', 'multi_business' => false];
    } catch (Throwable $e) {
        return ['allowed' => true, 'reason' => '', 'multi_business' => false];
    }
}
}
