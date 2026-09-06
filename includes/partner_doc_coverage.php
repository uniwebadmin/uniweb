<?php
declare(strict_types=1);

/**
 * Progressive merchant × partner document coverage (Phase 3).
 * Vault = existing kyc_documents. Pack codes = gateway_registry.doc_pack_json.
 * No partner API ACK. No PPI.
 */

function partnerDocCoverageStatusLabels(): array
{
    return [
        'not_started' => 'Not started',
        'docs_incomplete' => 'Documents incomplete',
        'docs_ready' => 'Documents ready',
    ];
}

function partnerDocCoverageVaultLatest(int $merchantId): array
{
    if ($merchantId < 1) {
        return [];
    }
    if (!function_exists('canonicalizeKycDocType') && is_file(__DIR__ . '/kyc_entity.php')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    try {
        $st = getDB()->prepare(
            'SELECT doc_type, status, scan_status, file_name, created_at
             FROM kyc_documents WHERE merchant_id=? ORDER BY created_at DESC'
        );
        $st->execute([$merchantId]);
        $latest = [];
        foreach ($st->fetchAll() as $row) {
            $canon = function_exists('canonicalizeKycDocType')
                ? canonicalizeKycDocType((string)($row['doc_type'] ?? ''))
                : strtolower(trim((string)($row['doc_type'] ?? '')));
            if ($canon === '' || isset($latest[$canon])) {
                continue;
            }
            $latest[$canon] = $row;
        }
        return $latest;
    } catch (Throwable $e) {
        return [];
    }
}

function partnerDocCoverageVaultSatisfies(?array $row): bool
{
    if (!$row) {
        return false;
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $scan = strtolower(trim((string)($row['scan_status'] ?? '')));
    if ($status === 'rejected' || $scan === 'infected' || $scan === 'missing') {
        return false;
    }
    return true;
}

function partnerDocCoverageVaultItemState(?array $row): string
{
    if (!$row) {
        return 'missing';
    }
    $status = strtolower(trim((string)($row['status'] ?? '')));
    $scan = strtolower(trim((string)($row['scan_status'] ?? '')));
    if ($status === 'rejected' || $scan === 'infected') {
        return 'rejected';
    }
    if ($status === 'approved') {
        return 'approved';
    }
    return 'pending';
}

function partnerDocCoverageItemStateLabel(string $state): string
{
    return match ($state) {
        'approved' => 'Approved (UniWeb)',
        'pending' => 'Uploaded — UniWeb review',
        'rejected' => 'Rejected — re-upload',
        default => 'Missing',
    };
}

/**
 * @return list<string>
 */
function partnerDocCoveragePackCodes(array $gatewayRow): array
{
    if (!function_exists('partnerRegistryV2ProfileFromRow')) {
        return [];
    }
    $profile = partnerRegistryV2ProfileFromRow($gatewayRow);
    $pack = $profile['doc_pack'] ?? [];
    if (!is_array($pack)) {
        return [];
    }
    if (!function_exists('partnerRegistryV2FilterMerchantDocCodes')) {
        return [];
    }
    if (!function_exists('kycDocsApplicableForEntity') && is_file(__DIR__ . '/kyc_entity.php')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    $pack = partnerRegistryV2FilterMerchantDocCodes($pack);
    $entityType = (string)($gatewayRow['_entity_type'] ?? '');
    if ($entityType !== '' && function_exists('kycDocsApplicableForEntity')) {
        $pack = kycDocsApplicableForEntity($entityType, $pack);
    }
    return $pack;
}

function partnerDocCoverageIsLinkedValid(?array $link): bool
{
    if (!$link) {
        return false;
    }
    $src = strtolower(trim((string)($link['account_source'] ?? '')));
    $cred = strtolower(trim((string)($link['credential_status'] ?? '')));
    $override = (int)($link['owner_override'] ?? 0) === 1;
    if ($override && in_array($src, ['linked', 'preexisting'], true)) {
        return true;
    }
    return in_array($src, ['linked', 'preexisting'], true) && $cred === 'valid';
}

/**
 * @return list<array<string,mixed>>
 */
function partnerDocCoveragePartnerRows(): array
{
    if (!function_exists('getRegisteredGateways')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    $out = [];
    foreach (getRegisteredGateways(false) as $g) {
        if ((int)($g['is_active'] ?? 0) !== 1) {
            continue;
        }
        if (function_exists('partnerRegistryRowIsRetired') && partnerRegistryRowIsRetired($g)) {
            continue;
        }
        $pack = partnerDocCoveragePackCodes($g);
        if ($pack === []) {
            continue;
        }
        $g['_pack'] = $pack;
        $out[] = $g;
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function partnerDocCoverageComputeOne(int $merchantId, array $gatewayRow, array $vault, ?array $link): array
{
    $key = strtolower(trim((string)($gatewayRow['gateway_key'] ?? '')));
    $pack = $gatewayRow['_pack'] ?? partnerDocCoveragePackCodes($gatewayRow);
    $entityType = (string)($gatewayRow['_entity_type'] ?? '');
    if ($entityType !== '' && function_exists('kycDocsApplicableForEntity')) {
        $pack = kycDocsApplicableForEntity($entityType, $pack);
    }
    $labels = function_exists('getKycDocLabels') ? getKycDocLabels() : [];
    $items = [];
    $present = 0;
    $missingCodes = [];
    foreach ($pack as $code) {
        $row = $vault[$code] ?? null;
        $state = partnerDocCoverageVaultItemState($row);
        $ok = partnerDocCoverageVaultSatisfies($row);
        if ($ok) {
            $present++;
        } else {
            $missingCodes[] = $code;
        }
        $items[] = [
            'code' => $code,
            'label' => (string)($labels[$code] ?? ucwords(str_replace('_', ' ', $code))),
            'state' => $state,
            'state_label' => partnerDocCoverageItemStateLabel($state),
            'ready' => $ok,
        ];
    }
    $total = count($pack);
    if ($total === 0) {
        $status = 'not_started';
        $pct = 0;
    } elseif ($present === 0) {
        $status = 'not_started';
        $pct = 0;
    } elseif ($present < $total) {
        $status = 'docs_incomplete';
        $pct = (int)round(100 * $present / $total);
    } else {
        $status = 'docs_ready';
        $pct = 100;
    }
    $linkedValid = partnerDocCoverageIsLinkedValid($link);
    $enableVia = '';
    if ($linkedValid) {
        $enableVia = 'linked';
    } elseif ($status === 'docs_ready') {
        $enableVia = 'docs';
    }
    $statusLabels = partnerDocCoverageStatusLabels();
    return [
        'partner_key' => $key,
        'partner_name' => (string)($gatewayRow['gateway_name'] ?? $key),
        'status' => $status,
        'status_label' => $statusLabels[$status] ?? $status,
        'percent' => $pct,
        'present' => $present,
        'total' => $total,
        'missing' => $missingCodes,
        'items' => $items,
        'linked_valid' => $linkedValid,
        'enable_allowed' => $enableVia !== '',
        'enable_via' => $enableVia,
        'checkout_enabled' => $link ? ((int)($link['checkout_enabled'] ?? 0) === 1) : false,
        'honest_note' => 'UniWeb document check only. Not sent to the partner. Not partner-approved.',
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function partnerDocCoverageForMerchant(int $merchantId): array
{
    if ($merchantId < 1) {
        return [];
    }
    if (!function_exists('ensurePartnerControlTables')) {
        require_once __DIR__ . '/partner_control.php';
    }
    ensurePartnerControlTables();
    $entityType = 'sole_proprietorship';
    try {
        $st = getDB()->prepare('SELECT business_entity_type FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $entityType = (string)$st->fetchColumn();
    } catch (Throwable $e) {
        $entityType = 'sole_proprietorship';
    }
    if (function_exists('normalizeKycEntityType')) {
        $entityType = normalizeKycEntityType($entityType);
    }
    $vault = partnerDocCoverageVaultLatest($merchantId);
    $links = [];
    foreach (function_exists('getMerchantPartnerLinks') ? getMerchantPartnerLinks($merchantId) : [] as $lr) {
        $links[strtolower((string)$lr['partner_key'])] = $lr;
    }
    $rows = [];
    foreach (partnerDocCoveragePartnerRows() as $g) {
        $g['_entity_type'] = $entityType;
        $key = strtolower((string)$g['gateway_key']);
        $one = partnerDocCoverageComputeOne($merchantId, $g, $vault, $links[$key] ?? null);
        if ((int)$one['total'] === 0 && empty($one['linked_valid'])) {
            continue;
        }
        $rows[] = $one;
    }
    return $rows;
}

/**
 * @return list<string>
 */
function merchantKycAllowedUploadTypes(int $merchantId, array $merchant): array
{
    if (!function_exists('getKycRequirements') && is_file(__DIR__ . '/kyc_entity.php')) {
        require_once __DIR__ . '/kyc_entity.php';
    }
    $types = [];
    $entityType = (string)($merchant['business_entity_type'] ?? 'sole_proprietorship');
    if (function_exists('getKycRequirements')) {
        $types = getKycRequirements($entityType);
    }
    foreach (partnerDocCoverageForMerchant($merchantId) as $row) {
        foreach ($row['items'] as $item) {
            $code = (string)$item['code'];
            if ($code === '') {
                continue;
            }
            if (function_exists('kycDocsApplicableForEntity') && kycDocsApplicableForEntity($entityType, [$code]) === []) {
                continue;
            }
            $types[] = $code;
        }
    }
    $types = array_values(array_unique(array_filter(array_map('strval', $types))));
    return array_values(array_filter($types, static fn(string $c): bool => $c !== 'video_kyc'));
}

function persistMerchantPartnerDocCoverage(int $merchantId): void
{
    if ($merchantId < 1 || !function_exists('ensurePartnerControlTables')) {
        return;
    }
    ensurePartnerControlTables();
    try {
        $has = (bool)getDB()->query("SHOW COLUMNS FROM partner_merchant_links LIKE 'coverage_status'")->fetch();
        if (!$has) {
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    foreach (partnerDocCoverageForMerchant($merchantId) as $row) {
        $key = (string)$row['partner_key'];
        $status = (string)$row['status'];
        try {
            getDB()->prepare(
                "INSERT INTO partner_merchant_links (merchant_id, partner_key, coverage_status, coverage_updated_at, kyc_status)
                 VALUES (?,?,?,?, 'pending')
                 ON DUPLICATE KEY UPDATE coverage_status=VALUES(coverage_status), coverage_updated_at=VALUES(coverage_updated_at)"
            )->execute([$merchantId, $key, $status, date('Y-m-d H:i:s')]);
        } catch (Throwable $e) {
            try {
                getDB()->prepare(
                    'UPDATE partner_merchant_links SET coverage_status=?, coverage_updated_at=NOW() WHERE merchant_id=? AND partner_key=?'
                )->execute([$status, $merchantId, $key]);
            } catch (Throwable $e2) { /* column lag */ }
        }
    }
}

function merchantPartnerMayEnableFromCoverage(int $merchantId, string $partnerKey): array
{
    $partnerKey = strtolower(trim($partnerKey));
    foreach (partnerDocCoverageForMerchant($merchantId) as $row) {
        if ($row['partner_key'] === $partnerKey) {
            if (!empty($row['enable_allowed'])) {
                return ['ok' => true, 'via' => (string)$row['enable_via']];
            }
            return ['ok' => false, 'error' => 'Enable is not available until documents are ready (or already-live keys are Valid).'];
        }
    }
    return ['ok' => false, 'error' => 'No document pack for this partner.'];
}

/**
 * Platform enable from docs (or Linked+Valid skip). Not a partner ACK.
 *
 * @param array{actor_role?:string} $actor
 */
function setMerchantCoverageCheckoutEnabled(int $merchantId, string $partnerKey, bool $enabled, array $actor = []): array
{
    if ($merchantId < 1) {
        return ['ok' => false, 'error' => 'Invalid merchant.'];
    }
    $partnerKey = strtolower(trim($partnerKey));
    if (!function_exists('ensurePartnerControlTables')) {
        require_once __DIR__ . '/partner_control.php';
    }
    ensurePartnerControlTables();
    if ($enabled) {
        $gate = merchantPartnerMayEnableFromCoverage($merchantId, $partnerKey);
        if (empty($gate['ok'])) {
            return ['ok' => false, 'error' => (string)($gate['error'] ?? 'Not available.')];
        }
    }
    persistMerchantPartnerDocCoverage($merchantId);
    $row = function_exists('getMerchantPartnerLinkRow') ? getMerchantPartnerLinkRow($merchantId, $partnerKey) : null;
    try {
        if ($row) {
            getDB()->prepare('UPDATE partner_merchant_links SET checkout_enabled=? WHERE merchant_id=? AND partner_key=?')
                ->execute([$enabled ? 1 : 0, $merchantId, $partnerKey]);
        } else {
            getDB()->prepare(
                "INSERT INTO partner_merchant_links (merchant_id, partner_key, account_source, checkout_enabled, kyc_status, coverage_status)
                 VALUES (?,?, 'platform', ?, 'pending', 'docs_ready')"
            )->execute([$merchantId, $partnerKey, $enabled ? 1 : 0]);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update enable flag.'];
    }
    if (function_exists('recordImmutableAudit')) {
        $who = (string)($actor['actor_role'] ?? 'merchant');
        recordImmutableAudit(
            $enabled ? 'merchant_coverage_checkout_on' : 'merchant_coverage_checkout_off',
            $merchantId,
            'partner',
            $partnerKey,
            'docs coverage enable=' . ($enabled ? '1' : '0') . ' by ' . $who . ' (not partner ACK)'
        );
    }
    return ['ok' => true];
}
