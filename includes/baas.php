<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

if (!defined('UNIWEB_WALLET_CAP_V26')) {
    define('UNIWEB_WALLET_CAP_V26', true);
}

/** B2B BaaS helpers — multi-tenant mode, MDR, geo, wallet */

function merchantAccountMode(?array $merchant): string
{
    if (!$merchant) {
        return 'test';
    }
    $merchantId = (int)($merchant['id'] ?? 0);
    $kycOk = ($merchant['kyc_status'] ?? '') === 'verified'
        && $merchantId > 0
        && function_exists('merchantLiveGateSatisfied')
        && merchantLiveGateSatisfied($merchantId);
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
    $failedSt = $db->prepare("SELECT COUNT(*) as cnt FROM transactions WHERE merchant_id = ? AND status = 'failed' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)" . $modeSql);
    $failedSt->execute([$merchantId]);
    $failedCount = (int)($failedSt->fetch()['cnt'] ?? 0);
    return [
        'today_amount' => capStatAmount((float)($todayData['total'] ?? 0), (int)($todayData['count'] ?? 0)),
        'today_count' => (int)($todayData['count'] ?? 0),
        'month_amount' => capStatAmount((float)($monthData['total'] ?? 0), (int)($monthData['count'] ?? 0)),
        'month_count' => (int)($monthData['count'] ?? 0),
        'total_amount' => capStatAmount((float)($totalData['total'] ?? 0), (int)($totalData['count'] ?? 0)),
        'total_count' => (int)($totalData['count'] ?? 0),
        'success_rate' => $successRate,
        'failed_count_7d' => $failedCount,
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
    $gate = function_exists('merchantLiveGateReport') ? merchantLiveGateReport($merchantId) : ['ok' => false, 'missing' => ['live_gate']];
    if (empty($gate['ok'])) {
        $missing = function_exists('merchantLiveGateMissingLabels')
            ? merchantLiveGateMissingLabels($gate)
            : ($gate['missing'] ?? ['unknown']);
        throw new RuntimeException('Live activation blocked: ' . implode(', ', $missing));
    }
    getDB()->prepare("UPDATE merchants SET account_mode='live',live_enabled_at=NOW() WHERE id=?")->execute([$merchantId]);
    $m = getDB()->prepare('SELECT collection_mode FROM merchants WHERE id=?');
    $m->execute([$merchantId]);
    $row = $m->fetch();
    if (($row['collection_mode'] ?? '') === 'axis_va') {
        ensureAxisVirtualAccount($merchantId);
    }
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Account Live!', 'Your KYC is approved. Your dashboard is now in LIVE mode — accept real payments.', 'account_live_' . $merchantId);
    } else {
        createNotification($merchantId, 'Account Live!', 'Your KYC is approved. Your dashboard is now in LIVE mode — accept real payments.');
    }
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

/** Merchant-facing fee + settlement schedule (Razorpay-style transparency card). */
function merchantCommercialSchedule(array $merchant): array
{
    $commission = (float)($merchant['commission_rate'] ?? getSetting('default_commission', '1.50'));
    $cycle = function_exists('getPlatformSettlementCycle')
        ? getPlatformSettlementCycle()
        : (string)getSetting('settlement_cycle', 'T+1');
    $minSettle = (float)getSetting('min_settlement_amount', '100');
    $prefs = function_exists('getMerchantSettlementPrefs') ? getMerchantSettlementPrefs($merchant) : [];
    $mode = (string)($prefs['mode'] ?? 'manual');
    $methods = [];
    foreach (['upi', 'card_debit', 'card_credit', 'netbanking', 'wallet'] as $key) {
        $meta = getPaymentModes()[$key] ?? null;
        if (!$meta) {
            continue;
        }
        $methods[] = [
            'key' => $key,
            'label' => $meta['label'],
            'mdr' => getMdrWithMargin($key, $merchant),
            'gst' => !empty($meta['gst']),
            'custom' => !empty($meta['custom']),
        ];
    }
    return [
        'platform_commission' => $commission,
        'settlement_cycle' => $cycle,
        'min_settlement' => $minSettle,
        'settlement_mode' => $mode === 'scheduled' ? 'Scheduled batch' : 'Manual settle',
        'account_mode' => merchantAccountMode($merchant),
        'methods' => $methods,
        'grouped' => getGroupedMerchantPricing($merchant),
    ];
}

/**
 * Build grouped pricing rows: method+rate → partner names.
 * Only includes active partners with enabled methods.
 * Returns [['method_label','rate','partners_csv','gst']]
 */
function getGroupedMerchantPricing(?array $merchant = null): array
{
    if (!function_exists('getPartnerRegistry')) {
        return [];
    }
    $registry = getPartnerRegistry();
    $isTestMode = $merchant && merchantAccountMode($merchant) === 'test';
    $groups = [];
    try {
        $db = getDB();
        $sql = "SELECT pm.partner_key, pm.method, pm.base_mdr_percent, g.gateway_name, g.is_active
                FROM partner_methods pm
                JOIN gateway_registry g ON g.gateway_key = pm.partner_key
                WHERE pm.is_enabled = 1 AND g.is_active = 1";
        if (!$isTestMode) {
            $sql .= " AND (g.public_go_live = 1 OR g.public_go_live IS NULL)";
        }
        $sql .= " ORDER BY pm.method, pm.base_mdr_percent";
        $rows = $db->query($sql)->fetchAll();
    } catch (Throwable $e) {
        $rows = [];
    }
    $modeLabels = [
        'upi' => 'UPI', 'credit_card' => 'Credit Card', 'debit_card' => 'Debit Card',
        'netbanking' => 'Net Banking', 'wallet' => 'Wallets', 'emi' => 'EMI',
    ];
    foreach ($rows as $row) {
        $method = $row['method'];
        $rate = (float)$row['base_mdr_percent'];
        $partnerName = $row['gateway_name'] ?: ($registry[$row['partner_key']]['name'] ?? ucfirst($row['partner_key']));
        $label = $modeLabels[$method] ?? ucfirst($method);
        $key = $method . '|' . number_format($rate, 2);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'method_label' => $label,
                'rate' => $rate,
                'partners' => [],
                'gst' => in_array($method, ['credit_card', 'debit_card', 'netbanking'], true),
            ];
        }
        $groups[$key]['partners'][] = $partnerName;
    }
    return array_values($groups);
}

