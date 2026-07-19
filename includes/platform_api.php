<?php
declare(strict_types=1);

/** Admin: platform vs merchant API key overview */

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
    $db = getDB();
    $st = $db->prepare('SELECT id, api_key, api_secret, test_api_key, test_api_secret FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $m = $st->fetch();
    if (!$m) {
        return;
    }
    $liveKey = trim((string)($m['api_key'] ?? ''));
    $liveSec = trim((string)($m['api_secret'] ?? ''));
    $testKey = trim((string)($m['test_api_key'] ?? ''));
    $testSec = trim((string)($m['test_api_secret'] ?? ''));
    if ($liveKey === '') {
        $liveKey = 'uk_' . bin2hex(random_bytes(16));
    }
    if ($liveSec === '') {
        $liveSec = 'us_' . bin2hex(random_bytes(24));
    }
    if ($testKey === '') {
        $testKey = 'test_' . bin2hex(random_bytes(16));
    }
    if ($testSec === '') {
        $testSec = 'testsec_' . bin2hex(random_bytes(24));
    }
    try {
        $db->prepare('UPDATE merchants SET api_key=?, api_secret=?, test_api_key=?, test_api_secret=? WHERE id=?')
            ->execute([$liveKey, $liveSec, $testKey, $testSec, $merchantId]);
    } catch (Throwable $e) {
        $db->prepare('UPDATE merchants SET api_key=?, api_secret=? WHERE id=?')
            ->execute([$liveKey, $liveSec, $merchantId]);
    }
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

    if ($mode === 'test') {
        $newKey = 'test_' . bin2hex(random_bytes(16));
        $newSecret = 'testsec_' . bin2hex(random_bytes(24));
        $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=? WHERE id=?')->execute([$newKey, $newSecret, $merchantId]);
        $label = 'Test';
    } else {
        $newKey = 'uk_' . bin2hex(random_bytes(16));
        $newSecret = 'us_' . bin2hex(random_bytes(24));
        $db->prepare('UPDATE merchants SET api_key=?, api_secret=? WHERE id=?')->execute([$newKey, $newSecret, $merchantId]);
        $label = 'Live';
    }

    $who = $byAdminId ? 'by admin' : 'by you';
    createNotification($merchantId, "{$label} API Key Regenerated", "A new {$label} API key was generated {$who}. Your old key stopped working immediately — update it in your integration.");

    $email = trim((string)($m['email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $subject = "[{$label} API Key Regenerated] " . ($m['business_name'] ?? 'Your UniWeb Account');
        $body = "Hi " . ($m['business_name'] ?? 'Merchant') . ",\n\n"
            . "A new {$label} API key was just generated for your UniWeb account (Merchant ID: " . ($m['merchant_code'] ?? '') . ").\n\n"
            . "New {$label} API Key: {$newKey}\n\n"
            . "Your previous key has been deactivated immediately. Please update it in your website/app integration.\n\n"
            . "If you did not request this change, contact support immediately at " . COMPANY_SUPPORT_EMAIL . ".\n\n"
            . "— " . COMPANY_LEGAL_NAME . "\n" . APP_URL;
        sendPlatformEmail($email, $subject, $body);
    }

    if ($byAdminId) {
        logStaffActivity('api_key_regenerated', "{$label} key regenerated for merchant #{$merchantId}", $merchantId);
    }

    return ['ok' => true, 'mode' => $mode, 'key' => $newKey];
}

function getPlatformGatewayKeyStatus(): array
{
    $partners = [
        ['id' => 'razorpay', 'name' => 'Razorpay', 'env' => getSetting('razorpay_environment', 'test'), 'key_label' => 'key_id', 'key' => getSetting('razorpay_key_id', '')],
        ['id' => 'cashfree', 'name' => 'Cashfree', 'env' => getSetting('cashfree_environment', 'sandbox'), 'key_label' => 'app_id', 'key' => getSetting('cashfree_app_id', '')],
        ['id' => 'payu', 'name' => 'PayU', 'env' => getSetting('payu_environment', 'test'), 'key_label' => 'merchant_key', 'key' => getSetting('payu_merchant_key', '')],
        ['id' => 'decentro', 'name' => 'Decentro', 'env' => getSetting('decentro_base_url', '') ? 'configured' : 'sandbox', 'key_label' => 'client_id', 'key' => getSetting('decentro_client_id', '')],
        ['id' => 'axis', 'name' => 'Axis Bank', 'env' => getSetting('axis_environment', 'uat'), 'key_label' => 'client_id', 'key' => getSetting('axis_client_id', getSetting('axis_api_key', ''))],
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
        'label' => 'Website & API Keys page',
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
        $demoLive = isMerchantLive($demoRow);
        $checks[] = [
            'id' => 'demo_mode_toggle',
            'label' => 'Demo merchant Test/Live toggle',
            'ok' => $demoLive,
            'detail' => $demoLive
                ? 'demo@uniweb.co.in can switch Test ↔ Live'
                : 'Demo account_mode=' . ($demoRow['account_mode'] ?? '?') . ' — toggle stays on Test only',
            'fix' => 'includes/demo.php + login as demo@uniweb.co.in',
        ];
    }

    $blocked = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status='verified' AND account_mode='test' AND status='active'")->fetchColumn();
    $checks[] = [
        'id' => 'verified_not_live',
        'label' => 'KYC verified but not Live-activated',
        'ok' => $blocked === 0,
        'detail' => $blocked === 0
            ? 'All verified merchants can use Live toggle'
            : $blocked . ' merchant(s) verified but account_mode=test — approve Live in KYC / Edit Merchant',
        'fix' => 'admin_kyc.php',
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
        'ok' => $errCount === 0,
        'detail' => $errCount === 0 ? 'No logged errors' : $errCount . ' error(s) in Error Log',
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
