<?php
declare(strict_types=1);

/** Full-platform link watchdog — every portal, page, file & link */

function watchdogRoot(): string
{
    return dirname(__DIR__);
}

function getWatchdogPageRegistry(): array
{
    $pages = [];

    $add = static function (string $file, string $label, string $portal, string $auth = 'none') use (&$pages): void {
        $pages[$file] = [
            'file' => $file,
            'label' => $label,
            'portal' => $portal,
            'auth' => $auth,
        ];
    };

    foreach ([
        ['index.php', 'Homepage', 'public'],
        ['about.php', 'About', 'public'],
        ['contact.php', 'Contact', 'public'],
        ['faq.php', 'FAQ', 'public'],
        ['blog.php', 'Blog', 'public'],
        ['api_docs.php', 'API Docs', 'public'],
        ['tour_videos.php', 'Video Tour', 'public'],
        ['merchant_register.php', 'Merchant Signup', 'public'],
        ['signup.php', 'Signup', 'public'],
        ['login.php', 'Merchant Login', 'public'],
        ['customer_login.php', 'Customer Login', 'public'],
        ['cust.php', 'Customer Login short /cust', 'public'],
        ['cust/index.php', 'Customer Login short /cust/ (dir)', 'public'],
        ['customer_portal.php', 'Customer Portal', 'public'],
        ['customer_ticket.php', 'Customer Complaint', 'public'],
        ['customer_logout.php', 'Customer Logout', 'public'],
        ['admin_login.php', 'Admin Login', 'public'],
        ['admin_forgot_password.php', 'Admin Forgot Password', 'public'],
        ['admin_reset_password.php', 'Admin Reset Password', 'public'],
        ['staff_login.php', 'Staff Login', 'public'],
        ['forgot_password.php', 'Forgot Password', 'public'],
        ['reset_password.php', 'Reset Password', 'public'],
        ['terms.php', 'Terms', 'public'],
        ['compliance.php', 'Compliance Framework', 'public'],
        ['privacy.php', 'Privacy', 'public'],
        ['refund_policy.php', 'Refund Policy', 'public'],
        ['business_agreement.php', 'Merchant Agreement', 'public'],
        ['status.php', 'System Status', 'public'],
        ['health.php', 'Health check', 'public'],
        ['mobile.php', 'Mobile', 'public'],
        ['pricing.php', 'Pricing', 'public'],
        ['trust.php', 'Trust Centre', 'public'],
        ['solutions.php', 'Solutions', 'public'],
        ['roadmap.php', 'Roadmap', 'public'],
        ['compare.php', 'Compare', 'public'],
        ['checkout.php', 'Checkout', 'public'],
        ['payment_status.php', 'Payment Status', 'public'],
        ['error_404.php', 'Branded 404 (alias)', 'public'],
        ['error.php', 'Branded ErrorDocument', 'public'],
    ] as [$f, $l, $p]) {
        $add($f, $l, $p);
    }

    foreach ([
        ['dashboard.php', 'Dashboard'],
        ['transactions.php', 'Transactions'],
        ['transaction_detail.php', 'Transaction Detail', 'merchant'],
        ['reports.php', 'Reports'],
        ['payment_links.php', 'Payment Links'],
        ['merchant_payment_pack.php', 'Payment Pack'],
        ['wallet.php', 'Settlement Balance'],
        ['collection_settings.php', 'Collection Settings'],
        ['merchant_instant_settlement.php', 'Instant Settlement'],
        ['qr_code.php', 'QR Code'],
        ['qr_upi_print.php', 'Instant UPI Print QR'],
        ['settlements.php', 'Settlements'],
        ['settlement_detail.php', 'Settlement Detail'],
        ['merchant_settlement_settings.php', 'Settlement Settings'],
        ['merchant_payout.php', 'Payouts'],
        ['merchant_payout_keys.php', 'Payout API Keys'],
        ['invoices.php', 'Invoices'],
        ['agents.php', 'Agents'],
        ['kyc.php', 'KYC'],
        ['merchant_video_verification.php', 'Video KYC'],
        ['merchant_team.php', 'Team Members'],
        ['merchant_team_accept.php', 'Team Invite Accept'],
        ['merchant_settings.php', 'Settings'],
        ['merchant_notify_settings.php', 'Notification Preferences'],
        ['merchant_2fa.php', 'Two-Factor Authentication'],
        ['merchant_agreement.php', 'Merchant Agreement'],
        ['merchant_website.php', 'My Website'],
        ['api_settings.php', 'API Settings'],
        ['disputes.php', 'Disputes'],
        ['refunds.php', 'Refunds'],
        ['chargebacks.php', 'Chargebacks'],
        ['notifications.php', 'Notifications'],
        ['support.php', 'Support'],
        ['support_ticket.php', 'Support Ticket'],
        ['merchant_customer_tickets.php', 'Customer Complaints'],
        ['my_account.php', 'My Account'],
        ['security.php', 'Security'],
        ['merchant_setup.php', 'Merchant Setup'],
        ['merchant_toggle_mode.php', 'Mode Toggle'],
        ['export_transactions.php', 'Export Transactions'],
        ['add_bank.php', 'Add Bank'],
        ['add_agent.php', 'Add Agent'],
        ['invoice_view.php', 'Invoice View'],
        ['invoice_pdf.php', 'Invoice PDF'],
        ['merchant_agreement_pdf.php', 'Merchant Agreement PDF'],
        ['merchant_recurring.php', 'Recurring Payments'],
    ] as $row) {
        $add($row[0], $row[1], 'merchant', 'merchant');
    }

    foreach ([
        ['admin_dashboard.php', 'Dashboard'],
        ['manage_merchant.php', 'All Merchants'],
        ['add_merchant.php', 'Add Merchant'],
        ['admin_view_merchant.php', 'Merchant View'],
        ['admin_edit_merchant.php', 'Edit Merchant'],
        ['admin_manage_staff.php', 'Staff'],
        ['admin_gateway_submit.php', 'Gateway Submit'],
        ['admin_transactions.php', 'Transactions'],
        ['admin_refunds.php', 'Refunds'],
        ['admin_disputes.php', 'Disputes'],
        ['admin_staff_activity.php', 'Staff Activity'],
        ['admin_pg_webhooks.php', 'PG Webhooks'],
        ['admin_reconciliation.php', 'PG Reconciliation'],
        ['admin_bank_reconciliation.php', 'Bank Auto-Reconciliation'],
        ['admin_settlements.php', 'Settlements'],
        ['admin_settlement_settings.php', 'Settlement Engine'],
        ['admin_settlement_batches.php', 'Settlement Batches'],
        ['admin_wallet.php', 'Platform Wallet'],
        ['admin_payout.php', 'Payout Requests'],
        ['admin_kyc.php', 'KYC Review'],
        ['admin_method_requests.php', 'Method Requests'],
        ['method_partner_webhook.php', 'Method Partner Webhook', 'system', 'system'],
        ['merchant_instant_settlement.php', 'Instant Settlement', 'merchant'],
        ['admin_kyc_doc.php', 'KYC Document'],
        ['admin_aml.php', 'AML'],
        ['admin_chargebacks.php', 'Chargebacks'],
        ['admin_financial_reports.php', 'Financial Reports'],
        ['admin_merchant_banks.php', 'Merchant Banks'],
        ['admin_support.php', 'Support'],
        ['admin_customer_tickets.php', 'Customer Complaints'],
        ['admin_partner_requests.php', 'Partner Requests'],
        ['admin_partners.php', 'Partners'],
        ['admin_partner.php', 'Partner Detail'],
        ['admin_platform_status.php', 'Platform Status'],
        ['admin_audit_plan.php', 'Deep Audit Plan'],
        ['admin_website.php', 'Platform API guide (Advanced)'],
        ['admin_link_audit.php', 'Link Audit'],
        ['admin_watchdog.php', 'Link Watchdog'],
        ['admin_error_log.php', 'Error Log'],
        ['admin_axis.php', 'Axis UAT'],
        ['admin_stepup.php', 'Step-up Auth'],
        ['gateway_settings.php', 'Platform Settings'],
        ['admin_security.php', 'Security'],
        ['admin_partner_decentro.php', 'Decentro Checklist'],
        ['admin_customer_message.php', 'Customer Message'],
    ] as $row) {
        $add($row[0], $row[1], 'admin', 'admin');
    }

    foreach ([
        ['staff_dashboard.php', 'Staff Dashboard'],
        ['manage_merchant.php', 'Merchants (Staff)'],
        ['admin_kyc.php', 'KYC Review (Staff)'],
        ['admin_refunds.php', 'Refunds (Staff)'],
        ['admin_disputes.php', 'Disputes (Staff)'],
        ['admin_support.php', 'Support (Staff)'],
        ['admin_customer_tickets.php', 'Customer Complaints (Staff)'],
        ['admin_transactions.php', 'Transactions (Staff)'],
        ['admin_settlements.php', 'Settlements (Staff)'],
        ['admin_pg_webhooks.php', 'PG Webhooks (Staff)'],
        ['admin_reconciliation.php', 'PG Reconciliation (Staff)'],
        ['admin_manage_staff.php', 'Staff Control'],
        ['admin_staff_activity.php', 'Staff Activity Log'],
    ] as $row) {
        $add($row[0], $row[1], 'staff', 'staff');
    }

    foreach ([
        ['api.php', 'Merchant API', 'api', 'api'],
        ['verify_api.php', 'API Verify', 'api', 'api'],
        ['webhook.php', 'Merchant Webhook', 'webhook', 'webhook'],
        ['razorpay_webhook.php', 'Razorpay Webhook', 'webhook', 'webhook'],
        ['cashfree_webhook.php', 'Cashfree Webhook', 'webhook', 'webhook'],
        ['payu_webhook.php', 'PayU Webhook', 'webhook', 'webhook'],
        ['axis_webhook.php', 'Axis Webhook', 'webhook', 'webhook'],
        ['whatsapp_webhook.php', 'WhatsApp Webhook', 'webhook', 'webhook'],
        ['platform_watchdog.php', 'Cron Watchdog', 'system', 'system'],
        ['cron_auto_audit.php', 'Auto Audit Cron', 'system', 'system'],
        ['cron_settlements.php', 'Settlement Cron', 'system', 'system'],
        ['health.php', 'Health', 'system', 'system'],
    ] as $row) {
        $add($row[0], $row[1], $row[2], $row[3] ?? 'none');
    }

    // Real launch pages that were previously auto-classified as "other" (so the
    // live cron HTTP probe either skipped or mis-graded them). Register with the
    // correct auth so redirects/JSON-guards are treated as healthy, not failures.
    foreach ([
        ['admin.php', 'Admin Entry', 'public', 'admin'],            // redirects to login/dashboard (302)
        ['qr_pay.php', 'QR Scan Pay', 'public', 'none'],            // 404 without a QR code (branded)
        ['blog_post.php', 'Blog Article', 'public', 'none'],        // 404 without a slug (branded)
        ['video_kyc.php', 'Video KYC Upload', 'merchant', 'merchant'],
        ['kyc_media_receiver.php', 'KYC Media Upload', 'merchant', 'merchant'],
        ['global_search.php', 'Global Search', 'merchant', 'api'],  // JSON, 401 when logged out
        ['migrate_release.php', 'Release Migrations', 'system', 'system'],
    ] as $row) {
        $add($row[0], $row[1], $row[2], $row[3] ?? 'none');
    }

    return array_values($pages);
}

