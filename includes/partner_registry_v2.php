<?php
declare(strict_types=1);

/**
 * Partner Registry v2 — Global Control Room (upgrade-first on gateway_registry).
 * Online payment collection partners only — no PPI / wallet product types.
 */

/** @return list<string> */
function partnerRegistryV2PartnerTypes(): array
{
    return ['pg', 'bnpl', 'other_online'];
}

/** @return list<string> */
function partnerRegistryV2ContractModes(): array
{
    return ['platform', 'linked_existing', 'hybrid'];
}

/** @return list<string> */
function partnerRegistryV2CredentialStatuses(): array
{
    return ['missing', 'invalid', 'valid'];
}

/** Legacy partner-compliance codes (pre–Phase 1b) — remapped to partner_compliance_docs_json. */
function partnerRegistryV2LegacyComplianceCodes(): array
{
    return [
        'MERCHANT_AGREEMENT', 'PG_MSA', 'KYC_POLICY', 'REFUND_POLICY',
        'WEBHOOK_SPEC', 'API_DOCS', 'PCI_AOC', 'SOC2_REPORT',
    ];
}

/**
 * Merchant KYC / onboarding doc codes for progressive coverage (Phase 3 engine).
 * Aligns with getKycDocLabels() + canonicalizeKycDocType().
 *
 * @return array<string,string>
 */
function partnerRegistryV2MerchantDocPackCatalog(): array
{
    if (!function_exists('getKycDocLabels')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    $labels = getKycDocLabels();
    $codes = [
        'aadhaar', 'pan', 'gst', 'bank_proof', 'udyam', 'letterhead',
        'photo', 'video_kyc', 'business_proof',
        'partnership_deed', 'llp_certificate', 'incorporation_certificate',
        'trust_deed', 'iec',
    ];
    $out = [];
    foreach ($codes as $code) {
        $out[$code] = $labels[$code] ?? ucwords(str_replace('_', ' ', $code));
    }
    return $out;
}

/** @return array<string,string> */
function partnerRegistryV2ComplianceDocCatalog(): array
{
    return [
        'PG_MSA' => 'PG master service agreement',
        'PCI_AOC' => 'PCI AOC (if applicable)',
        'SOC2_REPORT' => 'SOC2 / security report',
        'API_DOCS' => 'API integration docs',
        'WEBHOOK_SPEC' => 'Webhook specification',
    ];
}

/** @deprecated Use partnerRegistryV2MerchantDocPackCatalog() */
function partnerRegistryV2DocPackCatalog(): array
{
    return partnerRegistryV2MerchantDocPackCatalog();
}

/** @param list<mixed> $raw @return list<string> */
function partnerRegistryV2FilterMerchantDocCodes(array $raw): array
{
    if (!function_exists('canonicalizeKycDocType')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    $allowed = array_keys(partnerRegistryV2MerchantDocPackCatalog());
    $out = [];
    foreach ($raw as $code) {
        $code = canonicalizeKycDocType(strtolower(trim((string)$code)));
        if ($code !== '' && in_array($code, $allowed, true)) {
            $out[] = $code;
        }
    }
    return array_values(array_unique($out));
}

/** @param list<mixed> $raw @return list<string> */
function partnerRegistryV2FilterOptionalMerchantDocCodes(array $raw, array $required = []): array
{
    return array_values(array_diff(partnerRegistryV2FilterMerchantDocCodes($raw), $required));
}

/** @param list<mixed> $raw @return list<string> */
function partnerRegistryV2FilterComplianceDocCodes(array $raw): array
{
    $allowed = array_keys(partnerRegistryV2ComplianceDocCatalog());
    $out = [];
    foreach ($raw as $code) {
        $code = strtoupper(trim((string)$code));
        if ($code !== '' && in_array($code, $allowed, true)) {
            $out[] = $code;
        }
    }
    return array_values(array_unique($out));
}

/** @return list<string> */
function partnerRegistryV2NormalizeCurrencies(mixed $raw): array
{
    if (is_string($raw)) {
        if (strlen($raw) > 500) {
            throw new InvalidArgumentException('Currency list must be 500 characters or fewer.');
        }
        $raw = preg_split('/[\s,;]+/', strtoupper(trim($raw))) ?: [];
    }
    if (!is_array($raw)) {
        throw new InvalidArgumentException('Currencies must be comma-separated ISO 4217-format alpha-3 codes.');
    }
    if (count($raw) > 50) {
        throw new InvalidArgumentException('Currency list cannot contain more than 50 entries.');
    }
    $currencies = [];
    foreach ($raw as $currency) {
        $currency = strtoupper(trim((string)$currency));
        if ($currency === '') {
            continue;
        }
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException('Each currency must use the three-letter ISO 4217 code format.');
        }
        $currencies[] = $currency;
    }
    return array_values(array_unique($currencies));
}

function partnerRegistryV2NormalizeWebhookPath(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 255 || preg_match('/[\x00-\x20\x7f\\\\]/', $raw)) {
        throw new InvalidArgumentException('UniWeb webhook path is required and must be a valid local endpoint.');
    }

    $parts = parse_url($raw);
    if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('UniWeb webhook endpoint must not contain credentials, query parameters, or a fragment.');
    }

    if (isset($parts['host'])) {
        $appParts = parse_url(defined('APP_URL') ? APP_URL : '');
        if (!is_array($appParts)
            || strtolower((string)($parts['scheme'] ?? '')) !== strtolower((string)($appParts['scheme'] ?? ''))
            || strtolower((string)$parts['host']) !== strtolower((string)($appParts['host'] ?? ''))
            || (int)($parts['port'] ?? (strtolower((string)($parts['scheme'] ?? '')) === 'https' ? 443 : 80))
                !== (int)($appParts['port'] ?? (strtolower((string)($appParts['scheme'] ?? '')) === 'https' ? 443 : 80))) {
            throw new InvalidArgumentException('Webhook URL must use the configured UniWeb origin.');
        }
        $raw = (string)($parts['path'] ?? '/');
    }

    if (!str_starts_with($raw, '/') || str_starts_with($raw, '//')) {
        throw new InvalidArgumentException('UniWeb webhook endpoint must be a root-relative path or a URL on the configured UniWeb origin.');
    }
    foreach (explode('/', rawurldecode($raw)) as $segment) {
        if ($segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('UniWeb webhook path cannot contain dot segments.');
        }
    }
    return $raw;
}

function partnerRegistryV2NormalizePolicyUrl(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    $parts = parse_url($raw);
    if (strlen($raw) > 500
        || !filter_var($raw, FILTER_VALIDATE_URL)
        || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new InvalidArgumentException('Partner policy links must be valid HTTPS URLs without embedded credentials.');
    }
    return $raw;
}

function partnerRegistryV2NormalizeFeePercent(mixed $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '0.0000';
    }
    if (!preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $raw, $matches)) {
        throw new InvalidArgumentException('Default platform fee percent must be between 0 and 100 with up to 4 decimal places.');
    }
    $whole = (int)$matches[1];
    $fraction = str_pad($matches[2] ?? '', 4, '0');
    if ($whole > 100 || ($whole === 100 && trim($fraction, '0') !== '')) {
        throw new InvalidArgumentException('Default platform fee percent must be between 0 and 100 with up to 4 decimal places.');
    }
    return $whole . '.' . $fraction;
}

function partnerRegistryV2NormalizeFeeFlat(mixed $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '0.00';
    }
    if (!preg_match('/^(\d{1,10})(?:\.(\d{1,2}))?$/', $raw, $matches)) {
        throw new InvalidArgumentException('Default platform flat fee must be non-negative with up to 2 decimal places.');
    }
    $whole = ltrim($matches[1], '0');
    $fraction = str_pad($matches[2] ?? '', 2, '0');
    return ($whole !== '' ? $whole : '0') . '.' . $fraction;
}

function partnerRegistryV2NormalizeSuccessWeight(mixed $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '0.0000';
    }
    if (!preg_match('/^(\d{1,3})(?:\.(\d{1,4}))?$/', $raw, $matches)) {
        throw new InvalidArgumentException('Success weight must be between 0 and 100 with up to 4 decimal places.');
    }
    $whole = (int)$matches[1];
    $fraction = str_pad($matches[2] ?? '', 4, '0');
    if ($whole > 100 || ($whole === 100 && trim($fraction, '0') !== '')) {
        throw new InvalidArgumentException('Success weight must be between 0 and 100 with up to 4 decimal places.');
    }
    return $whole . '.' . $fraction;
}

function partnerRegistryV2NormalizeRoutingPriority(mixed $raw): int
{
    $raw = trim((string)$raw);
    if (!preg_match('/^\d{1,3}$/', $raw) || (int)$raw < 1 || (int)$raw > 999) {
        throw new InvalidArgumentException('Routing priority must be a whole number from 1 to 999.');
    }
    return (int)$raw;
}

/**
 * Required workbook fields for configuring a partner. Optional profile data is
 * deliberately not included here.
 *
 * @return list<string>
 */
function partnerRegistryV2RequiredProfileErrors(array $input): array
{
    $errors = [];
    try {
        if (partnerRegistryV2NormalizeConnectorId((string)($input['connector_id'] ?? '')) === null) {
            $errors[] = 'Connector ID is required.';
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    try {
        partnerRegistryV2NormalizeWebhookPath((string)($input['webhook_url'] ?? ''));
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    try {
        if (partnerRegistryV2NormalizeCountries($input['countries'] ?? []) === []) {
            $errors[] = 'Select at least one country.';
        }
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    $docPack = $input['doc_pack'] ?? [];
    if (!is_array($docPack) || partnerRegistryV2FilterMerchantDocCodes($docPack) === []) {
        $errors[] = 'Select at least one required merchant KYC document.';
    }
    return $errors;
}

function partnerRegistryV2ProfileIsComplete(array $row): bool
{
    $partnerCode = strtolower(trim((string)($row['gateway_key'] ?? '')));
    $displayName = trim((string)($row['gateway_name'] ?? ''));
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $partnerCode)
        || $displayName === ''
        || mb_strlen($displayName) > 80) {
        return false;
    }
    $errors = partnerRegistryV2RequiredProfileErrors([
        'connector_id' => $row['connector_id'] ?? '',
        'webhook_url' => $row['webhook_url'] ?? '',
        'countries' => json_decode((string)($row['countries_json'] ?? ''), true) ?? [],
        'doc_pack' => json_decode((string)($row['doc_pack_json'] ?? ''), true) ?? [],
    ]);
    if ($errors !== []) {
        return false;
    }
    try {
        partnerRegistryV2NormalizePartnerType((string)($row['partner_type'] ?? ''));
        partnerRegistryV2NormalizeContractMode((string)($row['contract_mode'] ?? ''));
        partnerRegistryV2NormalizeFeePercent($row['platform_fee_pct_default'] ?? '0');
        partnerRegistryV2NormalizeFeeFlat($row['platform_fee_flat_default'] ?? '0');
        partnerRegistryV2NormalizeSuccessWeight($row['weight_success'] ?? '0');
        partnerRegistryV2NormalizeRoutingPriority($row['routing_priority'] ?? 50);
        partnerRegistryV2NormalizeApiBaseUrl((string)($row['api_base_url'] ?? ''));
        partnerRegistryV2NormalizeLogoUrl((string)($row['logo_url'] ?? ''));
        partnerRegistryV2NormalizeCurrencies(json_decode((string)($row['currencies_json'] ?? ''), true) ?? []);
        $policyUrls = json_decode((string)($row['policy_urls_json'] ?? ''), true) ?? [];
        foreach (['terms', 'privacy', 'refund', 'support'] as $policyKey) {
            partnerRegistryV2NormalizePolicyUrl((string)($policyUrls[$policyKey] ?? ''));
        }
    } catch (InvalidArgumentException $e) {
        return false;
    }
    $supportEmail = trim((string)($row['support_email'] ?? ''));
    if ($supportEmail !== '' && (strlen($supportEmail) > 190 || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL))) {
        return false;
    }
    $settleNote = (string)($row['settle_note'] ?? '');
    if (mb_strlen($settleNote) > 500) {
        return false;
    }
    $supportPhone = trim((string)($row['support_phone'] ?? ''));
    if ($supportPhone !== '') {
        $phoneDigits = preg_replace('/\D/', '', $supportPhone) ?? '';
        if (strlen($supportPhone) > 40
            || !preg_match('/^\+?[0-9().\s-]+$/', $supportPhone)
            || strlen($phoneDigits) < 7
            || strlen($phoneDigits) > 15) {
            return false;
        }
    }
    return true;
}

function partnerRegistryV2NormalizeConnectorId(string $raw): ?string
{
    $raw = strtolower(trim($raw));
    if ($raw === '') {
        return null;
    }
    if (strlen($raw) > 80 || !preg_match('/^[a-z0-9_]{2,80}$/', $raw)) {
        throw new InvalidArgumentException('Connector ID must use 2–80 lowercase letters, numbers, or underscores.');
    }
    return $raw;
}

function partnerRegistryV2NormalizeLogoUrl(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $parts = parse_url($raw);
    if (strlen($raw) > 500
        || !filter_var($raw, FILTER_VALIDATE_URL)
        || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])) {
        throw new InvalidArgumentException('Partner logo must use a valid HTTPS URL without embedded credentials.');
    }
    return $raw;
}

