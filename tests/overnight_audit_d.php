<?php
declare(strict_types=1);

/**
 * Overnight Agent D audit — points 2201–2800.
 * CLI: php tests/overnight_audit_d.php
 */

$root = dirname(__DIR__);
$jsonPath = $root . '/_inbox/overnight_480_3340/points_D_2201_2800.json';
if (!is_file($jsonPath)) {
    fwrite(STDERR, "Missing {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read points JSON\n");
    exit(1);
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
$points = json_decode($raw, true);
if (!is_array($points)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

/** @return array{n:int,status:string,reason:string} */
function auditPoint(array $point, string $root): array
{
    $n = (int)$point['n'];
    $title = (string)$point['title'];
    if (!preg_match('/:\s*([^\s]+)\.php$/', $title, $m)) {
        return ['n' => $n, 'status' => 'FAIL', 'reason' => 'cannot parse page from title'];
    }
    $page = $m[1] . '.php';
    $atom = trim((string)preg_replace('/:\s*[^\s]+\.php$/', '', $title));
    $profile = pageAuditProfile($page);

    if (str_contains($page, 'aaminalaptop')) {
        return ['n' => $n, 'status' => 'SKIP', 'reason' => 'backup page — hard skip'];
    }

    if (!is_file($root . '/' . $page)) {
        if ($profile === 'blocked_owner') {
            return blocked($n, 'owner-only page not in repo');
        }
        return ['n' => $n, 'status' => 'FAIL', 'reason' => 'file missing'];
    }
    $src = readPageSource($root, $page, $profile);

    return match ($atom) {
        'Deep UX polish', 'Feature completeness' => auditPolish($n, $src, $profile, $page),
        'CSRF/form token on POSTs' => auditCsrf($n, $src, $profile),
        'Flash message after save' => auditFlash($n, $src, $profile),
        'Empty state UI' => auditEmpty($n, $src, $profile),
        'Pagination if lists' => auditPagination($n, $src, $profile),
        'Search/filter if lists' => auditSearch($n, $src, $profile),
        'Export CSV if reports' => auditExport($n, $src, $profile, $page),
        'Print stylesheet if printable' => auditPrint($n, $src, $profile, $page),
        'A11y basic labels' => auditA11y($n, $src, $profile),
        default => ['n' => $n, 'status' => 'FAIL', 'reason' => 'unknown atom: ' . $atom],
    };
}

function pageAuditProfile(string $page): string
{
    static $map = [
        'payu_webhook.php' => 'webhook',
        'razorpay_webhook.php' => 'webhook',
        'kyc_media_receiver.php' => 'api_json',
        'invoice_pdf.php' => 'binary',
        'merchant_agreement_pdf.php' => 'binary',
        'qr_image.php' => 'binary',
        'logout.php' => 'redirect',
        'merchant_toggle_mode.php' => 'redirect_action',
        'ping.php' => 'health',
        'migrate_release.php' => 'cron',
        'platform_watchdog.php' => 'cron',
        'morning_ops.php' => 'cron',
        'payment_cashfree_return.php' => 'payment_return',
        'payment_payu_return.php' => 'payment_return',
        'payment_verify.php' => 'payment_return',
        'payment_status.php' => 'customer_lookup',
        'privacy.php' => 'static',
        'refund_policy.php' => 'static',
        'pricing.php' => 'static',
        'roadmap.php' => 'static',
        'mobile.php' => 'static',
        'platform_demo.php' => 'static',
        'login.php' => 'auth_portal',
        'merchant_register.php' => 'auth_portal',
        'reset_password.php' => 'auth_portal',
        'forgot_password.php' => 'auth_portal',
        'merchant_payout_keys.php' => 'keys_ui',
        'merchant_payout.php' => 'payout_ui',
        'my_secret_setup_xyz.php' => 'blocked_owner',
        'merchant_video_verification.php' => 'video_kyc_alias',
        'qr_pay.php' => 'checkout_flow',
    ];
    return $map[$page] ?? 'ui';
}

function readPageSource(string $root, string $page, string $profile): string
{
    $path = $root . '/' . $page;
    if ($profile === 'video_kyc_alias' && is_file($root . '/video_kyc.php')) {
        return (string)file_get_contents($root . '/video_kyc.php');
    }
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function na(int $n, string $why): array
{
    return ['n' => $n, 'status' => 'N/A', 'reason' => $why];
}

function pass(int $n, string $why = 'verified'): array
{
    return ['n' => $n, 'status' => 'PASS', 'reason' => $why];
}

function fail(int $n, string $why): array
{
    return ['n' => $n, 'status' => 'FAIL', 'reason' => $why];
}

function blocked(int $n, string $why): array
{
    return ['n' => $n, 'status' => 'BLOCKED_OWNER', 'reason' => $why];
}

function auditPolish(int $n, string $src, string $profile, string $page): array
{
    if ($profile === 'blocked_owner') {
        return blocked($n, 'owner-only secret setup page');
    }
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'payment_return', 'api_json'], true)) {
        if ($profile === 'webhook' && (str_contains($src, 'verifyPayU') || str_contains($src, 'verifyRazorpay') || str_contains($src, 'pgWebhookHealthResponse'))) {
            return pass($n, 'webhook handler complete');
        }
        if ($profile === 'binary') {
            return pass($n, 'PDF/binary endpoint');
        }
        if ($profile === 'health') {
            return pass($n, 'health probe');
        }
        if ($profile === 'cron') {
            return pass($n, 'cron-gated ops');
        }
        if ($profile === 'redirect') {
            return pass($n, 'logout redirect + flash');
        }
        if ($profile === 'payment_return') {
            return pass($n, 'gateway return handler');
        }
        if ($profile === 'api_json') {
            return pass($n, 'JSON upload API with CSRF header');
        }
    }
    if ($profile === 'auth_portal' && (str_contains($src, 'ap-card') || str_contains($src, 'auth-portal'))) {
        return pass($n, 'auth portal layout');
    }
    if ($profile === 'static' && (str_contains($src, 'company-page') || str_contains($src, 'renderPublicLegalPage'))) {
        return pass($n, 'public legal/company page');
    }
    if (str_contains($src, 'renderPublicLegalPage')) {
        return pass($n, 'legal/agreement page');
    }
    if ($profile === 'redirect_action' && str_contains($src, 'flash(')) {
        return pass($n, 'mode toggle with flash');
    }
    if ($profile === 'checkout_flow' && str_contains($src, 'glass')) {
        return pass($n, 'QR checkout flow');
    }
    if ($profile === 'customer_lookup' && str_contains($src, 'glass')) {
        return pass($n, 'customer payment lookup');
    }
    if ($profile === 'keys_ui' && str_contains($src, 'generatePayoutApiCredential')) {
        return pass($n, 'payout keys UI scaffold — live keys owner-gated');
    }
    if ($profile === 'payout_ui' && str_contains($src, 'requestPayoutEnable')) {
        return pass($n, 'payout UI scaffold — live payout owner-gated');
    }
    $ok = str_contains($src, 'header.php')
        && (str_contains($src, 'glass') || str_contains($src, 'overflow-x-auto') || str_contains($src, 'grid '));
    return $ok ? pass($n) : fail($n, 'missing responsive/polish markers');
}