function watchdogDiscoverPhpFiles(): array
{
    $root = watchdogRoot();
    $skip = ['update_', 'wallet_fix', 'wallet_diagnose', 'debug_', 'night_setup', 'my_secret', 'diag', 'platform_wallet_fix', 'axis_probe', 'db_wizard'];
    // Gitignored dev-only files — never deployed to production, don't flag as missing
    $skipFiles = ['migrate_release.php', 'morning_ops.php', 'config.private.php'];
    $files = [];
    foreach (glob($root . '/*.php') ?: [] as $path) {
        if (!is_file($path) || is_dir($path)) {
            continue;
        }
        $base = basename($path);
        $skipIt = false;
        if (in_array($base, $skipFiles, true)) {
            $skipIt = true;
        }
        if (!$skipIt) {
            foreach ($skip as $prefix) {
                if (str_starts_with($base, $prefix)) {
                    $skipIt = true;
                    break;
                }
            }
        }
        if ($skipIt) {
            continue;
        }
        $files[] = $base;
    }
    sort($files);
    return $files;
}

function watchdogClassifyFile(string $file, array $registryByFile): string
{
    if (isset($registryByFile[$file])) {
        return $registryByFile[$file]['portal'];
    }
    if (str_starts_with($file, 'admin_')) {
        return 'admin';
    }
    if (str_starts_with($file, 'staff_')) {
        return 'staff';
    }
    if (str_contains($file, 'webhook') || $file === 'api.php') {
        return 'webhook';
    }
    if (str_starts_with($file, 'merchant_')) {
        return 'merchant';
    }
    if (str_starts_with($file, 'cron_') || $file === 'platform_watchdog.php') {
        return 'system';
    }
    if (in_array($file, ['logout.php', 'payment_verify.php', 'payment_payu_return.php', 'payment_cashfree_return.php', 'checkout_upi_status.php', 'qr_image.php'], true)) {
        return 'system';
    }
    return 'other';
}