function renderMerchantCommercialCard(array $merchant): void
{
    $s = merchantCommercialSchedule($merchant);
    ?>
    <div class="glass rounded-xl p-5 mb-6 border border-violet-500/20">
        <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
            <div>
                <p class="text-xs text-violet-400 uppercase tracking-wider">Your commercial schedule</p>
                <h2 class="font-semibold text-white mt-1">Fees &amp; settlement</h2>
                <p class="text-xs text-gray-500 mt-1">Same clarity merchants expect from Indian payment gateways. Portal values are authoritative.</p>
            </div>
            <div class="text-right text-xs text-gray-500 space-y-1">
                <p>Mode: <span class="text-gray-300"><?= e(ucfirst($s['account_mode'])) ?></span></p>
                <p>Settlement: <span class="text-gray-300"><?= e($s['settlement_cycle']) ?> · <?= e($s['settlement_mode']) ?></span></p>
                <p>Min transfer: <span class="text-gray-300"><?= formatMoney($s['min_settlement']) ?></span></p>
            </div>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm mb-4">
            <div class="rounded-lg border border-gray-800 px-3 py-2">
                <p class="text-[10px] text-gray-600 uppercase">Platform commission</p>
                <p class="font-semibold text-sky-300 mt-0.5"><?= e(rtrim(rtrim(number_format($s['platform_commission'], 2), '0'), '.')) ?>%</p>
                <p class="text-[10px] text-gray-600">On successful collections (merchant schedule)</p>
            </div>
            <?php foreach ($s['methods'] as $m): ?>
            <div class="rounded-lg border border-gray-800 px-3 py-2">
                <p class="text-[10px] text-gray-600 uppercase"><?= e($m['label']) ?></p>
                <p class="font-semibold text-gray-200 mt-0.5"><?= e(formatMdr($m['mdr'], $m['custom'], $m['gst'])) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($s['grouped'])): ?>
        <div class="mb-4">
            <h3 class="text-sm font-semibold mb-2">Partner rates by method</h3>
            <div class="overflow-x-auto rounded-lg border border-gray-800">
                <table class="w-full text-xs">
                    <thead class="text-gray-500 uppercase bg-dark-900/50"><tr>
                        <th class="px-4 py-2 text-left">Method</th>
                        <th class="px-4 py-2 text-left">Partner MDR (P)</th>
                        <th class="px-4 py-2 text-left">Partners</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-800">
                        <?php foreach ($s['grouped'] as $g): ?>
                        <tr>
                            <td class="px-4 py-2 text-gray-300"><?= e($g['method_label']) ?></td>
                            <td class="px-4 py-2 font-mono text-gray-200"><?= e(number_format($g['rate'], 2)) ?>%<?= $g['gst'] ? ' + GST' : '' ?></td>
                            <td class="px-4 py-2 text-gray-500"><?= e(implode(', ', $g['partners'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <p class="text-[11px] text-gray-600">Gateway MDR may include partner charges + published platform margin. Refunds, chargebacks and holds can reduce settleable balance. <a href="pricing.php" class="text-sky-400">Public pricing</a> · <a href="merchant_settlement_settings.php" class="text-sky-400">Settlement settings</a></p>
    </div>
    <?php
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
    $canonicalUploaded = [];
    foreach ($uploaded as $doc) {
        $canonicalUploaded[canonicalizeKycDocType((string)$doc)] = true;
    }
    $missing = [];
    $have = 0;
    foreach ($required as $doc) {
        $key = canonicalizeKycDocType((string)$doc);
        if (!empty($canonicalUploaded[$key])) {
            $have++;
        } else {
            $missing[] = $labels[$doc] ?? $doc;
        }
    }
    return [
        'uploaded' => $have,
        'required' => count($required),
        'complete' => empty($missing),
        'missing' => $missing,
    ];
}

function requireKycDocumentsUploaded(): void
{
    $merchant = getMerchant();
    if (!$merchant) {
        flash('error', 'Your session expired. Please log in again.');
        redirect('login.php');
    }
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
    if (!$merchant) {
        flash('error', 'Your session expired. Please log in again.');
        redirect('login.php');
    }
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

/** Platform amount safety cap. Real per-txn limits are enforced by the active bank / payment partner. */
function livePaymentAmountCap(): float
{
    return 999999999999.99;
}

/** True when this checkout link was created from a merchant QR. */
function isQrOriginPaymentLink(array $link): bool
{
    return (int)($link['qr_code_id'] ?? 0) > 0;
}

/** Cap payment / wallet amounts — test max ₹100, live uses the platform safety cap. */
function sanitizePaymentAmount(float $amount, bool $isTest = true): float
{
    if (!is_finite($amount) || $amount < 0) {
        return 0.0;
    }
    $max = $isTest ? 100.0 : livePaymentAmountCap();
    return round(min($amount, $max), 2);
}

function walletCreditCap(bool $isTest): float
{
    return $isTest ? 100.0 : livePaymentAmountCap();
}

function walletCorruptThreshold(bool $isTest): float
{
    // Slightly above live payment cap so a full-day wallet accrual is not wiped as "corrupt".
    return $isTest ? 1000.0 : (livePaymentAmountCap() * 50);
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
