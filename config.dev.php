<?php
declare(strict_types=1);

/**
 * LOCAL DEVELOPMENT BOOTSTRAP (config.php)
 * ---------------------------------------------------------------------------
 * NOTE: The real production config.php is intentionally gitignored (it holds
 * live DB credentials, gateway keys and the private bootstrap). It is NOT in
 * the repository. This file is a reconstructed LOCAL DEV bootstrap generated
 * for the Cursor Cloud development environment so the application can boot and
 * be exercised. It reads DB credentials from environment variables and falls
 * back to the local MariaDB dev database. Do not deploy this to production.
 * ---------------------------------------------------------------------------
 */

error_reporting(E_ALL);
require_once __DIR__ . '/includes/boot_errors.php';
date_default_timezone_set('Asia/Kolkata');

// Load .env file if present (secrets management)
if (function_exists('loadEnvFile')) {
    loadEnvFile(__DIR__ . '/.env');
}

/* ------------------------------------------------------------------ *
 *  App / company constants
 * ------------------------------------------------------------------ */
define('APP_NAME', getenv('APP_NAME') ?: 'UniWeb');
define('APP_URL', rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/'));
define('APP_VERSION', '1.0.0-dev');
define('COMPANY_LEGAL_NAME', 'UniWeb Fintech Private Limited');
define('COMPANY_SUPPORT_EMAIL', 'support@uniweb.co.in');
define('COMPANY_ADMIN_EMAIL', 'admin@uniweb.co.in');
define('COMPANY_PHONE', '+911140000000');
// Admin support / social channel links (replace with real values before going live)
if (!defined('SUPPORT_WHATSAPP')) define('SUPPORT_WHATSAPP', '9000000000');
if (!defined('SUPPORT_EMAIL')) define('SUPPORT_EMAIL', 'support@uniweb.co.in');
if (!defined('SUPPORT_INSTAGRAM')) define('SUPPORT_INSTAGRAM', 'https://instagram.com/uniweb');
if (!defined('SUPPORT_TELEGRAM')) define('SUPPORT_TELEGRAM', 'https://t.me/uniweb');
if (!defined('SUPPORT_FACEBOOK')) define('SUPPORT_FACEBOOK', 'https://facebook.com/uniweb');
if (!defined('SUPPORT_TWITTER')) define('SUPPORT_TWITTER', 'https://x.com/uniweb');
if (!defined('SUPPORT_LINKEDIN')) define('SUPPORT_LINKEDIN', 'https://linkedin.com/company/uniweb');
if (!defined('SUPPORT_YOUTUBE')) define('SUPPORT_YOUTUBE', 'https://youtube.com/@uniweb');
define('COMPANY_ADDRESS', 'Bengaluru, Karnataka, India');
define('COMPANY_GST', '29ABCDE1234F1Z5');
if (!defined('ENCRYPTION_KEY')) define('ENCRYPTION_KEY', (string)(getenv('ENCRYPTION_KEY') ?: ''));
define('COMPANY_CIN', 'U72900KA2024PTC000000');
define('COMPANY_CEO', 'UniWeb Management');
define('COMPANY_MAP_URL', 'https://maps.google.com/?q=Bengaluru');
// Named grievance contact — public pages use COMPANY_* so a leftover demo name cannot leak.
define('GRIEVANCE_OFFICER_NAME', COMPANY_CEO);
define('GRIEVANCE_OFFICER_DESIGNATION', 'Managing Director / Grievance Officer');
define('GRIEVANCE_OFFICER_EMAIL', COMPANY_SUPPORT_EMAIL);
define('GRIEVANCE_OFFICER_PHONE', COMPANY_PHONE);
define('ACTIVE_MERCHANT_AGREEMENT_VERSION', date('Y') . '.07.19');
define('MIN_SETTLEMENT', 100);

/* ------------------------------------------------------------------ *
 *  Sensitive data encryption (AES-256-GCM)
 *  Set ENCRYPTION_KEY in production config.php or as an env variable.
 *  Must be 32 raw bytes or base64 of 32 bytes. Never commit the live key.
 * ------------------------------------------------------------------ */
if (!defined('ENCRYPTION_KEY')) {
    define('ENCRYPTION_KEY', getenv('ENCRYPTION_KEY') ?: '');
}
if (ENCRYPTION_KEY === '' && PHP_SAPI !== 'cli') {
    error_log('UNIWEB SECURITY WARNING: ENCRYPTION_KEY is not set. PII encryption disabled. Set it in .env or config.php');
}

/* ------------------------------------------------------------------ *
 *  Storage paths
 * ------------------------------------------------------------------ */
define('PRIVATE_STORAGE_DIR', getenv('UNIWEB_PRIVATE_DIR') ?: (__DIR__ . '/storage'));
define('KYC_PRIVATE_DIR', PRIVATE_STORAGE_DIR . '/kyc_private');
define('UPLOAD_DIR', __DIR__ . '/uploads');
foreach ([PRIVATE_STORAGE_DIR, KYC_PRIVATE_DIR, UPLOAD_DIR] as $__dir) {
    if (!is_dir($__dir)) {
        @mkdir($__dir, 0700, true);
    }
}
unset($__dir);

/* ------------------------------------------------------------------ *
 *  Database (PDO / MySQL). Credentials via env with dev defaults.
 * ------------------------------------------------------------------ */
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'uniweb');
define('DB_USER', getenv('DB_USER') ?: 'uniweb');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'uniweb_dev');