/** Auth class for files not listed in the registry (so 403/302 is graded correctly). */
function watchdogDefaultAuth(string $file): string
{
    if (str_starts_with($file, 'cron_') || $file === 'platform_watchdog.php' || watchdogIsKeyGatedFile($file)) {
        return 'system';
    }
    if (str_contains($file, 'webhook') || $file === 'api.php') {
        return 'webhook';
    }
    if (str_starts_with($file, 'admin_')) {
        return 'admin';
    }
    if (str_starts_with($file, 'staff_')) {
        return 'staff';
    }
    if (str_starts_with($file, 'merchant_')) {
        return 'merchant';
    }
    return 'none';
}

/** Cron / probe URLs that return 403 without the watchdog key — not public pages. */
function watchdogIsKeyGatedFile(string $relFile): bool
{
    $base = basename($relFile);
    if (str_starts_with($base, 'cron_')) {
        return true;
    }
    return in_array($base, [
        'platform_watchdog.php',
        'cleanup2.php',
        'db_probe.php',
        'wallet_repair_once.php',
        'migrate_release.php',
    ], true);
}

function watchdogExtractLinksFromFile(string $absPath): array
{
    if (!is_readable($absPath)) {
        return [];
    }
    $content = (string)file_get_contents($absPath);
    $links = [];
    $patterns = [
        '/href\s*=\s*["\']([^"\']+)["\']/i',
        '/action\s*=\s*["\']([^"\']+)["\']/i',
        '/location\.href\s*=\s*["\']([^"\']+)["\']/i',
        '/redirect\(\s*["\']([^"\']+)["\']/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $m)) {
            foreach ($m[1] as $href) {
                $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
                if ($href !== '' && !str_contains($href, '<?') && !str_contains($href, '$') && !str_contains($href, '<')) {
                    $links[] = $href;
                }
            }
        }
    }
    return array_values(array_unique($links));
}