function migratePartnerRegistryDocPackSemantics(): void
{
    static $done = false;
    if ($done || !partnerRegistryV2HasColumns()) {
        return;
    }
    $done = true;
    $legacy = array_flip(partnerRegistryV2LegacyComplianceCodes());
    try {
        $db = getDB();
        $rows = $db->query("SELECT id, doc_pack_json, partner_compliance_docs_json FROM gateway_registry WHERE doc_pack_json IS NOT NULL AND doc_pack_json != ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $decoded = json_decode((string)$row['doc_pack_json'], true);
            if (!is_array($decoded)) {
                continue;
            }
            $merchant = [];
            $compliance = [];
            $existingCompliance = [];
            if (!empty($row['partner_compliance_docs_json'])) {
                $ec = json_decode((string)$row['partner_compliance_docs_json'], true);
                if (is_array($ec)) {
                    $existingCompliance = partnerRegistryV2FilterComplianceDocCodes($ec);
                }
            }
            foreach ($decoded as $code) {
                $code = strtoupper(trim((string)$code));
                if ($code === '') {
                    continue;
                }
                if (isset($legacy[$code])) {
                    $compliance[] = $code;
                } else {
                    $merchant[] = strtolower($code);
                }
            }
            $merchant = partnerRegistryV2FilterMerchantDocCodes($merchant);
            $compliance = array_values(array_unique(array_merge($existingCompliance, partnerRegistryV2FilterComplianceDocCodes($compliance))));
            $db->prepare('UPDATE gateway_registry SET doc_pack_json=?, partner_compliance_docs_json=? WHERE id=?')->execute([
                $merchant === [] ? null : json_encode($merchant, JSON_UNESCAPED_UNICODE),
                $compliance === [] ? null : json_encode($compliance, JSON_UNESCAPED_UNICODE),
                (int)$row['id'],
            ]);
        }
    } catch (Throwable $e) {
        error_log('migratePartnerRegistryDocPackSemantics: ' . $e->getMessage());
    }
}

function ensurePartnerRegistryV2Columns(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('ensurePaymentMethodsTable')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    ensurePaymentMethodsTable();
    $db = getDB();
    $alters = [
        "ALTER TABLE gateway_registry ADD COLUMN partner_type ENUM('pg','bnpl','other_online') NOT NULL DEFAULT 'pg'",
        "ALTER TABLE gateway_registry MODIFY COLUMN partner_type ENUM('pg','bnpl','other_online') NOT NULL DEFAULT 'pg'",
        "ALTER TABLE gateway_registry ADD COLUMN contract_mode ENUM('platform','linked_existing','hybrid') NOT NULL DEFAULT 'platform'",
        'ALTER TABLE gateway_registry ADD COLUMN allows_existing_merchant_link TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_collect TINYINT(1) NOT NULL DEFAULT 1',
        'ALTER TABLE gateway_registry ADD COLUMN cap_upi TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_card TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_netbanking TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_refund TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_pay_later TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_qr TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_payout TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_kyc_forward_api TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN doc_pack_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN optional_doc_pack_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN partner_compliance_docs_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN policy_urls_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN routing_priority INT NOT NULL DEFAULT 50',
        'ALTER TABLE gateway_registry ADD COLUMN circuit_breaker_on TINYINT(1) NOT NULL DEFAULT 1',
        'ALTER TABLE gateway_registry ADD COLUMN connector_notes VARCHAR(255) DEFAULT NULL',
        "ALTER TABLE gateway_registry ADD COLUMN credential_test_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing'",
        "ALTER TABLE gateway_registry ADD COLUMN credential_live_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing'",
        'ALTER TABLE gateway_registry ADD COLUMN display_description VARCHAR(500) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN channel_capabilities_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN api_base_url VARCHAR(500) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN connector_id VARCHAR(80) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN logo_url VARCHAR(500) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN countries_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN currencies_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN support_email VARCHAR(190) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN support_phone VARCHAR(40) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN platform_fee_pct_default DECIMAL(8,4) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN platform_fee_flat_default DECIMAL(12,2) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN settle_note VARCHAR(500) DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN updated_by INT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN weight_success DECIMAL(8,4) NOT NULL DEFAULT 0',
        "ALTER TABLE gateway_registry ADD COLUMN integration_status ENUM('legacy_unverified','draft','sandbox','live') NOT NULL DEFAULT 'legacy_unverified'",
        "ALTER TABLE gateway_registry MODIFY COLUMN integration_status ENUM('legacy_unverified','draft','sandbox','live','paused','retired') NOT NULL DEFAULT 'legacy_unverified'",
        "ALTER TABLE gateway_registry ADD COLUMN lifecycle_resume_status ENUM('legacy_unverified','draft','sandbox','live') DEFAULT NULL",
        'ALTER TABLE gateway_registry ADD COLUMN lifecycle_updated_at TIMESTAMP NULL DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN lifecycle_updated_by INT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN retired_at TIMESTAMP NULL DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN retired_by VARCHAR(120) DEFAULT NULL',
    ];
    foreach ($alters as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            /* column may exist */
        }
    }
    seedCollectPartnerAlreadyLiveLinkDefault();
    if (partnerRegistryV2HasColumns()) {
        migratePartnerRegistryDocPackSemantics();
    }
}

