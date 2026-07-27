<?php
declare(strict_types=1);

/**
 * Entity types + KYC document requirements (single source of truth).
 * Loaded from git so live Hostinger does not depend on editing secrets-only config.php bodies.
 *
 * Each helper is guarded with function_exists() so older live config.php stubs
 * that still define a subset cannot fatal on redeclare when staff.php loads this file.
 * Prefer removing those stubs from live config.php so these definitions win.
 */

if (!function_exists('getBusinessEntityTypes')) {
    function getBusinessEntityTypes(): array
    {
        return [
            'individual' => 'Individual / Freelancer (Personal PAN)',
            'sole_proprietorship' => 'Sole Proprietorship (Business PAN)',
            'partnership' => 'Partnership Firm',
            'llp' => 'LLP (Limited Liability Partnership)',
            'private_limited' => 'Private Limited Company (Pvt Ltd)',
            'opc' => 'One Person Company (OPC)',
            'public_limited' => 'Public Limited Company',
            'trust' => 'Trust',
            'society' => 'Society / NGO',
            'huf' => 'HUF (Hindu Undivided Family)',
        ];
    }
}

if (!function_exists('getKycDocLabels')) {
    function getKycDocLabels(): array
    {
        return [
            'aadhaar' => 'Aadhaar Card',
            'pan' => 'PAN Card',
            'gst' => 'GST Registration Certificate',
            'bank_proof' => 'Bank Proof / Cancelled Cheque',
            'photo' => 'Merchant Photo / Selfie',
            'merchant_agreement' => 'Merchant Agreement (Signed)',
            'business_proof' => 'Business Proof (Shop Act / Utility Bill)',
            'partnership_deed' => 'Partnership Deed',
            'llp_certificate' => 'LLP Incorporation Certificate',
            'llp_agreement' => 'LLP Agreement',
            'incorporation_certificate' => 'Certificate of Incorporation (COI)',
            'moa_aoa' => 'MOA & AOA',
            'board_resolution' => 'Board Resolution / Authorization Letter',
            'opc_certificate' => 'OPC Incorporation Certificate',
            'trust_deed' => 'Trust Deed',
            'society_registration' => 'Society Registration Certificate',
            'huf_deed' => 'HUF Deed / Declaration',
            'iec' => 'IEC (Import Export Code)',
            'udyam' => 'Udyam Registration Certificate',
            'video_kyc' => 'Video KYC Recording',
            'shop_signboard' => 'Shop Signboard Photo',
            'shop_outside' => 'Outside Shop Photo',
            'shop_inside_1' => 'Inside Shop Photo 1',
            'shop_inside_2' => 'Inside Shop Photo 2',
        ];
    }
}

/** Map legacy / alternate entity keys to the canonical KYC entity list. */
if (!function_exists('normalizeKycEntityType')) {
    function normalizeKycEntityType(?string $entityType): string
    {
        $raw = strtolower(trim((string)$entityType));
        $aliases = [
            'freelancer' => 'individual',
            'sole_proprietor' => 'sole_proprietorship',
            'proprietor' => 'sole_proprietorship',
            'proprietorship' => 'sole_proprietorship',
            'pvt_ltd' => 'private_limited',
            'pvt ltd' => 'private_limited',
            'private limited' => 'private_limited',
            'public limited' => 'public_limited',
            'one_person_company' => 'opc',
        ];
        $canonical = $aliases[$raw] ?? $raw;
        $types = getBusinessEntityTypes();
        return isset($types[$canonical]) ? $canonical : 'sole_proprietorship';
    }
}

/** Legacy uploaded doc_type values → canonical requirement keys. */
if (!function_exists('canonicalizeKycDocType')) {
    function canonicalizeKycDocType(string $docType): string
    {
        $aliases = [
            'pan_card' => 'pan',
            'aadhaar_card' => 'aadhaar',
            'aadhar' => 'aadhaar',
            'gst_certificate' => 'gst',
            'gstin' => 'gst',
            'bank' => 'bank_proof',
            'cancelled_cheque' => 'bank_proof',
            'selfie' => 'photo',
            'merchant_photo' => 'photo',
            'coi' => 'incorporation_certificate',
            'moa' => 'moa_aoa',
            'aoa' => 'moa_aoa',
        ];
        $key = strtolower(trim($docType));
        return $aliases[$key] ?? $key;
    }
}