function watchdogIsIgnorableHref(string $href): bool
{
    $href = trim($href);
    if ($href === '' || str_contains($href, '{') || str_contains($href, '}') || str_contains($href, '%')) {
        return true;
    }
    if (str_contains($href, '<') || str_contains($href, '>')) {
        return true;
    }
    if (str_contains($href, '${')) {
        return true;
    }
    return false;
}

function watchdogCleanUrlRoutes(): array
{
    return [
        'register' => 'merchant_register.php',
        'login' => 'login.php',
        'admin' => 'admin_login.php',
    ];
}

function watchdogNormalizeInternalTarget(string $href): ?string
{
    $href = trim($href);
    if (watchdogIsIgnorableHref($href)) {
        return null;
    }
    if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'sms:') || str_starts_with($href, 'data:')) {
        return null;
    }
    if (preg_match('#^https?://#i', $href)) {
        $host = parse_url($href, PHP_URL_HOST);
        $appHost = parse_url(APP_URL, PHP_URL_HOST);
        if ($host && $appHost && strcasecmp($host, $appHost) !== 0) {
            return null;
        }
        $path = parse_url($href, PHP_URL_PATH) ?: '';
        $href = ltrim($path, '/');
    }
    if (str_starts_with($href, '?')) {
        return null;
    }
    if (($q = strpos($href, '?')) !== false) {
        $href = substr($href, 0, $q);
    }
    if (($h = strpos($href, '#')) !== false) {
        $href = substr($href, 0, $h);
    }
    if ($href === '' || str_contains($href, '://')) {
        return null;
    }
    return $href;
}

