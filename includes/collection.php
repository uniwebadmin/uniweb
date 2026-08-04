<?php
declare(strict_types=1);

require_once __DIR__ . '/risk.php';

/** B2B Collection engine — direct UPI, PayU split, Axis VA, PG routes */

function getCollectionModes(): array
{
    return [
        'direct_upi' => 'P2M Direct UPI (zero liability — merchant VPA)',
        'payu_split' => 'PayU Split Settlement (auto commission)',
        'razorpay_route' => 'Razorpay Route (linked account transfer)',
        'cashfree_route' => 'Cashfree Easy Split (vendor payout)',
        'axis_va' => 'Axis Virtual Account + UPI Collection',
        'platform_pg' => 'Platform PG (Razorpay/Cashfree pool)',
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

function getMerchantFacingCollectionModes(?array $merchant = null): array
{
    $keys = ['direct_upi', 'cashfree_route', 'platform_pg'];
    $all = getCollectionModes();
    $out = [];
    foreach ($keys as $k) {
        if (isset($all[$k])) {
            $out[$k] = $all[$k];
        }
    }
    $current = $merchant ? getMerchantCollectionMode($merchant) : '';
    if ($current && isset($all[$current]) && !isset($out[$current])) {
        $out[$current] = $all[$current];
    }
    return $out;
}

function getAmlHighValueThreshold(): float
{
    return normalizedSettingAmount('aml_high_value_threshold', '200000', 500000.0);
}

function collectionModeLabel(string $mode): string
{
    return getCollectionModes()[$mode] ?? ucfirst(str_replace('_', ' ', $mode));
}

function calculatePlatformCommission(float $amount, array $merchant): float
{
    $rate = (float)($merchant['commission_rate'] ?? getSetting('default_commission', '0.10'));
    if ($rate <= 0) {
        $rate = (float)getSetting('platform_margin_pct', '0.10');
    }
    return round($amount * $rate / 100, 2);
}

function calculateSplitBreakdown(float $amount, array $merchant): array
{
    $platformFee = calculatePlatformCommission($amount, $merchant);
    $merchantNet = round($amount - $platformFee, 2);
    return [
        'gross' => $amount,
        'platform_fee' => $platformFee,
        'merchant_net' => max(0, $merchantNet),
    ];
}

function recordSplitPayment(int $transactionId, int $merchantId, array $split, string $method = 'auto'): void
{
    $db = getDB();
    $db->prepare('INSERT INTO split_payments (transaction_id, merchant_id, recipient_type, recipient_id, amount, status) VALUES (?,?,?,?,?,?)')
        ->execute([$transactionId, $merchantId, 'platform', null, $split['platform_fee'], 'completed']);
    $db->prepare('INSERT INTO split_payments (transaction_id, merchant_id, recipient_type, recipient_id, amount, status) VALUES (?,?,?,?,?,?)')
        ->execute([$transactionId, $merchantId, 'merchant', $merchantId, $split['merchant_net'], 'completed']);
    $db->prepare('UPDATE transactions SET platform_fee = ?, split_amount = ? WHERE id = ?')
        ->execute([$split['platform_fee'], $split['merchant_net'], $transactionId]);
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
    try {
        $db->prepare('INSERT INTO transactions (txn_id, merchant_id, amount, status, payment_method, description, utr, payment_link_id, platform_fee, split_amount, is_test, collection_mode, customer_name, customer_email, customer_phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $txnId, $link['merchant_id'], $amount, $status, $methodStored,
                $link['description'] ?? '', $ref, $link['id'],
                $split['platform_fee'], $split['merchant_net'], $isTest ? 1 : 0,
                getMerchantCollectionMode($link),
                $customerName, $customerEmail, $customerPhone,
            ]);
    } catch (Throwable $e) {
        $db->prepare('INSERT INTO transactions (txn_id, merchant_id, amount, status, payment_method, description, utr, payment_link_id) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$txnId, $link['merchant_id'], $amount, $status, $methodStored, $link['description'] ?? '', $ref, $link['id']]);
    }
    $id = (int)$db->lastInsertId();
    if ($status === 'success') {
        if ($split['platform_fee'] > 0) {
            recordSplitPayment($id, (int)$link['merchant_id'], $split, $method);
        }
        creditWalletsFromTransaction($id);
        addTransactionToSettlementBatch($id, (int)$link['merchant_id']);
        $txnSt = $db->prepare('SELECT txn_id, amount, status, payment_method, utr FROM transactions WHERE id = ?');
        $txnSt->execute([$id]);
        $txnRow = $txnSt->fetch();
        if ($txnRow) {
            $linkId = null;
            if (!empty($link['link_id'])) {
                $linkId = $link['link_id'];
            } elseif (!empty($link['id'])) {
                $lid = $db->prepare('SELECT link_id FROM payment_links WHERE id = ?');
                $lid->execute([(int)$link['id']]);
                $linkId = $lid->fetchColumn() ?: null;
            }
            notifyMerchantPaymentSuccess((int)$link['merchant_id'], $txnRow, $linkId);
            recordTransactionRisk($id, (int)$link['merchant_id'], $amount, ['email' => $customerEmail ?? '', 'phone' => $customerPhone ?? '']);
            updateMerchantRiskScore((int)$link['merchant_id']);
        }
    }
    return $id;
}

function finalizePaymentLink(int $linkDbId, int $merchantId, float $amount, string $message): void
{
    getDB()->prepare("UPDATE payment_links SET status = 'paid', paid_at = NOW() WHERE id = ?")->execute([$linkDbId]);
    createNotification($merchantId, 'Payment Received', $message);
}

/**
 * Build a NPCI-standard `upi://pay` intent that any UPI app (GPay, PhonePe,
 * Paytm, BHIM) can open directly. Amount and note are optional; when the
 * amount is omitted the payer types it in their UPI app (open-amount QR).
 */
function buildUpiPayIntent(string $payeeVpa, string $payeeName, ?float $amount = null, string $note = ''): string
{
    $params = [
        'pa' => trim($payeeVpa),
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
    $db = getDB();
    $m = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $m->execute([$merchantId]);
    $merchant = $m->fetch();
    if (!$merchant) return null;

    if (!empty($merchant['axis_va_number'])) {
        return [
            'va_number' => $merchant['axis_va_number'],
            'va_ifsc' => $merchant['axis_va_ifsc'] ?? getSetting('axis_va_ifsc', 'UTIB0000000'),
            'va_upi' => $merchant['axis_va_upi'] ?? '',
            'axis_va_id' => $merchant['axis_va_id'] ?? '',
        ];
    }

    $va = createAxisVirtualAccount($merchant);
    if (!$va) return null;

    $normalized = [
        'va_number' => $va['va_number'] ?? '',
        'va_ifsc' => $va['ifsc'] ?? $va['va_ifsc'] ?? getSetting('axis_va_ifsc', 'UTIB0000000'),
        'va_upi' => $va['upi_id'] ?? $va['va_upi'] ?? '',
        'axis_va_id' => $va['va_id'] ?? $va['axis_va_id'] ?? '',
    ];

    try {
        $db->prepare('UPDATE merchants SET axis_va_id=?, axis_va_number=?, axis_va_ifsc=?, axis_va_upi=? WHERE id=?')
            ->execute([
                $normalized['axis_va_id'], $normalized['va_number'], $normalized['va_ifsc'],
                $normalized['va_upi'], $merchantId,
            ]);
    } catch (Throwable $e) {
        return $normalized;
    }
    return $normalized;
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
    $handler = resolveCheckoutHandlerForLink($link);
    $isTest = !empty($link['is_test']) || merchantAccountMode($link) === 'test';
    // Only advertise PG tabs when gateway is configured OR we are in test (Instant Test completes without redirect)
    $payuConfigured = isGatewayConfigured('payu');
    $rzpConfigured = isGatewayConfigured('razorpay');
    $cfConfigured = isGatewayConfigured('cashfree');
    $methods = [];

    $enabled = getMerchantEnabledMethods($link);
    $catalog = getPaymentMethodCatalog();
    $allow = function (string $methodKey) use ($enabled): bool {
        return in_array($methodKey, $enabled, true);
    };

    if ($allow('upi_p2m') || in_array($handler, ['direct_upi', 'axis_va'], true) || empty($link['payment_method'])) {
        $methods[] = ['key' => 'upi', 'label' => 'UPI / QR', 'sub' => 'Google Pay · PhonePe · Paytm', 'icon' => '📱', 'type' => 'p2m'];
    }

    // Card / NB / Wallet: show if PayU configured, or Test Mode Instant Pay
    if ($payuConfigured || $isTest) {
        if ($allow('debit_card')) {
            $methods[] = ['key' => 'dc', 'label' => 'Debit Card', 'sub' => $payuConfigured ? 'Visa · Mastercard · RuPay' : 'Test Mode — Instant Pay', 'icon' => '💳', 'type' => 'payu', 'pg' => 'DC'];
        }
        if ($allow('credit_card')) {
            $methods[] = ['key' => 'cc', 'label' => 'Credit Card', 'sub' => $payuConfigured ? 'Visa · Mastercard · Amex' : 'Test Mode — Instant Pay', 'icon' => '💳', 'type' => 'payu', 'pg' => 'CC'];
        }
        if ($allow('netbanking')) {
            $methods[] = ['key' => 'nb', 'label' => 'Net Banking', 'sub' => $payuConfigured ? 'All major banks' : 'Test Mode — Instant Pay', 'icon' => '🏦', 'type' => 'payu', 'pg' => 'NB'];
        }
        if ($allow('emi')) {
            $methods[] = ['key' => 'emi', 'label' => 'EMI', 'sub' => $payuConfigured ? 'Card EMI · No Cost EMI' : 'Test Mode — Instant Pay', 'icon' => '📅', 'type' => 'payu', 'pg' => 'EMI'];
        }
        if ($allow('wallet')) {
            $methods[] = ['key' => 'wallet', 'label' => 'Wallets', 'sub' => $payuConfigured ? 'Paytm · PhonePe · Amazon Pay' : 'Test Mode — Instant Pay', 'icon' => '👛', 'type' => 'payu', 'pg' => 'CASH'];
        }
        if ($allow('payu_upi')) {
            $methods[] = ['key' => 'payu_upi', 'label' => 'UPI (Gateway)', 'sub' => $payuConfigured ? 'Pay via PayU' : 'Test Mode — Instant Pay', 'icon' => '⚡', 'type' => 'payu', 'pg' => 'UPI'];
        }
    }
    if (($allow('razorpay') || $handler === 'razorpay_route') && ($rzpConfigured || $isTest) && ($handler === 'razorpay_route' || $handler === 'platform_pg' || $allow('razorpay'))) {
        $methods[] = ['key' => 'razorpay', 'label' => 'Cards & UPI', 'sub' => $rzpConfigured ? 'Razorpay Checkout' : 'Test Mode — Instant Pay', 'icon' => '🔒', 'type' => 'razorpay'];
    }
    if (($allow('cashfree') || $handler === 'cashfree_route') && ($cfConfigured || $isTest) && ($handler === 'cashfree_route' || $handler === 'platform_pg' || $allow('cashfree'))) {
        $methods[] = ['key' => 'cashfree', 'label' => 'Cashfree Pay', 'sub' => $cfConfigured ? 'Cards · UPI · NB' : 'Test Mode — Instant Pay', 'icon' => '💰', 'type' => 'cashfree'];
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
        'direct_upi' => 'P2M Direct UPI',
        'payu_split' => 'PayU Split Settlement',
        'razorpay_route' => 'Razorpay Route',
        'cashfree_route' => 'Cashfree Easy Split',
        'axis_va' => 'Axis Virtual Account',
        'platform_pg' => 'Platform Payment Gateway',
        default => ucfirst(str_replace('_', ' ', $handler)),
    };
}