/**
 * Only documents needed for that entity — never dump the full catalog.
 * Signed merchant agreement is collected on merchant_agreement.php (separate live gate).
 */
if (!function_exists('getKycRequirements')) {
    function getKycRequirements(string $entityType): array
    {
        $entityType = normalizeKycEntityType($entityType);
        $map = [
            // Individual: identity + bank + photo only (no GST / CIN / deed)
            'individual' => ['pan', 'aadhaar', 'bank_proof', 'photo'],
            // Proprietorship: add GST
            'sole_proprietorship' => ['pan', 'aadhaar', 'bank_proof', 'gst', 'photo'],
            // Partnership: GST + partnership deed
            'partnership' => ['pan', 'aadhaar', 'bank_proof', 'gst', 'photo', 'partnership_deed'],
            'llp' => ['pan', 'aadhaar', 'bank_proof', 'gst', 'photo', 'llp_certificate', 'llp_agreement'],
            'private_limited' => ['pan', 'gst', 'bank_proof', 'photo', 'incorporation_certificate', 'moa_aoa', 'board_resolution'],
            'opc' => ['pan', 'aadhaar', 'gst', 'bank_proof', 'photo', 'opc_certificate', 'incorporation_certificate'],
            'public_limited' => ['pan', 'gst', 'bank_proof', 'photo', 'incorporation_certificate', 'moa_aoa', 'board_resolution'],
            'trust' => ['pan', 'aadhaar', 'bank_proof', 'gst', 'photo', 'trust_deed'],
            'society' => ['pan', 'aadhaar', 'bank_proof', 'gst', 'photo', 'society_registration'],
            'huf' => ['pan', 'aadhaar', 'bank_proof', 'photo', 'huf_deed'],
        ];
        return $map[$entityType] ?? $map['sole_proprietorship'];
    }
}

/** True when every required doc type is covered by uploaded/approved types (alias-aware). */
if (!function_exists('kycDocsSatisfyRequirements')) {
    function kycDocsSatisfyRequirements(array $required, array $presentDocs): bool
    {
        $canonicalPresent = [];
        foreach ($presentDocs as $doc) {
            $canonicalPresent[canonicalizeKycDocType((string)$doc)] = true;
        }
        foreach ($required as $need) {
            if (empty($canonicalPresent[canonicalizeKycDocType((string)$need)])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('entityRequiresKycDoc')) {
    function entityRequiresKycDoc(string $entityType, string $docType): bool
    {
        return in_array($docType, getKycRequirements($entityType), true);
    }
}

/** Profile fields that should appear for this entity (GSTIN / CIN). */
if (!function_exists('entityProfileTaxFields')) {
    function entityProfileTaxFields(string $entityType): array
    {
        $docs = getKycRequirements($entityType);
        $fields = ['pan' => true];
        $fields['gst'] = in_array('gst', $docs, true);
        $fields['cin'] = in_array('incorporation_certificate', $docs, true)
            || in_array('llp_certificate', $docs, true)
            || in_array('opc_certificate', $docs, true);
        return $fields;
    }
}

if (!function_exists('getMerchantKycPrefills')) {
    function getMerchantKycPrefills(array $merchant): array
    {
        return [
            'pan' => strtoupper(trim((string)($merchant['pan_number'] ?? ''))),
            'gst' => strtoupper(trim((string)($merchant['gstin'] ?? ''))),
            'aadhaar' => preg_replace('/\D/', '', (string)($merchant['aadhaar_number'] ?? '')),
            'cin' => strtoupper(trim((string)($merchant['cin_llpin'] ?? ''))),
            'udyam' => strtoupper(trim((string)($merchant['udyam_number'] ?? ''))),
            'iec' => strtoupper(trim((string)($merchant['iec_number'] ?? ''))),
        ];
    }
}

if (!function_exists('entityTypeLabel')) {
    function entityTypeLabel(?string $type): string
    {
        $types = getBusinessEntityTypes();
        $canonical = normalizeKycEntityType($type);
        return $types[$canonical] ?? ucfirst(str_replace('_', ' ', $type ?? '—'));
    }
}

if (!function_exists('kycStepOneHint')) {
    function kycStepOneHint(array $verifyFields): string
    {
        if ($verifyFields === []) {
            return 'Confirm your business profile';
        }
        return implode(', ', array_values($verifyFields));
    }
}