function partnerRegistryV2HasColumns(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    ensurePartnerRegistryV2Columns();
    try {
        $has = (bool)getDB()->query("SHOW COLUMNS FROM gateway_registry LIKE 'partner_type'")->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function partnerRegistryV2NormalizePartnerType(string $raw): string
{
    $raw = strtolower(trim($raw));
    if (str_contains($raw, 'ppi') || str_contains($raw, 'wallet') || str_contains($raw, 'offline')) {
        throw new InvalidArgumentException('Partner type not allowed. Use Payment Gateway, BNPL partner, or other online collect only.');
    }
    if (!in_array($raw, partnerRegistryV2PartnerTypes(), true)) {
        throw new InvalidArgumentException('Invalid partner type.');
    }
    return $raw;
}

function partnerRegistryV2NormalizeContractMode(string $raw): string
{
    $raw = strtolower(trim($raw));
    if (!in_array($raw, partnerRegistryV2ContractModes(), true)) {
        throw new InvalidArgumentException('Invalid partner contract mode.');
    }
    return $raw;
}

/** @return list<string> */
function partnerRegistryV2NormalizeCountries(mixed $raw): array
{
    if (is_string($raw)) {
        if (strlen($raw) > 500) {
            throw new InvalidArgumentException('Country list must be 500 characters or fewer.');
        }
        $raw = preg_split('/[\s,;]+/', strtoupper(trim($raw))) ?: [];
    }
    if (!is_array($raw)) {
        throw new InvalidArgumentException('Countries must be comma-separated ISO 3166-1 alpha-2 format codes.');
    }
    if (count($raw) > 50) {
        throw new InvalidArgumentException('Country list cannot contain more than 50 entries.');
    }
    $countries = [];
    foreach ($raw as $country) {
        $country = strtoupper(trim((string)$country));
        if ($country === '') {
            continue;
        }
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new InvalidArgumentException('Each country must use the two-letter ISO country code format.');
        }
        $countries[] = $country;
    }
    return array_values(array_unique($countries));
}

function partnerRegistryV2NormalizeApiBaseUrl(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $parts = parse_url($raw);
    if (strlen($raw) > 500
        || !filter_var($raw, FILTER_VALIDATE_URL)
        || !is_array($parts)
        || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new InvalidArgumentException('API base URL must be an HTTPS URL without credentials, query, or fragment.');
    }
    return rtrim($raw, '/');
}

/** @return list<string> */
function partnerRegistryLifecycleStatuses(): array
{
    return ['legacy_unverified', 'draft', 'sandbox', 'live', 'paused', 'retired'];
}

function partnerRegistryLifecycleStatus(array $row): string
{
    if (partnerRegistryRowIsRetired($row)) {
        return 'retired';
    }
    $status = strtolower(trim((string)($row['integration_status'] ?? 'legacy_unverified')));
    return in_array($status, partnerRegistryLifecycleStatuses(), true) ? $status : 'legacy_unverified';
}

function partnerRegistryLifecycleAllowsMerchantEnablement(string $status): bool
{
    return in_array(strtolower(trim($status)), ['legacy_unverified', 'live'], true);
}

/**
 * @param array{adapter_wired?:bool,test_credentials_valid?:bool,live_credentials_valid?:bool,sandbox_certified?:bool,contract_approved?:bool,live_keys_validated?:bool,resume_status?:string} $checks
 * @return array{allowed:bool,reason:string}
 */
function partnerRegistryLifecycleTransitionGate(string $current, string $target, array $checks): array
{
    $current = strtolower(trim($current));
    $target = strtolower(trim($target));
    if (!in_array($current, partnerRegistryLifecycleStatuses(), true)) {
        $current = 'legacy_unverified';
    }
    if ($current === 'retired') {
        return ['allowed' => false, 'reason' => 'Retired partners cannot change lifecycle.'];
    }
    if ($target === 'retired') {
        return ['allowed' => false, 'reason' => 'Use the guarded Retire action to retire a partner.'];
    }
    if ($target === 'draft' && in_array($current, ['legacy_unverified', 'sandbox', 'live'], true)) {
        return ['allowed' => true, 'reason' => ''];
    }
    if ($target === 'paused' && $current !== 'paused') {
        return ['allowed' => true, 'reason' => ''];
    }
    if (in_array($target, ['sandbox', 'live'], true)
        && array_key_exists('profile_complete', $checks)
        && empty($checks['profile_complete'])) {
        return ['allowed' => false, 'reason' => 'Complete the required partner registry profile before entering Sandbox or LIVE readiness.'];
    }
    if ($current === 'paused' && $target === ($checks['resume_status'] ?? '')) {
        if ($target === 'sandbox' && (empty($checks['adapter_wired']) || empty($checks['test_credentials_valid']))) {
            return ['allowed' => false, 'reason' => 'A wired connector and passing Test credentials are required to resume Sandbox.'];
        }
        if ($target === 'live' && (empty($checks['adapter_wired'])
            || empty($checks['test_credentials_valid'])
            || empty($checks['live_credentials_valid']))) {
            return ['allowed' => false, 'reason' => 'Wired connector and currently verified Test and Live credentials are required to resume LIVE readiness.'];
        }
        if ($target === 'live' && (empty($checks['sandbox_certified'])
            || empty($checks['contract_approved'])
            || empty($checks['live_keys_validated']))) {
            return ['allowed' => false, 'reason' => 'Reconfirm sandbox certification, commercial/legal approval, and live-key validation before resuming LIVE readiness.'];
        }
        return ['allowed' => true, 'reason' => ''];
    }
    if ($target === 'sandbox' && in_array($current, ['draft', 'legacy_unverified'], true)) {
        if (empty($checks['adapter_wired'])) {
            return ['allowed' => false, 'reason' => 'A wired connector is required before sandbox testing.'];
        }
        if (empty($checks['test_credentials_valid'])) {
            return ['allowed' => false, 'reason' => 'Test credentials must pass Test Connection before entering sandbox.'];
        }
        return ['allowed' => true, 'reason' => ''];
    }
    if ($target === 'live' && $current === 'sandbox') {
        if (empty($checks['adapter_wired'])
            || empty($checks['test_credentials_valid'])
            || empty($checks['live_credentials_valid'])) {
            return ['allowed' => false, 'reason' => 'Wired connector, verified sandbox credentials, and verified live credentials are required.'];
        }
        if (empty($checks['sandbox_certified']) || empty($checks['contract_approved']) || empty($checks['live_keys_validated'])) {
            return ['allowed' => false, 'reason' => 'Confirm sandbox certification, commercial/legal approval, and live-key validation.'];
        }
        return ['allowed' => true, 'reason' => ''];
    }
    return ['allowed' => false, 'reason' => 'This partner lifecycle transition is not allowed.'];
}

function partnerRegistryLifecycleStatusBadge(array $row): string
{
    $status = partnerRegistryLifecycleStatus($row);
    $labels = [
        'legacy_unverified' => ['Legacy · not verified', 'bg-slate-700/50 text-slate-300'],
        'draft' => ['Draft', 'bg-gray-700/50 text-gray-300'],
        'sandbox' => ['Sandbox', 'bg-sky-500/15 text-sky-300'],
        'live' => ['LIVE readiness approved', 'bg-emerald-500/15 text-emerald-300'],
        'paused' => ['Paused', 'bg-amber-500/15 text-amber-300'],
        'retired' => ['Retired', 'bg-slate-600/40 text-slate-300'],
    ];
    [$label, $classes] = $labels[$status];
    return '<span class="text-[10px] px-2 py-0.5 rounded border border-gray-700 ' . $classes . '" title="Integration lifecycle; routing activation is a separate control">' . e($label) . '</span>';
}

/**
 * Advance the connector lifecycle after checking current credentials and explicit release attestations.
 *
 * @param array<string,mixed> $confirmations
 * @return array{ok:bool,message:string}
 */
function advancePartnerRegistryLifecycle(int $gatewayId, string $target, array $confirmations, ?int $adminId = null): array
{
    if ($gatewayId < 1) {
        return ['ok' => false, 'message' => 'Invalid partner.'];
    }
    $target = strtolower(trim($target));
    if ($target === 'draft' && empty($confirmations['confirm_demote'])) {
        return ['ok' => false, 'message' => 'Confirm that lifecycle demotion removes readiness without changing routing.'];
    }
    $stmt = getDB()->prepare("SELECT * FROM gateway_registry WHERE id=? AND registry_kind='partner' LIMIT 1");
    $stmt->execute([$gatewayId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'message' => 'Partner not found.'];
    }
    if (partnerRegistryRowIsRetired($row)) {
        return ['ok' => false, 'message' => 'Retired partners cannot advance lifecycle.'];
    }

    $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
    $checks = [
        'adapter_wired' => partnerAdapterIsWired($key, $row),
        'profile_complete' => partnerRegistryV2ProfileIsComplete($row),
        'test_credentials_valid' => partnerCredentialVaultStatus($key, 'test') === 'valid',
        'live_credentials_valid' => partnerCredentialVaultStatus($key, 'live') === 'valid',
        'resume_status' => strtolower(trim((string)($row['lifecycle_resume_status'] ?? ''))),
        'sandbox_certified' => !empty($confirmations['sandbox_certified']),
        'contract_approved' => !empty($confirmations['contract_approved']),
        'live_keys_validated' => !empty($confirmations['live_keys_validated']),
    ];
    $current = partnerRegistryLifecycleStatus($row);
    if ($target === 'resume') {
        $target = $checks['resume_status'];
    }
    $gate = partnerRegistryLifecycleTransitionGate($current, $target, $checks);
    if (empty($gate['allowed'])) {
        return ['ok' => false, 'message' => (string)$gate['reason']];
    }

    $resumeStatus = $target === 'paused' ? $current : null;
    $detail = json_encode([
        'from' => $current,
        'to' => $target,
        'attestations' => [
            'sandbox_certified' => $checks['sandbox_certified'],
            'contract_approved' => $checks['contract_approved'],
            'live_keys_validated' => $checks['live_keys_validated'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    if (!function_exists('recordImmutableAudit')) {
        return ['ok' => false, 'message' => 'Required immutable audit logging is unavailable. No lifecycle change was made.'];
    }

    $db = getDB();
    $ownsTransaction = !$db->inTransaction();
    $savepointCreated = false;
    $concurrentChange = false;
    try {
        if ($ownsTransaction) {
            $db->beginTransaction();
        } else {
            $db->exec('SAVEPOINT partner_lifecycle_advance');
            $savepointCreated = true;
        }

        $update = $db->prepare('UPDATE gateway_registry SET integration_status=?, lifecycle_resume_status=?, lifecycle_updated_at=NOW(), lifecycle_updated_by=? WHERE id=? AND integration_status=?');
        $update->execute([$target, $resumeStatus, $adminId, $gatewayId, $current]);
        if ($update->rowCount() !== 1) {
            $concurrentChange = true;
            throw new RuntimeException('Partner lifecycle changed during this request.');
        }

        recordImmutableAudit('partner_lifecycle_advanced', null, 'gateway', $key, (string)$detail, null, null, true);
        if ($ownsTransaction) {
            $db->commit();
        } else {
            $db->exec('RELEASE SAVEPOINT partner_lifecycle_advance');
            $savepointCreated = false;
        }
    } catch (Throwable $e) {
        try {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            } elseif ($savepointCreated && $db->inTransaction()) {
                $db->exec('ROLLBACK TO SAVEPOINT partner_lifecycle_advance');
                $db->exec('RELEASE SAVEPOINT partner_lifecycle_advance');
            }
        } catch (Throwable $rollbackError) {
            error_log('Partner lifecycle rollback failed for ' . $key . ': ' . $rollbackError->getMessage());
        }
        if (!$concurrentChange) {
            error_log('Partner lifecycle update/audit failed for ' . $key . ': ' . $e->getMessage());
        }
        return [
            'ok' => false,
            'message' => $concurrentChange
                ? 'Partner lifecycle changed during this request. Reload and try again.'
                : 'Could not safely save the lifecycle change and its audit record. No change was confirmed.',
        ];
    }

    $message = $target === 'paused'
        ? 'Partner paused. Existing routing was left unchanged.'
        : 'Partner integration lifecycle resumed at ' . strtoupper($target) . '.';
    return ['ok' => true, 'message' => $message];
}

/**
 * Cross-platform partner channels. Every surface reads this contract instead
 * of maintaining a separate partner allow-list.
 *
 * @return list<string>
 */
function partnerRegistryV2ChannelCatalog(): array
{
    return [
        'checkout_hosted',
        'status_webhook',
        'document_forward',
        'notifications',
        'support_forward',
        'dispute_forward',
        'refunds',
    ];
}

/** @return array<string,bool> */
function partnerRegistryV2ChannelCapabilities(array $row): array
{
    $defaults = [
        'checkout_hosted' => !empty($row['cap_collect']),
        'status_webhook' => trim((string)($row['webhook_url'] ?? '')) !== '',
        'document_forward' => !empty($row['cap_kyc_forward_api']),
        'notifications' => false,
        'support_forward' => false,
        'dispute_forward' => false,
        'refunds' => !empty($row['cap_refund']),
    ];
    $raw = json_decode((string)($row['channel_capabilities_json'] ?? ''), true);
    if (!is_array($raw)) {
        return $defaults;
    }
    foreach (partnerRegistryV2ChannelCatalog() as $channel) {
        if (array_key_exists($channel, $raw)) {
            $defaults[$channel] = (bool)$raw[$channel];
        }
    }
    return $defaults;
}

/** @return array<string,mixed> */
function partnerRegistryV2ProfileFromRow(array $row): array
{
    $docPack = [];
    if (!empty($row['doc_pack_json'])) {
        $decoded = json_decode((string)$row['doc_pack_json'], true);
        if (is_array($decoded)) {
            $docPack = partnerRegistryV2FilterMerchantDocCodes($decoded);
        }
    }
    $complianceDocs = [];
    if (!empty($row['partner_compliance_docs_json'])) {
        $decoded = json_decode((string)$row['partner_compliance_docs_json'], true);
        if (is_array($decoded)) {
            $complianceDocs = partnerRegistryV2FilterComplianceDocCodes($decoded);
        }
    }
    $policyUrls = [];
    if (!empty($row['policy_urls_json'])) {
        $decoded = json_decode((string)$row['policy_urls_json'], true);
        if (is_array($decoded)) {
            $policyUrls = $decoded;
        }
    }
    return [
        'partner_code' => (string)($row['gateway_key'] ?? ''),
        'display_name' => (string)($row['gateway_name'] ?? ''),
        'partner_type' => (string)($row['partner_type'] ?? 'pg'),
        'logo_url' => (string)($row['logo_url'] ?? ''),
        'connector_id' => (string)($row['connector_id'] ?? ''),
        'contract_mode' => (string)($row['contract_mode'] ?? 'platform'),
        'allows_existing_merchant_link' => !empty($row['allows_existing_merchant_link']),
        'api_base_url' => (string)($row['api_base_url'] ?? ''),
        'countries' => partnerRegistryV2NormalizeCountries(
            json_decode((string)($row['countries_json'] ?? ''), true) ?? []
        ),
        'currencies' => partnerRegistryV2NormalizeCurrencies(
            json_decode((string)($row['currencies_json'] ?? ''), true) ?? []
        ),
        'support_email' => (string)($row['support_email'] ?? ''),
        'support_phone' => (string)($row['support_phone'] ?? ''),
        'platform_fee_pct_default' => partnerRegistryV2NormalizeFeePercent($row['platform_fee_pct_default'] ?? '0'),
        'platform_fee_flat_default' => partnerRegistryV2NormalizeFeeFlat($row['platform_fee_flat_default'] ?? '0'),
        'weight_success' => partnerRegistryV2NormalizeSuccessWeight($row['weight_success'] ?? '0'),
        'settle_note' => (string)($row['settle_note'] ?? ''),
        'updated_by' => isset($row['updated_by']) ? (int)$row['updated_by'] : null,
        'connector_notes' => (string)($row['connector_notes'] ?? ''),
        'display_description' => (string)($row['display_description'] ?? ''),
        'capabilities' => [
            'collect' => !empty($row['cap_collect']),
            'upi' => !empty($row['cap_upi']),
            'card' => !empty($row['cap_card']),
            'netbanking' => !empty($row['cap_netbanking']),
            'refund' => !empty($row['cap_refund']),
            'pay_later' => !empty($row['cap_pay_later']),
            'qr' => !empty($row['cap_qr']),
            'payout' => !empty($row['cap_payout']),
            'kyc_forward_api' => !empty($row['cap_kyc_forward_api']),
        ],
        'doc_pack' => $docPack,
        'optional_doc_pack' => partnerRegistryV2FilterOptionalMerchantDocCodes(
            json_decode((string)($row['optional_doc_pack_json'] ?? ''), true) ?: [],
            $docPack
        ),
        'partner_compliance_docs' => $complianceDocs,
        'policy_urls' => [
            'terms' => (string)($policyUrls['terms'] ?? ''),
            'privacy' => (string)($policyUrls['privacy'] ?? ''),
            'refund' => (string)($policyUrls['refund'] ?? ''),
            'support' => (string)($policyUrls['support'] ?? ''),
        ],
        'routing_priority' => (int)($row['routing_priority'] ?? 50),
        'circuit_breaker_on' => !isset($row['circuit_breaker_on']) || (int)$row['circuit_breaker_on'] === 1,
        'credential_test_status' => (string)($row['credential_test_status'] ?? 'missing'),
        'credential_live_status' => (string)($row['credential_live_status'] ?? 'missing'),
        'webhook_url' => (string)($row['webhook_url'] ?? ''),
        'adapter_class' => (string)($row['adapter_class'] ?? ''),
        'channels' => partnerRegistryV2ChannelCapabilities($row),
    ];
}

function registryPartnerSupportsChannel(string $partnerKey, string $channel, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $channel = strtolower(trim($channel));
    if ($partnerKey === '' || !in_array($channel, partnerRegistryV2ChannelCatalog(), true)) {
        return false;
    }
    if ($gatewayRow === null) {
        if (!function_exists('getRegisteredGateways')) {
            require_once __DIR__ . '/payment_methods.php';
        }
        foreach (getRegisteredGateways(false) as $row) {
            if (strtolower(trim((string)($row['gateway_key'] ?? ''))) === $partnerKey) {
                $gatewayRow = $row;
                break;
            }
        }
    }
    if (!$gatewayRow) {
        return false;
    }
    $profile = partnerRegistryV2ProfileFromRow($gatewayRow);
    return !empty($profile['channels'][$channel]);
}

function partnerCredentialVaultStatus(string $partnerKey, string $env = 'test'): string
{
    ensurePartnerRegistryV2Columns();
    if (!function_exists('getPartnerCredentialStatus')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $cred = getPartnerCredentialStatus($partnerKey);
    $hasKeys = $env === 'live' ? !empty($cred['live']) : !empty($cred['test']);
    if (!$hasKeys) {
        return 'missing';
    }
    try {
        $col = $env === 'live' ? 'credential_live_status' : 'credential_test_status';
        $st = getDB()->prepare("SELECT {$col} FROM gateway_registry WHERE gateway_key=? LIMIT 1");
        $st->execute([$partnerKey]);
        $status = strtolower(trim((string)$st->fetchColumn()));
        if (in_array($status, partnerRegistryV2CredentialStatuses(), true) && $status !== 'missing') {
            return $status;
        }
    } catch (Throwable $e) {
        /* fall through */
    }
    return 'invalid';
}

function partnerCredentialVaultStatusBadge(string $status): string
{
    $status = strtolower(trim($status));
    $map = [
        'missing' => ['cls' => 'bg-gray-700/50 text-gray-400', 'label' => 'Keys Missing'],
        'invalid' => ['cls' => 'bg-amber-500/15 text-amber-300', 'label' => 'Keys Invalid'],
        'valid' => ['cls' => 'bg-emerald-500/15 text-emerald-300', 'label' => 'Keys Valid'],
    ];
    $row = $map[$status] ?? $map['missing'];
    return '<span class="text-[10px] px-2 py-0.5 rounded border border-gray-700 ' . $row['cls'] . '" title="Credential status — never shows secret values">' . e($row['label']) . '</span>';
}

/**
 * Built-in Partner Registry keys have PHP adapters. Custom partners are wired only when adapter_class is an existing file.
 */
function partnerAdapterIsWired(string $partnerKey, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '') {
        return false;
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    if (isset(getPartnerRegistry()[$partnerKey])) {
        return true;
    }
    $adapter = trim((string)($gatewayRow['adapter_class'] ?? ''));
    if ($adapter === '') {
        return false;
    }
    $root = dirname(__DIR__);
    $candidates = [];
    if (preg_match('/^[A-Za-z0-9_\\\\]+$/', $adapter) && !str_contains($adapter, '/') && !str_contains($adapter, '.php')) {
        return false;
    }
    $rel = ltrim(str_replace('\\', '/', $adapter), '/');
    $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!str_ends_with(strtolower($rel), '.php')) {
        $candidates[] = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel) . '.php';
    }
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return true;
        }
    }
    return false;
}

/**
 * @return array{allowed:bool,reason:string,warn_keys:bool}
 */
function partnerRegistryActivateGate(array $gatewayRow): array
{
    $key = strtolower(trim((string)($gatewayRow['gateway_key'] ?? '')));
    if (partnerRegistryLifecycleStatus($gatewayRow) === 'retired') {
        return [
            'allowed' => false,
            'reason' => 'Retired',
            'warn_keys' => true,
        ];
    }
    $lifecycleStatus = partnerRegistryLifecycleStatus($gatewayRow);
    if (in_array($lifecycleStatus, ['draft', 'sandbox', 'paused'], true)) {
        return [
            'allowed' => false,
            'reason' => 'Integration lifecycle is ' . strtoupper($lifecycleStatus) . ' — complete LIVE certification first',
            'warn_keys' => false,
        ];
    }
    if ($lifecycleStatus === 'live'
        && (partnerCredentialVaultStatus($key, 'test') !== 'valid' || partnerCredentialVaultStatus($key, 'live') !== 'valid')) {
        return [
            'allowed' => false,
            'reason' => 'LIVE readiness is approved, but current Test and Live credentials must both pass Test Connection before routing can be activated.',
            'warn_keys' => false,
        ];
    }
    $wired = partnerAdapterIsWired($key, $gatewayRow);
    if (!function_exists('getPartnerCredentialStatus')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $hasKeys = false;
    if (function_exists('getPartnerCredentialStatus')) {
        $cred = getPartnerCredentialStatus($key);
        $hasKeys = !empty($cred['test']) || !empty($cred['live']);
    } elseif (function_exists('partnerHasSavedCredentials')) {
        $hasKeys = partnerHasSavedCredentials($key);
    }
    if (!$wired) {
        return [
            'allowed' => false,
            'reason' => 'Not wired (adapter missing)',
            'warn_keys' => !$hasKeys,
        ];
    }
    return [
        'allowed' => true,
        'reason' => '',
        'warn_keys' => !$hasKeys,
    ];
}

function partnerRegistryNotWiredBadgeHtml(): string
{
    return '<span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-700/50 text-slate-300 border border-slate-600/40" title="No connector file — Test Connection cannot probe this partner">Not wired (adapter missing)</span>';
}

function updatePartnerCredentialVaultStatus(string $partnerKey, string $env, string $status): void
{
    ensurePartnerRegistryV2Columns();
    if (!partnerRegistryV2HasColumns()) {
        return;
    }
    $status = strtolower(trim($status));
    if (!in_array($status, partnerRegistryV2CredentialStatuses(), true)) {
        $status = 'missing';
    }
    $col = strtolower(trim($env)) === 'live' ? 'credential_live_status' : 'credential_test_status';
    getDB()->prepare("UPDATE gateway_registry SET {$col}=?, updated_at=NOW() WHERE gateway_key=?")
        ->execute([$status, $partnerKey]);
}

function syncPartnerCredentialVaultStatusFromKeys(string $partnerKey): void
{
    if (!function_exists('getPartnerCredentialStatus')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $cred = getPartnerCredentialStatus($partnerKey);
    updatePartnerCredentialVaultStatus($partnerKey, 'test', !empty($cred['test']) ? 'invalid' : 'missing');
    updatePartnerCredentialVaultStatus($partnerKey, 'live', !empty($cred['live']) ? 'invalid' : 'missing');
}

function recordPartnerCredentialTestResult(string $partnerKey, bool $ok, string $env = 'test'): void
{
    updatePartnerCredentialVaultStatus($partnerKey, $env, $ok ? 'valid' : 'invalid');
}

/**
 * Save registry profile (idempotent update by gateway id).
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message?:string}
 */
function savePartnerRegistryProfile(int $gatewayId, array $input, ?int $adminId = null): array
{
    ensurePartnerRegistryV2Columns();
    if ($gatewayId < 1) {
        return ['ok' => false, 'message' => 'Invalid partner.'];
    }
    $st = getDB()->prepare('SELECT * FROM gateway_registry WHERE id=? LIMIT 1');
    $st->execute([$gatewayId]);
    $existing = $st->fetch();
    if (!$existing) {
        return ['ok' => false, 'message' => 'Partner not found.'];
    }
    if ((string)($existing['registry_kind'] ?? 'partner') === 'method') {
        return ['ok' => false, 'message' => 'Payment method rows cannot be edited as partners.'];
    }

    $partnerCode = strtolower(trim((string)($input['partner_code'] ?? $existing['gateway_key'])));
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $partnerCode)) {
        return ['ok' => false, 'message' => 'Partner code must be 2–40 chars: lowercase letters, numbers, underscore.'];
    }
    if ($partnerCode !== (string)$existing['gateway_key']) {
        return ['ok' => false, 'message' => 'Partner code is immutable after registration; do not rename a key referenced by existing merchant links or transactions.'];
    }

    $requiredProfileInput = [
        'connector_id' => $input['connector_id'] ?? $existing['connector_id'] ?? '',
        'webhook_url' => $input['webhook_url'] ?? $existing['webhook_url'] ?? '',
        'countries' => $input['countries'] ?? (json_decode((string)($existing['countries_json'] ?? ''), true) ?? []),
        'doc_pack' => $input['doc_pack'] ?? (json_decode((string)($existing['doc_pack_json'] ?? ''), true) ?? []),
    ];
    $requiredErrors = partnerRegistryV2RequiredProfileErrors($requiredProfileInput);
    if ($requiredErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $requiredErrors)];
    }

    try {
        $partnerType = partnerRegistryV2NormalizePartnerType((string)($input['partner_type'] ?? $existing['partner_type'] ?? 'pg'));
        $contractMode = partnerRegistryV2NormalizeContractMode((string)($input['contract_mode'] ?? $existing['contract_mode'] ?? 'platform'));
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $displayName = trim((string)($input['display_name'] ?? $existing['gateway_name']));
    if ($displayName === '' || mb_strlen($displayName) > 80) {
        return ['ok' => false, 'message' => 'Display name is required and must be 80 characters or fewer.'];
    }

    $allowsLink = !empty($input['allows_existing_merchant_link']) ? 1 : 0;
    try {
        $apiBaseUrl = partnerRegistryV2NormalizeApiBaseUrl((string)($input['api_base_url'] ?? $existing['api_base_url'] ?? ''));
        $countries = partnerRegistryV2NormalizeCountries($requiredProfileInput['countries']);
        $currencies = partnerRegistryV2NormalizeCurrencies($input['currencies'] ?? (json_decode((string)($existing['currencies_json'] ?? ''), true) ?? []));
        $connectorId = partnerRegistryV2NormalizeConnectorId((string)$requiredProfileInput['connector_id']);
        $logoUrl = partnerRegistryV2NormalizeLogoUrl((string)($input['logo_url'] ?? $existing['logo_url'] ?? ''));
        $webhookUrl = partnerRegistryV2NormalizeWebhookPath((string)$requiredProfileInput['webhook_url']);
        $platformFeePct = partnerRegistryV2NormalizeFeePercent($input['platform_fee_pct_default'] ?? $existing['platform_fee_pct_default'] ?? '');
        $platformFeeFlat = partnerRegistryV2NormalizeFeeFlat($input['platform_fee_flat_default'] ?? $existing['platform_fee_flat_default'] ?? '');
        $successWeight = partnerRegistryV2NormalizeSuccessWeight($input['weight_success'] ?? $existing['weight_success'] ?? '');
        $policyIn = is_array($input['policy_urls'] ?? null) ? $input['policy_urls'] : [];
        $policyUrls = [
            'terms' => partnerRegistryV2NormalizePolicyUrl((string)($policyIn['terms'] ?? '')),
            'privacy' => partnerRegistryV2NormalizePolicyUrl((string)($policyIn['privacy'] ?? '')),
            'refund' => partnerRegistryV2NormalizePolicyUrl((string)($policyIn['refund'] ?? '')),
            'support' => partnerRegistryV2NormalizePolicyUrl((string)($policyIn['support'] ?? '')),
        ];
        $routingPriority = partnerRegistryV2NormalizeRoutingPriority($input['routing_priority'] ?? $existing['routing_priority'] ?? 50);
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $supportEmail = trim((string)($input['support_email'] ?? ''));
    if ($supportEmail !== '' && (strlen($supportEmail) > 190 || !filter_var($supportEmail, FILTER_VALIDATE_EMAIL))) {
        return ['ok' => false, 'message' => 'Enter a valid partner support email address.'];
    }
    $supportPhone = trim((string)($input['support_phone'] ?? ''));
    if ($supportPhone !== '') {
        $phoneDigits = preg_replace('/\D/', '', $supportPhone) ?? '';
        if (strlen($supportPhone) > 40
            || !preg_match('/^\+?[0-9().\s-]+$/', $supportPhone)
            || strlen($phoneDigits) < 7
            || strlen($phoneDigits) > 15) {
            return ['ok' => false, 'message' => 'Enter a valid partner support phone number.'];
        }
    }
    $circuitOn = !empty($input['circuit_breaker_on']) ? 1 : 0;
    $connectorNotes = mb_substr(trim((string)($input['connector_notes'] ?? '')), 0, 255);
    $displayDescription = mb_substr(trim((string)($input['display_description'] ?? '')), 0, 500);
    $adapterClass = mb_substr(trim((string)($input['adapter_class'] ?? '')), 0, 120) ?: null;
    $settleNote = trim((string)($input['settle_note'] ?? $existing['settle_note'] ?? ''));
    if (mb_strlen($settleNote) > 500) {
        return ['ok' => false, 'message' => 'Settlement note must be 500 characters or fewer.'];
    }
    $settleNote = $settleNote !== '' ? $settleNote : null;
    $updatedBy = $adminId !== null && $adminId > 0 ? $adminId : null;

    $caps = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
    $capCollect = !empty($caps['collect']) ? 1 : 0;
    $capUpi = !empty($caps['upi']) ? 1 : 0;
    $capCard = !empty($caps['card']) ? 1 : 0;
    $capNb = !empty($caps['netbanking']) ? 1 : 0;
    $capRefund = !empty($caps['refund']) ? 1 : 0;
    $capPayLater = !empty($caps['pay_later']) ? 1 : 0;
    $capQr = !empty($caps['qr']) ? 1 : 0;
    $capPayout = !empty($caps['payout']) ? 1 : 0;
    $capKyc = !empty($caps['kyc_forward_api']) ? 1 : 0;
    $channels = is_array($input['channels'] ?? null) ? $input['channels'] : [];
    $channelCapabilities = [];
    foreach (partnerRegistryV2ChannelCatalog() as $channel) {
        if (array_key_exists($channel, $channels)) {
            $channelCapabilities[$channel] = (bool)$channels[$channel];
        }
    }

    $docPackRaw = $requiredProfileInput['doc_pack'];
    $docPack = is_array($docPackRaw) ? partnerRegistryV2FilterMerchantDocCodes($docPackRaw) : [];
    $optionalDocPackRaw = $input['optional_doc_pack'] ?? [];
    $optionalDocPack = is_array($optionalDocPackRaw)
        ? partnerRegistryV2FilterOptionalMerchantDocCodes($optionalDocPackRaw, $docPack)
        : [];

    $complianceRaw = $input['partner_compliance_docs'] ?? [];
    $complianceDocs = is_array($complianceRaw) ? partnerRegistryV2FilterComplianceDocCodes($complianceRaw) : [];

    $sql = 'UPDATE gateway_registry SET gateway_key=?, gateway_name=?, partner_type=?, contract_mode=?, allows_existing_merchant_link=?,
            cap_collect=?, cap_upi=?, cap_card=?, cap_netbanking=?, cap_refund=?, cap_pay_later=?, cap_qr=?, cap_payout=?, cap_kyc_forward_api=?,
            doc_pack_json=?, optional_doc_pack_json=?, partner_compliance_docs_json=?, policy_urls_json=?, routing_priority=?, circuit_breaker_on=?, connector_notes=?, display_description=?,
            webhook_url=?, adapter_class=?, connector_id=?, logo_url=?, channel_capabilities_json=?, api_base_url=?, countries_json=?, currencies_json=?, support_email=?, support_phone=?,
            platform_fee_pct_default=?, platform_fee_flat_default=?, weight_success=?, settle_note=?, supports_collection=?, supports_refund=?, updated_by=?, updated_at=NOW() WHERE id=?';
    getDB()->prepare($sql)->execute([
        $partnerCode,
        $displayName,
        $partnerType,
        $contractMode,
        $allowsLink,
        $capCollect,
        $capUpi,
        $capCard,
        $capNb,
        $capRefund,
        $capPayLater,
        $capQr,
        $capPayout,
        $capKyc,
        $docPack === [] ? null : json_encode($docPack, JSON_UNESCAPED_UNICODE),
        $optionalDocPack === [] ? null : json_encode($optionalDocPack, JSON_UNESCAPED_UNICODE),
        $complianceDocs === [] ? null : json_encode($complianceDocs, JSON_UNESCAPED_UNICODE),
        json_encode($policyUrls, JSON_UNESCAPED_UNICODE),
        $routingPriority,
        $circuitOn,
        $connectorNotes !== '' ? $connectorNotes : null,
        $displayDescription !== '' ? $displayDescription : null,
        $webhookUrl,
        $adapterClass,
        $connectorId,
        $logoUrl,
        $channelCapabilities === [] ? null : json_encode($channelCapabilities, JSON_UNESCAPED_UNICODE),
        $apiBaseUrl,
        $countries === [] ? null : json_encode($countries, JSON_UNESCAPED_UNICODE),
        $currencies === [] ? null : json_encode($currencies, JSON_UNESCAPED_UNICODE),
        $supportEmail !== '' ? $supportEmail : null,
        $supportPhone !== '' ? $supportPhone : null,
        $platformFeePct,
        $platformFeeFlat,
        $successWeight,
        $settleNote,
        $capCollect,
        $capRefund,
        $updatedBy,
        $gatewayId,
    ]);

    $auditDetail = json_encode([
        'partner_code' => $partnerCode,
        'partner_type' => $partnerType,
        'connector_id' => $connectorId,
        'contract_mode' => $contractMode,
        'routing_priority' => $routingPriority,
    ], JSON_UNESCAPED_UNICODE);
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('partner_registry_v2_saved', null, 'gateway', $partnerCode, (string)$auditDetail);
    }
    if (function_exists('logStaffActivity')) {
        logStaffActivity('partner_registry_v2_saved', 'Registry profile saved for ' . $partnerCode, null, 'partner', $partnerCode);
    }

    return ['ok' => true, 'message' => 'Partner registry profile saved.', 'partner_code' => $partnerCode];
}