function watchdogTargetExists(string $target): bool
{
    $root = watchdogRoot();
    if (is_file($root . '/' . $target)) {
        return true;
    }
    if (is_file($root . '/' . $target . '.php')) {
        return true;
    }
    if (str_starts_with($target, 'includes/') && is_file($root . '/' . $target)) {
        return true;
    }
    if (in_array($target, ['openapi.json', 'manifest.json', 'robots.txt', 'favicon.ico', 'favicon.svg', 'favicon.png', 'sitemap.xml'], true) && is_file($root . '/' . $target)) {
        return true;
    }
    if (preg_match('#^assets/icons/(icon-192|icon-512|icon-32|apple-touch-icon)\\.png$#', $target) && is_file($root . '/' . $target)) {
        return true;
    }
    $routes = watchdogCleanUrlRoutes();
    if (isset($routes[$target]) && is_file($root . '/' . $routes[$target])) {
        return true;
    }
    if (preg_match('#^(pay|checkout)/[A-Za-z0-9_-]+$#', $target) && is_file($root . '/checkout.php')) {
        return true;
    }
    return false;
}

function watchdogValidateLink(string $href, string $sourceFile): array
{
    $internal = watchdogNormalizeInternalTarget($href);
    if ($internal === null) {
        if (preg_match('#^https?://#i', $href)) {
            return ['href' => $href, 'ok' => true, 'type' => 'external'];
        }
        return ['href' => $href, 'ok' => true, 'type' => 'anchor_or_dynamic'];
    }
    $ok = watchdogTargetExists($internal);
    return [
        'href' => $href,
        'target' => $internal,
        'ok' => $ok,
        'type' => 'internal',
        'source' => $sourceFile,
    ];
}

function watchdogPhpSyntaxOk(string $absPath): ?bool
{
    if (!is_file($absPath) || !function_exists('shell_exec')) {
        return null;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true)) {
        return null;
    }
    $php = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    $out = @shell_exec(escapeshellarg($php) . ' -l ' . escapeshellarg($absPath) . ' 2>&1');
    if ($out === null) {
        return null;
    }
    return str_contains($out, 'No syntax errors');
}

function watchdogSkipHttpProbe(string $relFile): bool
{
    $base = basename($relFile);
    if (watchdogIsKeyGatedFile($base)) {
        return true;
    }
    return in_array($base, [
        'config.php',
        'config.private.php',
        'header.php',
        'footer.php',
    ], true);
}

/** HTTP codes that are healthy when a route is opened without its required ID, login, method, or payload. */
function watchdogExpectedHttpStatuses(string $relFile, string $auth): array
{
    $routeSpecific = [
        'checkout.php' => [404],
        'blog_post.php' => [404],
        'qr_pay.php' => [404],
        'store.php' => [404],
        'qr_upi_redirect.php' => [404],
        'api_qr_create.php' => [405, 401, 403],
        'ifsc_lookup.php' => [401],
        'global_search.php' => [401],
        'webhook.php' => [410],
        'wallet_repair_once.php' => [403],
        'checkout_upi_status.php' => [400],
        'qr_image.php' => [400],
        // Direct GET has no Apache error context → defaults to 404 (healthy for ErrorDocument targets).
        'error.php' => [400, 403, 404, 500],
        'error_404.php' => [400, 403, 404, 500],
    ];
    $expected = $routeSpecific[$relFile] ?? [];
    if (watchdogIsKeyGatedFile($relFile)) {
        $expected = array_merge($expected, [403]);
    }
    if ($auth === 'api') {
        $expected = array_merge($expected, [400, 401, 403, 405]);
    } elseif ($auth === 'webhook') {
        $expected = array_merge($expected, [400, 401, 403, 405]);
    } elseif ($auth === 'system') {
        $expected = array_merge($expected, [400, 401, 403, 405]);
    }
    return array_values(array_unique($expected));
}

