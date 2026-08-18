<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

/** Merchant auto-provision + per-method payment link packs */

function ensurePaymentPackSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $db = getDB();
    $merchantCols = [
        'ALTER TABLE merchants ADD COLUMN auto_provisioned TINYINT(1) NOT NULL DEFAULT 0',
        'ALTER TABLE merchants ADD COLUMN enabled_methods TEXT DEFAULT NULL',
        'ALTER TABLE merchants ADD COLUMN provision_pack_id VARCHAR(32) DEFAULT NULL',
        'ALTER TABLE merchants ADD COLUMN provision_profile VARCHAR(64) DEFAULT NULL',
    ];
    $linkCols = [
        'ALTER TABLE payment_links ADD COLUMN payment_method VARCHAR(32) DEFAULT NULL',
        'ALTER TABLE payment_links ADD COLUMN gateway_code VARCHAR(32) DEFAULT NULL',
        'ALTER TABLE payment_links ADD COLUMN pack_id VARCHAR(32) DEFAULT NULL',
        'ALTER TABLE payment_links ADD COLUMN link_label VARCHAR(128) DEFAULT NULL',
        'ALTER TABLE payment_links ADD COLUMN link_collection_mode VARCHAR(32) DEFAULT NULL',
        "ALTER TABLE payment_links ADD COLUMN amount_type VARCHAR(16) NOT NULL DEFAULT 'fixed'",
    ];
    foreach (array_merge($merchantCols, $linkCols) as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) { /* column exists */ }
    }
}

function getPaymentMethodCatalog(): array
{
    return [
        'upi_p2m' => [
            'label' => 'UPI P2M (Direct)',
            'pay_key' => 'upi',
            'gateway' => 'direct',
            'collection_mode' => 'direct_upi',
            'icon' => '📱',
            'mdr' => 'upi',
        ],
        'axis_va' => [
            'label' => 'Virtual Account + UPI',
            'pay_key' => 'upi',
            'gateway' => 'axis',
            'collection_mode' => 'axis_va',
            'icon' => '🏦',
            'mdr' => 'axis_bank',
        ],
        'debit_card' => [
            'label' => 'Debit Card',
            'pay_key' => 'dc',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '💳',
            'mdr' => 'card_debit',
        ],
        'credit_card' => [
            'label' => 'Credit Card',
            'pay_key' => 'cc',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '💳',
            'mdr' => 'card_credit',
        ],
        'netbanking' => [
            'label' => 'Net Banking',
            'pay_key' => 'nb',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '🏦',
            'mdr' => 'netbanking',
        ],
        'wallet' => [
            'label' => 'Wallets',
            'pay_key' => 'wallet',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '👛',
            'mdr' => 'wallet',
        ],
        'emi' => [
            'label' => 'EMI',
            'pay_key' => 'emi',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '📅',
            'mdr' => 'emi',
        ],
        'payu_upi' => [
            'label' => 'UPI',
            'pay_key' => 'payu_upi',
            'gateway' => 'payu',
            'collection_mode' => 'payu_split',
            'icon' => '⚡',
            'mdr' => 'upi',
        ],
        'razorpay' => [
            'label' => 'Cards & UPI',
            'pay_key' => 'razorpay',
            'gateway' => 'razorpay',
            'collection_mode' => 'razorpay_route',
            'icon' => '🔒',
            'mdr' => 'card_debit',
        ],
        'cashfree' => [
            'label' => 'Cards & UPI',
            'pay_key' => 'cashfree',
            'gateway' => 'cashfree',
            'collection_mode' => 'cashfree_route',
            'icon' => '💰',
            'mdr' => 'card_debit',
        ],
        'instant_settlement' => [
            'label' => 'Instant Settlement',
            'pay_key' => 'instant_settlement',
            'gateway' => 'instant',
            'collection_mode' => 'platform_pg',
            'icon' => '⚡',
            'mdr' => 'upi',
        ],
        'payout' => [
            'label' => 'Payouts',
            'pay_key' => 'payout',
            'gateway' => 'razorpay',
            'collection_mode' => 'platform_pg',
            'icon' => '💸',
            'mdr' => 'netbanking',
        ],
    ];
}

