<?php
declare(strict_types=1);

if (!defined('UNIWEB_WALLET_CAP_V26')) {
    define('UNIWEB_WALLET_CAP_V26', true);
}

/** B2B BaaS helpers — multi-tenant mode, MDR, geo, wallet */

function merchantAccountMode(?array $merchant): string
{
    if (!$merchant) {
        return 'test';
    }
    $kycOk = ($merchant['kyc_status'] ?? '') === 'verified';
    $modeLive = ($merchant['account_mode'] ?? '') === 'live';
    if ($modeLive && $kycOk) {
        return 'live';
    }
    return 'test';
}

function isMerchantLive(?array $merchant): bool
{
    return merchantAccountMode($merchant) === 'live';
}

function isMerchantTest(?array $merchant): bool
{
    return !isMerchantLive($merchant);
}

/** Whether merchant account is approved for real payments (KYC + admin live) */
function merchantCanGoLive(?array $merchant): bool
{
    return isMerchantLive($merchant);
}

/** Active dashboard view — live merchants can switch to test view (like Razorpay/PayU) */
function getDashboardViewMode(?array $merchant): string
{
    if (!$merchant || !merchantCanGoLive($merchant)) {
        return 'test';
    }
    $mode = $_SESSION['dashboard_view_mode'] ?? 'live';
    return in_array($mode, ['test', 'live'], true) ? $mode : 'live';
}

function setDashboardViewMode(?array $merchant, string $mode): void
{
    if (!$merchant) {
        return;
    }
    if ($mode === 'live' && !merchantCanGoLive($merchant)) {
        $mode = 'test';
    }
    $_SESSION['dashboard_view_mode'] = in_array($mode, ['test', 'live'], true) ? $mode : 'test';
}

function isDashboardTestMode(?array $merchant): bool
{
    return getDashboardViewMode($merchant) === 'test';
}

function isDashboardLiveMode(?array $merchant): bool
{
    return getDashboardViewMode($merchant) === 'live';
}

/** Payment / link creation uses dashboard view when live-approved */
function isMerchantPaymentTest(?array $merchant): bool
{
    if (!merchantCanGoLive($merchant)) {
        return true;
    }
    return isDashboardTestMode($merchant);
}

function getMerchantStatsForMode(int $merchantId, bool $testMode): array
{
    $db = getDB();
    $cap = 'LEAST(amount, 1000)';
    $modeSql = $testMode ? ' AND is_test=1' : ' AND is_test=0';
    $today = $db->prepare("SELECT COALESCE(SUM($cap),0) as total, COUNT(*) as count FROM transactions WHERE merchant_id = ? AND status = 'success' AND DATE(created_at) = CURDATE()" . $modeSql);
    $today->execute([$merchantId]);
    $todayData = $today->fetch();
    $month = $db->prepare("SELECT COALESCE(SUM($cap),0) as total, COUNT(*) as count FROM transactions WHERE merchant_id = ? AND status = 'success' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())" . $modeSql);
    $month->execute([$merchantId]);
    $monthData = $month->fetch();
    $total = $db->prepare("SELECT COALESCE(SUM($cap),0) as total, COUNT(*) as count FROM transactions WHERE merchant_id = ? AND status = 'success'" . $modeSql);
    $total->execute([$merchantId]);
    $totalData = $total->fetch();
    $rateSt = $db->prepare("SELECT COUNT(*) AS total, SUM(status='success') AS ok FROM transactions WHERE merchant_id = ?" . $modeSql);
    $rateSt->execute([$merchantId]);
    $rateRow = $rateSt->fetch() ?: ['total' => 0, 'ok' => 0];
    $rateTotal = (int)($rateRow['total'] ?? 0);
    $successRate = $rateTotal > 0 ? round(100 * (int)($rateRow['ok'] ?? 0) / $rateTotal, 1) : 0.0;
    return [
        'today_amount' => capStatAmount((float)($todayData['total'] ?? 0), (int)($todayData['count'] ?? 0)),
        'today_count' => (int)($todayData['count'] ?? 0),
        'month_amount' => capStatAmount((float)($monthData['total'] ?? 0), (int)($monthData['count'] ?? 0)),
        'month_count' => (int)($monthData['count'] ?? 0),
        'total_amount' => capStatAmount((float)($totalData['total'] ?? 0), (int)($totalData['count'] ?? 0)),
        'total_count' => (int)($totalData['count'] ?? 0),
        'success_rate' => $successRate,
    ];
}

