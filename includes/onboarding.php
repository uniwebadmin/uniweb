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
            'id' => 'payment_pack',
            'label' => 'Set up Payment Pack',
            'done' => count($packLinks) > 0,
            'url' => 'merchant_payment_pack.php',
            'hint' => count($packLinks) . ' test link(s)',
        ],
        [
            'id' => 'test_pay',
            'label' => 'Run a ₹1 test payment',
            'done' => $wallet['success_txns'] > 0,
            'url' => 'merchant_payment_pack.php',
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
            'hint' => collectionModeLabel($preview['collection_mode'], true),
        ],
    ];
}

function ensureMerchantOnboardingDraftSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    getDB()->exec('CREATE TABLE IF NOT EXISTS merchant_onboarding_drafts (
        merchant_id INT NOT NULL PRIMARY KEY,
        draft_json JSON NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_merchant_onboarding_draft_merchant FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function merchantOnboardingDraftData(array $input): array
{
    $fields = ['name', 'business_name', 'country', 'state', 'district', 'city', 'pincode', 'address', 'business_entity_type', 'business_type', 'pan_number', 'collection_mode'];
    $draft = [];
    foreach ($fields as $field) {
        $value = trim((string)($input[$field] ?? ''));
        $draft[$field] = mb_substr($value, 0, $field === 'address' ? 1000 : 255);
    }
    $allowedMethods = array_keys(getPaymentMethodCatalog());
    $draft['enabled_methods'] = array_values(array_intersect(
        $allowedMethods,
        array_map('strval', (array)($input['enabled_methods'] ?? []))
    ));
    return $draft;
}

function getMerchantOnboardingDraft(int $merchantId): array
{
    ensureMerchantOnboardingDraftSchema();
    $stmt = getDB()->prepare('SELECT draft_json FROM merchant_onboarding_drafts WHERE merchant_id=?');
    $stmt->execute([$merchantId]);
    $raw = $stmt->fetchColumn();
    $draft = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($draft) ? merchantOnboardingDraftData($draft) : [];
}

function saveMerchantOnboardingDraft(int $merchantId, array $input): array
{
    ensureMerchantOnboardingDraftSchema();
    $draft = merchantOnboardingDraftData($input);
    $json = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'message' => 'Could not save your draft.'];
    }
    getDB()->prepare('INSERT INTO merchant_onboarding_drafts (merchant_id, draft_json) VALUES (?,?) ON DUPLICATE KEY UPDATE draft_json=VALUES(draft_json)')
        ->execute([$merchantId, $json]);
    return ['ok' => true, 'message' => 'Draft saved. You can continue from any device.'];
}

function clearMerchantOnboardingDraft(int $merchantId): void
{
    ensureMerchantOnboardingDraftSchema();
    getDB()->prepare('DELETE FROM merchant_onboarding_drafts WHERE merchant_id=?')->execute([$merchantId]);
}

function getMerchantLaunchTestData(int $merchantId): array
{
    $db = getDB();
    $linkStmt = $db->prepare("SELECT * FROM payment_links WHERE merchant_id=? AND is_test=1 AND status='active' AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY created_at DESC LIMIT 1");
    $linkStmt->execute([$merchantId]);
    $link = $linkStmt->fetch() ?: null;
    $successStmt = $db->prepare("SELECT txn_id, amount, payment_method, created_at FROM transactions WHERE merchant_id=? AND is_test=1 AND status='success' ORDER BY created_at DESC LIMIT 1");
    $successStmt->execute([$merchantId]);
    $success = $successStmt->fetch() ?: null;

    $url = null;
    if ($link) {
        $catalog = getPaymentMethodCatalog();
        $method = $catalog[(string)($link['payment_method'] ?? '')] ?? [];
        $url = buildPaymentLinkUrl((string)$link['link_id'], $method['pay_key'] ?? null);
    }
    return ['link' => $link, 'url' => $url, 'success' => $success];
}

function ensureMerchantLaunchTestPack(int $merchantId): array
{
    $existing = getMerchantLaunchTestData($merchantId);
    if (!empty($existing['link'])) {
        return ['ok' => true, 'created' => false, 'message' => 'Your test checkout is ready.'];
    }
    $pack = generateMerchantPaymentPack($merchantId, 1.0, true);
    return [
        'ok' => !empty($pack['ok']),
        'created' => !empty($pack['ok']),
        'message' => !empty($pack['ok']) ? 'Your ₹1 test checkout is ready.' : 'Could not prepare a test checkout. Add a payment method first.',
    ];
}

function getMerchantLaunchCenter(array $merchant): array
{
    $steps = getMerchantOnboardingSteps($merchant);
    $requiredIds = ['profile', 'payment_pack', 'test_pay', 'website', 'bank', 'kyc', 'video_kyc', 'agreement', 'methods'];
    $launchSteps = [];
    $completed = 0;

    foreach ($steps as $step) {
        if (!in_array((string)($step['id'] ?? ''), $requiredIds, true)) {
            continue;
        }
        $done = !empty($step['done']);
        $launchSteps[] = [
            'id' => (string)$step['id'],
            'label' => (string)$step['label'],
            'hint' => (string)$step['hint'],
            'url' => (string)$step['url'],
            'state' => $done ? 'completed' : 'needs_action',
            'done' => $done,
        ];
        if ($done) {
            $completed++;
        }
    }

    $next = null;
    foreach ($launchSteps as $step) {
        if (!$step['done']) {
            $next = $step;
            break;
        }
    }

    $total = count($launchSteps);
    $score = (int)round(($completed / max(1, $total)) * 100);
    return [
        'steps' => $launchSteps,
        'completed' => $completed,
        'total' => $total,
        'score' => $score,
        'next' => $next,
        'test_ready' => $next === null || !empty($launchSteps[2]['done']),
        'live_ready' => function_exists('merchantCanGoLive') && merchantCanGoLive($merchant),
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
    if ($amount < 0 || !is_finite($amount) || $amount > livePaymentAmountCap()) {
        return 0.0;
    }
    return round($amount, 2);
}

function safePlatformAmount(float $amount): float
{
    return safeDisplayBalance($amount);
}
