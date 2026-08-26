<?php
declare(strict_types=1);

require_once __DIR__ . '/risk.php';
require_once __DIR__ . '/nodal.php';

/** B2B Collection engine — direct UPI, PayU split, Axis VA, PG routes */

function getCollectionModes(): array
{
    return [
        'direct_upi' => 'P2M Direct UPI (zero liability — merchant VPA)',
        'payu_split' => 'PayU Split Settlement (auto commission)',
        'razorpay_route' => 'Razorpay Route (linked account transfer)',
        'cashfree_route' => 'Cashfree Easy Split (vendor payout)',
        'axis_va' => 'Axis Virtual Account + UPI Collection',
        'platform_pg' => 'Platform checkout (Card · UPI · Net Banking — UniWeb methods pool)',
    ];
}

function getMerchantCollectionMode(array $merchant): string
{
    $mode = $merchant['collection_mode'] ?? '';
    $modes = array_keys(getCollectionModes());
    if ($mode && in_array($mode, $modes, true)) return $mode;
    $default = getSetting('default_collection_mode', 'direct_upi');
    return in_array($default, $modes, true) ? $default : 'direct_upi';
}

function merchantCollectionModeLabel(string $mode): string
{
    return match ($mode) {
        'direct_upi' => 'Direct UPI (your business VPA)',
        'platform_pg' => 'Platform checkout (Card · UPI · Net Banking)',
        'axis_va' => 'Virtual account collection',
        'payu_split', 'razorpay_route', 'cashfree_route' => 'Platform checkout (legacy — not selectable)',
        default => 'Platform checkout',
    };
}

function getMerchantFacingCollectionModes(?array $merchant = null): array
{
    // P11-01: merchants do not pick Route/Split rails as a live product — labels are method-only.
    $keys = ['direct_upi', 'platform_pg'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = merchantCollectionModeLabel($k);
    }
    $current = $merchant ? getMerchantCollectionMode($merchant) : '';
    if ($current && !isset($out[$current])) {
        $out[$current] = merchantCollectionModeLabel($current) . ' (current — not selectable for new links)';
    }
    return $out;
}

/**
 * Platform Settings template for new merchants — hide parked Route/Split modes.
 * If a parked value is already saved, keep it visible once as "(parked)".
 */
function getAdminTemplateCollectionModes(?string $currentMode = null): array
{
    $keys = ['direct_upi', 'platform_pg', 'axis_va'];
    $parked = ['payu_split', 'razorpay_route', 'cashfree_route'];
    $all = getCollectionModes();
    $out = [];
    foreach ($keys as $k) {
        if (isset($all[$k])) {
            $out[$k] = $all[$k];
        }
    }
    $current = trim((string)$currentMode);
    if ($current !== '' && isset($all[$current]) && !isset($out[$current])) {
        $suffix = in_array($current, $parked, true)
            ? ' (parked — Route/Split is not live)'
            : '';
        $out[$current] = $all[$current] . $suffix;
    }
    return $out;
}