function accountModeBadge(?array $merchant): string
{
    if (isDashboardTestMode($merchant)) {
        return '<span class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-400 border border-amber-500/30">⚡ TEST MODE</span>';
    }
    return '<span class="px-2 py-0.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">● LIVE MODE</span>';
}

function merchantModeToggleUrl(string $mode, ?string $return = null): string
{
    $return = $return ?: basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
    if (!preg_match('/^[a-z0-9_.-]+$/i', $return)) {
        $return = 'dashboard.php';
    }
    return 'merchant_toggle_mode.php?mode=' . rawurlencode($mode) . '&return=' . rawurlencode($return) . '&csrf=' . rawurlencode(csrfToken());
}

function renderMerchantModeToggle(?array $merchant, string $variant = 'header'): string
{
    if (!$merchant) {
        return '';
    }
    $view = getDashboardViewMode($merchant);
    $canLive = merchantCanGoLive($merchant);
    $return = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
    $testActive = $view === 'test';
    $liveActive = $view === 'live';

    if ($variant === 'sidebar') {
        $href = $testActive && $canLive
            ? merchantModeToggleUrl('live', $return)
            : merchantModeToggleUrl('test', $return);
        $label = $testActive ? 'Enable Live Mode' : 'Enable Test Mode';
        $hint = $testActive ? 'Sandbox · no real money' : 'Real payments enabled';
        if (!$canLive) {
            return '<div class="px-3 py-3 border-t border-gray-800">'
                . '<div class="flex items-center justify-between gap-2">'
                . '<div><p class="text-xs font-semibold text-amber-400">Test Mode</p><p class="text-[10px] text-gray-500">Complete KYC for Live</p></div>'
                . '<span class="mode-switch mode-switch-on" aria-hidden="true"><span class="mode-switch-knob"></span></span>'
                . '</div></div>';
        }
        $switchCls = 'mode-switch mode-switch-on' . ($testActive ? '' : ' mode-switch-live');
        return '<a href="' . e($href) . '" class="block px-3 py-3 border-t border-gray-800 hover:bg-white/5 transition group" title="' . e($label) . '">'
            . '<div class="flex items-center justify-between gap-2">'
            . '<div><p class="text-xs font-semibold ' . ($testActive ? 'text-amber-400' : 'text-emerald-400') . '">' . ($testActive ? 'Test Mode' : 'Live Mode') . '</p>'
            . '<p class="text-[10px] text-gray-500 group-hover:text-gray-400">' . e($hint) . ' · tap to switch</p></div>'
            . '<span class="' . $switchCls . '" aria-hidden="true"><span class="mode-switch-knob"></span></span>'
            . '</div></a>';
    }

    if ($variant === 'profile') {
        if (!$canLive) {
            return '<span class="block px-4 py-2.5 text-sm text-amber-400/80 border-b border-gray-800">⚡ Test Mode (KYC pending)</span>';
        }
        if ($testActive) {
            return '<a href="' . e(merchantModeToggleUrl('live', $return)) . '" class="block px-4 py-2.5 text-sm text-emerald-400 hover:bg-white/5 border-b border-gray-800">● Enable Live Mode</a>';
        }
        return '<a href="' . e(merchantModeToggleUrl('test', $return)) . '" class="block px-4 py-2.5 text-sm text-amber-400 hover:bg-white/5 border-b border-gray-800">⚡ Enable Test Mode</a>';
    }

    // header pill switcher (PayU / Razorpay style)
    if (!$canLive) {
        return '<div class="mode-pill mode-pill-test" title="Complete KYC to unlock Live Mode">'
            . '<span class="mode-dot mode-dot-amber"></span><span class="hidden sm:inline">Test Mode</span></div>';
    }
    return '<div class="mode-pill-group" role="group" aria-label="Payment mode">'
        . '<a href="' . e(merchantModeToggleUrl('test', $return)) . '" class="mode-pill ' . ($testActive ? 'mode-pill-active mode-pill-test' : 'mode-pill-inactive') . '"><span class="mode-dot mode-dot-amber"></span><span class="hidden sm:inline">Test</span></a>'
        . '<a href="' . e(merchantModeToggleUrl('live', $return)) . '" class="mode-pill ' . ($liveActive ? 'mode-pill-active mode-pill-live' : 'mode-pill-inactive') . '"><span class="mode-dot mode-dot-green"></span><span class="hidden sm:inline">Live</span></a>'
        . '</div>';
}

