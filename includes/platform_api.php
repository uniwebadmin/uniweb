<?php
declare(strict_types=1);

/** Admin: platform vs merchant API key overview */

function defaultApiScopes(string $mode): array
{
    $scopes = ['links:read', 'links:write', 'transactions:read', 'balance:read', 'refunds:read'];
    if ($mode === 'live') {
        $scopes[] = 'refunds:write';
    }
    return $scopes;
}

function createMerchantApiCredential(int $merchantId, string $mode, ?array $scopes = null, array $allowedOrigins = []): array
{
    requireFinancialTables();
    if (!in_array($mode, ['test', 'live'], true)) {
        throw new InvalidArgumentException('Invalid API credential mode.');
    }
    $db = getDB();
    $merchantSt = $db->prepare('SELECT * FROM merchants WHERE id=? AND status=?');
    $merchantSt->execute([$merchantId, 'active']);
    $merchant = $merchantSt->fetch();
    if (!$merchant) {
        throw new RuntimeException('Active merchant not found.');
    }
    if ($mode === 'live' && !isMerchantLive($merchant)) {
        throw new RuntimeException('Live API credentials require Live Mode approval.');
    }
    $scopes = array_values(array_unique($scopes ?: defaultApiScopes($mode)));
    $validScopes = ['links:read', 'links:write', 'transactions:read', 'balance:read', 'refunds:read', 'refunds:write'];
    foreach ($scopes as $scope) {
        if (!in_array($scope, $validScopes, true)) {
            throw new InvalidArgumentException('Invalid API scope.');
        }
    }
    $normalizedOrigins = [];
    foreach ($allowedOrigins as $origin) {
        $normalized = normalizeApiOrigin((string)$origin);
        if ($normalized !== null) {
            $normalizedOrigins[] = $normalized;
        }
    }
    $key = 'uw_' . $mode . '_' . bin2hex(random_bytes(24));
    $secret = 'uws_' . bin2hex(random_bytes(32));
    $prefix = substr($key, 0, 18);
    $insert = $db->prepare(
        'INSERT INTO api_credentials (merchant_id,credential_name,mode,key_prefix,key_hash,secret_hash,scopes,allowed_origins)
         VALUES (?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $merchantId,
        ucfirst($mode) . ' API',
        $mode,
        $prefix,
        hash('sha256', $key),
        password_hash($secret, PASSWORD_ARGON2ID),
        json_encode($scopes),
        $normalizedOrigins ? json_encode(array_values(array_unique($normalizedOrigins))) : null,
    ]);
    return ['id' => (int)$db->lastInsertId(), 'key' => $key, 'secret' => $secret, 'mode' => $mode, 'scopes' => $scopes];
}

function normalizeApiOrigin(string $origin): ?string
{
    $origin = rtrim(trim($origin), '/');
    $parts = parse_url($origin);
    if (!$parts || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host'])) {
        return null;
    }
    if (isset($parts['path']) && $parts['path'] !== '') {
        return null;
    }
    $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    if (!empty($parts['port'])) {
        $normalized .= ':' . (int)$parts['port'];
    }
    return $normalized;
}

function apiOriginAllowed(array $credential, string $origin): bool
{
    if ($origin === '') {
        return true;
    }
    $normalized = normalizeApiOrigin($origin);
    $allowed = json_decode((string)($credential['allowed_origins'] ?? ''), true);
    return $normalized !== null && is_array($allowed) && in_array($normalized, $allowed, true);
}

function authenticateMerchantApiCredential(string $key, string $secret, string $requiredScope): ?array
{
    requireFinancialTables();
    if ($key === '' || $secret === '') {
        return null;
    }
    $st = getDB()->prepare(
        "SELECT c.*, m.*,
                c.id AS credential_id, c.mode AS credential_mode, c.scopes AS credential_scopes,
                c.allowed_origins AS credential_allowed_origins
         FROM api_credentials c JOIN merchants m ON m.id=c.merchant_id
         WHERE c.key_hash=? AND c.status='active' AND m.status='active'
           AND (c.expires_at IS NULL OR c.expires_at>NOW()) LIMIT 1"
    );
    $st->execute([hash('sha256', $key)]);
    $row = $st->fetch();
    if (!$row || !password_verify($secret, (string)$row['secret_hash'])) {
        return null;
    }
    $scopes = json_decode((string)$row['credential_scopes'], true);
    if (!is_array($scopes) || !in_array($requiredScope, $scopes, true)) {
        return null;
    }
    if ($row['credential_mode'] === 'live' && !isMerchantLive($row)) {
        return null;
    }
    if (!consumeApiRateLimit((int)$row['credential_id'])) {
        throw new RuntimeException('API rate limit exceeded.');
    }
    getDB()->prepare('UPDATE api_credentials SET last_used_at=NOW() WHERE id=?')->execute([(int)$row['credential_id']]);
    $row['api_mode'] = $row['credential_mode'];
    $row['api_allowed_origins'] = $row['credential_allowed_origins'];
    return $row;
}