function collectionModeLabel(string $mode, bool $merchantFacing = false): string
{
    if ($merchantFacing) {
        return merchantCollectionModeLabel($mode);
    }
    return getCollectionModes()[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

function merchantEffectiveMdrPercent(array $merchant): float
{
    $merchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);
    if ($merchantId > 0 && function_exists('getMerchantMdr')) {
        return getMerchantMdr($merchantId);
    }
    $rate = (float)($merchant['commission_rate'] ?? 0);
    if ($rate > 0) {
        return $rate;
    }
    $default = (float)getSetting('default_commission', (string)(defined('DEFAULT_MDR_PERCENT') ? DEFAULT_MDR_PERCENT : '2.00'));
    return $default > 0 ? $default : 2.0;
}

function calculatePlatformCommission(float $amount, array $merchant): float
{
    $rate = merchantEffectiveMdrPercent($merchant);
    if ($rate <= 0) {
        $rate = (float)getSetting('platform_margin_pct', '0.10');
    }
    return round($amount * $rate / 100, 2);
}

/**
 * Human labels for PPT-style ₹100 split (Admin cut + Partner cut + Merchant baaki).
 * Uses Admin-saved M/P via calculateSplitBreakdown — same engine as capture.
 *
 * @return array{gross:float,admin_cut:float,partner_cut:float,merchant_net:float,mdr_m:float,mdr_p:float,lines:list<string>}
 */
function commissionSplitRealtimePreview(float $amount, array $merchant): array
{
    $split = calculateSplitBreakdown($amount, $merchant);
    $admin = (float)($split['platform_fee'] ?? 0);
    $partner = (float)($split['partner_fee'] ?? 0);
    $merchantNet = (float)($split['merchant_net'] ?? 0);
    return [
        'gross' => (float)($split['gross'] ?? $amount),
        'admin_cut' => $admin,
        'partner_cut' => $partner,
        'merchant_net' => $merchantNet,
        'mdr_m' => (float)($split['mdr_m'] ?? 0),
        'mdr_p' => (float)($split['mdr_p'] ?? 0),
        'lines' => [
            'Gross ' . formatMoney((float)($split['gross'] ?? $amount)),
            'Admin cut ' . formatMoney($admin),
            'Partner cut ' . formatMoney($partner),
            'Merchant baaki ' . formatMoney($merchantNet),
        ],
    ];
}

/**
 * F2: Calculate split breakdown using M (merchant MDR) and P (partner base MDR).
 * Returns gross, mdr_m, mdr_p, platform_fee, merchant_net, partner_fee (informational),
 * and pricing_snapshot JSON.
 *
 * If split_settlement functions are available, uses getMerchantMdr() + getPartnerBaseMdr().
 * Falls back to legacy commission_rate for backward compat.
 */
function calculateSplitBreakdown(float $amount, array $merchant): array
{
    $merchantId = (int)($merchant['merchant_id'] ?? $merchant['id'] ?? 0);

    // F2: Try new M/P pricing model
    if ($merchantId > 0 && function_exists('getMerchantMdr')) {
        $m = getMerchantMdr($merchantId);
        // Determine partner key from merchant link or collection mode
        $partnerKey = (string)($merchant['partner_key'] ?? '');
        if ($partnerKey === '' && function_exists('getMerchantPartnerLinks')) {
            $links = getMerchantPartnerLinks($merchantId);
            foreach ($links as $link) {
                if (($link['kyc_status'] ?? '') === 'live') {
                    $partnerKey = (string)$link['partner_key'];
                    break;
                }
            }
        }
        $method = (string)($merchant['method'] ?? $merchant['payment_method'] ?? $merchant['mode'] ?? '');
        $p = $partnerKey !== '' ? getPartnerBaseMdr($partnerKey, $method !== '' ? $method : null) : 0.0;

        $merchantFee = round($amount * $m / 100, 2);
        $platformFee = round($amount * ($m - $p) / 100, 2);
        $partnerFee = round($amount * $p / 100, 2);
        $merchantNet = round($amount - $merchantFee, 2);

        // F7: Safety — merchant_net + platform_fee must not exceed gross (1-paise rule, remainder to platform)
        $remainder = round($amount - $merchantNet - $platformFee, 2);
        if (abs($remainder) > 0.001) {
            $platformFee = round($platformFee + $remainder, 2);
        }

        return [
            'gross' => $amount,
            'mdr_m' => $m,
            'mdr_p' => $p,
            'platform_fee' => max(0, $platformFee),
            'merchant_net' => max(0, $merchantNet),
            'partner_fee' => $partnerFee,
            'pricing_snapshot' => json_encode([
                'gross' => $amount,
                'mdr_m' => $m,
                'mdr_p' => $p,
                'merchant_fee' => $merchantFee,
                'platform_fee' => max(0, $platformFee),
                'merchant_net' => max(0, $merchantNet),
                'partner_fee' => $partnerFee,
                'partner_key' => $partnerKey,
                'method' => $method,
            ], JSON_UNESCAPED_SLASHES),
        ];
    }

    // Legacy fallback
    $platformFee = calculatePlatformCommission($amount, $merchant);
    $merchantNet = round($amount - $platformFee, 2);
    return [
        'gross' => $amount,
        'mdr_m' => 0.0,
        'mdr_p' => 0.0,
        'platform_fee' => $platformFee,
        'merchant_net' => max(0, $merchantNet),
        'partner_fee' => 0.0,
        'pricing_snapshot' => json_encode([
            'gross' => $amount,
            'mdr_m' => 0,
            'mdr_p' => 0,
            'platform_fee' => $platformFee,
            'merchant_net' => max(0, $merchantNet),
            'partner_fee' => 0,
            'legacy' => true,
        ], JSON_UNESCAPED_SLASHES),
    ];
}

function recordSplitPayment(int $transactionId, int $merchantId, array $split, string $method = 'auto'): void
{
    $db = getDB();
    try {
        if (!function_exists('ensureSplitSettlementTable')) {
            require_once __DIR__ . '/split_settlement.php';
        }
        if (function_exists('ensureSplitSettlementTable')) {
            ensureSplitSettlementTable();
        }
        $db->prepare('INSERT INTO transaction_splits (transaction_id, merchant_id, gross_amount, platform_fee, merchant_net, split_status) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE platform_fee=VALUES(platform_fee), merchant_net=VALUES(merchant_net)')
            ->execute([$transactionId, $merchantId, $split['gross'] ?? $split['platform_fee'] + $split['merchant_net'], $split['platform_fee'], $split['merchant_net'], 'pending']);
    } catch (Throwable $e) { /* non-fatal */ }
    try {
        $db->prepare('UPDATE transactions SET platform_fee = ?, split_amount = ?, mdr_m = ?, mdr_p = ?, partner_fee = ?, pricing_snapshot = ? WHERE id = ?')
            ->execute([
                $split['platform_fee'],
                $split['merchant_net'],
                (float)($split['mdr_m'] ?? 0),
                (float)($split['mdr_p'] ?? 0),
                (float)($split['partner_fee'] ?? 0),
                $split['pricing_snapshot'] ?? null,
                $transactionId,
            ]);
    } catch (Throwable $e) {
        try {
            $db->prepare('UPDATE transactions SET platform_fee = ?, split_amount = ? WHERE id = ?')
                ->execute([$split['platform_fee'], $split['merchant_net'], $transactionId]);
        } catch (Throwable $e2) { /* non-fatal */ }
    }
}

/**
 * Normalize checkout mobile to 10 digits. Returns '' when invalid.
 * Checkout requires mobile; no OTP is sent or verified here.
 */
function normalizeCheckoutCustomerPhone(string $raw): string
{
    $phone = preg_replace('/\D/', '', $raw) ?? '';
    if (strlen($phone) > 10) {
        $phone = substr($phone, -10);
    }
    if (strlen($phone) !== 10 || !preg_match('/^[6-9]/', $phone)) {
        return '';
    }
    return $phone;
}

/** @return array{ok:bool,message:string,phone:string,email:string,name:string} */
function validateCheckoutCustomerDetails(array $post = []): array
{
    $name = mb_substr(trim((string)($post['customer_name'] ?? '')), 0, 120);
    $phone = normalizeCheckoutCustomerPhone((string)($post['customer_phone'] ?? ''));
    $emailRaw = mb_substr(trim((string)($post['customer_email'] ?? '')), 0, 190);
    $email = $emailRaw !== '' && filter_var($emailRaw, FILTER_VALIDATE_EMAIL) ? $emailRaw : '';
    if ($emailRaw !== '' && $email === '') {
        return ['ok' => false, 'message' => 'Enter a valid email address, or leave email blank.', 'phone' => $phone, 'email' => '', 'name' => $name];
    }
    if ($phone === '') {
        return ['ok' => false, 'message' => 'Mobile number is required (10 digits, starting with 6–9). No OTP is needed.', 'phone' => '', 'email' => $email, 'name' => $name];
    }
    return ['ok' => true, 'message' => '', 'phone' => $phone, 'email' => $email, 'name' => $name];
}

function persistCheckoutCustomerDetails(array &$link, array $post = []): void
{
    $validated = validateCheckoutCustomerDetails($post);
    $name = $validated['name'];
    $phone = $validated['phone'];
    $email = $validated['email'];
    $updates = [];
    $params = [];
    if ($name !== '') {
        $updates[] = 'customer_name=?';
        $params[] = $name;
        $link['customer_name'] = $name;
    }
    if ($phone !== '') {
        $updates[] = 'customer_phone=?';
        $params[] = $phone;
        $link['customer_phone'] = $phone;
    }
    if ($email !== '') {
        $link['customer_email'] = $email;
    }
    if ($updates !== [] && !empty($link['id'])) {
        $params[] = (int)$link['id'];
        getDB()->prepare('UPDATE payment_links SET ' . implode(', ', $updates) . ' WHERE id=?')->execute($params);
    }
}

function createTransactionFromPayment(array $link, string $method, string $status, string $ref, bool $isTest = false): int
{
    $db = getDB();
    ensureWalletEngine();
    $amount = sanitizePaymentAmount((float)($link['amount'] ?? 0), $isTest);
    if ($amount <= 0) {
        $amount = $isTest ? 1.0 : 0.0;
    }
    if ($amount <= 0) {
        throw new InvalidArgumentException('Invalid payment amount');
    }
    $link['amount'] = $amount;
    $txnId = generateId('TXN');
    $split = calculateSplitBreakdown($amount, $link);
    $methodStored = preg_replace('/[^a-z0-9_]/i', '', $method) ?: 'upi';
    if (strlen($methodStored) > 32) $methodStored = substr($methodStored, 0, 32);
    $customerName = mb_substr(trim((string)($link['customer_name'] ?? '')), 0, 160) ?: null;
    $customerEmail = mb_substr(trim((string)($link['customer_email'] ?? '')), 0, 190) ?: null;
    $customerPhone = mb_substr(trim((string)($link['customer_phone'] ?? '')), 0, 32) ?: null;
    $txnCore = [
        $txnId, $link['merchant_id'], $amount, $status, $methodStored,
        $link['description'] ?? '', $ref, $link['id'],
        $split['platform_fee'], $split['merchant_net'], $isTest ? 1 : 0,
        getMerchantCollectionMode($link),
        $customerName, $customerEmail, $customerPhone,
        (int)($link['qr_code_id'] ?? 0) > 0 ? (int)$link['qr_code_id'] : null,
    ];
    try {
        $db->prepare('INSERT INTO transactions (txn_id, merchant_id, amount, status, payment_method, description, utr, payment_link_id, platform_fee, split_amount, is_test, collection_mode, customer_name, customer_email, customer_phone, qr_code_id, mdr_m, mdr_p, partner_fee, pricing_snapshot) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge($txnCore, [
                (float)($split['mdr_m'] ?? 0),
                (float)($split['mdr_p'] ?? 0),
                (float)($split['partner_fee'] ?? 0),
                $split['pricing_snapshot'] ?? null,
            ]));
    } catch (Throwable $e) {
        try {
            $db->prepare('INSERT INTO transactions (txn_id, merchant_id, amount, status, payment_method, description, utr, payment_link_id, platform_fee, split_amount, is_test, collection_mode, customer_name, customer_email, customer_phone, qr_code_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute($txnCore);
        } catch (Throwable $e2) {
            $db->prepare('INSERT INTO transactions (txn_id, merchant_id, amount, status, payment_method, description, utr, payment_link_id) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$txnId, $link['merchant_id'], $amount, $status, $methodStored, $link['description'] ?? '', $ref, $link['id']]);
        }
    }
    $id = (int)$db->lastInsertId();
    if ($status === 'success') {
        if (!function_exists('finalizeSuccessfulPaymentTransaction') && is_file(__DIR__ . '/financial_integrity.php')) {
            require_once __DIR__ . '/financial_integrity.php';
        }
        $linkId = null;
        if (!empty($link['link_id'])) {
            $linkId = $link['link_id'];
        } elseif (!empty($link['id'])) {
            $lid = $db->prepare('SELECT link_id FROM payment_links WHERE id = ?');
            $lid->execute([(int)$link['id']]);
            $linkId = $lid->fetchColumn() ?: null;
        }
        if (function_exists('finalizeSuccessfulPaymentTransaction')) {
            finalizeSuccessfulPaymentTransaction($id, [
                'provider' => $methodStored,
                'link_id' => $linkId !== null ? (string)$linkId : null,
                'run_risk_hooks' => true,
            ]);
        } else {
            if ($split['platform_fee'] > 0) {
                recordSplitPayment($id, (int)$link['merchant_id'], $split, $method);
            }
            creditWalletsFromTransaction($id);
            addTransactionToSettlementBatch($id, (int)$link['merchant_id']);
            $txnSt = $db->prepare('SELECT txn_id, amount, status, payment_method, utr FROM transactions WHERE id = ?');
            $txnSt->execute([$id]);
            $txnRow = $txnSt->fetch();
            if ($txnRow) {
                notifyMerchantPaymentSuccess((int)$link['merchant_id'], $txnRow, $linkId);
                recordTransactionRisk($id, (int)$link['merchant_id'], $amount, ['email' => $customerEmail ?? '', 'phone' => $customerPhone ?? '']);
                evaluateTransactionRiskFull((int)$link['merchant_id'], $amount, ['email' => $customerEmail ?? '', 'phone' => $customerPhone ?? ''], $id);
                recordNodalCollection($id, (int)$link['merchant_id'], $amount, 'Customer collection from ' . ($customerEmail ?? 'customer'));
                updateMerchantRiskScore((int)$link['merchant_id']);
                if (function_exists('applyRollingReserveHold')) {
                    applyRollingReserveHold((int)$link['merchant_id'], $id, $amount);
                }
            }
        }
    }
    return $id;
}

function finalizePaymentLink(int $linkDbId, int $merchantId, float $amount, string $message): void
{
    getDB()->prepare("UPDATE payment_links SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$linkDbId]);
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Payment Received', $message, 'pay_link_' . $linkDbId);
    } else {
        createNotification($merchantId, 'Payment Received', $message);
    }
}

/**
 * Build a NPCI-standard `upi://pay` intent that any UPI app (GPay, PhonePe,
 * Paytm, BHIM) can open directly. Amount and note are optional; when the
 * amount is omitted the payer types it in their UPI app (open-amount QR).
 */
function buildUpiPayIntent(string $payeeVpa, string $payeeName, ?float $amount = null, string $note = ''): string
{
    $pa = trim($payeeVpa);
    if ($pa === '') {
        return '';
    }
    $params = [
        'pa' => $pa,
        'pn' => trim($payeeName) !== '' ? trim($payeeName) : 'Merchant',
        'cu' => 'INR',
    ];
    if ($amount !== null && $amount > 0) {
        $params['am'] = number_format($amount, 2, '.', '');
    }
    if (trim($note) !== '') {
        $params['tn'] = $note;
    }
    // rawurlencode keeps spaces as %20 (UPI apps reject the "+" that urlencode emits).
    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . rawurlencode((string)$value);
    }
    return 'upi://pay?' . implode('&', $pairs);
}

