<?php
declare(strict_types=1);

/**
 * Partner Registry v2 — Global Control Room (upgrade-first on gateway_registry).
 * Online payment collection partners only — no PPI / wallet product types.
 */

/** @return list<string> */
function partnerRegistryV2PartnerTypes(): array
{
    return ['pg', 'other_online'];
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
        "ALTER TABLE gateway_registry ADD COLUMN partner_type ENUM('pg','other_online') NOT NULL DEFAULT 'pg'",
        "ALTER TABLE gateway_registry ADD COLUMN contract_mode ENUM('platform','linked_existing','hybrid') NOT NULL DEFAULT 'platform'",
        'ALTER TABLE gateway_registry ADD COLUMN allows_existing_merchant_link TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_collect TINYINT(1) NOT NULL DEFAULT 1',
        'ALTER TABLE gateway_registry ADD COLUMN cap_upi TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_card TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_netbanking TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_refund TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_pay_later TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN cap_kyc_forward_api TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE gateway_registry ADD COLUMN doc_pack_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN partner_compliance_docs_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN policy_urls_json TEXT DEFAULT NULL',
        'ALTER TABLE gateway_registry ADD COLUMN routing_priority INT NOT NULL DEFAULT 50',
        'ALTER TABLE gateway_registry ADD COLUMN circuit_breaker_on TINYINT(1) NOT NULL DEFAULT 1',
        'ALTER TABLE gateway_registry ADD COLUMN connector_notes VARCHAR(255) DEFAULT NULL',
        "ALTER TABLE gateway_registry ADD COLUMN credential_test_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing'",
        "ALTER TABLE gateway_registry ADD COLUMN credential_live_status ENUM('missing','invalid','valid') NOT NULL DEFAULT 'missing'",
        'ALTER TABLE gateway_registry ADD COLUMN display_description VARCHAR(500) DEFAULT NULL',
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
        throw new InvalidArgumentException('Partner type not allowed. Use Payment Gateway or other online collect only.');
    }
    if (!in_array($raw, partnerRegistryV2PartnerTypes(), true)) {
        throw new InvalidArgumentException('Invalid partner type.');
    }
    return $raw;
}