/**
 * Register new partner with v2 defaults (wraps registerGateway).
 *
 * @param array<string,mixed> $input
 * @return array{ok:bool,message?:string,gateway_id?:int,partner_code?:string}
 */
function registerPartnerRegistryV2(array $input, ?int $adminId = null): array
{
    ensurePartnerRegistryV2Columns();
    $partnerCode = strtolower(trim((string)($input['partner_code'] ?? $input['gateway_key'] ?? '')));
    $displayName = trim((string)($input['display_name'] ?? $input['gateway_name'] ?? ''));
    if ($partnerCode === '' || $displayName === '') {
        return ['ok' => false, 'message' => 'Partner code and display name are required.'];
    }
    if (mb_strlen($displayName) > 80) {
        return ['ok' => false, 'message' => 'Display name must be 80 characters or fewer.'];
    }
    try {
        partnerRegistryV2NormalizePartnerType((string)($input['partner_type'] ?? 'pg'));
        partnerRegistryV2NormalizeContractMode((string)($input['contract_mode'] ?? 'platform'));
        partnerRegistryV2NormalizeFeePercent($input['platform_fee_pct_default'] ?? '');
        partnerRegistryV2NormalizeFeeFlat($input['platform_fee_flat_default'] ?? '');
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    $requiredErrors = partnerRegistryV2RequiredProfileErrors($input);
    if ($requiredErrors !== []) {
        return ['ok' => false, 'message' => implode(' ', $requiredErrors)];
    }

    if (!function_exists('registerGateway')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $caps = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
    $db = getDB();
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $result = registerGateway($partnerCode, $displayName, [
            'collection' => !empty($caps['collect']) ? 1 : (int)!empty($input['supports_collection']),
            'payout' => 0,
            'refund' => !empty($caps['refund']) ? 1 : (int)!empty($input['supports_refund']),
            'recurring' => (int)!empty($input['supports_recurring']),
            'adapter' => trim((string)($input['adapter_class'] ?? '')) ?: null,
            'webhook_url' => partnerRegistryV2NormalizeWebhookPath((string)($input['webhook_url'] ?? '')),
        ]);
        if (empty($result['ok'])) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return ['ok' => false, 'message' => (string)($result['error'] ?? 'Could not register partner.')];
        }

        $gatewayId = (int)($result['gateway_id'] ?? 0);
        if ($gatewayId < 1) {
            throw new RuntimeException('Partner registration returned no registry ID.');
        }
        getDB()->prepare("UPDATE gateway_registry SET integration_status='draft', lifecycle_updated_at=NOW(), lifecycle_updated_by=? WHERE id=? AND integration_status='legacy_unverified'")
            ->execute([$adminId, $gatewayId]);
        $save = savePartnerRegistryProfile($gatewayId, $input, $adminId);
        if (empty($save['ok'])) {
            if ($ownsTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            return $save;
        }
        if ($ownsTransaction) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('registerPartnerRegistryV2 failed: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Could not register partner profile. Check the application log and retry.'];
    }

    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('partner_registry_v2_created', null, 'gateway', $partnerCode, 'Partner registered via Global Control Room');
    }

    return [
        'ok' => true,
        'message' => 'Partner registered.',
        'gateway_id' => $gatewayId,
        'partner_code' => $partnerCode,
    ];
}

