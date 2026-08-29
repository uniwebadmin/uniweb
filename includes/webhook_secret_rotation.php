<?php
declare(strict_types=1);

/**
 * Partner webhook signing secret rotation — primary + previous with grace window.
 * Secrets stored in encrypted partner_credentials payload (never logged or echoed).
 */

if (!function_exists('getPartnerCredentials') && is_file(__DIR__ . '/partner_control.php')) {
    require_once __DIR__ . '/partner_control.php';
}

/** Default grace after rotation — both primary and previous verify inbound webhooks. */
function webhookSecretRotationGraceSeconds(): int
{
    return 172800; // 48 hours
}

/** @return array<string, string> partner_key => primary credential key name */
function partnerWebhookSigningSecretKeyMap(): array
{
    return [
        'razorpay' => 'razorpay_webhook_secret',
        'cashfree' => 'cashfree_secret_key',
        'payu' => 'payu_merchant_salt',
        'decentro' => 'decentro_client_secret',
        'axis' => 'axis_webhook_secret',
    ];
}

function partnerWebhookSigningSecretKey(string $partnerKey): ?string
{
    $map = partnerWebhookSigningSecretKeyMap();
    $k = strtolower(trim($partnerKey));
    return $map[$k] ?? null;
}

function partnerWebhookSecretPreviousKey(string $primaryKey): string
{
    return $primaryKey . '_previous';
}

function partnerWebhookSecretPreviousUntilKey(string $primaryKey): string
{
    return $primaryKey . '_previous_until';
}

function partnerCredentialEnvForPartner(string $partnerKey): string
{
    if (!function_exists('partnerCredentialEnvBucket')) {
        return 'test';
    }
    return partnerCredentialEnvBucket($partnerKey);
}

/** Resolve live primary webhook secret (Razorpay falls back to key secret when webhook secret unset). */
function partnerWebhookPrimarySecret(string $partnerKey, ?array $creds = null): string
{
    $partnerKey = strtolower(trim($partnerKey));
    $primaryKey = partnerWebhookSigningSecretKey($partnerKey);
    if ($primaryKey === null) {
        return '';
    }
    if ($creds === null) {
        $env = partnerCredentialEnvForPartner($partnerKey);
        $creds = getPartnerCredentials($partnerKey, $env);
        if (empty($creds) && $env === 'live') {
            $creds = getPartnerCredentials($partnerKey, 'production');
        }
        if (empty($creds)) {
            $creds = getPartnerCredentials($partnerKey, 'test');
        }
    }
    unset($creds['_last4']);
    $primary = trim((string)($creds[$primaryKey] ?? ''));
    if ($primary === '' && $partnerKey === 'razorpay') {
        $primary = trim((string)($creds['razorpay_key_secret'] ?? ''));
    }
    if ($primary === '' && function_exists('getPartnerSetting')) {
        $primary = trim((string)getPartnerSetting($partnerKey, $primaryKey, ''));
        if ($primary === '' && $partnerKey === 'razorpay') {
            $primary = trim((string)getPartnerSetting($partnerKey, 'razorpay_key_secret', ''));
        }
    }
    return $primary;
}

/** @return list<string> primary then previous (if still inside grace), deduped */
function partnerWebhookSecretCandidates(string $partnerKey, ?array $creds = null): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $primaryKey = partnerWebhookSigningSecretKey($partnerKey);
    if ($primaryKey === null) {
        return [];
    }

    if ($creds === null) {
        $env = partnerCredentialEnvForPartner($partnerKey);
        $creds = getPartnerCredentials($partnerKey, $env);
        if (empty($creds) && $env === 'live') {
            $creds = getPartnerCredentials($partnerKey, 'production');
        }
        if (empty($creds)) {
            $creds = getPartnerCredentials($partnerKey, 'test');
        }
    }
    unset($creds['_last4']);

    clearExpiredPartnerWebhookPreviousSecrets($partnerKey);

    $out = [];
    $primary = partnerWebhookPrimarySecret($partnerKey, $creds);
    if ($primary !== '') {
        $out[] = $primary;
    }

    $prevKey = partnerWebhookSecretPreviousKey($primaryKey);
    $untilKey = partnerWebhookSecretPreviousUntilKey($primaryKey);
    $previous = trim((string)($creds[$prevKey] ?? ''));
    $untilRaw = trim((string)($creds[$untilKey] ?? ''));
    if ($previous !== '' && $untilRaw !== '') {
        $untilTs = strtotime($untilRaw);
        if ($untilTs !== false && time() <= $untilTs && !in_array($previous, $out, true)) {
            $out[] = $previous;
        }
    }

    return $out;
}

