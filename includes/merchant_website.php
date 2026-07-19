<?php
declare(strict_types=1);

/** Merchant website / app URLs — PayU-style Website & App settings */

function ensureMerchantWebsiteEngine(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $db = getDB();
    foreach ([
        "ALTER TABLE merchants ADD COLUMN website_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN android_app_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN ios_app_url VARCHAR(255) DEFAULT NULL",
        "ALTER TABLE merchants ADD COLUMN website_status VARCHAR(20) NOT NULL DEFAULT 'not_set'",
    ] as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            /* column exists */
        }
    }
}

function normalizeWebsiteUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    return rtrim($url, '/');
}

function normalizeAppStoreUrl(string $url): string
{
    return trim($url);
}

function isValidWebsiteUrl(string $url): bool
{
    if ($url === '') {
        return true;
    }
    return (bool)filter_var($url, FILTER_VALIDATE_URL)
        && (bool)preg_match('#^https?://#i', $url);
}

function merchantWebsiteStatus(?array $merchant): string
{
    ensureMerchantWebsiteEngine();
    $status = strtolower(trim((string)($merchant['website_status'] ?? 'not_set')));
    $url = trim((string)($merchant['website_url'] ?? ''));
    if ($url === '') {
        return 'not_set';
    }
    return in_array($status, ['not_set', 'pending', 'verified', 'rejected'], true) ? $status : 'pending';
}

function merchantWebsiteStatusBadge(?array $merchant): string
{
    return match (merchantWebsiteStatus($merchant)) {
        'verified' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">✓ Verified</span>',
        'pending' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-sky-500/20 text-sky-300 border border-sky-500/30">Verification in Process</span>',
        'rejected' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-red-500/20 text-red-400 border border-red-500/30">Rejected</span>',
        default => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-500/20 text-gray-400 border border-gray-600/40">Not Added</span>',
    };
}

function merchantWebsiteConfigured(?array $merchant): bool
{
    return trim((string)($merchant['website_url'] ?? '')) !== '';
}

function saveMerchantWebsite(int $merchantId, string $websiteUrl, string $androidUrl, string $iosUrl): array
{
    ensureMerchantWebsiteEngine();
    $db = getDB();
    $stmt = $db->prepare('SELECT website_url, website_status FROM merchants WHERE id=?');
    $stmt->execute([$merchantId]);
    $row = $stmt->fetch() ?: [];

    $websiteUrl = normalizeWebsiteUrl($websiteUrl);
    $androidUrl = normalizeAppStoreUrl($androidUrl);
    $iosUrl = normalizeAppStoreUrl($iosUrl);

    if ($websiteUrl !== '' && !isValidWebsiteUrl($websiteUrl)) {
        return ['ok' => false, 'message' => 'Enter a valid website URL (https://example.com).'];
    }
    if ($androidUrl !== '' && !isValidWebsiteUrl($androidUrl)) {
        return ['ok' => false, 'message' => 'Enter a valid Android app URL.'];
    }
    if ($iosUrl !== '' && !isValidWebsiteUrl($iosUrl)) {
        return ['ok' => false, 'message' => 'Enter a valid iOS app URL.'];
    }

    $oldUrl = trim((string)($row['website_url'] ?? ''));
    $status = strtolower((string)($row['website_status'] ?? 'not_set'));
    if ($websiteUrl === '') {
        $status = 'not_set';
    } elseif ($websiteUrl !== $oldUrl) {
        $status = 'pending';
    } elseif (!in_array($status, ['verified', 'rejected', 'pending'], true)) {
        $status = 'pending';
    }

    $db->prepare('UPDATE merchants SET website_url=?, android_app_url=?, ios_app_url=?, website_status=? WHERE id=?')
        ->execute([
            $websiteUrl ?: null,
            $androidUrl ?: null,
            $iosUrl ?: null,
            $status,
            $merchantId,
        ]);

    if ($websiteUrl !== '' && $websiteUrl !== $oldUrl) {
        createNotification($merchantId, 'Website Submitted', 'Your website URL was saved. Verification is in process — required for PayU/Razorpay live onboarding.');
    }

    return ['ok' => true, 'message' => 'Website & app details saved.', 'status' => $status];
}

function adminSetMerchantWebsiteStatus(int $merchantId, string $status): void
{
    ensureMerchantWebsiteEngine();
    if (!in_array($status, ['pending', 'verified', 'rejected', 'not_set'], true)) {
        return;
    }
    getDB()->prepare('UPDATE merchants SET website_status=? WHERE id=?')->execute([$status, $merchantId]);
    if ($status === 'verified') {
        createNotification($merchantId, 'Website Verified', 'Your business website has been verified. You can use it in payment gateway applications.');
    } elseif ($status === 'rejected') {
        createNotification($merchantId, 'Website Review', 'Your website URL needs correction. Please update it in Settings → Website & App.');
    }
}