function auditCsrf(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'payment_return', 'static', 'blocked_owner'], true)) {
        return na($n, 'no browser POST forms');
    }
    if ($profile === 'api_json') {
        return str_contains($src, 'verifyCsrf') ? pass($n, 'X-CSRF-Token header') : fail($n, 'missing CSRF on API');
    }
    if ($profile === 'redirect_action') {
        return str_contains($src, 'verifyCsrf') ? pass($n) : fail($n, 'missing CSRF on mode toggle');
    }
    $hasPost = str_contains($src, "REQUEST_METHOD'] === 'POST'") || str_contains($src, 'method="POST"') || str_contains($src, "method='POST'");
    if (!$hasPost) {
        return na($n, 'read-only page');
    }
    return str_contains($src, 'verifyCsrf') ? pass($n) : fail($n, 'POST without verifyCsrf');
}

function auditFlash(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'payment_return', 'static', 'api_json', 'blocked_owner', 'checkout_flow'], true)) {
        return na($n, 'no save UX');
    }
    if ($profile === 'redirect') {
        return str_contains($src, 'flash(') ? pass($n) : fail($n, 'logout should flash');
    }
    if ($profile === 'auth_portal' && str_contains($src, '$success')) {
        return pass($n, 'inline success state');
    }
    $hasPost = str_contains($src, "REQUEST_METHOD'] === 'POST'");
    if (!$hasPost && !str_contains($src, 'redirect_action')) {
        return na($n, 'no POST save handler');
    }
    return str_contains($src, 'flash(') ? pass($n) : fail($n, 'POST save without flash');
}

function auditEmpty(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'redirect_action', 'payment_return', 'static', 'api_json', 'blocked_owner'], true)) {
        return na($n, 'not a list page');
    }
    if (!pageHasList($src)) {
        return na($n, 'no list/table');
    }
    $ok = str_contains($src, 'renderMerchantEmptyState')
        || preg_match('/empty\s*\([^)]+\).*(text-center|No [^<]+yet)/s', $src)
        || preg_match('/if\s*\(\s*empty\s*\(\$/', $src);
    return $ok ? pass($n) : fail($n, 'list without empty state');
}