function partnerRegistryV2NormalizeContractMode(string $raw): string
{
    $raw = strtolower(trim($raw));
    return in_array($raw, partnerRegistryV2ContractModes(), true) ? $raw : 'platform';
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
        'contract_mode' => (string)($row['contract_mode'] ?? 'platform'),
        'allows_existing_merchant_link' => !empty($row['allows_existing_merchant_link']),
        'connector_notes' => (string)($row['connector_notes'] ?? ''),
        'display_description' => (string)($row['display_description'] ?? ''),
        'capabilities' => [
            'collect' => !empty($row['cap_collect']),
            'upi' => !empty($row['cap_upi']),
            'card' => !empty($row['cap_card']),
            'netbanking' => !empty($row['cap_netbanking']),
            'refund' => !empty($row['cap_refund']),
            'pay_later' => !empty($row['cap_pay_later']),
            'kyc_forward_api' => !empty($row['cap_kyc_forward_api']),
        ],
        'doc_pack' => $docPack,
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
    ];
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
    if (partnerRegistryRowIsRetired($gatewayRow)) {
        return [
            'allowed' => false,
            'reason' => 'Retired',
            'warn_keys' => true,
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
        $dup = getDB()->prepare('SELECT id FROM gateway_registry WHERE gateway_key=? AND id<>? LIMIT 1');
        $dup->execute([$partnerCode, $gatewayId]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'message' => 'Partner code already exists. Choose a unique code.'];
        }
    }

    try {
        $partnerType = partnerRegistryV2NormalizePartnerType((string)($input['partner_type'] ?? 'pg'));
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    $displayName = trim((string)($input['display_name'] ?? $existing['gateway_name']));
    if ($displayName === '') {
        return ['ok' => false, 'message' => 'Display name is required.'];
    }

    $contractMode = partnerRegistryV2NormalizeContractMode((string)($input['contract_mode'] ?? 'platform'));
    $allowsLink = !empty($input['allows_existing_merchant_link']) ? 1 : 0;
    $routingPriority = max(1, min(999, (int)($input['routing_priority'] ?? 50)));
    $circuitOn = !empty($input['circuit_breaker_on']) ? 1 : 0;
    $connectorNotes = mb_substr(trim((string)($input['connector_notes'] ?? '')), 0, 255);
    $displayDescription = mb_substr(trim((string)($input['display_description'] ?? '')), 0, 500);
    $webhookUrl = mb_substr(trim((string)($input['webhook_url'] ?? '')), 0, 255) ?: null;
    $adapterClass = mb_substr(trim((string)($input['adapter_class'] ?? '')), 0, 120) ?: null;

    $caps = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
    $capCollect = !empty($caps['collect']) ? 1 : 0;
    $capUpi = !empty($caps['upi']) ? 1 : 0;
    $capCard = !empty($caps['card']) ? 1 : 0;
    $capNb = !empty($caps['netbanking']) ? 1 : 0;
    $capRefund = !empty($caps['refund']) ? 1 : 0;
    $capPayLater = !empty($caps['pay_later']) ? 1 : 0;
    $capKyc = !empty($caps['kyc_forward_api']) ? 1 : 0;

    $docPackRaw = $input['doc_pack'] ?? [];
    $docPack = is_array($docPackRaw) ? partnerRegistryV2FilterMerchantDocCodes($docPackRaw) : [];

    $complianceRaw = $input['partner_compliance_docs'] ?? [];
    $complianceDocs = is_array($complianceRaw) ? partnerRegistryV2FilterComplianceDocCodes($complianceRaw) : [];

    $policyIn = is_array($input['policy_urls'] ?? null) ? $input['policy_urls'] : [];
    $policyUrls = [
        'terms' => mb_substr(trim((string)($policyIn['terms'] ?? '')), 0, 500),
        'privacy' => mb_substr(trim((string)($policyIn['privacy'] ?? '')), 0, 500),
        'refund' => mb_substr(trim((string)($policyIn['refund'] ?? '')), 0, 500),
        'support' => mb_substr(trim((string)($policyIn['support'] ?? '')), 0, 500),
    ];

    $sql = 'UPDATE gateway_registry SET gateway_key=?, gateway_name=?, partner_type=?, contract_mode=?, allows_existing_merchant_link=?,
            cap_collect=?, cap_upi=?, cap_card=?, cap_netbanking=?, cap_refund=?, cap_pay_later=?, cap_kyc_forward_api=?,
            doc_pack_json=?, partner_compliance_docs_json=?, policy_urls_json=?, routing_priority=?, circuit_breaker_on=?, connector_notes=?, display_description=?,
            webhook_url=?, adapter_class=?, supports_collection=?, supports_refund=?, updated_at=NOW() WHERE id=?';
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
        $capKyc,
        $docPack === [] ? null : json_encode($docPack, JSON_UNESCAPED_UNICODE),
        $complianceDocs === [] ? null : json_encode($complianceDocs, JSON_UNESCAPED_UNICODE),
        json_encode($policyUrls, JSON_UNESCAPED_UNICODE),
        $routingPriority,
        $circuitOn,
        $connectorNotes !== '' ? $connectorNotes : null,
        $displayDescription !== '' ? $displayDescription : null,
        $webhookUrl,
        $adapterClass,
        $capCollect,
        $capRefund,
        $gatewayId,
    ]);

    $auditDetail = json_encode([
        'partner_code' => $partnerCode,
        'partner_type' => $partnerType,
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
    try {
        partnerRegistryV2NormalizePartnerType((string)($input['partner_type'] ?? 'pg'));
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }

    if (!function_exists('registerGateway')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $caps = is_array($input['capabilities'] ?? null) ? $input['capabilities'] : [];
    $result = registerGateway($partnerCode, $displayName, [
        'collection' => !empty($caps['collect']) ? 1 : (int)!empty($input['supports_collection']),
        'payout' => (int)!empty($input['supports_payout']),
        'refund' => !empty($caps['refund']) ? 1 : (int)!empty($input['supports_refund']),
        'recurring' => (int)!empty($input['supports_recurring']),
        'adapter' => trim((string)($input['adapter_class'] ?? '')) ?: null,
        'webhook_url' => trim((string)($input['webhook_url'] ?? '')) ?: null,
    ]);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => (string)($result['error'] ?? 'Could not register partner.')];
    }

    $gatewayId = (int)($result['gateway_id'] ?? 0);
    if ($gatewayId > 0) {
        $save = savePartnerRegistryProfile($gatewayId, $input, $adminId);
        if (empty($save['ok'])) {
            return $save;
        }
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
        . 'Activate turns routing ON. Keys Missing / Keys Valid / Keys Invalid come from saved keys and Test Connection. '
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
    if (partnerRegistryRowIsRetired($gatewayRow)) {
        return false;
    }
    if (!empty($gatewayRow['allows_existing_merchant_link'])) {
        return true;
    }
    $mode = strtolower(trim((string)($gatewayRow['contract_mode'] ?? 'platform')));
    return in_array($mode, ['linked_existing', 'hybrid'], true);
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
    if (!function_exists('getPartnerRegistryKeys')) {
        require_once __DIR__ . '/partner_engine.php';
    }
    $protected = array_values(array_unique(array_merge(
        function_exists('paymentMethodRegistryKeys') ? paymentMethodRegistryKeys() : [],
        ['payu', 'razorpay', 'cashfree', 'axis', 'decentro', 'phonepe', 'paytm', 'worldline', 'digio', 'rbl'],
        getPartnerRegistryKeys()
    )));
    if (in_array($key, $protected, true)) {
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
            $db->prepare('UPDATE gateway_registry SET is_active=0, retired_at=NOW(), retired_by=? WHERE id=? AND retired_at IS NULL')
                ->execute([$who !== '' ? $who : 'admin', $gatewayId]);
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