/**
 * Hostinger shared hosting often 500s when the server curls its own public
 * HTTPS/IPv6 URL. Force IPv4; on 0/500 retry without SSL verify, then HTTP.
 */
function watchdogCurlGet(string $url, bool $verifySsl): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_NOBODY => false,
        CURLOPT_HEADER => true,
        CURLOPT_USERAGENT => 'UniWeb-Watchdog/1.0',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => $verifySsl,
        CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string)curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'err' => $err];
}

function watchdogHttpProbe(string $relFile, string $auth = 'none'): array
{
    if (watchdogIsKeyGatedFile($relFile)) {
        return [
            'ok' => true,
            'status' => null,
            'detail' => 'Key-gated cron — open URL without key is 403 (normal). Probe skipped.',
        ];
    }
    if (watchdogSkipHttpProbe($relFile)) {
        return ['ok' => null, 'status' => null, 'detail' => 'Include-only file — HTTP probe skipped'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => null, 'status' => null, 'detail' => 'curl unavailable'];
    }
    $url = rtrim(APP_URL, '/') . '/' . ltrim($relFile, '/');
    $transient = static function (array $hit): bool {
        $st = (int)($hit['status'] ?? 0);
        return ($hit['err'] ?? '') !== '' || $st === 0 || $st === 500 || $st === 503;
    };
    $hit = watchdogCurlGet($url, true);
    if ($transient($hit)) {
        $retry = watchdogCurlGet($url, false);
        if (!$transient($retry) && (int)$retry['status'] > 0) {
            $hit = $retry;
        } elseif (str_starts_with($url, 'https://')) {
            $httpUrl = 'http://' . substr($url, strlen('https://'));
            $httpHit = watchdogCurlGet($httpUrl, false);
            if ($httpHit['err'] === '' && $httpHit['status'] >= 200 && $httpHit['status'] < 400) {
                $hit = $httpHit;
            }
        }
        // Hostinger often 503s the first self-curl of health.php; one extra retry.
        if ($transient($hit) && basename($relFile) === 'health.php') {
            $extra = watchdogCurlGet($url, false);
            if (!$transient($extra) && (int)$extra['status'] > 0) {
                $hit = $extra;
            }
        }
    }
    $status = (int)$hit['status'];
    $err = (string)$hit['err'];

    if ($err !== '' && $status === 0) {
        return ['ok' => false, 'status' => 0, 'detail' => $err];
    }

    $ok = false;
    if ($status >= 200 && $status < 400) {
        $ok = true;
    } elseif ($status >= 300 && $status < 400 && in_array($auth, ['merchant', 'admin', 'staff'], true)) {
        $ok = true;
        $status = $status; // login redirect expected
    } elseif (in_array($status, watchdogExpectedHttpStatuses($relFile, $auth), true)) {
        $ok = true;
    }

    return [
        'ok' => $ok,
        'status' => $status,
        'detail' => $ok
            ? ("HTTP $status" . ($status >= 400 ? ' — expected without required input/auth' : ''))
            : "HTTP $status — page error or not reachable",
    ];
}

