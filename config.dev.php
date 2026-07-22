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
ini_set('display_errors', getenv('UNIWEB_DISPLAY_ERRORS') === '0' ? '0' : '1');
date_default_timezone_set('Asia/Kolkata');

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
define('COMPANY_ADDRESS', 'Bengaluru, Karnataka, India');
define('COMPANY_GST', '29ABCDE1234F1Z5');
define('COMPANY_CIN', 'U72900KA2024PTC000000');
define('COMPANY_CEO', 'UniWeb Management');
define('COMPANY_MAP_URL', 'https://maps.google.com/?q=Bengaluru');
define('ACTIVE_MERCHANT_AGREEMENT_VERSION', date('Y') . '.07.19');
define('MIN_SETTLEMENT', 100);

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
    ]);
    return $pdo;
}

/* ------------------------------------------------------------------ *
 *  Settings store (gateway_settings key/value table)
 * ------------------------------------------------------------------ */
function getSetting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = getDB()->prepare('SELECT setting_value FROM gateway_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        $cache[$key] = ($val === false || $val === null) ? $default : (string)$val;
    } catch (Throwable $e) {
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function saveSetting(string $key, string $value): void
{
    getDB()->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
        ->execute([$key, $value, $value]);
    clearSettingCache($key);
}

function clearSettingCache(?string $key = null): void
{
    // Cache is request-scoped static in getSetting; nothing persistent to clear.
    // Kept for API compatibility with callers.
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
        session_start();
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
    if ($merchant !== null) {
        return $merchant ?: null;
    }
    try {
        $stmt = getDB()->prepare('SELECT * FROM merchants WHERE id = ? LIMIT 1');
        $stmt->execute([(int)$_SESSION['merchant_id']]);
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
 *  Notifications
 * ------------------------------------------------------------------ */
function createNotification(int $merchantId, string $title, string $body): void
{
    try {
        getDB()->prepare('INSERT INTO notifications (merchant_id, title, message, is_read, created_at) VALUES (?,?,?,0,NOW())')
            ->execute([$merchantId, $title, $body]);
    } catch (Throwable $e) {
        // notifications table may not be ready yet; non-fatal
    }
    if (function_exists('onMerchantNotificationCreated')) {
        try {
            onMerchantNotificationCreated($merchantId, $title, $body);
        } catch (Throwable $e) {
            // WhatsApp / channel fan-out must never break the request
        }
    }
}

function notificationActionUrl(array $row): string
{
    $title = strtolower((string)($row['title'] ?? ''));
    if (str_contains($title, 'kyc')) {
        return 'kyc.php';
    }
    if (str_contains($title, 'payment') || str_contains($title, 'settlement')) {
        return 'transactions.php';
    }
    return 'dashboard.php';
}

/* ------------------------------------------------------------------ *
 *  Public homepage stats
 * ------------------------------------------------------------------ */
function getPublicStats(): array
{
    $stats = ['volume' => 0.0, 'merchants' => 0, 'transactions' => 0];
    try {
        $db = getDB();
        $stats['merchants'] = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active'")->fetchColumn();
        $stats['transactions'] = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
        $stats['volume'] = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE status='success'")->fetchColumn();
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
    'schema_ensure', 'migrations', 'financial_integrity', 'ops_security',
    'kyc_entity', 'onboarding', 'onboarding_security', 'verification', 'totp', 'notify',
    'velocity_check', 'cron_guard', 'baas', 'gateways', 'smart_routing',
    'wallet', 'settlement_engine', 'reconciliation', 'refunds', 'chargebacks',
    'merchant_profile', 'merchant_ui', 'merchant_admin_view', 'merchant_website',
    'merchant_webhooks', 'pg_webhooks', 'collection', 'upi_confirm',
    'transaction_detail', 'ui_links', 'staff', 'partners', 'partner_engine',
    'provision', 'demo', 'demo_tour', 'customer_messaging', 'mailer',
    'platform_api', 'platform_health', 'link_watchdog', 'auto_audit',
    'morning_ops', 'axis', 'notify', 'error_catcher',
];
$__loaded = [];
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
unset($__includes, $__inc, $__path, $__loaded);