function buildMerchantUpiIntent(array $link): string
{
    $pa = $link['upi_id'] ?? '';
    if (($link['collection_mode'] ?? '') === 'axis_va' && !empty($link['axis_va_upi'])) {
        $pa = $link['axis_va_upi'];
    }
    return buildUpiPayIntent(
        (string)$pa,
        (string)($link['business_name'] ?? 'Merchant'),
        isset($link['amount']) ? (float)$link['amount'] : null,
        (string)(($link['description'] ?? '') !== '' ? $link['description'] : 'Payment via ' . APP_NAME)
    );
}

function buildWhatsAppPaymentLink(array $link): string
{
    $upi = buildMerchantUpiIntent($link);
    $text = rawurlencode('Pay ' . formatMoney((float)$link['amount']) . ' to ' . ($link['business_name'] ?? 'Merchant') . ' via UPI: ' . $upi);
    $phone = preg_replace('/\D/', '', $link['customer_phone'] ?? '');
    return $phone ? "https://wa.me/{$phone}?text={$text}" : "https://api.whatsapp.com/send?text={$text}";
}

function ensureAxisVirtualAccount(int $merchantId): ?array
{
    if (!function_exists('ensureMerchantVirtualAccount')) {
        require_once __DIR__ . '/va_manager.php';
    }
    return ensureMerchantVirtualAccount($merchantId);
}