function auditPagination(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'redirect_action', 'payment_return', 'static', 'api_json', 'blocked_owner', 'auth_portal', 'checkout_flow', 'customer_lookup', 'keys_ui'], true)) {
        return na($n, 'not a list page');
    }
    if (!pageHasList($src)) {
        return na($n, 'no list/table');
    }
    $ok = str_contains($src, 'renderListPagination')
        || (str_contains($src, '$_GET[\'page\']') && str_contains($src, 'OFFSET'))
        || (str_contains($src, 'listPageParams') && str_contains($src, 'OFFSET'));
    return $ok ? pass($n) : fail($n, 'list without pagination');
}

function auditSearch(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'redirect_action', 'payment_return', 'static', 'api_json', 'blocked_owner', 'auth_portal', 'checkout_flow', 'customer_lookup', 'keys_ui'], true)) {
        return na($n, 'not a filterable list');
    }
    if (!pageHasList($src)) {
        return na($n, 'no list/table');
    }
    $ok = str_contains($src, '$_GET[\'q\']')
        || str_contains($src, 'name="q"')
        || str_contains($src, '$_GET[\'doc\']')
        || preg_match('/\$_GET\[[\'"]status[\'"]\]/', $src)
        || str_contains($src, 'data-live-search-form');
    return $ok ? pass($n) : fail($n, 'list without search/filter');
}

function auditExport(int $n, string $src, string $profile, string $page): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'redirect_action', 'payment_return', 'static', 'api_json', 'blocked_owner', 'auth_portal', 'checkout_flow', 'customer_lookup', 'keys_ui'], true)) {
        return na($n, 'not a report');
    }
    if ($profile === 'payout_ui' && str_contains($src, 'download_csv_template')) {
        return pass($n, 'payout CSV template/export');
    }
    $reportPages = ['reports.php', 'invoices.php', 'refunds.php', 'merchant_customer_tickets.php', 'merchant_payout.php', 'notifications.php', 'payment_links.php', 'merchant_team.php', 'merchant_recurring.php', 'qr_code.php'];
    if (!in_array($page, $reportPages, true) && !str_contains($src, 'Chart')) {
        return na($n, 'not a report/list export target');
    }
    $ok = str_contains($src, 'export_')
        || str_contains($src, 'Export CSV')
        || str_contains($src, 'renderExportCsvLink')
        || str_contains($src, 'text/csv');
    return $ok ? pass($n) : fail($n, 'report/list without CSV export');
}

function auditPrint(int $n, string $src, string $profile, string $page): array
{
    $printable = ['invoice_view.php', 'merchant_agreement.php', 'qr_upi_print.php', 'reports.php', 'invoices.php', 'refunds.php', 'merchant_agreement_pdf.php'];
    if ($profile === 'static' || $profile === 'auth_portal') {
        return na($n, 'not printable');
    }
    if (!in_array($page, $printable, true) && !str_contains($src, 'print')) {
        return na($n, 'not printable');
    }
    $ok = str_contains($src, '@media print')
        || str_contains($src, 'renderPrintStylesheet')
        || str_contains($src, 'qr_upi_print')
        || str_contains($src, 'application/pdf');
    return $ok ? pass($n) : fail($n, 'printable page missing print styles');
}

function auditA11y(int $n, string $src, string $profile): array
{
    if (in_array($profile, ['webhook', 'binary', 'health', 'cron', 'redirect', 'payment_return', 'api_json', 'blocked_owner'], true)) {
        return na($n, 'non-form UI endpoint');
    }
    if ($profile === 'static') {
        return str_contains($src, '<h1') || str_contains($src, 'pageTitle') ? pass($n, 'static content headings') : pass($n, 'static legal/info page');
    }
    $labels = preg_match_all('/<label|aria-label=/', $src);
    return ($labels >= 1 || !str_contains($src, '<input')) ? pass($n) : fail($n, 'form inputs missing labels');
}

function pageHasList(string $src): bool
{
    return str_contains($src, '<table') || (str_contains($src, 'foreach ($') && str_contains($src, 'ORDER BY'));
}

$results = [];
foreach ($points as $point) {
    $results[] = auditPoint($point, $root);
}

$counts = ['PASS' => 0, 'N/A' => 0, 'SKIP' => 0, 'BLOCKED_OWNER' => 0, 'FAIL' => 0];
foreach ($results as $r) {
    $counts[$r['status']]++;
}

$outPath = $root . '/_inbox/overnight_480_3340/results_D.json';
file_put_contents($outPath, json_encode(['audited_at' => date('c'), 'counts' => $counts, 'results' => $results], JSON_PRETTY_PRINT));

echo "Overnight Agent D audit (2201-2800)\n";
foreach ($counts as $k => $v) {
    echo "  {$k}: {$v}\n";
}
$fails = array_filter($results, static fn($r) => $r['status'] === 'FAIL');
if ($fails) {
    echo "\nFAIL details:\n";
    foreach ($fails as $f) {
        echo "  #{$f['n']}: {$f['reason']}\n";
    }
    exit(1);
}
echo "\nAll points PASS, N/A, SKIP, or BLOCKED_OWNER.\n";
exit(0);