function partnerRegistryV2ControlRoomNote(): string
{
    return 'Global Control Room — partner identity, connector, keys status, and capability flags. '
        . 'New partners show immediately as Inactive (Activate is not required to see the row). '
        . 'New integrations progress Draft → Sandbox → LIVE readiness; routing activation stays separate. '
        . 'Keys Missing / Keys Valid / Keys Invalid come from saved keys and Test Connection. '
        . 'Not wired means no adapter file — routing ON is blocked. This screen does not move customer money.';
}

/**
 * Default key fields for custom (non-builtin) partners so Keys tab is always usable.
 *
 * @return array<string, array{label:string,type:string}>
 */
function partnerRegistryV2DefaultConfigKeys(string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $prefix = $partnerKey !== '' ? $partnerKey : 'partner';
    return [
        $prefix . '_api_key' => ['label' => 'API Key / Client ID', 'type' => 'password'],
        $prefix . '_api_secret' => ['label' => 'API Secret', 'type' => 'password'],
        $prefix . '_merchant_id' => ['label' => 'Merchant / Mid ID (optional)', 'type' => 'text'],
        $prefix . '_webhook_secret' => ['label' => 'Webhook Secret (optional)', 'type' => 'password'],
    ];
}

/**
 * Builtin registry meta, or synthetic meta for custom DB partners (keys always available).
 *
 * @param array<string,mixed>|null $gatewayRow
 * @return array<string,mixed>
 */
function resolvePartnerAdminMeta(string $partnerKey, ?array $gatewayRow = null): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $builtin = getPartnerRegistry()[$partnerKey] ?? null;
    if (is_array($builtin) && !empty($builtin['config_keys']) && is_array($builtin['config_keys'])) {
        return $builtin;
    }

    $name = trim((string)($gatewayRow['gateway_name'] ?? ''));
    if ($name === '') {
        $name = $partnerKey !== '' ? ucwords(str_replace('_', ' ', $partnerKey)) : 'Partner';
    }
    $meta = is_array($builtin) ? $builtin : [];
    $meta['name'] = $meta['name'] ?? $name;
    $meta['type'] = $meta['type'] ?? 'gateway';
    $meta['icon'] = $meta['icon'] ?? '⚙️';
    $meta['config_keys'] = partnerRegistryV2DefaultConfigKeys($partnerKey);
    $meta['docs'] = $meta['docs'] ?? '';
    $meta['dashboard'] = $meta['dashboard'] ?? '';
    $meta['webhook'] = $meta['webhook'] ?? (string)($gatewayRow['webhook_url'] ?? '');
    $meta['checklist'] = $meta['checklist'] ?? [
        'Paste Test / Sandbox keys on the Keys tab',
        'Run Test Connection',
        'Paste Live keys when ready',
        'Use Activate for routing when ready to expose methods',
    ];
    return $meta;
}

/** List status badge: Active | Inactive (routing flag only — not money Live). */
function partnerRegistryListStatusBadge(array $gatewayRow): string
{
    if (function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($gatewayRow)) {
        return '<span class="text-[10px] px-2 py-0.5 rounded-full bg-slate-600/40 text-slate-300 border border-slate-500/40" title="Retired — hidden from default list and routing">Retired</span>';
    }
    $active = (int)($gatewayRow['is_active'] ?? 0) === 1;
    if ($active) {
        return '<span class="text-[10px] px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 border border-emerald-500/30" title="Routing ON — methods can be offered to merchants">Active</span>';
    }
    return '<span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-700/60 text-gray-300 border border-gray-600/40" title="In the list, routing OFF">Inactive</span>';
}

function partnerRegistryRowIsRetired(array $gatewayRow): bool
{
    return trim((string)($gatewayRow['retired_at'] ?? '')) !== '';
}

function partnerRegistryHasRetiredColumn(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        $has = (bool)getDB()->query("SHOW COLUMNS FROM gateway_registry LIKE 'retired_at'")->fetch();
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}

function partnerAllowsAlreadyLiveLink(array $gatewayRow): bool
{
    if (!partnerRegistryLifecycleAllowsMerchantEnablement(partnerRegistryLifecycleStatus($gatewayRow))) {
        return false;
    }
    if (!empty($gatewayRow['allows_existing_merchant_link'])) {
        return true;
    }
    $mode = strtolower(trim((string)($gatewayRow['contract_mode'] ?? 'platform')));
    return in_array($mode, ['linked_existing', 'hybrid'], true);
}

/** One-time: Razorpay / Cashfree / PayU already-live LINK on. Admin can still uncheck later. */
function seedCollectPartnerAlreadyLiveLinkDefault(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    if (function_exists('getSetting') && getSetting('seed_already_live_collect_pg', '') === '1') {
        return;
    }
    try {
        getDB()->exec("UPDATE gateway_registry SET allows_existing_merchant_link=1 WHERE gateway_key IN ('razorpay','cashfree','payu')");
        if (function_exists('saveSetting')) {
            saveSetting('seed_already_live_collect_pg', '1');
        }
    } catch (Throwable $e) { /* registry table may be missing on first boot */ }
}

function merchantPartnerRouteOverview(int $merchantId): array
{
    if ($merchantId < 1) {
        return [];
    }
    if (!function_exists('getMerchantPartnerLinks')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }

    $rows = [];
    foreach (getMerchantPartnerLinks($merchantId) as $link) {
        $partnerKey = strtolower(trim((string)($link['partner_key'] ?? '')));
        if ($partnerKey === '') {
            continue;
        }
        $gateway = null;
        foreach (getRegisteredGateways(false) as $candidate) {
            if (strtolower(trim((string)($candidate['gateway_key'] ?? ''))) === $partnerKey) {
                $gateway = $candidate;
                break;
            }
        }

        $contractMode = strtolower(trim((string)($gateway['contract_mode'] ?? 'platform')));
        $source = strtolower(trim((string)($link['account_source'] ?? 'platform')));
        $credentialStatus = strtolower(trim((string)($link['credential_status'] ?? 'missing')));
        $ownerOverride = (int)($link['owner_override'] ?? 0) === 1;
        $isExistingAccount = in_array($source, ['linked', 'preexisting'], true);
        $linkedValid = $isExistingAccount && ($credentialStatus === 'valid' || $ownerOverride);

        $lifecycle = function_exists('partnerRegistryLifecycleStatus')
            ? partnerRegistryLifecycleStatus($gateway ?: ['integration_status' => 'legacy_unverified'])
            : strtolower(trim((string)($gateway['integration_status'] ?? 'legacy_unverified')));

        $route = 'platform';
        $routeLabel = 'Platform onboarding';
        $message = 'Platform onboarding path is in use for this partner.';
        if ($isExistingAccount) {
            $route = 'linked_existing';
            $routeLabel = 'Already-live account path';
            $message = $linkedValid
                ? 'Merchant already has a linked partner account and the link is valid; do not create a new platform onboarding record.'
                : 'Existing partner account is on file, but it is not yet valid. Platform onboarding stays blocked until the link is corrected.';
        } elseif (in_array($contractMode, ['linked_existing', 'hybrid'], true)) {
            $route = 'hybrid';
            $routeLabel = 'Hybrid route';
            $message = 'This partner allows hybrid routing. Platform onboarding is allowed only when no valid already-live account is linked.';
        }

        if ($gateway && function_exists('partnerRegistryLifecycleAllowsMerchantEnablement')
            && !partnerRegistryLifecycleAllowsMerchantEnablement($lifecycle)) {
            $message .= ' Lifecycle status: ' . strtoupper($lifecycle) . ' — existing checkout state stays unchanged.';
        }

        $rows[] = [
            'partner_key' => $partnerKey,
            'partner_name' => (string)($gateway['gateway_name'] ?? ucfirst(str_replace('_', ' ', $partnerKey))),
            'route' => $route,
            'route_label' => $routeLabel,
            'message' => $message,
            'linked_valid' => $linkedValid,
            'contract_mode' => $contractMode,
            'lifecycle' => $lifecycle,
            'platform_blocked' => $isExistingAccount && $linkedValid,
        ];
    }

    return $rows;
}

