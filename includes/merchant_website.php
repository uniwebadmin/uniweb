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

function merchantStorefrontTemplates(): array
{
    return [
        'services' => ['label' => 'Services', 'description' => 'Best for consultants, agencies, tutors and local services.'],
        'retail' => ['label' => 'Retail', 'description' => 'Best for products, stores and catalogue businesses.'],
        'invoice' => ['label' => 'Invoice & appointments', 'description' => 'Best for bookings, deposits and invoice collections.'],
    ];
}

function merchantStorefrontTableAvailable(): bool
{
    static $available = null;
    if ($available !== null) {
        return $available;
    }
    try {
        getDB()->query('SELECT 1 FROM merchant_storefronts LIMIT 1');
        $available = true;
        try {
            getDB()->exec("ALTER TABLE merchant_storefronts ADD COLUMN logo_url VARCHAR(500) DEFAULT NULL AFTER template_key");
        } catch (Throwable $e) {
            /* column exists */
        }
    } catch (Throwable $e) {
        $available = false;
    }
    return $available;
}

function getMerchantStorefront(int $merchantId): ?array
{
    if (!merchantStorefrontTableAvailable()) {
        return null;
    }
    $stmt = getDB()->prepare('SELECT * FROM merchant_storefronts WHERE merchant_id=?');
    $stmt->execute([$merchantId]);
    return $stmt->fetch() ?: null;
}

function merchantStorefrontUrl(?array $storefront): string
{
    $slug = trim((string)($storefront['storefront_slug'] ?? ''));
    return $slug === '' ? '' : APP_URL . '/store.php?s=' . rawurlencode($slug);
}

function saveMerchantStorefront(array $merchant, array $input): array
{
    if (!merchantStorefrontTableAvailable()) {
        return ['ok' => false, 'message' => 'Storefront setup is preparing. Please try again shortly.'];
    }
    $templates = merchantStorefrontTemplates();
    $template = (string)($input['template_key'] ?? 'services');
    if (!isset($templates[$template])) {
        return ['ok' => false, 'message' => 'Choose a valid storefront template.'];
    }
    $headline = mb_substr(trim((string)($input['headline'] ?? '')), 0, 160);
    $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 2000);
    $contact = mb_substr(trim((string)($input['contact_text'] ?? '')), 0, 255);
    $logoUrl = mb_substr(trim((string)($input['logo_url'] ?? '')), 0, 500);
    if ($logoUrl !== '' && !filter_var($logoUrl, FILTER_VALIDATE_URL)) {
        return ['ok' => false, 'message' => 'Enter a valid logo image URL (https://...).'];
    }
    if ($headline === '' || $description === '') {
        return ['ok' => false, 'message' => 'Add a headline and a short business description.'];
    }
    $merchantId = (int)$merchant['id'];
    $existing = getMerchantStorefront($merchantId);
    $slug = (string)($existing['storefront_slug'] ?? '');
    if ($slug === '') {
        $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string)($merchant['merchant_code'] ?? 'merchant-' . $merchantId)) ?? 'merchant-' . $merchantId);
        $slug = trim($base, '-') . '-shop';
    }
    $published = !empty($input['is_published']) ? 1 : 0;
    getDB()->prepare('INSERT INTO merchant_storefronts (merchant_id, storefront_slug, template_key, headline, description, contact_text, logo_url, is_published, published_at) VALUES (?,?,?,?,?,?,?,?,IF(?=1,NOW(),NULL)) ON DUPLICATE KEY UPDATE template_key=VALUES(template_key), headline=VALUES(headline), description=VALUES(description), contact_text=VALUES(contact_text), logo_url=VALUES(logo_url), is_published=VALUES(is_published), published_at=IF(VALUES(is_published)=1,COALESCE(published_at,NOW()),NULL)')
        ->execute([$merchantId, $slug, $template, $headline, $description, $contact, $logoUrl ?: null, $published, $published]);
    return ['ok' => true, 'message' => $published ? 'Your sales page is published.' : 'Sales page saved as draft.'];
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
        createNotification($merchantId, 'Website Submitted', 'Your website URL was saved. Verification is in process — required for live activation through UniWeb.');
    }

    return ['ok' => true, 'message' => 'Website & app details saved.', 'status' => $status];
}

/**
 * Fetch a merchant's website homepage and check for the compliance pages
 * gateways expect (Contact, Privacy, Terms, Refund/Cancellation, About) plus
 * HTTPS. Read-only, SSRF-guarded, best-effort — never blocks onboarding.
 * @return array{ok:bool,fetched:bool,error?:string,checks:array<int,array{key:string,label:string,pass:bool,required:bool,detail:string}>,score:int,max:int,required_pass:bool}
 */
