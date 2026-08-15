<?php
declare(strict_types=1);

/** Admin read-only merchant profile snapshot */

function buildMerchantAdminView(int $merchantId): ?array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM merchants WHERE id = ? AND status != 'deleted'");
    $stmt->execute([$merchantId]);
    $merchant = $stmt->fetch();
    if (!$merchant) {
        return null;
    }

    ensureMerchantWalletReady($merchantId);
    $st = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $merchant = $st->fetch();
    // B-03: admin detail always shows decrypted KYC PII (never raw enc:v1:)
    if (is_array($merchant) && function_exists('decryptMerchantPiiFields')) {
        $merchant = decryptMerchantPiiFields($merchant);
    }

    $wallet = ensureMerchantWalletReady($merchantId);
    $preview = merchantMethodPreview($merchant);
    $kyc = getMerchantKycProgress($merchant);
    $packLinks = getMerchantPackLinks($merchantId, $merchant['provision_pack_id'] ?? null);

    $banks = $db->prepare('SELECT * FROM bank_accounts WHERE merchant_id = ? ORDER BY is_primary DESC');
    $banks->execute([$merchantId]);

    $txCount = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id = ?");
    $txCount->execute([$merchantId]);
    $txSuccess = $db->prepare("SELECT COUNT(*) FROM transactions WHERE merchant_id = ? AND status = 'success'");
    $txSuccess->execute([$merchantId]);

    $settlements = $db->prepare('SELECT * FROM settlements WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 10');
    $settlements->execute([$merchantId]);

    ensureMerchantWebhookEngine();
    $webhookSummary = getMerchantWebhookSummary($merchantId);
    $webhookLogs = getMerchantWebhookLogs($merchantId, 8);

    $enabledMethods = [];
    if (!empty($merchant['enabled_methods'])) {
        $decoded = json_decode((string)$merchant['enabled_methods'], true);
        if (is_array($decoded)) {
            $enabledMethods = $decoded;
        }
    }

    return [
        'merchant' => $merchant,
        'wallet' => $wallet,
        'preview' => $preview,
        'kyc' => $kyc,
        'pack_links' => $packLinks,
        'banks' => $banks->fetchAll(),
        'txn_total' => (int)$txCount->fetchColumn(),
        'txn_success' => (int)$txSuccess->fetchColumn(),
        'settlements' => $settlements->fetchAll(),
        'webhook_summary' => $webhookSummary,
        'webhook_logs' => $webhookLogs,
        'enabled_methods' => $enabledMethods,
        'collection_modes' => getCollectionModes(),
        'plans' => getSubscriptionPlans(),
    ];
}