function getMerchantProvisionProfile(array $merchant): array
{
    $entity = normalizeKycEntityType($merchant['business_entity_type'] ?? 'sole_proprietorship');
    // Provision profiles still use short keys for proprietor / freelancer.
    if ($entity === 'sole_proprietorship') {
        $entity = 'proprietor';
    }
    $plan = $merchant['subscription_plan'] ?? 'starter';

    $allMethods = array_keys(getPaymentMethodCatalog());

    $profiles = [
        'private_limited' => [
            'profile' => 'enterprise_pvt_ltd',
            'collection_mode' => 'payu_split',
            'methods' => $allMethods,
            'label' => 'Private Limited — Full PG + VA stack',
        ],
        'public_limited' => [
            'profile' => 'enterprise_public_ltd',
            'collection_mode' => 'payu_split',
            'methods' => $allMethods,
            'label' => 'Public Limited — Full PG + VA stack',
        ],
        'llp' => [
            'profile' => 'llp_standard',
            'collection_mode' => 'payu_split',
            'methods' => array_diff($allMethods, ['axis_va']),
            'label' => 'LLP — PG stack (VA on request)',
        ],
        'partnership' => [
            'profile' => 'partnership_standard',
            'collection_mode' => 'payu_split',
            'methods' => ['upi_p2m', 'debit_card', 'credit_card', 'netbanking', 'wallet', 'payu_upi', 'razorpay', 'cashfree'],
            'label' => 'Partnership — Cards + UPI + Routes',
        ],
        'proprietor' => [
            'profile' => 'proprietor_starter',
            'collection_mode' => 'direct_upi',
            'methods' => ['upi_p2m', 'debit_card', 'credit_card', 'payu_upi', 'wallet'],
            'label' => 'Proprietor — UPI P2M + basic cards',
        ],
        'individual' => [
            'profile' => 'individual_basic',
            'collection_mode' => 'direct_upi',
            'methods' => ['upi_p2m', 'debit_card', 'credit_card', 'payu_upi'],
            'label' => 'Individual — UPI + Cards (test)',
        ],
        'freelancer' => [
            'profile' => 'freelancer_basic',
            'collection_mode' => 'direct_upi',
            'methods' => ['upi_p2m', 'debit_card', 'credit_card', 'payu_upi', 'wallet'],
            'label' => 'Freelancer — UPI + Cards',
        ],
    ];

    $base = $profiles[$entity] ?? [
        'profile' => 'default',
        'collection_mode' => getSetting('default_collection_mode', 'platform_pg'),
        'methods' => ['upi_p2m', 'debit_card', 'credit_card', 'payu_upi', 'razorpay', 'cashfree'],
        'label' => 'Standard B2B profile',
    ];

    if ($plan === 'business' || $plan === 'enterprise') {
        $base['methods'] = array_values(array_unique(array_merge($base['methods'], ['razorpay', 'cashfree', 'emi'])));
    }

    $base['methods'] = array_values(array_filter($base['methods'], function ($m) {
        $cat = getPaymentMethodCatalog()[$m] ?? null;
        if (!$cat) return false;
        $gw = $cat['gateway'];
        if ($gw === 'direct') return true;
        if ($gw === 'axis') return isGatewayConfigured('axis');
        if ($gw === 'payu') return isGatewayConfigured('payu');
        if ($gw === 'razorpay') return isGatewayConfigured('razorpay');
        if ($gw === 'cashfree') return isGatewayConfigured('cashfree');
        return false;
    }));

    return $base;
}

function getMerchantEnabledMethods(array $merchant): array
{
    $raw = $merchant['enabled_methods'] ?? '';
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && $decoded) {
            $keys = array_values(array_unique(array_map('strval', $decoded)));
            return function_exists('normalizeCheckoutMethodKeys')
                ? normalizeCheckoutMethodKeys($keys)
                : $keys;
        }
    }
    // Check new merchant_payment_methods table
    if (function_exists('getMerchantEnabledMethodKeys')) {
        $pmEnabled = getMerchantEnabledMethodKeys((int)$merchant['id']);
        if (!empty($pmEnabled)) {
            return function_exists('normalizeCheckoutMethodKeys')
                ? normalizeCheckoutMethodKeys($pmEnabled)
                : $pmEnabled;
        }
    }
    // New merchants: only UPI P2M until partner/admin unlocks more.
    return ['upi_p2m'];
}

function buildPaymentLinkUrl(string $linkId, ?string $payKey = null): string
{
    $url = APP_URL . '/checkout.php?link=' . rawurlencode($linkId);
    if ($payKey) {
        $url .= '&pay=' . rawurlencode($payKey);
    }
    return $url;
}