/**
 * @param callable(string $secret): bool $verifyFn
 */
function verifyWithPartnerWebhookSecrets(string $partnerKey, callable $verifyFn): bool
{
    foreach (partnerWebhookSecretCandidates($partnerKey) as $secret) {
        if ($verifyFn($secret)) {
            return true;
        }
    }
    return false;
}

/** Patch encrypted partner_credentials without requiring full key form submit. */
function patchPartnerCredentials(string $partnerKey, string $env, array $patch): bool
{
    if ($patch === []) {
        return false;
    }
    if (!function_exists('ensurePartnerControlTables')) {
        return false;
    }
    ensurePartnerControlTables();
    $existing = getPartnerCredentials($partnerKey, $env);
    unset($existing['_last4']);
    $payload = is_array($existing) ? $existing : [];
    foreach ($patch as $k => $v) {
        if (!is_string($k) || $k === '_last4') {
            continue;
        }
        $payload[$k] = is_string($v) ? $v : (string)$v;
    }
    if ($payload === []) {
        return false;
    }
    $last4 = (string)($existing['_last4'] ?? '');
    foreach ($payload as $key => $val) {
        if (!is_string($val) || $val === '') {
            continue;
        }
        if (str_contains((string)$key, 'secret') || str_contains((string)$key, 'salt')) {
            $last4 = substr($val, -4);
            break;
        }
    }
    $encrypted = function_exists('sensitiveEncrypt')
        ? sensitiveEncrypt(json_encode($payload))
        : base64_encode(json_encode($payload));
    getDB()->prepare(
        'INSERT INTO partner_credentials (partner_key, env, encrypted_payload, last4) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE encrypted_payload=VALUES(encrypted_payload), last4=VALUES(last4)'
    )->execute([$partnerKey, $env, $encrypted, $last4]);
    return true;
}

/**
 * Rotate inbound webhook signing secret — transactional; primary kept on failure.
 *
 * @return array{ok:bool,message:string,grace_until?:string}
 */
function rotatePartnerWebhookSigningSecret(string $partnerKey, string $env, string $newPrimary, ?int $staffId = null): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $newPrimary = trim($newPrimary);
    if ($newPrimary === '') {
        return ['ok' => false, 'message' => 'New webhook secret is required. Paste from the partner dashboard.'];
    }
    if (!in_array($env, ['test', 'live', 'production'], true)) {
        $env = 'test';
    }
    $primaryKey = partnerWebhookSigningSecretKey($partnerKey);
    if ($primaryKey === null) {
        return ['ok' => false, 'message' => 'This partner does not support webhook secret rotation here.'];
    }

    $creds = getPartnerCredentials($partnerKey, $env);
    unset($creds['_last4']);
    $currentPrimary = partnerWebhookPrimarySecret($partnerKey, $creds);
    if ($currentPrimary === '') {
        // First-time set — no previous grace needed
        $patch = [$primaryKey => $newPrimary];
        if (!patchPartnerCredentials($partnerKey, $env, $patch)) {
            return ['ok' => false, 'message' => 'Could not save webhook secret.'];
        }
        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('webhook_secret_rotated', $staffId, 'partner', $partnerKey, $env . ' initial webhook secret set');
        }
        return ['ok' => true, 'message' => 'Webhook secret saved.'];
    }

    if (cryptoTimingSafeEqual($currentPrimary, $newPrimary)) {
        return ['ok' => false, 'message' => 'New secret matches the current secret. No rotation performed.'];
    }

    $graceUntil = date('Y-m-d H:i:s', time() + webhookSecretRotationGraceSeconds());
    $prevKey = partnerWebhookSecretPreviousKey($primaryKey);
    $untilKey = partnerWebhookSecretPreviousUntilKey($primaryKey);
    $patch = [
        $primaryKey => $newPrimary,
        $prevKey => $currentPrimary,
        $untilKey => $graceUntil,
    ];
    if (!patchPartnerCredentials($partnerKey, $env, $patch)) {
        return ['ok' => false, 'message' => 'Rotation failed — current secret unchanged.'];
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('webhook_secret_rotated', $staffId, 'partner', $partnerKey, $env . ' rotated; previous valid until ' . $graceUntil);
    }
    return ['ok' => true, 'message' => 'Webhook secret rotated. Previous secret accepts signatures until ' . $graceUntil . ' IST.', 'grace_until' => $graceUntil];
}