function merchantPartnerOnboardingDecision(int $merchantId, string $partnerKey): array
{
    $merchantId = (int)$merchantId;
    $partnerKey = strtolower(trim((string)$partnerKey));
    $gateway = null;
    if ($partnerKey === '') {
        return ['decision' => 'blocked', 'summary' => 'No partner key supplied.', 'checks' => []];
    }
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    foreach (getRegisteredGateways(false) as $candidate) {
        if (strtolower(trim((string)($candidate['gateway_key'] ?? ''))) === $partnerKey) {
            $gateway = $candidate;
            break;
        }
    }
    $link = function_exists('getMerchantPartnerLinkRow') ? getMerchantPartnerLinkRow($merchantId, $partnerKey) : null;
    $source = strtolower(trim((string)($link['account_source'] ?? 'platform')));
    $credentialStatus = strtolower(trim((string)($link['credential_status'] ?? 'missing')));
    $ownerOverride = (int)($link['owner_override'] ?? 0) === 1;
    $validLinked = in_array($source, ['linked', 'preexisting'], true) && ($credentialStatus === 'valid' || $ownerOverride);
    $contractMode = strtolower(trim((string)($gateway['contract_mode'] ?? 'platform')));
    $platformAllowed = $gateway ? partnerRegistrySupportsPlatformOnboarding($gateway) : false;
    $lifecycle = $gateway && function_exists('partnerRegistryLifecycleStatus')
        ? partnerRegistryLifecycleStatus($gateway)
        : strtolower(trim((string)($gateway['integration_status'] ?? 'legacy_unverified')));
    $lifecycleEligible = $gateway ? partnerRegistryLifecycleAllowsMerchantEnablement($lifecycle) : false;
    $coverage = function_exists('merchantPartnerMayEnableFromCoverage')
        ? merchantPartnerMayEnableFromCoverage($merchantId, $partnerKey)
        : ['ok' => false, 'error' => 'Coverage gate not available.'];

    $checks = [
        ['key' => 'platform_mode', 'ok' => $platformAllowed, 'label' => 'Partner supports platform onboarding', 'detail' => $platformAllowed ? 'This partner is configured for platform or hybrid onboarding.' : 'This partner is configured for linked-existing only.'],
        ['key' => 'lifecycle', 'ok' => $lifecycleEligible, 'label' => 'Partner lifecycle permits enablement', 'detail' => $gateway ? 'Lifecycle status is ' . strtoupper($lifecycle) . '.' : 'No registry row found.'],
        ['key' => 'existing_link', 'ok' => !$validLinked, 'label' => 'No valid already-live account block', 'detail' => $validLinked ? 'A valid already-live link exists and blocks a fresh platform onboarding record.' : 'No valid already-live link is present.'],
        ['key' => 'coverage', 'ok' => !empty($coverage['ok']), 'label' => 'Document coverage gate is satisfied', 'detail' => !empty($coverage['ok']) ? 'Coverage allows enablement.' : ((string)($coverage['error'] ?? 'Coverage gate is not satisfied.'))],
    ];

    $decision = 'blocked';
    $summary = 'Blocked: platform onboarding is not allowed under the current partner state.';
    if ($validLinked) {
        $decision = 'linked_existing';
        $summary = 'Linked existing route wins. A valid already-live account is already connected and platform onboarding is deliberately blocked.';
    } elseif ($platformAllowed && $lifecycleEligible && !empty($coverage['ok'])) {
        $decision = 'platform';
        $summary = 'Platform onboarding is permitted. No valid already-live link is present, lifecycle is eligible, and coverage gate is satisfied.';
    } elseif ($platformAllowed && $lifecycleEligible && empty($coverage['ok'])) {
        $summary = 'Platform onboarding is still blocked until the coverage gate is satisfied.';
    } elseif ($platformAllowed && !$lifecycleEligible) {
        $summary = 'Platform onboarding is blocked because the partner lifecycle is not eligible for merchant enablement.';
    } elseif (!$platformAllowed) {
        $summary = 'This partner is configured as linked-existing only; platform onboarding is not available.';
    }

    return ['decision' => $decision, 'summary' => $summary, 'checks' => $checks, 'partner_key' => $partnerKey, 'lifecycle' => $lifecycle, 'linked_valid' => $validLinked];
}

function merchantPartnerRouteSummary(int $merchantId): array
{
    $merchantId = (int)$merchantId;
    $summary = ['platform' => 0, 'linked_existing' => 0, 'blocked' => 0, 'total' => 0, 'partners' => []];
    if ($merchantId < 1) {
        return $summary;
    }
    if (!function_exists('getMerchantPartnerLinks')) {
        require_once __DIR__ . '/partner_control.php';
    }
    foreach (getMerchantPartnerLinks($merchantId) as $link) {
        $partnerKey = strtolower(trim((string)($link['partner_key'] ?? '')));
        if ($partnerKey === '') {
            continue;
        }
        $decision = merchantPartnerOnboardingDecision($merchantId, $partnerKey);
        $summary['total']++;
        if ($decision['decision'] === 'platform') {
            $summary['platform']++;
        } elseif ($decision['decision'] === 'linked_existing') {
            $summary['linked_existing']++;
        } else {
            $summary['blocked']++;
        }
        $summary['partners'][] = [
            'partner_key' => $partnerKey,
            'decision' => $decision['decision'],
            'summary' => $decision['summary'],
            'lifecycle' => $decision['lifecycle'] ?? '',
        ];
    }
    return $summary;
}

function partnerRegistrySupportsPlatformOnboarding(array $gatewayRow): bool
{
    $mode = strtolower(trim((string)($gatewayRow['contract_mode'] ?? 'platform')));
    return in_array($mode, ['platform', 'hybrid'], true);
}

function partnerRegistryResolveCollectAccountRoute(array $gatewayRow, bool $hasMerchantLink, bool $merchantLinkReady): string
{
    if ($hasMerchantLink) {
        return $merchantLinkReady && partnerAllowsAlreadyLiveLink($gatewayRow)
            ? 'linked_existing'
            : 'blocked';
    }
    return partnerRegistrySupportsPlatformOnboarding($gatewayRow) ? 'platform' : 'blocked';
}

function partnerRegistryPlatformFallbackAllowed(string $accountRoute, bool $sandbox): bool
{
    return $sandbox && $accountRoute === 'platform';
}

/**
 * @return array{platform_ready:bool,linked_ready:bool,blockers:list<string>,lifecycle:string,summary:string}
 */
function partnerRegistryRouteReadinessSummary(array $gatewayRow): array
{
    $key = strtolower(trim((string)($gatewayRow['gateway_key'] ?? '')));
    $lifecycle = partnerRegistryLifecycleStatus($gatewayRow);
    $blockers = [];

    if ($lifecycle === 'retired') {
        $blockers[] = 'Retired — routing and lifecycle actions are locked.';
    }
    if (in_array($lifecycle, ['draft', 'sandbox', 'paused'], true)) {
        $blockers[] = 'Lifecycle status is ' . strtoupper($lifecycle) . ' — not eligible for live merchant enablement.';
    }
    if (!partnerAdapterIsWired($key, $gatewayRow)) {
        $blockers[] = 'No connector adapter / wiring file is present.';
    }
    if ($key !== '' && partnerCredentialVaultStatus($key, 'test') !== 'valid') {
        $blockers[] = 'Test credentials are missing or invalid.';
    }
    if ($key !== '' && $lifecycle === 'live' && partnerCredentialVaultStatus($key, 'live') !== 'valid') {
        $blockers[] = 'Live credentials are missing or invalid for LIVE certification.';
    }

    $platformReady = partnerRegistrySupportsPlatformOnboarding($gatewayRow)
        && !in_array($lifecycle, ['draft', 'sandbox', 'paused', 'retired'], true)
        && partnerRegistryActivateGate($gatewayRow)['allowed'];

    $linkedReady = partnerAllowsAlreadyLiveLink($gatewayRow)
        && !in_array($lifecycle, ['draft', 'sandbox', 'paused', 'retired'], true);

    $summary = 'Platform: ' . ($platformReady ? 'ready' : 'blocked') . '; Linked existing: ' . ($linkedReady ? 'ready' : 'blocked');
    if ($blockers !== []) {
        $summary .= ' — ' . implode('; ', $blockers);
    }

    return [
        'platform_ready' => $platformReady,
        'linked_ready' => $linkedReady,
        'blockers' => $blockers,
        'lifecycle' => $lifecycle,
        'summary' => $summary,
    ];
}

/**
 * @param list<array<string,mixed>> $gateways
 * @return array{platform_ready:int,linked_ready:int,blocked:int,platform_blocked:int,linked_blocked:int,hybrid_ready:int}
 */
function partnerRegistryRouteReadinessCounts(array $gateways): array
{
    $counts = ['platform_ready' => 0, 'linked_ready' => 0, 'blocked' => 0, 'platform_blocked' => 0, 'linked_blocked' => 0, 'hybrid_ready' => 0];
    foreach ($gateways as $gateway) {
        $summary = function_exists('partnerRegistryRouteReadinessSummary') ? partnerRegistryRouteReadinessSummary($gateway) : ['platform_ready' => false, 'linked_ready' => false, 'summary' => ''];
        $mode = strtolower(trim((string)($gateway['contract_mode'] ?? 'platform')));
        if (!empty($summary['platform_ready'])) {
            $counts['platform_ready']++;
        } else {
            $counts['platform_blocked']++;
        }
        if (!empty($summary['linked_ready'])) {
            $counts['linked_ready']++;
        } else {
            $counts['linked_blocked']++;
        }
        if (in_array($mode, ['hybrid', 'linked_existing'], true) && !empty($summary['linked_ready'])) {
            $counts['hybrid_ready']++;
        }
        if (empty($summary['platform_ready']) && empty($summary['linked_ready'])) {
            $counts['blocked']++;
        }
    }
    return $counts;
}

/**
 * @return list<string>
 */
function partnerRegistryRetireBlockers(string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $blockers = [];
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM merchant_payment_methods WHERE method_key=? AND is_enabled=1");
        $st->execute([$partnerKey]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $blockers[] = $n . ' merchant(s) still have this method ON';
        }
    } catch (Throwable $e) { /* ok */ }
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM partner_merchant_links WHERE partner_key=? AND checkout_enabled=1");
        $st->execute([$partnerKey]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $blockers[] = $n . ' already-live checkout link(s) still enabled';
        }
    } catch (Throwable $e) { /* column may be missing */ }
    try {
        $cols = getDB()->query('SHOW COLUMNS FROM transactions')->fetchAll(PDO::FETCH_COLUMN);
        $col = null;
        foreach (['gateway', 'payment_gateway', 'pg', 'partner_key'] as $c) {
            if (in_array($c, $cols, true)) {
                $col = $c;
                break;
            }
        }
        if ($col !== null) {
            $st = getDB()->prepare("SELECT COUNT(*) FROM transactions WHERE {$col}=? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $st->execute([$partnerKey]);
            $n = (int)$st->fetchColumn();
            if ($n > 0) {
                $blockers[] = $n . ' transaction(s) in the last 7 days';
            }
        }
    } catch (Throwable $e) { /* ok */ }
    return $blockers;
}

function partnerRegistryIsProtectedFromRetire(string $partnerKey): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '') {
        return true;
    }
    if (!function_exists('getPartnerRegistryKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $protected = array_values(array_unique(array_merge(
        function_exists('paymentMethodRegistryKeys') ? paymentMethodRegistryKeys() : [],
        ['payu', 'razorpay', 'cashfree', 'axis', 'decentro', 'phonepe', 'paytm', 'worldline', 'digio', 'rbl'],
        function_exists('getPartnerRegistryKeys') ? getPartnerRegistryKeys() : []
    )));
    return in_array($partnerKey, $protected, true);
}

/**
 * Soft-retire a custom partner. Idempotent if already retired. Confirm code must equal partner_code.
 *
 * @param array{confirm_code?:string,admin_id?:int,admin_email?:string} $opts
 * @return array{ok:bool,error?:string,gateway_key?:string,gateway_name?:string,already?:bool}
 */
function retirePartnerRegistryRow(int $gatewayId, array $opts = []): array
{
    ensurePartnerRegistryV2Columns();
    if ($gatewayId < 1) {
        return ['ok' => false, 'error' => 'Invalid partner.'];
    }
    $db = getDB();
    $st = $db->prepare('SELECT * FROM gateway_registry WHERE id=? LIMIT 1');
    $st->execute([$gatewayId]);
    $gw = $st->fetch();
    if (!$gw) {
        return ['ok' => false, 'error' => 'Partner not found.'];
    }
    $key = strtolower(trim((string)$gw['gateway_key']));
    $confirm = strtolower(trim((string)($opts['confirm_code'] ?? '')));
    if ($confirm !== $key) {
        return ['ok' => false, 'error' => 'Type the partner code to confirm retire.'];
    }
    if (partnerRegistryRowIsRetired($gw)) {
        return ['ok' => true, 'already' => true, 'gateway_key' => $key, 'gateway_name' => (string)$gw['gateway_name']];
    }
    if (partnerRegistryIsProtectedFromRetire($key)) {
        return ['ok' => false, 'error' => 'Built-in partners cannot be retired. Use Turn OFF routing.'];
    }
    if ((int)($gw['is_active'] ?? 0) === 1) {
        return ['ok' => false, 'error' => 'Turn OFF routing first, then retire.'];
    }
    $blockers = partnerRegistryRetireBlockers($key);
    if ($blockers !== []) {
        return ['ok' => false, 'error' => 'Cannot retire while in use: ' . implode('; ', $blockers) . '.'];
    }
    $who = mb_substr(trim((string)($opts['admin_email'] ?? '')), 0, 120);
    try {
        if (partnerRegistryHasRetiredColumn()) {
            $db->prepare("UPDATE gateway_registry SET is_active=0, retired_at=NOW(), retired_by=?, integration_status='retired', lifecycle_resume_status=NULL, lifecycle_updated_at=NOW(), lifecycle_updated_by=? WHERE id=? AND retired_at IS NULL")
                ->execute([$who !== '' ? $who : 'admin', (int)($opts['admin_id'] ?? 0), $gatewayId]);
        } else {
            $db->prepare('UPDATE gateway_registry SET is_active=0 WHERE id=?')->execute([$gatewayId]);
        }
        try {
            $db->prepare('UPDATE gateway_method_map SET is_active=0 WHERE gateway_id=?')->execute([$gatewayId]);
        } catch (Throwable $e) { /* ok */ }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not retire partner.'];
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('partner_retired', null, 'gateway', $key, 'Retired by ' . ($who !== '' ? $who : 'admin'));
    }
    if (function_exists('logStaffActivity')) {
        logStaffActivity('partner_retired', 'Retired partner ' . $key, null, 'partner', $key);
    }
    return ['ok' => true, 'gateway_key' => $key, 'gateway_name' => (string)$gw['gateway_name']];
}