function merchantMethodPreview(array $merchant): array
{
    $profile = getMerchantProvisionProfile($merchant);
    $methods = getMerchantEnabledMethods($merchant);
    $catalog = getPaymentMethodCatalog();
    $rows = [];
    foreach ($methods as $key) {
        if (!isset($catalog[$key])) continue;
        $c = $catalog[$key];
        $rows[] = [
            'key' => $key,
            'label' => $c['label'],
            'icon' => $c['icon'],
            'gateway' => $c['gateway'],
            'mdr' => getMdrWithMargin($c['mdr'], $merchant),
            'collection_mode' => $c['collection_mode'],
        ];
    }
    return [
        'profile' => $merchant['provision_profile'] ?? $profile['profile'],
        'profile_label' => $profile['label'],
        'collection_mode' => getMerchantCollectionMode($merchant),
        'methods' => $rows,
        'auto_provisioned' => !empty($merchant['auto_provisioned']),
    ];
}

function createMethodPaymentLink(int $merchantId, string $methodKey, float $amount, string $packId, string $label, bool $isTest, string $amountType = 'fixed'): ?string
{
    ensurePaymentPackSchema();
    $catalog = getPaymentMethodCatalog();
    if (!isset($catalog[$methodKey])) {
        return null;
    }

    $cat = $catalog[$methodKey];
    $db = getDB();
    $linkId = generateId('LNK');
    $amountType = $amountType === 'open' ? 'open' : 'fixed';
    $storeAmount = $amountType === 'open' ? 0.0 : max(1.0, $amount);
    // Pack links must stay usable for demos — no accidental expiry
    $expiresAt = null;
    $desc = ($amountType === 'open' ? 'Open amount — ' : 'Fixed ₹' . number_format($storeAmount, 2) . ' — ') . $cat['label'];

    try {
        $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, expires_at, is_test, status, payment_method, gateway_code, pack_id, link_label, link_collection_mode, amount_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $linkId, $merchantId, $storeAmount,
                $desc,
                $expiresAt, $isTest ? 1 : 0, 'active',
                $methodKey, $cat['gateway'], $packId,
                $cat['label'] . ($amountType === 'open' ? ' (Open)' : ' (Fixed)'),
                $cat['collection_mode'],
                $amountType,
            ]);
    } catch (Throwable $e) {
        try {
            $db->prepare('INSERT INTO payment_links (link_id, merchant_id, amount, description, expires_at, is_test, status, payment_method, gateway_code, pack_id, link_label, link_collection_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $linkId, $merchantId, $storeAmount,
                    $desc,
                    $expiresAt, $isTest ? 1 : 0, 'active',
                    $methodKey, $cat['gateway'], $packId, $cat['label'], $cat['collection_mode'],
                ]);
        } catch (Throwable $e2) {
            return null;
        }
    }
    return $linkId;
}

function generateMerchantPaymentPack(int $merchantId, float $amount = 1.0, ?bool $forceTest = null): array
{
    ensurePaymentPackSchema();
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $stmt->execute([$merchantId]);
    $merchant = $stmt->fetch();
    if (!$merchant) {
        return ['ok' => false, 'links' => []];
    }

    $methods = getMerchantEnabledMethods($merchant);
    if (!in_array('upi_p2m', $methods, true)) {
        array_unshift($methods, 'upi_p2m');
        try {
            $db->prepare('UPDATE merchants SET enabled_methods=? WHERE id=?')->execute([json_encode(array_values($methods)), $merchantId]);
        } catch (Throwable $e) {
            /* column may be missing */
        }
    }
    $packId = generateId('PACK');
    $isTest = $forceTest ?? isMerchantPaymentTest($merchant);
    $created = [];

    // Retire old pack links so broken/expired URLs stop showing
    try {
        $db->prepare("UPDATE payment_links SET status='inactive' WHERE merchant_id=? AND pack_id IS NOT NULL AND status='active'")
            ->execute([$merchantId]);
    } catch (Throwable $e) { /* ok */ }

    foreach ($methods as $methodKey) {
        $cat = getPaymentMethodCatalog()[$methodKey] ?? null;
        if (!$cat) {
            continue;
        }
        // Fixed ₹1 (Instant Test Pay) + Open amount (customer enters)
        foreach (['fixed' => $amount, 'open' => 0.0] as $amountType => $linkAmount) {
            $linkId = createMethodPaymentLink($merchantId, $methodKey, (float)$linkAmount, $packId, 'Payment Pack', $isTest, $amountType);
            if ($linkId) {
                $created[] = [
                    'method' => $methodKey,
                    'label' => $cat['label'],
                    'amount_type' => $amountType,
                    'link_id' => $linkId,
                    'url' => buildPaymentLinkUrl($linkId, $cat['pay_key']),
                ];
            }
        }
    }

    try {
        $db->prepare('UPDATE merchants SET provision_pack_id=? WHERE id=?')->execute([$packId, $merchantId]);
    } catch (Throwable $e) {
        // column may not exist
    }

    return ['ok' => count($created) > 0, 'pack_id' => $packId, 'links' => $created];
}