function getDB(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
    ]);
    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  Settings store (gateway_settings key/value table)
 * ------------------------------------------------------------------ */
function getSetting(string $key, string $default = ''): string
{
    global $settingsCache;
    if (!is_array($settingsCache)) {
        $settingsCache = [];
        try {
            $rows = getDB()->query('SELECT setting_key, setting_value FROM gateway_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            foreach ($rows as $k => $v) {
                $settingsCache[(string)$k] = (string)$v;
            }
        } catch (Throwable $e) {
            // DB unavailable: fall through to default values
        }
    }
    return $settingsCache[$key] ?? $default;
}

function saveSetting(string $key, string $value): void
{
    getDB()->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute([$key, $value, $value]);
    clearSettingCache($key);
}

function clearSettingCache(?string $key = null): void
{
    global $settingsCache;
    $settingsCache = null;
}

/* ------------------------------------------------------------------ *
 *  Output / template helpers
 * ------------------------------------------------------------------ */
function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function __(string $key, array $vars = []): string
{
    static $strings = null;
    if ($strings === null) {
        $file = __DIR__ . '/lang/en.php';
        $strings = is_file($file) ? (require $file) : [];
        if (!is_array($strings)) {
            $strings = [];
        }
    }
    $text = $strings[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

function redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    }
    exit;
}

function jsonResponse(array $data, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json');
    }
    echo json_encode($data);
    exit;
}

/* ------------------------------------------------------------------ *
 *  Session bootstrap + flash + CSRF
 * ------------------------------------------------------------------ */
if (session_status() === PHP_SESSION_NONE) {
    if (PHP_SAPI !== 'cli') {
        // Hardened session cookie settings
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'secure'   => $isHttps,
            'samesite' => 'Lax',
        ]);
        session_start();

        // Security headers (set once at bootstrap)
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('X-XSS-Protection: 1; mode=block');
            // Baseline CSP — allows inline scripts/styles (existing code uses them),
            // images/fonts from self and data: URIs, form actions to self,
            // connect-src for API calls and partner redirects.
            // frame-ancestors denies clickjacking; 'self' for form-action.
            $csp = "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data: https:; "
                . "font-src 'self' data:; "
                . "connect-src 'self' https:; "
                . "frame-src 'self' https:; "
                . "form-action 'self' https:; "
                . "base-uri 'self'; "
                . "frame-ancestors 'self'; "
                . "object-src 'none'";
            header('Content-Security-Policy: ' . $csp);
            if ($isHttps) {
                header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
            }
        }
    }
}

function flash(string $type, string $message): void
{
    // Single-message flash model: header.php reads $flash['type'] / $flash['message'].
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): array
{
    $flash = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return (is_array($flash) && isset($flash['type'])) ? $flash : [];
}