/** Whether a gateway_registry row supports KYC forward (cap flag or built-in registry flag). */
function registryRowSupportsKycForward(array $row): bool
{
    $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
    if ($key === '' || !partnerRegistrySupportsPlatformOnboarding($row)) {
        return false;
    }
    if (registryPartnerSupportsChannel($key, 'document_forward', $row)) {
        return true;
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    return partnerHasRegistryFlag($key, 'kyc_forward');
}

/**
 * Registry partners with KYC-forward capability, sorted by routing_priority.
 *
 * @return list<string>
 */
function registryKycForwardCapablePartnerKeys(bool $requireActive = false): array
{
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $rows = [];
    foreach (getRegisteredGateways(false) as $row) {
        $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
        if ($key === '' || !registryRowSupportsKycForward($row)) {
            continue;
        }
        if ($requireActive && (int)($row['is_active'] ?? 0) !== 1) {
            continue;
        }
        if (!partnerAdapterIsWired($key, $row)) {
            continue;
        }
        $prio = (int)($row['routing_priority'] ?? 50);
        $rows[] = ['key' => $key, 'prio' => $prio];
    }
    usort($rows, static fn(array $a, array $b): int => $a['prio'] <=> $b['prio'] ?: strcmp($a['key'], $b['key']));
    return array_values(array_unique(array_column($rows, 'key')));
}

/** Whether a gateway_registry row is a collect partner (not KYC-only / parked). */
function registryRowSupportsCollect(array $row): bool
{
    $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
    if ($key === '') {
        return false;
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $flags = getPartnerRegistry()[$key]['flags'] ?? [];
    if (isset($flags['checkout_pg']) && $flags['checkout_pg'] === false) {
        return false;
    }
    $checkoutPg = partnerHasRegistryFlag($key, 'checkout_pg');
    if (in_array($key, ['phonepe', 'pinelabs', 'worldline', 'toucanpay', 'digio'], true) && !$checkoutPg) {
        return false;
    }
    if (partnerRegistryV2HasColumns() && array_key_exists('cap_collect', $row)) {
        return (int)($row['cap_collect'] ?? 0) === 1;
    }
    return (int)($row['supports_collection'] ?? 0) === 1;
}

function registryPartnerGatewayRow(string $partnerKey, ?array $gatewayRow = null): ?array
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($gatewayRow !== null && strtolower(trim((string)($gatewayRow['gateway_key'] ?? ''))) === $partnerKey) {
        return $gatewayRow;
    }
    if (!function_exists('getRegisteredGateways')) {
        return null;
    }
    foreach (getRegisteredGateways(false) as $row) {
        if (strtolower(trim((string)($row['gateway_key'] ?? ''))) === $partnerKey) {
            return $row;
        }
    }
    return null;
}

/** Keys ready for checkout — permitted merchant LINK or platform vault route. */
function registryPartnerKeysReadyForMode(string $partnerKey, bool $sandbox, int $merchantId = 0, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($partnerKey === '') {
        return false;
    }
    $gatewayRow = registryPartnerGatewayRow($partnerKey, $gatewayRow);
    if ($gatewayRow === null) {
        return false;
    }
    $hasMerchantLink = $merchantId > 0 && function_exists('merchantHasAlreadyLivePayload')
        && merchantHasAlreadyLivePayload($merchantId, $partnerKey);
    $merchantLinkReady = $merchantId > 0 && function_exists('merchantAlreadyLiveCollectReady')
        && merchantAlreadyLiveCollectReady($merchantId, $partnerKey, $sandbox);
    $accountRoute = partnerRegistryResolveCollectAccountRoute($gatewayRow, $hasMerchantLink, $merchantLinkReady);
    if ($accountRoute === 'linked_existing') {
        return true;
    }
    if ($accountRoute !== 'platform') {
        return false;
    }
    $env = $sandbox ? 'test' : 'live';
    if (function_exists('partnerCredentialVaultStatus')) {
        if (partnerCredentialVaultStatus($partnerKey, $env) === 'valid') {
            return true;
        }
    }
    if (function_exists('isGatewayConfigured') && isGatewayConfigured($partnerKey)) {
        return true;
    }
    return false;
}

/** Normalize checkout tab/method to capability bucket. */
function registryCheckoutMethodBucket(string $checkoutMethod): string
{
    $m = strtolower(trim($checkoutMethod));
    return match (true) {
        in_array($m, ['card', 'dc', 'cc', 'debit_card', 'credit_card', 'razorpay', 'cashfree', 'pg_pool'], true) => 'card',
        in_array($m, ['upi', 'upi_p2m', 'payu_upi', 'qr', 'qr_code', 'p2m'], true) => 'upi',
        in_array($m, ['nb', 'netbanking', 'net_banking'], true) => 'netbanking',
        in_array($m, ['emi'], true) => 'emi',
        in_array($m, ['wallet'], true) => 'wallet',
        default => $m,
    };
}

/** partner_methods.method rows that satisfy a checkout bucket. */
function registryCheckoutBucketPartnerMethods(string $bucket): array
{
    return match ($bucket) {
        'card' => ['debit_card', 'credit_card'],
        'upi' => ['upi'],
        'netbanking' => ['netbanking'],
        'emi' => ['emi'],
        'wallet' => ['wallet'],
        default => [$bucket],
    };
}

/** Inferred caps when gateway_registry cap_* columns are unset (upgrade-first). */
function registryPartnerInferredCaps(string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!function_exists('partnerHasRegistryFlag')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $checkoutPg = partnerHasRegistryFlag($partnerKey, 'checkout_pg');
    return match ($partnerKey) {
        'razorpay', 'cashfree', 'payu', 'ccavenue' => [
            'collect' => true, 'upi' => true, 'card' => true, 'netbanking' => true, 'emi' => true, 'wallet' => true,
        ],
        'decentro', 'rbl' => ['collect' => true, 'upi' => true, 'card' => false, 'netbanking' => false, 'emi' => false, 'wallet' => false],
        'axis' => ['collect' => true, 'upi' => true, 'card' => false, 'netbanking' => false, 'emi' => false, 'wallet' => false],
        default => [
            'collect' => $checkoutPg,
            'upi' => $checkoutPg,
            'card' => $checkoutPg,
            'netbanking' => $checkoutPg,
            'emi' => $checkoutPg,
            'wallet' => $checkoutPg,
        ],
    };
}

/**
 * Effective registry caps (DB cap_* with upgrade-first inference).
 *
 * @return array{collect:bool,upi:bool,card:bool,netbanking:bool,emi:bool,wallet:bool}
 */
function registryPartnerEffectiveCaps(string $partnerKey, ?array $gatewayRow = null): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($gatewayRow === null && function_exists('getRegisteredGateways')) {
        foreach (getRegisteredGateways(false) as $row) {
            if (strtolower((string)($row['gateway_key'] ?? '')) === $partnerKey) {
                $gatewayRow = $row;
                break;
            }
        }
    }
    $hasExplicit = false;
    $caps = [
        'collect' => false,
        'upi' => false,
        'card' => false,
        'netbanking' => false,
        'emi' => false,
        'wallet' => false,
    ];
    if ($gatewayRow && function_exists('partnerRegistryV2HasColumns') && partnerRegistryV2HasColumns()) {
        $caps['collect'] = (int)($gatewayRow['cap_collect'] ?? 0) === 1;
        $caps['upi'] = (int)($gatewayRow['cap_upi'] ?? 0) === 1;
        $caps['card'] = (int)($gatewayRow['cap_card'] ?? 0) === 1;
        $caps['netbanking'] = (int)($gatewayRow['cap_netbanking'] ?? 0) === 1;
        $hasExplicit = $caps['collect'] || $caps['upi'] || $caps['card'] || $caps['netbanking'];
    } elseif ($gatewayRow) {
        $caps['collect'] = (int)($gatewayRow['supports_collection'] ?? 0) === 1;
        $hasExplicit = $caps['collect'];
    }
    if (!$hasExplicit) {
        $inferred = registryPartnerInferredCaps($partnerKey);
        foreach ($inferred as $k => $v) {
            if ($v) {
                $caps[$k] = true;
            }
        }
    }
    if (!$caps['collect'] && ($caps['upi'] || $caps['card'] || $caps['netbanking'])) {
        $caps['collect'] = true;
    }
    return $caps;
}

/**
 * Probe UniWeb codebase — is hosted collect implemented for partner+method?
 * Registry DB caps can claim card; this gate prevents Keys Valid + order fail.
 */
function registryPartnerCollectMethodImplementedInCode(string $partnerKey, string $checkoutMethod): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $bucket = registryCheckoutMethodBucket($checkoutMethod);
    if (!function_exists('buildPayUPaymentForm') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }
    return match ($bucket) {
        'card', 'netbanking', 'emi', 'wallet' => match ($partnerKey) {
            'razorpay' => function_exists('createRazorpayOrder'),
            'cashfree' => function_exists('createCashfreeOrder'),
            'payu' => function_exists('buildPayUPaymentForm'),
            'ccavenue' => function_exists('createCcavenueHostedOrder') || function_exists('initiateCcavenueCheckout'),
            default => false,
        },
        'upi' => match ($partnerKey) {
            'decentro' => function_exists('createDecentroDynamicQr'),
            'rbl' => function_exists('rblUpiCollection'),
            'razorpay', 'cashfree', 'payu' => function_exists('createRazorpayOrder')
                || function_exists('createCashfreeOrder')
                || function_exists('buildPayUPaymentForm'),
            default => false,
        },
        default => false,
    };
}

/** @deprecated Use registryPartnerCollectMethodImplementedInCode — kept for smoke/back-compat probes. */
function registryPartnerUpiOnlyRailKeys(): array
{
    return [];
}

/**
 * Hosted checkout / QR pay path exists in UniWeb for this method (Keys Valid alone is not enough).
 */
function registryPartnerCollectPayPathWired(string $partnerKey, string $checkoutMethod): bool
{
    return registryPartnerCollectMethodImplementedInCode($partnerKey, $checkoutMethod);
}

/**
 * Checkout UI can invoke collect for this partner+method (API wired into checkout.php / upi_confirm).
 */
function registryPartnerCheckoutUiWired(string $partnerKey, string $checkoutMethod): bool
{
    return registryPartnerCollectMethodImplementedInCode($partnerKey, $checkoutMethod);
}

/** Registry cap allows this checkout bucket (ignores partner_methods toggle). */
function registryPartnerCapSupportsCheckoutMethod(string $partnerKey, string $checkoutMethod, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!registryPartnerSupportsChannel($partnerKey, 'checkout_hosted', $gatewayRow)) {
        return false;
    }
    $bucket = registryCheckoutMethodBucket($checkoutMethod);
    $caps = registryPartnerEffectiveCaps($partnerKey, $gatewayRow);
    if (!$caps['collect']) {
        return false;
    }
    return match ($bucket) {
        'card' => $caps['card'],
        'upi' => $caps['upi'],
        'netbanking' => $caps['netbanking'],
        'emi' => $caps['emi'],
        'wallet' => $caps['wallet'],
        default => $caps['collect'],
    };
}

/** Active routing + collect cap + wired adapter (keys optional). */
function registryPartnerConnectorCollectReady(string $partnerKey, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if ($gatewayRow === null && function_exists('getRegisteredGateways')) {
        foreach (getRegisteredGateways(false) as $row) {
            if (strtolower((string)($row['gateway_key'] ?? '')) === $partnerKey) {
                $gatewayRow = $row;
                break;
            }
        }
    }
    if (!$gatewayRow || (int)($gatewayRow['is_active'] ?? 0) !== 1) {
        return false;
    }
    if (function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($gatewayRow)) {
        return false;
    }
    if (!registryRowSupportsCollect($gatewayRow)) {
        return false;
    }
    return partnerAdapterIsWired($partnerKey, $gatewayRow);
}

