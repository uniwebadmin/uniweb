<?php
declare(strict_types=1);

/** Merchant onboarding checklist + platform readiness */

function getMerchantOnboardingSteps(array $merchant): array
{
    $merchantId = (int)$merchant['id'];
    $wallet = ensureMerchantWalletReady($merchantId);
    $kyc = getMerchantKycProgress($merchant);
    $packLinks = getMerchantPackLinks($merchantId, $merchant['provision_pack_id'] ?? null);
    $preview = merchantMethodPreview($merchant);
    $banks = getDB()->prepare('SELECT COUNT(*) FROM bank_accounts WHERE merchant_id=? AND status=?');
    $banks->execute([$merchantId, 'active']);
    $hasBank = (int)$banks->fetchColumn() > 0;

    $agreementDone = false;
    try {
        $agreement = getDB()->prepare('SELECT COUNT(*) FROM merchant_agreement_acceptances WHERE merchant_id=?');
        $agreement->execute([$merchantId]);
        $agreementDone = (int)$agreement->fetchColumn() > 0;
    } catch (Throwable $e) {
        $agreementDone = !empty($merchant['agreement_accepted_at']) || !empty($merchant['agreement_signed_at']);
    }

    return [
        [
            'id' => 'profile',
            'label' => 'Complete business profile',
            'done' => merchantProfileComplete($merchant),
            'url' => 'merchant_setup.php',
            'hint' => merchantProfileComplete($merchant) ? 'Done' : 'Name, address, category',
        ],
        [
            'label' => 'Set up Payment Pack',
            'done' => count($packLinks) > 0,
            'url' => 'merchant_payment_pack.php',
            'hint' => count($packLinks) . ' test link(s)',
        ],
        [
            'id' => 'test_pay',
            'label' => 'Run a ₹1 test payment',
            'done' => $wallet['success_txns'] > 0,
            'url' => count($packLinks) > 0 ? ('checkout.php?link=' . rawurlencode($packLinks[0]['link_id'])) : 'demo.php',
            'hint' => $wallet['success_txns'] . ' success payment(s)',
        ],
        [
            'id' => 'wallet',
            'label' => 'Check your wallet',
            'done' => $wallet['balance'] > 0,
            'url' => 'wallet.php',
            'hint' => formatMoney(safeDisplayBalance($wallet['balance'], $wallet['is_test'] ?? true)),
        ],
        [
            'id' => 'website',
            'label' => 'Add business website',
            'done' => merchantWebsiteConfigured($merchant),
            'url' => 'merchant_website.php',
            'hint' => merchantWebsiteConfigured($merchant) ? merchantWebsiteStatus($merchant) : 'Required for PG live',
        ],
        [
            'id' => 'bank',
            'label' => 'Add bank account',
            'done' => $hasBank,
            'url' => 'add_bank.php',
            'hint' => $hasBank ? 'Added' : 'Required for transfer',
        ],
        [
            'id' => 'kyc',
            'label' => 'KYC documents upload',
            'done' => $kyc['complete'],
            'url' => 'kyc.php',
            'hint' => $kyc['uploaded'] . '/' . $kyc['required'] . ' docs',
        ],
        [
            'id' => 'video_kyc',
            'label' => 'Upload Video KYC',
            'done' => in_array((string)($merchant['video_kyc_status'] ?? ''), ['verified', 'approved', 'submitted'], true),
            'url' => 'merchant_video_verification.php',
            'hint' => match ((string)($merchant['video_kyc_status'] ?? 'pending')) {
                'verified', 'approved' => 'Verified',
                'submitted' => 'Under review',
                default => 'Required for Live',
            },
        ],
        [
            'id' => 'agreement',
            'label' => 'Sign merchant agreement',
            'done' => $agreementDone,
            'url' => 'merchant_agreement.php',
            'hint' => $agreementDone ? 'Signed' : 'Required for Live',
        ],
        [
            'id' => 'methods',
            'label' => 'Payment methods setup',
            'done' => count($preview['methods']) > 0,
            'url' => 'collection_settings.php',
            'hint' => collectionModeLabel($preview['collection_mode']),
        ],
    ];
}

function getPlatformReadiness(): array
{
    $partners = partnerConfiguredCount();
    $db = getDB();
    $merchants = (int)$db->query("SELECT COUNT(*) FROM merchants WHERE status='active'")->fetchColumn();
    $txns = (int)$db->query("SELECT COUNT(*) FROM transactions WHERE status='success'")->fetchColumn();
    $walletOk = getPlatformWalletBalance() <= 1000;

    $checks = [
        ['label' => 'Merchant Registration + Signup Modes', 'ok' => true, 'note' => 'Live'],
        ['label' => 'Payment Pack + Test Checkout', 'ok' => true, 'note' => 'Test pay without keys'],
        ['label' => 'Wallet + Bank Transfer (Test)', 'ok' => true, 'note' => 'Sync + transfer'],
        ['label' => 'Admin Merchant View', 'ok' => true, 'note' => 'View + Edit + Methods'],
        ['label' => 'Transaction Detail Pages', 'ok' => true, 'note' => 'Merchant + Admin'],
        ['label' => 'Partner Structure (9 partners)', 'ok' => $partners['total'] >= 9, 'note' => $partners['ready'] . '/' . $partners['total'] . ' keys saved'],
        ['label' => 'Live Gateway Keys', 'ok' => isGatewayConfigured('payu') || isGatewayConfigured('razorpay') || isGatewayConfigured('cashfree'), 'note' => 'Paste in All Partners'],
        ['label' => 'Axis Bank Live API', 'ok' => isGatewayConfigured('axis'), 'note' => 'UAT page ready'],
        ['label' => 'Platform Wallet Clean', 'ok' => $walletOk, 'note' => formatMoney(getPlatformWalletBalance())],
    ];

    $done = count(array_filter($checks, fn($c) => $c['ok']));
    return [
        'checks' => $checks,
        'done' => $done,
        'total' => count($checks),
        'pct' => (int)round($done / max(1, count($checks)) * 100),
        'merchants' => $merchants,
        'transactions' => $txns,
    ];
}

function capStatAmount(float $amount, int $count = -1): float
{
    if ($count === 0) {
        return 0.0;
    }
    if ($amount < 0 || !is_finite($amount) || $amount > 500000) {
        return 0.0;
    }
    return round($amount, 2);
}

function safePlatformAmount(float $amount): float
{
    return safeDisplayBalance($amount);
}