function resolveCheckoutHandler(array $merchant): string
{
    $mode = getMerchantCollectionMode($merchant);
    return match ($mode) {
        'direct_upi' => 'direct_upi',
        'payu_split' => isGatewayConfigured('payu') ? 'payu_split' : 'direct_upi',
        'razorpay_route' => isGatewayConfigured('razorpay') ? 'razorpay_route' : 'platform_pg',
        'cashfree_route' => isGatewayConfigured('cashfree') ? 'cashfree_route' : 'platform_pg',
        'axis_va' => 'axis_va',
        default => getActivePaymentGateway() !== 'manual' ? 'platform_pg' : 'direct_upi',
    };
}

function resolveCheckoutHandlerForLink(array $link): string
{
    if (!empty($link['link_collection_mode'])) {
        $mode = $link['link_collection_mode'];
        $tmp = $link;
        $tmp['collection_mode'] = $mode;
        return resolveCheckoutHandler($tmp);
    }
    if (!empty($link['gateway_code']) && $link['gateway_code'] === 'axis') {
        return 'axis_va';
    }
    if (!empty($link['payment_method'])) {
        $cat = getPaymentMethodCatalog()[$link['payment_method']] ?? null;
        if ($cat && !empty($cat['collection_mode'])) {
            $tmp = $link;
            $tmp['collection_mode'] = $cat['collection_mode'];
            return resolveCheckoutHandler($tmp);
        }
    }
    return resolveCheckoutHandler($link);
}