/** Detail Methods tab: at least one partner_methods row ON for this checkout bucket. */
function registryPartnerDetailMethodSupportsCheckout(string $partnerKey, string $checkoutMethod): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!function_exists('isPartnerMethodEnabled')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $bucket = registryCheckoutMethodBucket($checkoutMethod);
    foreach (registryCheckoutBucketPartnerMethods($bucket) as $method) {
        if (isPartnerMethodEnabled($partnerKey, $method)) {
            return true;
        }
    }
    return false;
}

/**
 * Admin Methods toggle gate — cap + connector only (Commercial never unlocks).
 *
 * @return array{allowed:bool,reason:string}
 */
function registryPartnerMethodAdminGate(string $partnerKey, string $partnerMethod, ?array $gatewayRow = null): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $partnerMethod = strtolower(trim($partnerMethod));
    if ($gatewayRow === null && function_exists('getRegisteredGateways')) {
        foreach (getRegisteredGateways(false) as $row) {
            if (strtolower((string)($row['gateway_key'] ?? '')) === $partnerKey) {
                $gatewayRow = $row;
                break;
            }
        }
    }
    if (!registryPartnerConnectorCollectReady($partnerKey, $gatewayRow)) {
        if ($gatewayRow && (int)($gatewayRow['is_active'] ?? 0) !== 1) {
            return ['allowed' => false, 'reason' => 'Routing OFF — activate partner first'];
        }
        if (!partnerAdapterIsWired($partnerKey, $gatewayRow)) {
            return ['allowed' => false, 'reason' => 'Not wired — adapter missing'];
        }
        return ['allowed' => false, 'reason' => 'Collect not enabled on this partner'];
    }
    $checkoutProbe = match ($partnerMethod) {
        'debit_card', 'credit_card' => 'card',
        'emandate_card' => 'card',
        'emandate_upi' => 'upi',
        'emandate_nb' => 'netbanking',
        default => $partnerMethod,
    };
    if (!registryPartnerCapSupportsCheckoutMethod($partnerKey, $checkoutProbe, $gatewayRow)) {
        return ['allowed' => false, 'reason' => 'Registry cap does not include this method'];
    }
    if (!registryPartnerCollectPayPathWired($partnerKey, $checkoutProbe)) {
        return ['allowed' => false, 'reason' => 'Not wired — hosted checkout adapter missing in UniWeb'];
    }
    return ['allowed' => true, 'reason' => ''];
}

/**
 * Registry capability + Detail Methods ON + connector + pay path (no keys / merchant gates).
 * Commercial rates never unlock methods.
 */
function registryPartnerSupportsCheckoutMethod(string $partnerKey, string $checkoutMethod, ?array $gatewayRow = null): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!registryPartnerConnectorCollectReady($partnerKey, $gatewayRow)) {
        return false;
    }
    if (!registryPartnerCapSupportsCheckoutMethod($partnerKey, $checkoutMethod, $gatewayRow)) {
        return false;
    }
    if (!registryPartnerCollectPayPathWired($partnerKey, $checkoutMethod)) {
        return false;
    }
    return registryPartnerDetailMethodSupportsCheckout($partnerKey, $checkoutMethod);
}

/**
 * Full eligible() partner gate: registry method support + keys for mode + checkout UI wired.
 */
function registryPartnerCheckoutEligible(string $partnerKey, string $checkoutMethod, bool $sandbox, ?array $gatewayRow = null, int $merchantId = 0): bool
{
    $partnerKey = strtolower(trim($partnerKey));
    $gatewayRow = registryPartnerGatewayRow($partnerKey, $gatewayRow);
    if ($gatewayRow === null) {
        return false;
    }
    if (!registryPartnerSupportsCheckoutMethod($partnerKey, $checkoutMethod, $gatewayRow)) {
        return false;
    }
    $hasMerchantLink = $merchantId > 0 && function_exists('merchantHasAlreadyLivePayload')
        && merchantHasAlreadyLivePayload($merchantId, $partnerKey);
    $merchantLinkReady = $merchantId > 0 && function_exists('merchantAlreadyLiveCollectReady')
        && merchantAlreadyLiveCollectReady($merchantId, $partnerKey, $sandbox);
    $accountRoute = partnerRegistryResolveCollectAccountRoute($gatewayRow, $hasMerchantLink, $merchantLinkReady);
    if ($accountRoute === 'blocked') {
        return false;
    }
    if (!registryPartnerKeysReadyForMode($partnerKey, $sandbox, $merchantId, $gatewayRow)) {
        if (!partnerRegistryPlatformFallbackAllowed($accountRoute, $sandbox)) {
            return false;
        }
        $collectConfigured = function_exists('isCollectPartnerConfigured')
            && isCollectPartnerConfigured($partnerKey, $merchantId, $sandbox);
        $platformConfigured = function_exists('isGatewayConfigured')
            && isGatewayConfigured($partnerKey)
            && !($merchantId > 0 && function_exists('merchantHasAlreadyLivePayload')
                && merchantHasAlreadyLivePayload($merchantId, $partnerKey));
        if (!$collectConfigured && !$platformConfigured) {
            return false;
        }
    }
    return registryPartnerCheckoutUiWired($partnerKey, $checkoutMethod);
}

/** Partners with card / hosted checkout order APIs (Registry caps + Detail Methods). */
function registryCardCheckoutPartnerKeys(): array
{
    $out = [];
    foreach (registryCollectCapablePartnerKeys(false) as $key) {
        if (registryPartnerSupportsCheckoutMethod($key, 'card')
            && registryPartnerCheckoutUiWired($key, 'card')) {
            $out[] = $key;
        }
    }
    return $out;
}

/**
 * Collect-capable registry partners sorted by routing_priority (lower = first).
 *
 * @return list<string>
 */
function registryCollectCapablePartnerKeys(bool $requireRoutingActive = false): array
{
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $rows = [];
    foreach (getRegisteredGateways(false) as $row) {
        $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
        if ($key === '' || !registryRowSupportsCollect($row)) {
            continue;
        }
        if ($requireRoutingActive && (int)($row['is_active'] ?? 0) !== 1) {
            continue;
        }
        if (!partnerAdapterIsWired($key, $row)) {
            continue;
        }
        $prio = (int)($row['routing_priority'] ?? 50);
        $rows[] = ['key' => $key, 'prio' => $prio];
    }
    usort($rows, static fn(array $a, array $b): int => $a['prio'] <=> $b['prio'] ?: strcmp($a['key'], $b['key']));
    return array_values(array_unique(array_column($rows, 'key')));
}

/**
 * @return array{label:string,active:bool,wired:bool,key_status:string,collect:bool,is_template:bool}
 */
function registryPartnerCollectStatus(string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $row = null;
    foreach (getRegisteredGateways(false) as $g) {
        if (strtolower((string)($g['gateway_key'] ?? '')) === $partnerKey) {
            $row = $g;
            break;
        }
    }
    $meta = getPartnerRegistry()[$partnerKey] ?? [];
    $label = (string)($row['gateway_name'] ?? $meta['name'] ?? ucfirst($partnerKey));
    $active = $row ? (int)($row['is_active'] ?? 0) === 1 : false;
    $wired = partnerAdapterIsWired($partnerKey, $row);
    $collect = $row ? registryRowSupportsCollect($row) : false;
    $env = 'test';
    if (function_exists('getPartnerEnvironment')) {
        $env = getPartnerEnvironment($partnerKey, 'test') === 'production' ? 'live' : 'test';
    }
    $sandboxCheck = $env !== 'live';
    $keyStatus = 'missing';
    if (function_exists('registryPartnerKeysReadyForMode') && registryPartnerKeysReadyForMode($partnerKey, $sandboxCheck)) {
        $keyStatus = 'valid';
    } elseif (function_exists('partnerCredentialVaultStatus')) {
        $vault = partnerCredentialVaultStatus($partnerKey, $env);
        $keyStatus = in_array($vault, ['valid', 'invalid'], true) ? $vault : 'missing';
    } elseif (function_exists('isGatewayConfigured') && isGatewayConfigured($partnerKey)) {
        $keyStatus = 'valid';
    }
    $templatePg = strtolower(trim((string)getSetting('active_payment_gateway', 'razorpay')));
    return [
        'label' => $label,
        'active' => $active,
        'wired' => $wired,
        'key_status' => $keyStatus,
        'collect' => $collect,
        'is_template' => $templatePg !== '' && $templatePg === $partnerKey,
    ];
}

/** Human status line for Platform Settings gateway grid. */
function registryPartnerPlatformStatusLine(string $partnerKey): string
{
    $s = registryPartnerCollectStatus($partnerKey);
    if (!$s['wired']) {
        return 'Not wired (adapter missing)';
    }
    if (!$s['collect']) {
        return 'KYC / forward only — not on collect path';
    }
    if ($s['key_status'] === 'valid' && function_exists('registryPartnerCollectPayPathWired')
        && !registryPartnerCollectPayPathWired($partnerKey, 'card')
        && !registryPartnerCollectPayPathWired($partnerKey, 'upi')) {
        return 'Keys Valid · Not wired for hosted checkout';
    }
    if (!$s['active']) {
        return 'Routing OFF — turn ON in Partner Registry';
    }
    return match ($s['key_status']) {
        'valid' => $s['is_template'] ? 'Keys Valid · new-merchant template' : 'Keys Valid · Active routing',
        'invalid' => 'Keys Invalid — re-test in Partner Registry',
        default => 'Keys Missing — paste in Partner Registry → Keys',
    };
}

/**
 * Template default PG dropdown — Registry is source of truth.
 *
 * @return array<string,string>
 */
function templateDefaultPaymentGatewayOptions(): array
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $opts = ['registry_auto' => 'Registry auto (first ready / priority)'];
    foreach (registryCollectCapablePartnerKeys(false) as $key) {
        $meta = getPartnerRegistry()[$key] ?? [];
        $opts[$key] = (string)($meta['name'] ?? ucfirst($key));
    }
    $opts['manual'] = 'Manual UPI Only';
    return $opts;
}

/**
 * Active (or template-selected) collect partners that still need keys.
 *
 * @return list<array{id:string,label:string,detail:string,status:string}>
 */
function registryCollectPartnerKeyGaps(): array
{
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $templatePg = strtolower(trim((string)getSetting('active_payment_gateway', '')));
    $gaps = [];
    foreach (getRegisteredGateways(false) as $row) {
        $key = strtolower(trim((string)($row['gateway_key'] ?? '')));
        if ($key === '' || !registryRowSupportsCollect($row)) {
            continue;
        }
        if (!partnerAdapterIsWired($key, $row)) {
            continue;
        }
        $routingActive = (int)($row['is_active'] ?? 0) === 1;
        $templateSelected = $templatePg !== '' && $templatePg === $key;
        if (!$routingActive && !$templateSelected) {
            continue;
        }
        $status = registryPartnerCollectStatus($key);
        if ($status['key_status'] === 'valid') {
            continue;
        }
        $meta = getPartnerRegistry()[$key] ?? [];
        $label = (string)($row['gateway_name'] ?? $meta['name'] ?? ucfirst($key));
        $detail = match ($status['key_status']) {
            'invalid' => 'Keys saved but invalid — run Test Connection in Partner Registry',
            default => 'Paste Test keys in Partner Registry → Keys',
        };
        $gaps[] = [
            'id' => $key,
            'label' => $label,
            'detail' => $detail,
            'status' => $status['key_status'] === 'invalid' ? 'Keys Invalid' : 'Keys Missing',
        ];
    }
    return $gaps;
}

/** First collect partner with valid keys (sandbox OK), by registry routing priority. */
function registryFirstReadyCollectPartner(bool $sandbox = true): ?string
{
    $env = $sandbox ? 'test' : 'live';
    foreach (registryCollectCapablePartnerKeys(true) as $key) {
        if (!function_exists('isGatewayActive') || !isGatewayActive($key)) {
            continue;
        }
        $vault = function_exists('partnerCredentialVaultStatus') ? partnerCredentialVaultStatus($key, $env) : 'missing';
        if ($vault === 'valid') {
            return $key;
        }
        if ($sandbox && function_exists('isGatewayConfigured') && isGatewayConfigured($key)) {
            return $key;
        }
    }
    return null;
}

/**
 * Gateway Status cards for Platform Settings (derived from Registry).
 *
 * @return list<array{id:string,label:string,test:bool}>
 */
function registryPlatformGatewayStatusCards(): array
{
    if (!function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $cards = [];
    foreach (registryCollectCapablePartnerKeys(false) as $key) {
        $meta = getPartnerRegistry()[$key] ?? [];
        $cards[] = [
            'id' => $key,
            'label' => (string)($meta['name'] ?? ucfirst($key)),
            'test' => true,
        ];
    }
    foreach (['axis', 'decentro'] as $extra) {
        if (!isset(getPartnerRegistry()[$extra])) {
            continue;
        }
        $already = false;
        foreach ($cards as $c) {
            if ($c['id'] === $extra) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $meta = getPartnerRegistry()[$extra];
            $cards[] = [
                'id' => $extra,
                'label' => (string)($meta['name'] ?? ucfirst($extra)),
                'test' => true,
            ];
        }
    }
    return $cards;
}