function checkWebsiteCompliance(string $url): array
{
    $url = normalizeWebsiteUrl($url);
    $checks = [];
    $add = function (string $key, string $label, bool $pass, bool $required, string $detail = '') use (&$checks) {
        $checks[] = ['key' => $key, 'label' => $label, 'pass' => $pass, 'required' => $required, 'detail' => $detail];
    };

    if ($url === '' || !isValidWebsiteUrl($url)) {
        return ['ok' => false, 'fetched' => false, 'error' => 'Enter a valid website URL first.', 'checks' => [], 'score' => 0, 'max' => 0, 'required_pass' => false];
    }

    $isHttps = (bool)preg_match('#^https://#i', $url);
    $guard = publicWebhookDestination(preg_replace('#^http://#i', 'https://', $url));
    if (empty($guard['ok'])) {
        $add('https', 'Served over HTTPS', $isHttps, true, $isHttps ? 'Secure' : 'Use https:// — gateways require a secure site');
        return ['ok' => false, 'fetched' => false, 'error' => $guard['error'] ?? 'Website host could not be reached safely.', 'checks' => $checks, 'score' => 0, 'max' => 0, 'required_pass' => false];
    }

    $html = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'UniWeb-ComplianceBot/1.0 (+' . APP_URL . ')',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $out = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($out !== false && $code >= 200 && $code < 400) {
            $html = (string)$out;
        }
    }
    if ($html === '') {
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true, 'user_agent' => 'UniWeb-ComplianceBot/1.0']]);
        $out = @file_get_contents($url, false, $ctx, 0, 512000);
        if ($out !== false) {
            $html = (string)$out;
        }
    }

    if ($html === '') {
        $add('https', 'Served over HTTPS', $isHttps, true, $isHttps ? 'Secure' : 'Use https://');
        return ['ok' => false, 'fetched' => false, 'error' => 'Could not load the homepage (site down, blocking bots, or too slow). You can still submit — admin will review manually.', 'checks' => $checks, 'score' => 0, 'max' => 0, 'required_pass' => false];
    }

    $hay = strtolower($html);
    $has = function (array $needles) use ($hay): bool {
        foreach ($needles as $n) {
            if (str_contains($hay, $n)) return true;
        }
        return false;
    };

    $add('https', 'Served over HTTPS', $isHttps, true, $isHttps ? 'Secure connection' : 'Not secure — switch to https://');
    $add('contact', 'Contact details / Contact Us page', $has(['contact us', 'contact-us', '>contact<', 'contact.php', '/contact', 'mailto:', 'tel:']), true, 'Phone/email or a Contact page');
    $add('privacy', 'Privacy Policy', $has(['privacy policy', 'privacy-policy', '/privacy', 'privacy.php', '>privacy<']), true, 'Required by all gateways');
    $add('terms', 'Terms & Conditions', $has(['terms & conditions', 'terms and conditions', 'terms of service', 'terms-and-conditions', '/terms', '>terms<']), true, 'Required by all gateways');
    $add('refund', 'Refund / Cancellation Policy', $has(['refund', 'cancellation', 'return policy', 'refund-policy']), true, 'Required for card/UPI onboarding');
    $add('about', 'About Us / business info', $has(['about us', 'about-us', '/about', '>about<']), false, 'Recommended');
    $add('pricing', 'Products / Pricing shown', $has(['price', 'pricing', '₹', 'buy now', 'add to cart', 'products', 'services']), false, 'Recommended — shows a real business');

    $score = 0;
    $max = 0;
    $requiredPass = true;
    foreach ($checks as $c) {
        $max++;
        if ($c['pass']) $score++;
        if ($c['required'] && !$c['pass']) $requiredPass = false;
    }

    return ['ok' => true, 'fetched' => true, 'checks' => $checks, 'score' => $score, 'max' => $max, 'required_pass' => $requiredPass];
}

function adminSetMerchantWebsiteStatus(int $merchantId, string $status): void
{
    ensureMerchantWebsiteEngine();
    if (!in_array($status, ['pending', 'verified', 'rejected', 'not_set'], true)) {
        return;
    }
    if ($status === 'verified') {
        getDB()->prepare("UPDATE merchants SET website_status=?,website_review_status=? WHERE id=?")
            ->execute([$status, $status, $merchantId]);
    } else {
        getDB()->prepare("UPDATE merchants SET website_status=?,website_review_status=?,account_mode='test' WHERE id=?")
            ->execute([$status, $status, $merchantId]);
    }
    if ($status === 'verified') {
        createNotification($merchantId, 'Website Verified', 'Your business website has been verified. You can use it in payment gateway applications.');
    } elseif ($status === 'rejected') {
        createNotification($merchantId, 'Website Review', 'Your website URL needs correction. Please update it in Settings → Website & App.');
    }
}