/** @return array{ok:bool,message:string} */
function clearPartnerWebhookPreviousSecret(string $partnerKey, string $env, ?int $staffId = null): array
{
    $primaryKey = partnerWebhookSigningSecretKey(strtolower(trim($partnerKey)));
    if ($primaryKey === null) {
        return ['ok' => false, 'message' => 'Partner not supported.'];
    }
    $prevKey = partnerWebhookSecretPreviousKey($primaryKey);
    $untilKey = partnerWebhookSecretPreviousUntilKey($primaryKey);
    if (!patchPartnerCredentials($partnerKey, $env, [$prevKey => '', $untilKey => ''])) {
        return ['ok' => false, 'message' => 'Could not clear previous secret.'];
    }
    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('webhook_secret_previous_cleared', $staffId, 'partner', $partnerKey, $env);
    }
    return ['ok' => true, 'message' => 'Previous webhook secret cleared. Only the current secret verifies now.'];
}

/** Lazy expiry cleanup — returns rows cleared. */
function clearExpiredPartnerWebhookPreviousSecrets(?string $partnerKey = null): int
{
    $cleared = 0;
    $map = partnerWebhookSigningSecretKeyMap();
    $partners = $partnerKey !== null ? [strtolower(trim($partnerKey))] : array_keys($map);
    foreach ($partners as $pk) {
        $primaryKey = $map[$pk] ?? null;
        if ($primaryKey === null) {
            continue;
        }
        foreach (['test', 'live', 'production'] as $env) {
            $creds = getPartnerCredentials($pk, $env);
            if ($creds === []) {
                continue;
            }
            unset($creds['_last4']);
            $prevKey = partnerWebhookSecretPreviousKey($primaryKey);
            $untilKey = partnerWebhookSecretPreviousUntilKey($primaryKey);
            $previous = trim((string)($creds[$prevKey] ?? ''));
            $untilRaw = trim((string)($creds[$untilKey] ?? ''));
            if ($previous === '' || $untilRaw === '') {
                continue;
            }
            $untilTs = strtotime($untilRaw);
            if ($untilTs !== false && time() > $untilTs) {
                if (patchPartnerCredentials($pk, $env, [$prevKey => '', $untilKey => ''])) {
                    $cleared++;
                }
            }
        }
    }
    return $cleared;
}

/** @return array{primary_configured:bool,previous_active:bool,grace_until:?string,primary_key:string} */
function partnerWebhookRotationStatus(string $partnerKey, string $env = 'live'): array
{
    $partnerKey = strtolower(trim($partnerKey));
    $primaryKey = partnerWebhookSigningSecretKey($partnerKey) ?? '';
    $creds = getPartnerCredentials($partnerKey, $env);
    unset($creds['_last4']);
    $primary = partnerWebhookPrimarySecret($partnerKey, $creds);
    $prevKey = partnerWebhookSecretPreviousKey($primaryKey);
    $untilKey = partnerWebhookSecretPreviousUntilKey($primaryKey);
    $previous = trim((string)($creds[$prevKey] ?? ''));
    $untilRaw = trim((string)($creds[$untilKey] ?? ''));
    $untilTs = $untilRaw !== '' ? strtotime($untilRaw) : false;
    $previousActive = $previous !== '' && $untilTs !== false && time() <= $untilTs;
    return [
        'primary_configured' => $primary !== '',
        'previous_active' => $previousActive,
        'grace_until' => $previousActive ? $untilRaw : null,
        'primary_key' => $primaryKey,
    ];
}