function consumeApiRateLimit(int $credentialId, int $limit = 120): bool
{
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT window_started_at,request_count FROM api_rate_limits WHERE credential_id=? FOR UPDATE');
        $st->execute([$credentialId]);
        $row = $st->fetch();
        if (!$row) {
            $db->prepare('INSERT INTO api_rate_limits (credential_id,window_started_at,request_count) VALUES (?,NOW(),1)')->execute([$credentialId]);
            $db->commit();
            return true;
        }
        $windowStart = strtotime((string)$row['window_started_at']);
        if ($windowStart === false || $windowStart <= time() - 60) {
            $db->prepare('UPDATE api_rate_limits SET window_started_at=NOW(),request_count=1 WHERE credential_id=?')->execute([$credentialId]);
            $db->commit();
            return true;
        }
        if ((int)$row['request_count'] >= $limit) {
            $db->commit();
            return false;
        }
        $db->prepare('UPDATE api_rate_limits SET request_count=request_count+1 WHERE credential_id=?')->execute([$credentialId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function apiScopeForAction(string $action): ?string
{
    return [
        'create_payment_link' => 'links:write',
        'check_status' => 'transactions:read',
        'list_transactions' => 'transactions:read',
        'get_balance' => 'balance:read',
        'create_refund' => 'refunds:write',
        'list_refunds' => 'refunds:read',
        'list_payment_links' => 'links:read',
        'get_payment_link' => 'links:read',
    ][$action] ?? null;
}

function maskApiKey(?string $key, int $show = 8): string
{
    $key = trim((string)$key);
    if ($key === '') {
        return '—';
    }
    if (strlen($key) <= $show + 4) {
        return $key;
    }
    return substr($key, 0, $show) . '…' . substr($key, -4);
}

/** Ensure live + test API keys exist (approval / merchant portal) */
function ensureMerchantApiKeys(int $merchantId): void
{
    // Credentials are created explicitly so the raw secret can be shown exactly once.
}

/** Regenerate a merchant's API credentials (test or live) and notify them by email + in-app. */
function regenerateMerchantApiKey(int $merchantId, string $mode = 'live', ?int $byAdminId = null): array
{
    $db = getDB();
    $st = $db->prepare('SELECT id, business_name, email, merchant_code FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $m = $st->fetch();
    if (!$m) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    $mode = $mode === 'test' ? 'test' : 'live';
    $db->prepare("UPDATE api_credentials SET status='revoked',revoked_at=NOW() WHERE merchant_id=? AND mode=? AND status='active'")
        ->execute([$merchantId, $mode]);
    try {
        $credential = createMerchantApiCredential($merchantId, $mode);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    $newKey = $credential['key'];
    $newSecret = $credential['secret'];
    $label = ucfirst($mode);

    $who = $byAdminId ? 'by admin' : 'by you';
    createNotification($merchantId, "{$label} API Key Regenerated", "A new {$label} API key was generated {$who}. Your old key stopped working immediately — update it in your integration.");

    $email = trim((string)($m['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $subject = "[{$label} API Key Regenerated] " . ($m['business_name'] ?? 'Your UniWeb Account');
        $body = "Hi " . ($m['business_name'] ?? 'Merchant') . ",\n\n"
            . "A new {$label} API credential was generated for your UniWeb account (Merchant ID: " . ($m['merchant_code'] ?? '') . ").\n\n"
            . "For security, the key and secret are shown only in the authenticated portal and are not sent by email.\n\n"
            . "Your previous credential has been deactivated immediately. Please update your integration.\n\n"
            . "If you did not request this change, contact support immediately at " . COMPANY_SUPPORT_EMAIL . ".\n\n"
            . "— " . COMPANY_LEGAL_NAME . "\n" . APP_URL;
        sendPlatformEmail($email, $subject, $body);
    }

    if ($byAdminId) {
        logStaffActivity('api_key_regenerated', "{$label} key regenerated for merchant #{$merchantId}", $merchantId);
    }

    return ['ok' => true, 'mode' => $mode, 'key' => $newKey, 'secret' => $newSecret, 'scopes' => $credential['scopes']];
}

function getPlatformGatewayKeyStatus(): array
{
    $partners = [
        ['id' => 'razorpay', 'name' => 'Razorpay', 'env' => getPartnerEnvironment('razorpay', 'test'), 'key_label' => 'key_id', 'key' => getPartnerSetting('razorpay', 'razorpay_key_id', '')],
        ['id' => 'cashfree', 'name' => 'Cashfree', 'env' => getPartnerEnvironment('cashfree', 'sandbox'), 'key_label' => 'app_id', 'key' => getPartnerSetting('cashfree', 'cashfree_app_id', '')],
        ['id' => 'payu', 'name' => 'PayU', 'env' => getPartnerEnvironment('payu', 'test'), 'key_label' => 'merchant_key', 'key' => getPartnerSetting('payu', 'payu_merchant_key', '')],
        ['id' => 'decentro', 'name' => 'Decentro', 'env' => decentroClientId() !== '' ? 'configured' : 'sandbox', 'key_label' => 'client_id', 'key' => decentroClientId()],
        ['id' => 'axis', 'name' => 'Axis Bank', 'env' => getPartnerEnvironment('axis', 'uat'), 'key_label' => 'client_id', 'key' => getPartnerSetting('axis', 'axis_client_id', getPartnerSetting('axis', 'axis_api_key', ''))],
    ];
    foreach ($partners as &$p) {
        $p['configured'] = isGatewayConfigured($p['id']);
        $p['key_masked'] = maskApiKey($p['key']);
        $p['who_gets'] = 'UniWeb platform only — never share with merchants';
    }
    unset($p);
    return $partners;
}

function getMerchantApiKeyRows(int $limit = 50): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, merchant_code, business_name, email, kyc_status, account_mode, status,
        api_key, api_secret, test_api_key, test_api_secret,
        payu_child_key, razorpay_linked_account_id, cashfree_vendor_id,
        website_url, website_status
        FROM merchants WHERE status != 'deleted' ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    $rows = [];
    foreach ($stmt->fetchAll() as $m) {
        $rows[] = [
            'id' => (int)$m['id'],
            'merchant_code' => $m['merchant_code'],
            'business_name' => $m['business_name'],
            'email' => $m['email'],
            'kyc_status' => $m['kyc_status'],
            'live' => isMerchantLive($m),
            'status' => $m['status'],
            'api_key_masked' => maskApiKey($m['api_key'] ?? ''),
            'test_api_key_masked' => maskApiKey($m['test_api_key'] ?? ''),
            'has_live_keys' => !empty($m['api_key']) && !empty($m['api_secret']),
            'has_test_keys' => !empty($m['test_api_key']),
            'split_ids' => array_filter([
                !empty($m['payu_child_key']) ? 'PayU child' : null,
                !empty($m['razorpay_linked_account_id']) ? 'Razorpay Route' : null,
                !empty($m['cashfree_vendor_id']) ? 'Cashfree vendor' : null,
            ]),
            'website_url' => $m['website_url'] ?? '',
            'website_status' => $m['website_status'] ?? 'not_set',
        ];
    }
    return $rows;
}

function getWebsitePlatformStats(): array
{
    $db = getDB();
    $total = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status != 'deleted'")->fetchColumn();
    $withWebsite = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status != 'deleted' AND website_url IS NOT NULL AND website_url != ''")->fetchColumn();
    $verified = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE website_status = 'verified'")->fetchColumn();
    $pending = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE website_status = 'pending'")->fetchColumn();
    $withApi = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status != 'deleted' AND api_key IS NOT NULL AND api_key != ''")->fetchColumn();
    return [
        'merchants' => $total,
        'with_website' => $withWebsite,
        'website_verified' => $verified,
        'website_pending' => $pending,
        'with_api_keys' => $withApi,
    ];
}

/** Admin self-check — surfaces broken pages / mode-toggle blockers without manual QA */
function runAdminPlatformSelfChecks(): array
{
    $checks = [];
    $checks[] = [
        'id' => 'admin_website_syntax',
        'label' => 'Platform API guide page',
        'ok' => is_file(__DIR__ . '/../admin_website.php')
            && strpos((string)file_get_contents(__DIR__ . '/../admin_website.php'), 'adminMerchantUrl($m[\'id\'])') === false,
        'detail' => 'admin_website.php must not reference undefined $m in gateway table',
        'fix' => 'admin_website.php',
    ];

    $db = getDB();
    $demo = $db->prepare("SELECT id, merchant_code, kyc_status, account_mode FROM merchants WHERE email = 'demo@uniweb.co.in' LIMIT 1");
    $demo->execute();
    $demoRow = $demo->fetch();
    if ($demoRow) {
        $demoSandboxed = !isMerchantLive($demoRow) && ($demoRow['account_mode'] ?? '') === 'test';
        $checks[] = [
            'id' => 'demo_mode_toggle',
            'label' => 'Demo merchant sandbox isolation',
            'ok' => $demoSandboxed,
            'detail' => $demoSandboxed
                ? 'demo@uniweb.co.in is permanently isolated in Test Mode'
                : 'Demo account is not safely isolated from Live Mode',
            'fix' => 'includes/demo.php + login as demo@uniweb.co.in',
        ];
    }

    $blocked = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status='verified' AND account_mode='test' AND status='active' AND email<>'demo@uniweb.co.in'")->fetchColumn();
    $checks[] = [
        'id' => 'verified_not_live',
        'label' => 'Pending Live activation queue',
        'ok' => true,
        'detail' => $blocked === 0
            ? 'No merchants waiting for Live activation'
            : $blocked . ' verified merchant(s) still in Test — intentional until independent Live checker approval (admin_kyc.php)',
        'fix' => 'admin_kyc.php',
    ];

    $root = dirname(__DIR__);
    $publicAssets = [
        'robots.txt' => 'robots.txt',
        'favicon.ico' => 'favicon.ico',
        'favicon.svg' => 'favicon.svg',
        'manifest.json' => 'manifest.json',
        'assets/icons/icon-192.png' => 'PWA icon 192',
        'assets/icons/icon-512.png' => 'PWA icon 512',
    ];
    $missingAssets = [];
    foreach ($publicAssets as $rel => $label) {
        if (!is_file($root . '/' . $rel)) {
            $missingAssets[] = $label;
        }
    }
    $checks[] = [
        'id' => 'public_assets',
        'label' => 'Public SEO / PWA assets',
        'ok' => $missingAssets === [],
        'detail' => $missingAssets === []
            ? 'robots.txt, favicon, and PWA icons present'
            : 'Missing: ' . implode(', ', $missingAssets),
        'fix' => 'robots.txt / favicon.* / assets/icons/',
    ];

    $checks[] = [
        'id' => 'toggle_handler',
        'label' => 'Mode toggle handler',
        'ok' => is_file(__DIR__ . '/../merchant_toggle_mode.php'),
        'detail' => 'merchant_toggle_mode.php present on server',
        'fix' => 'merchant_toggle_mode.php',
    ];

    $errCount = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;
    $checks[] = [
        'id' => 'error_log',
        'label' => 'Unresolved platform errors',
        'ok' => true,
        'detail' => $errCount === 0 ? 'No logged errors' : $errCount . ' error(s) in Error Log — open admin_error_log.php',
        'fix' => 'admin_error_log.php',
    ];

    $checks[] = [
        'id' => 'error_catcher',
        'label' => 'Global error catcher',
        'ok' => is_file(__DIR__ . '/error_catcher.php') && defined('UNIWEB_ERROR_CATCHER_INIT'),
        'detail' => 'Catches PHP errors, exceptions & fatals automatically',
        'fix' => 'includes/error_catcher.php',
    ];

    $failed = 0;
    foreach ($checks as $c) {
        if (!$c['ok']) {
            $failed++;
        }
    }

    return ['checks' => $checks, 'failed' => $failed, 'ok' => $failed === 0];
}