function renderMerchantTestStripe(?array $merchant): string
{
    if (!$merchant || !isDashboardTestMode($merchant)) {
        return '';
    }
    $note = merchantCanGoLive($merchant)
        ? 'You are viewing Test Mode — sandbox only, no real money. Switch to Live Mode for production.'
        : 'Test Mode — complete KYC and wait for admin approval to accept real payments.';
    return '<div class="mode-test-stripe">⚡ TEST MODE — ' . e($note) . '</div>';
}

function activateMerchantLive(int $merchantId): void
{
    getDB()->prepare("UPDATE merchants SET account_mode='live', kyc_status='verified' WHERE id=?")->execute([$merchantId]);
    $m = getDB()->prepare('SELECT collection_mode FROM merchants WHERE id=?');
    $m->execute([$merchantId]);
    $row = $m->fetch();
    if (($row['collection_mode'] ?? '') === 'axis_va') {
        ensureAxisVirtualAccount($merchantId);
    }
    createNotification($merchantId, 'Account Live!', 'Your KYC is approved. Your dashboard is now in LIVE mode — accept real payments.');
    notifyMerchantEmail(
        $merchantId,
        'KYC approved — Live mode enabled',
        'Your KYC verification is complete. Live mode is now active on your dashboard — you can accept real payments and switch between Test and Live in the header.'
    );
    $_SESSION['dashboard_view_mode'] = 'live';
}

function setMerchantTestMode(int $merchantId): void
{
    getDB()->prepare("UPDATE merchants SET account_mode='test' WHERE id=?")->execute([$merchantId]);
}

function getPlatformMarginPct(): float
{
    return (float)getSetting('platform_margin_pct', '0.10');
}

function getPaymentModes(): array
{
    return [
        'upi' => ['label' => 'UPI / QR', 'icon' => '📱', 'base_key' => 'upi_mdr', 'default' => 0.00, 'custom' => false],
        'card_debit' => ['label' => 'Debit Card', 'icon' => '💳', 'base_key' => 'card_mdr', 'default' => 1.90, 'custom' => false, 'gst' => true],
        'card_credit' => ['label' => 'Credit Card', 'icon' => '💳', 'base_key' => 'card_mdr', 'default' => 1.90, 'custom' => false, 'gst' => true],
        'netbanking' => ['label' => 'Net Banking', 'icon' => '🏦', 'base_key' => 'netbanking_mdr', 'default' => 1.90, 'custom' => false, 'gst' => true],
        'wallet' => ['label' => 'Wallets (Paytm, PhonePe)', 'icon' => '👛', 'base_key' => 'wallet_mdr', 'default' => 1.75, 'custom' => false],
        'emi' => ['label' => 'EMI', 'icon' => '📅', 'base_key' => 'emi_mdr', 'default' => 2.50, 'custom' => false],
        'bnpl' => ['label' => 'Buy Now Pay Later', 'icon' => '🛒', 'base_key' => 'bnpl_mdr', 'default' => 2.00, 'custom' => false],
        'international' => ['label' => 'International Cards', 'icon' => '🌍', 'base_key' => 'international_mdr', 'default' => 3.50, 'custom' => false],
        'axis_bank' => ['label' => 'Axis Bank Gateway', 'icon' => '🏛️', 'base_key' => 'axis_mdr', 'default' => 0.00, 'custom' => true],
        'enterprise' => ['label' => 'Enterprise / High Volume', 'icon' => '⭐', 'base_key' => '', 'default' => 0.00, 'custom' => true],
    ];
}

function getBaseMdr(string $mode): float
{
    $modes = getPaymentModes();
    if (!isset($modes[$mode])) return 0.0;
    $m = $modes[$mode];
    if ($m['custom']) return 0.0;
    $key = $m['base_key'];
    return $key ? (float)getSetting($key, (string)$m['default']) : (float)$m['default'];
}

function getMdrWithMargin(string $mode, ?array $merchant = null): float
{
    if (($merchant['commission_rate'] ?? null) && in_array($mode, ['card_debit', 'card_credit', 'netbanking'], true)) {
        return (float)$merchant['commission_rate'];
    }
    $base = getBaseMdr($mode);
    if ($base <= 0) return 0.0;
    return round($base + getPlatformMarginPct(), 2);
}

function formatMdr(?float $pct, bool $custom = false, bool $withGst = false): string
{
    if ($custom) return 'Custom Price';
    if ($pct === null || $pct <= 0) return '0%';
    $s = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
    return $withGst ? $s . ' + GST' : $s;
}