function applyMerchantSignupPreferences(int $merchantId, string $collectionMode, array $enabledMethods): void
{
    $db = getDB();
    $modes = array_keys(getCollectionModes());
    if (!in_array($collectionMode, $modes, true)) {
        $collectionMode = getSetting('default_collection_mode', 'direct_upi');
    }
    // P11-01: new merchants must not start on live Route/Easy Split.
    if (!in_array($collectionMode, ['direct_upi', 'platform_pg'], true)) {
        $collectionMode = 'direct_upi';
    }
    $catalogKeys = array_keys(getPaymentMethodCatalog());
    $enabledMethods = array_values(array_intersect($catalogKeys, $enabledMethods));
    if (empty($enabledMethods)) {
        $enabledMethods = ['upi_p2m'];
    }
    try {
        $db->prepare('UPDATE merchants SET collection_mode=?, enabled_methods=?, provision_profile=?, auto_provisioned=1 WHERE id=?')
            ->execute([$collectionMode, json_encode($enabledMethods), 'signup_custom', $merchantId]);
    } catch (Throwable $e) {
        try {
            $db->prepare('UPDATE merchants SET collection_mode=? WHERE id=?')->execute([$collectionMode, $merchantId]);
        } catch (Throwable $e2) { /* ok */ }
    }
    if (!function_exists('setMerchantPaymentMethods') && is_file(__DIR__ . '/payment_methods.php')) {
        require_once __DIR__ . '/payment_methods.php';
    }
    if (function_exists('setMerchantPaymentMethods')) {
        setMerchantPaymentMethods($merchantId, $enabledMethods, 'signup');
    }
    generateMerchantPaymentPack($merchantId, 1.0, true);
    notifyMerchant($merchantId, 'Payment Pack Ready', 'Your selected payment methods are active in TEST mode. Check Payment Pack for ₹1 test links.', 'payment_pack_ready');
}

function autoProvisionMerchant(int $merchantId, int $adminId): array
{
    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $stmt->execute([$merchantId]);
    $merchant = $stmt->fetch();
    if (!$merchant) {
        return ['ok' => false, 'message' => 'Merchant not found.'];
    }

    if (!function_exists('bootstrapMerchantMethodAutomation')) {
        require_once __DIR__ . '/method_requests.php';
    }
    $boot = bootstrapMerchantMethodAutomation($merchantId, 'Admin auto setup — P2M on, other methods queued for partner');

    $pack = generateMerchantPaymentPack($merchantId, 1.0);

    notifyMerchant(
        $merchantId,
        'Payment Stack Queued',
        'UPI P2M is active in TEST mode. Other methods are waiting for admin → partner approval.',
        'payment_stack_queued'
    );

    return [
        'ok' => true,
        'message' => 'P2M enabled. ' . (int)($boot['queued'] ?? 0) . ' method(s) queued for partner. ' . count($pack['links']) . ' test link(s) ready.',
        'bootstrap' => $boot,
        'pack' => $pack,
    ];
}

function getMerchantPackLinks(int $merchantId, ?string $packId = null): array
{
    ensurePaymentPackSchema();
    $db = getDB();
    if ($packId) {
        $stmt = $db->prepare("SELECT * FROM payment_links
            WHERE merchant_id=? AND pack_id=? AND status='active'
              AND (expires_at IS NULL OR expires_at > NOW())
            ORDER BY link_label, amount_type, id");
        try {
            $stmt->execute([$merchantId, $packId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            $stmt = $db->prepare("SELECT * FROM payment_links
                WHERE merchant_id=? AND pack_id=? AND status='active'
                  AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY link_label, id");
            $stmt->execute([$merchantId, $packId]);
            return $stmt->fetchAll();
        }
    }
    $stmt = $db->prepare("SELECT * FROM payment_links
        WHERE merchant_id=? AND pack_id IS NOT NULL AND status='active'
          AND (expires_at IS NULL OR expires_at > NOW())
        ORDER BY created_at DESC");
    $stmt->execute([$merchantId]);
    return $stmt->fetchAll();
}

/** True when the payer must type the amount (open-amount link). */
function paymentLinkIsOpenAmount(array $link): bool
{
    $type = strtolower(trim((string)($link['amount_type'] ?? '')));
    if ($type === 'open') {
        return true;
    }
    return (float)($link['amount'] ?? $link['payment_amount'] ?? 0) <= 0;
}