function runFullLinkWatchdog(bool $httpProbe = true): array
{
    $root = watchdogRoot();
    $registry = getWatchdogPageRegistry();
    $registryByFile = [];
    foreach ($registry as $row) {
        $registryByFile[$row['file']] = $row;
    }

    $discovered = watchdogDiscoverPhpFiles();
    $allFiles = array_unique(array_merge($discovered, array_column($registry, 'file')));
    sort($allFiles);

    $pages = [];
    $brokenLinks = [];
    $summary = [
        'total_files' => 0,
        'missing_files' => 0,
        'http_fail' => 0,
        'syntax_fail' => 0,
        'broken_links' => 0,
        'by_portal' => [],
    ];

    foreach ($allFiles as $file) {
        $abs = $root . '/' . $file;
        $meta = $registryByFile[$file] ?? [
            'file' => $file,
            'label' => $file,
            'portal' => watchdogClassifyFile($file, $registryByFile),
            'auth' => watchdogDefaultAuth($file),
        ];
        $portal = $meta['portal'];
        $summary['by_portal'][$portal] = ($summary['by_portal'][$portal] ?? 0) + 1;
        $summary['total_files']++;

        $exists = is_file($abs);
        if (!$exists) {
            $summary['missing_files']++;
        }

        $syntax = $exists ? watchdogPhpSyntaxOk($abs) : false;
        if ($syntax === false) {
            $summary['syntax_fail']++;
        }

        $http = ['ok' => null, 'status' => null, 'detail' => 'skipped'];
        if ($httpProbe && $exists) {
            $http = watchdogHttpProbe($file, $meta['auth'] ?? 'none');
            if ($http['ok'] === false) {
                $summary['http_fail']++;
            }
        }

        $links = $exists ? watchdogExtractLinksFromFile($abs) : [];
        $linkResults = [];
        $badOnPage = 0;
        foreach ($links as $href) {
            $v = watchdogValidateLink($href, $file);
            $linkResults[] = $v;
            if (($v['type'] ?? '') === 'internal' && empty($v['ok'])) {
                $badOnPage++;
                $brokenLinks[] = $v;
            }
        }
        $summary['broken_links'] += $badOnPage;

        $issues = [];
        if (!$exists) {
            $issues[] = 'File missing on server';
        }
        if ($syntax === false) {
            $issues[] = 'PHP syntax error';
        }
        if ($http['ok'] === false) {
            $issues[] = $http['detail'];
        }
        if ($badOnPage > 0) {
            $issues[] = $badOnPage . ' broken internal link(s)';
        }

        $pages[] = [
            'file' => $file,
            'label' => $meta['label'],
            'portal' => $portal,
            'auth' => $meta['auth'] ?? 'none',
            'exists' => $exists,
            'syntax_ok' => $syntax,
            'http' => $http,
            'link_count' => count($links),
            'broken_link_count' => $badOnPage,
            'links' => $linkResults,
            'issues' => $issues,
            'ok' => $exists && $syntax !== false && $http['ok'] !== false && $badOnPage === 0,
            'url' => rtrim(APP_URL, '/') . '/' . $file,
        ];
    }

    usort($pages, static fn($a, $b) => [$a['portal'], $a['file']] <=> [$b['portal'], $b['file']]);

    $platformChecks = function_exists('runAdminPlatformSelfChecks') ? runAdminPlatformSelfChecks() : ['ok' => true, 'failed' => 0, 'checks' => []];
    $errorCount = function_exists('countUnresolvedPlatformErrors') ? countUnresolvedPlatformErrors() : 0;

    return [
        'scanned_at' => date('Y-m-d H:i:s'),
        'pages' => $pages,
        'broken_links' => $brokenLinks,
        'summary' => $summary,
        'platform_checks' => $platformChecks,
        'unresolved_errors' => $errorCount,
        'ok' => $summary['missing_files'] === 0 && $summary['http_fail'] === 0 && $summary['syntax_fail'] === 0
            && $summary['broken_links'] === 0 && $platformChecks['ok'] && $errorCount === 0,
    ];
}

function watchdogPortalLabel(string $portal): string
{
    return match ($portal) {
        'public' => 'Public Website',
        'merchant' => 'Merchant Portal',
        'admin' => 'Admin Panel',
        'staff' => 'Staff / Operations',
        'api' => 'API',
        'webhook' => 'Webhooks',
        'system' => 'System / Cron',
        default => ucfirst($portal),
    };
}

function watchdogPortalColor(string $portal): string
{
    return match ($portal) {
        'public' => 'sky',
        'merchant' => 'brand',
        'admin' => 'red',
        'staff' => 'violet',
        'api' => 'amber',
        'webhook' => 'gray',
        'system' => 'gray',
        default => 'gray',
    };
}