function getMerchantKycProgress(?array $merchant): array
{
    if (!$merchant) return ['uploaded' => 0, 'required' => 0, 'complete' => false, 'missing' => []];
    $entity = $merchant['business_entity_type'] ?? 'sole_proprietorship';
    $required = getKycRequirements($entity);
    $db = getDB();
    $stmt = $db->prepare("SELECT doc_type FROM kyc_documents WHERE merchant_id = ? AND status != 'rejected'");
    $stmt->execute([$merchant['id']]);
    $uploaded = array_column($stmt->fetchAll(), 'doc_type');
    $labels = getKycDocLabels();
    $missing = [];
    foreach ($required as $doc) {
        if (!in_array($doc, $uploaded, true)) {
            $missing[] = $labels[$doc] ?? $doc;
        }
    }
    return [
        'uploaded' => count(array_intersect($required, $uploaded)),
        'required' => count($required),
        'complete' => empty($missing),
        'missing' => $missing,
    ];
}

function requireKycDocumentsUploaded(): void
{
    $merchant = getMerchant();
    if (!$merchant) redirect('login.php');
    if (isMerchantTest($merchant)) {
        return;
    }
    $progress = getMerchantKycProgress($merchant);
    if (!$progress['complete']) {
        flash('error', 'Upload all KYC documents before using payment features. Missing: ' . implode(', ', array_slice($progress['missing'], 0, 3)));
        redirect('kyc.php');
    }
}

function requireLivePayments(): void
{
    $merchant = getMerchant();
    if (!$merchant) redirect('login.php');
    if (isMerchantTest($merchant)) {
        flash('error', 'Live payments are disabled in Test Mode. Complete KYC and wait for admin approval to go Live.');
        redirect('dashboard.php');
    }
}

function detectVisitorCountry(): string
{
    if (!empty($_SESSION['visitor_country'])) {
        return $_SESSION['visitor_country'];
    }
    $ip = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
    if (strlen($ip) === 2) {
        $_SESSION['visitor_country'] = strtoupper($ip) === 'IN' ? 'India' : 'International';
        return $_SESSION['visitor_country'];
    }
    $addr = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $addr = trim(explode(',', $addr)[0]);
    if ($addr && filter_var($addr, FILTER_VALIDATE_IP) && $addr !== '127.0.0.1') {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $json = @file_get_contents('http://ip-api.com/json/' . urlencode($addr) . '?fields=country', false, $ctx);
        if ($json) {
            $data = json_decode($json, true);
            $country = $data['country'] ?? 'India';
            $_SESSION['visitor_country'] = ($country === 'India') ? 'India' : 'International';
            return $_SESSION['visitor_country'];
        }
    }
    $_SESSION['visitor_country'] = 'India';
    return 'India';
}

function isIndiaVisitor(): bool
{
    return detectVisitorCountry() === 'India';
}

function getMerchantWalletBalance(?array $merchant): float
{
    if (!$merchant) return 0.0;
    $id = (int)($merchant['id'] ?? 0);
    if ($id > 0) {
        return refreshMerchantWalletBalance($id);
    }
    $bal = (float)($merchant['wallet_balance'] ?? 0);
    return $bal > 1000 ? 0.0 : $bal;
}

/** Cap payment / wallet amounts — test max ₹100, live max ₹5L */
function sanitizePaymentAmount(float $amount, bool $isTest = true): float
{
    if (!is_finite($amount) || $amount < 0) {
        return 0.0;
    }
    $max = $isTest ? 100.0 : 500000.0;
    return round(min($amount, $max), 2);
}

function walletCreditCap(bool $isTest): float
{
    return $isTest ? 100.0 : 500000.0;
}

function walletCorruptThreshold(bool $isTest): float
{
    return $isTest ? 1000.0 : 500000.0;
}

function safeDisplayBalance(float $amount, bool $isTest = true): float
{
    return walletAmount($amount, $isTest);
}

/** Always use for wallet UI and transfer validation */
function walletAmount(float $amount, bool $isTest = true): float
{
    $max = walletCorruptThreshold($isTest);
    if ($amount < 0 || !is_finite($amount) || $amount > $max) {
        return 0.0;
    }
    return round($amount, 2);
}

function walletMoney(float $amount, bool $isTest = true): string
{
    return formatMoney(walletAmount($amount, $isTest));
}

function getSubscriptionPlans(): array
{
    return [
        'starter' => ['name' => 'Starter', 'monthly_fee' => 0, 'label' => 'Free — pay per transaction'],
        'business' => ['name' => 'Business', 'monthly_fee' => 999, 'label' => '₹999/month + lower MDR'],
        'enterprise' => ['name' => 'Enterprise', 'monthly_fee' => 0, 'label' => 'Custom — Contact Sales'],
    ];
}