function csrfToken(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function verifyCsrf(?string $token): bool
{
    return !empty($_SESSION['_csrf']) && is_string($token) && hash_equals($_SESSION['_csrf'], $token);
}

/* ------------------------------------------------------------------ *
 *  Auth / session helpers
 * ------------------------------------------------------------------ */
function isLoggedIn(): bool
{
    return !empty($_SESSION['merchant_id']);
}

function isAdminLoggedIn(): bool
{
    return !empty($_SESSION['admin_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
}

function requireAdmin(): void
{
    if (!isAdminLoggedIn()) {
        flash('error', 'Please log in to continue.');
        redirect('admin_login.php');
    }
}

function getMerchant(): ?array
{
    if (empty($_SESSION['merchant_id'])) {
        return null;
    }
    static $merchant = null;
    static $cachedId = 0;
    $currentId = (int)$_SESSION['merchant_id'];
    // Clear cache if merchant ID changed (business switch)
    if ($cachedId !== $currentId) {
        $merchant = null;
        $cachedId = $currentId;
    }
    if ($merchant !== null) {
        return $merchant ?: null;
    }
    try {
        $stmt = getDB()->prepare('SELECT * FROM merchants WHERE id = ? LIMIT 1');
        $stmt->execute([$currentId]);
        $merchant = $stmt->fetch() ?: false;
    } catch (Throwable $e) {
        $merchant = false;
    }
    return $merchant ?: null;
}

function getAdmin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $admin = null;
    if ($admin !== null) {
        return $admin ?: null;
    }
    try {
        $stmt = getDB()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: false;
    } catch (Throwable $e) {
        $admin = false;
    }
    return $admin ?: null;
}

function initializePortalSession(): void
{
    if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    $_SESSION['_portal_started'] = time();
    $_SESSION['_portal_last'] = time();
}

function clearPortalSession(string $message = ''): void
{
    $_SESSION = [];
    if ($message !== '') {
        flash('info', $message);
    }
    redirect('login.php');
}

function portalSessionSecurityInfo(): array
{
    $idleLimit = isAdminLoggedIn() ? 1800 : 3600;
    $last = (int)($_SESSION['_portal_last'] ?? time());
    if (isLoggedIn() || isAdminLoggedIn()) {
        $_SESSION['_portal_last'] = time();
    }
    $remaining = max(0, $idleLimit - (time() - $last));
    return ['remaining' => $remaining, 'idle_limit' => $idleLimit];
}

/* ------------------------------------------------------------------ *
 *  Formatting + IDs + badges
 * ------------------------------------------------------------------ */
function formatMoney($amount): string
{
    return '₹' . number_format((float)$amount, 2);
}

function formatDate($datetime): string
{
    if (empty($datetime)) {
        return '-';
    }
    $ts = is_numeric($datetime) ? (int)$datetime : strtotime((string)$datetime);
    return $ts ? date('d M Y, h:i A', $ts) : (string)$datetime;
}

function generateId(string $prefix = ''): string
{
    return $prefix . strtoupper(bin2hex(random_bytes(6)));
}

function statusBadge(string $status): string
{
    $map = [
        'success'   => 'bg-emerald-500/10 text-emerald-400',
        'paid'      => 'bg-emerald-500/10 text-emerald-400',
        'captured'  => 'bg-emerald-500/10 text-emerald-400',
        'verified'  => 'bg-emerald-500/10 text-emerald-400',
        'active'    => 'bg-emerald-500/10 text-emerald-400',
        'completed' => 'bg-emerald-500/10 text-emerald-400',
        'pending'   => 'bg-amber-500/10 text-amber-400',
        'submitted' => 'bg-amber-500/10 text-amber-400',
        'under_review' => 'bg-amber-500/10 text-amber-400',
        'forwarded_partner' => 'bg-violet-500/10 text-violet-400',
        'failed'    => 'bg-red-500/10 text-red-400',
        'rejected'  => 'bg-red-500/10 text-red-400',
        'expired'   => 'bg-gray-500/10 text-gray-400',
    ];
    $cls = $map[strtolower($status)] ?? 'bg-gray-500/10 text-gray-400';
    return '<span class="px-2 py-1 rounded-full text-xs font-medium ' . $cls . '">' . e(ucfirst(str_replace('_', ' ', $status))) . '</span>';
}

function categoryLabel(string $businessType): string
{
    return getBusinessCategories()[$businessType] ?? ucwords(str_replace('_', ' ', $businessType));
}

/* ------------------------------------------------------------------ *
 *  Onboarding / KYC metadata
 *  Entity types + KYC doc maps live in includes/kyc_entity.php (loaded below).
 *  Do not redefine getBusinessEntityTypes / getKycRequirements here — redeclarations
 *  fatal when staff.php loads kyc_entity.php.
 * ------------------------------------------------------------------ */
function getBusinessCategories(): array
{
    return [
        'retail'      => 'Retail / Shop',
        'ecommerce'   => 'E-commerce',
        'services'    => 'Services',
        'food'        => 'Food & Beverage',
        'education'   => 'Education',
        'healthcare'  => 'Healthcare',
        'travel'      => 'Travel & Hospitality',
        'saas'        => 'Software / SaaS',
        'ngo'         => 'NGO / Non-Profit',
        'other'       => 'Other',
    ];
}

/* ------------------------------------------------------------------ *
 *  Notifications — implementation lives in includes/notifications.php
 *  so live config.php drift cannot drop event_key dedup (P0-04).
 * ------------------------------------------------------------------ */
if (is_file(__DIR__ . '/includes/notifications.php')) {
    require_once __DIR__ . '/includes/notifications.php';
}

/* ------------------------------------------------------------------ *
 *  Public homepage stats
 * ------------------------------------------------------------------ */
function getPublicStats(): array
{
    $stats = ['volume' => 0.0, 'merchants' => 0, 'transactions' => 0, 'partners' => 0];
    try {
        $db = getDB();
        $stats['merchants'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active' AND account_mode='live' AND email != 'demo@uniweb.co.in'")->fetchColumn();
        $stats['transactions'] = (int)$db->query("SELECT COUNT(*) FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.status='success' AND t.is_test=0 AND m.account_mode='live' AND m.email != 'demo@uniweb.co.in'")->fetchColumn();
        $stats['volume'] = (float)$db->query("SELECT COALESCE(SUM(t.amount),0) FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.status='success' AND t.is_test=0 AND m.account_mode='live' AND m.email != 'demo@uniweb.co.in'")->fetchColumn();
        if (function_exists('getPublicLivePartners')) {
            $stats['partners'] = count(getPublicLivePartners());
        }
    } catch (Throwable $e) {
        // tables may be empty/missing; return zeros
    }
    return $stats;
}

function formatPublicVolume(float $amount): string
{
    if ($amount >= 10000000) {
        return '₹' . round($amount / 10000000, 1) . ' Cr+';
    }
    if ($amount >= 100000) {
        return '₹' . round($amount / 100000, 1) . ' L+';
    }
    if ($amount >= 1000) {
        return '₹' . round($amount / 1000, 1) . 'K+';
    }
    return '₹' . number_format($amount);
}

/* ------------------------------------------------------------------ *
 *  Load application engine includes (order matters: getDB first).
 * ------------------------------------------------------------------ */
$__includes = [
    'crypto', 'boot_errors', 'env_loader', 'error_catcher', 'schema_ensure', 'migrations', 'financial_integrity', 'ops_security',
    'notifications', 'release_helpers', 'kyc_entity', 'onboarding', 'onboarding_security', 'verification', 'totp', 'notify',
    'velocity_check', 'cron_guard', 'baas', 'gateways', 'smart_routing',
    'wallet', 'settlement_engine', 'reconciliation', 'refunds', 'chargebacks',
    'merchant_profile', 'contact_change', 'merchant_ui', 'page_ux', 'page_ux_compat', 'merchant_admin_view', 'merchant_website',
    'merchant_webhooks', 'pg_webhooks', 'collection', 'upi_confirm',
    'gateway_reason_map', 'transaction_detail', 'ui_links', 'id_click', 'staff', 'partners', 'partner_engine',
    'provision', 'customer_messaging', 'customer_portal', 'mailer', 'qr_svg', 'qr_events',
    'platform_api', 'platform_health', 'link_watchdog', 'auto_audit',
    'morning_ops', 'axis', 'va_manager', 'webhook_queue', 'notify', 'error_catcher', 'rolling_reserve', 'grievance_engine', 'merchant_health', 'webhook_reliability', 'fast_qr_api', 'circuit_breaker', 'rate_limiter', 'split_settlement', 'route_split_partner_api', 'sub_merchant', 'recurring',
    'risk', 'nodal', 'payout', 'env_loader',
    'integration_matrix', 'settlement_delay_spec', 'kyc_timeline', 'cloud_modules',
    'beneficiaries',
    'audit_log',
    'payout_jobs',
    'payout_adapters',
    'payout_partner_api',
    'payout_worker',
    'client_context',
    'multi_merchant',
    'email_templates',
    'payment_methods',
    'partner_forward_queue',
    'auto_kyc',
];
$__loaded = [];
if (!(defined('UNIWEB_HEALTH_PROBE') && UNIWEB_HEALTH_PROBE)) {
    foreach ($__includes as $__inc) {
        if (isset($__loaded[$__inc])) {
            continue;
        }
        $__loaded[$__inc] = true;
        $__path = __DIR__ . '/includes/' . $__inc . '.php';
        if (is_file($__path)) {
            require_once $__path;
        }
    }
}
unset($__includes, $__inc, $__path, $__loaded);
