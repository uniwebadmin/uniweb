<?php
declare(strict_types=1);

/** Demo merchant + payment link for instant testing (Instant Test Pay on public demo) */

function ensureDemoMerchant(): array
{
    $db = getDB();
    $email = 'demo@uniweb.co.in';
    $stmt = $db->prepare('SELECT * FROM merchants WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $merchant = $stmt->fetch();

    if (!$merchant) {
        $code = 'UWDEMO01';
        $pass = password_hash('Demo@1234', PASSWORD_ARGON2ID);
        $db->prepare('INSERT INTO merchants (merchant_code,name,email,phone,password,business_name,business_type,business_entity_type,upi_id,kyc_status,account_mode,collection_mode,commission_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $code, 'Demo Merchant', $email, COMPANY_PHONE, $pass,
                'UniWeb Demo Store', 'retail', 'sole_proprietorship',
                'demomerchant@paytm', 'submitted', 'test', 'direct_upi', 0.10, 'active',
            ]);
        $merchantId = (int)$db->lastInsertId();
        try {
            $db->prepare('UPDATE merchants SET test_api_key=?, test_api_secret=? WHERE id=?')
                ->execute(['test_demo_' . bin2hex(random_bytes(8)), 'testsec_demo', $merchantId]);
        } catch (Throwable $e) { /* ok */ }
        $stmt->execute([$email]);
        $merchant = $stmt->fetch();
        if (function_exists('bootstrapMerchantMethodAutomation')) {
            bootstrapMerchantMethodAutomation($merchantId, 'Demo merchant auto-queue');
        } elseif (is_file(__DIR__ . '/method_requests.php')) {
            require_once __DIR__ . '/method_requests.php';
            if (function_exists('bootstrapMerchantMethodAutomation')) {
                bootstrapMerchantMethodAutomation($merchantId, 'Demo merchant auto-queue');
            }
        }
    }

    $merchantId = (int)$merchant['id'];

    // Public demo is permanently sandbox-only and must never obtain Live Mode.
    try {
        $db->prepare("UPDATE merchants SET account_mode='test', kyc_status='submitted', status='active', collection_mode='direct_upi', upi_id=COALESCE(NULLIF(upi_id,''), 'demomerchant@paytm'), business_entity_type=COALESCE(NULLIF(business_entity_type,''), 'sole_proprietorship') WHERE id=?")
            ->execute([$merchantId]);
    } catch (Throwable $e) {
        try {
            $db->prepare("UPDATE merchants SET account_mode='test', kyc_status='submitted', status='active' WHERE id=?")->execute([$merchantId]);
        } catch (Throwable $e2) { /* ok */ }
    }

    ensureMerchantWebsiteEngine();
    try {
        $db->prepare("UPDATE merchants SET website_url=?, website_status='verified' WHERE id=? AND (website_url IS NULL OR website_url='')")
            ->execute([APP_URL, $merchantId]);
    } catch (Throwable $e) { /* ok */ }

    $demoMethods = ['upi_p2m', 'debit_card', 'credit_card', 'netbanking', 'wallet', 'payu_upi', 'emi'];
    applyMerchantSignupPreferences($merchantId, 'direct_upi', $demoMethods);

    $stmt->execute([$email]);
    $merchant = $stmt->fetch() ?: $merchant;

    // Public demo + packs accept Instant Test Pay in sandbox only.
    try {
        $db->prepare("UPDATE payment_links SET is_test=1 WHERE merchant_id=? AND status='active'")->execute([$merchantId]);
    } catch (Throwable $e) { /* ok */ }

    $db->prepare("UPDATE payment_links SET status='expired' WHERE merchant_id=? AND status='active' AND (amount != 1 OR amount IS NULL)")->execute([$merchantId]);

    $link = $db->prepare("SELECT * FROM payment_links WHERE merchant_id = ? AND amount = 1 AND status='active' AND (payment_method='upi_p2m' OR payment_method IS NULL OR payment_method='') ORDER BY FIELD(COALESCE(payment_method,''),'upi_p2m') DESC, created_at DESC LIMIT 1");
    $link->execute([$merchantId]);
    $activeLink = $link->fetch();

    $needsNew = !$activeLink
        || ($activeLink['expires_at'] && strtotime($activeLink['expires_at']) < time());

    if ($needsNew) {
        $db->prepare("UPDATE payment_links SET status='expired' WHERE merchant_id=? AND status='active' AND (payment_method IS NULL OR payment_method='' OR payment_method='upi_p2m')")->execute([$merchantId]);
        $linkId = 'DEMO' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $expires = date('Y-m-d H:i:s', time() + 86400 * 365);
        try {
            $db->prepare('INSERT INTO payment_links (link_id,merchant_id,amount,description,customer_name,expires_at,is_test,status,payment_method,gateway_code,link_label,link_collection_mode) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $linkId, $merchantId, 1.00, 'UniWeb Demo Payment — Test ₹1 (UPI)', 'Test Customer', $expires, 1, 'active',
                    'upi_p2m', 'direct', 'UPI / QR', 'direct_upi',
                ]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO payment_links (link_id,merchant_id,amount,description,expires_at,status,is_test) VALUES (?,?,?,?,?,?,?)')
                ->execute([$linkId, $merchantId, 1.00, 'UniWeb Demo Payment', $expires, 'active', 1]);
        }
        $activeLink = ['link_id' => $linkId, 'amount' => 1];
    } else {
        try {
            $db->prepare("UPDATE payment_links SET is_test=1, status='active' WHERE id=?")->execute([(int)$activeLink['id']]);
        } catch (Throwable $e) { /* ok */ }
    }

    return [
        'merchant_id' => $merchantId,
        'merchant_code' => $merchant['merchant_code'] ?? 'UWDEMO01',
        'link_id' => $activeLink['link_id'],
        'amount' => 1.0,
        'pay_url' => buildPaymentLinkUrl($activeLink['link_id'], 'upi'),
        'pay_url_clean' => buildPaymentLinkUrl($activeLink['link_id'], 'upi'),
        'login_email' => $email,
        'login_password' => 'Demo@1234',
    ];
}

function getDemoPaymentUrl(): string
{
    return ensureDemoMerchant()['pay_url'];
}