function getCheckoutPaymentMethods(array $link): array
{
    try {
        return buildCheckoutPaymentMethods($link);
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('error', 'Checkout method list failed: ' . $e->getMessage(), ['link_id' => $link['link_id'] ?? '']);
        }
        return [['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'Google Pay · PhonePe · Paytm', 'icon' => '📱', 'type' => 'p2m']];
    }
}

function buildCheckoutPaymentMethods(array $link): array
{
    $handler = resolveCheckoutHandlerForLink($link);
    $isTest = !empty($link['is_test']) || merchantAccountMode($link) === 'test';
    $payuConfigured = isGatewayConfigured('payu');
    $rzpConfigured = isGatewayConfigured('razorpay');
    $cfConfigured = isGatewayConfigured('cashfree');
    $methods = [];

    $merchantId = (int)($link['merchant_id'] ?? 0);
    if (($link['enabled_methods'] ?? '') === '' && $merchantId > 0) {
        try {
            $st = getDB()->prepare('SELECT enabled_methods FROM merchants WHERE id=?');
            $st->execute([$merchantId]);
            $row = $st->fetch();
            if ($row) {
                $link['enabled_methods'] = $row['enabled_methods'] ?? '';
            }
        } catch (Throwable $e) {
            /* column missing — UPI tab still renders */
        }
    }

    $availableMethods = [];
    if ($merchantId > 0 && function_exists('get_available_pay_methods')) {
        try {
            $availableMethods = get_available_pay_methods($merchantId);
        } catch (Throwable $e) {
            $availableMethods = [];
        }
    }

    // Build a lookup of available method keys
    $availableKeys = array_column($availableMethods, 'key');

    // Merchant enabled_methods JSON is the product data model for checkout tabs.
    // Do not let an empty partner-registry / partner_methods row hide cards.
    $fromJson = getMerchantEnabledMethods($link);
    $enabled = $fromJson;
    if (!empty($availableKeys)) {
        $enabled = array_values(array_unique(array_merge($fromJson, $availableKeys)));
    }
    if (function_exists('normalizeCheckoutMethodKeys')) {
        $enabled = normalizeCheckoutMethodKeys($enabled);
    }
    $catalog = getPaymentMethodCatalog();
    $allow = function (string $methodKey) use ($enabled): bool {
        $want = normalizeCheckoutMethodKey($methodKey);
        return in_array($want, $enabled, true);
    };

    if ($allow('upi_p2m') || in_array($handler, ['direct_upi', 'axis_va'], true) || empty($link['payment_method'])) {
        $methods[] = ['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'Google Pay · PhonePe · Paytm', 'icon' => '📱', 'type' => 'p2m'];
    }

    // Render tabs from merchant method flags. Live keys are not required to SHOW the UI.
    if ($allow('debit_card')) {
        $methods[] = ['key' => 'dc', 'label' => 'Debit Card', 'sub' => $payuConfigured ? 'Visa · Mastercard · RuPay' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '💳', 'type' => 'payu', 'pg' => 'DC'];
    }
    if ($allow('credit_card')) {
        $methods[] = ['key' => 'cc', 'label' => 'Credit Card', 'sub' => $payuConfigured ? 'Visa · Mastercard · Amex' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '💳', 'type' => 'payu', 'pg' => 'CC'];
    }
    if ($allow('netbanking')) {
        $methods[] = ['key' => 'nb', 'label' => 'Net Banking', 'sub' => $payuConfigured ? 'All major banks' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '🏦', 'type' => 'payu', 'pg' => 'NB'];
    }
    if ($allow('emi')) {
        $methods[] = ['key' => 'emi', 'label' => 'EMI', 'sub' => $payuConfigured ? 'Card EMI · No Cost EMI' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '📅', 'type' => 'payu', 'pg' => 'EMI'];
    }
    if ($allow('wallet')) {
        $methods[] = ['key' => 'wallet', 'label' => 'Wallets', 'sub' => $payuConfigured ? 'Paytm · PhonePe · Amazon Pay' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '👛', 'type' => 'payu', 'pg' => 'CASH'];
    }
    if ($allow('payu_upi')) {
        $methods[] = ['key' => 'payu_upi', 'label' => 'UPI', 'sub' => $payuConfigured ? 'Google Pay · PhonePe · Paytm' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '⚡', 'type' => 'payu', 'pg' => 'UPI'];
    }
    if ($allow('razorpay') || $handler === 'razorpay_route') {
        $methods[] = ['key' => 'razorpay', 'label' => 'Cards & UPI', 'sub' => $rzpConfigured ? 'Visa · Mastercard · UPI' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '🔒', 'type' => 'razorpay'];
    }
    if ($allow('cashfree') || $handler === 'cashfree_route') {
        $methods[] = ['key' => 'cashfree', 'label' => 'Cards & UPI', 'sub' => $cfConfigured ? 'Visa · Mastercard · Net Banking' : ($isTest ? 'Test Mode — Instant Pay' : 'Waiting for activation'), 'icon' => '💰', 'type' => 'cashfree'];
    }

    // Dedicated method link — only that checkout tab
    if (!empty($link['payment_method'])) {
        $cat = $catalog[$link['payment_method']] ?? null;
        if ($cat && !empty($cat['pay_key'])) {
            $payKey = $cat['pay_key'];
            $filtered = array_values(array_filter($methods, fn($m) => $m['key'] === $payKey));
            if (!empty($filtered)) {
                $methods = $filtered;
            } else {
                // Method locked but not yet buildable — still show a single Instant/Test-friendly tab
                $methods = [[
                    'key' => $payKey,
                    'label' => $cat['label'],
                    'sub' => $isTest ? 'Test Mode — Instant Pay' : 'Awaiting gateway configuration',
                    'icon' => $cat['icon'] ?? '💳',
                    'type' => ($cat['gateway'] ?? '') === 'payu' ? 'payu' : (($cat['gateway'] ?? '') === 'razorpay' ? 'razorpay' : (($cat['gateway'] ?? '') === 'cashfree' ? 'cashfree' : 'p2m')),
                    'pg' => match ($payKey) {
                        'dc' => 'DC', 'cc' => 'CC', 'nb' => 'NB', 'emi' => 'EMI', 'wallet' => 'CASH', 'payu_upi' => 'UPI', default => '',
                    },
                ]];
            }
        }
    }

    if (empty($methods)) {
        $methods[] = ['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'Google Pay · PhonePe · Paytm', 'icon' => '📱', 'type' => 'p2m'];
    }

    return $methods;
}

function checkoutHandlerLabel(string $handler): string
{
    return match ($handler) {
        'direct_upi' => 'Direct UPI',
        'payu_split', 'razorpay_route', 'cashfree_route' => 'Secure checkout',
        'axis_va' => 'Virtual account',
        'platform_pg' => 'Secure checkout',
        default => 'Secure checkout',
    };
}

/** Audit B #9 — effective collection rail for a checkout link (link → method → merchant). */
function resolveEffectiveCollectionModeKey(array $link): string
{
    if (!empty($link['link_collection_mode'])) {
        $mode = (string)$link['link_collection_mode'];
        if ($mode !== '') {
            return $mode;
        }
    }
    if (!empty($link['gateway_code']) && (string)$link['gateway_code'] === 'axis') {
        return 'axis_va';
    }
    if (!empty($link['payment_method'])) {
        $cat = getPaymentMethodCatalog()[(string)$link['payment_method']] ?? null;
        if ($cat && !empty($cat['collection_mode'])) {
            return (string)$cat['collection_mode'];
        }
    }
    return getMerchantCollectionMode($link);
}

/** Customer-facing collection label on checkout (matches actual rail, not generic "Secure checkout"). */
function checkoutCollectionCustomerLabel(array $link): string
{
    return merchantCollectionModeLabel(resolveEffectiveCollectionModeKey($link));
}

/** Merchant payment-link row: Test/Live + collection rail (Audit B #9). */
function checkoutLinkModeCollectionSummary(array $link, ?array $merchant = null): string
{
    $ctx = $link;
    if ($merchant !== null) {
        $ctx = array_merge($merchant, $link);
    }
    $isTest = !empty($link['is_test'])
        || (isset($link['account_mode']) && merchantAccountMode($ctx) === 'test')
        || ($merchant !== null && isDashboardTestMode($merchant));
    $modeLabel = $isTest ? 'Test Mode' : 'Live Mode';
    return $modeLabel . ' · ' . checkoutCollectionCustomerLabel($ctx);
}

// End of collection.php
