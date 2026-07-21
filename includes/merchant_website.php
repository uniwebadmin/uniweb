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
    getDB()->prepare("UPDATE merchants SET website_status=?,website_review_status=?,account_mode=IF(?='verified',account_mode,'test') WHERE id=?")
        ->execute([$status, $status, $status, $merchantId]);
    if ($status === 'verified') {
        createNotification($merchantId, 'Website Verified', 'Your business website has been verified. You can use it in payment gateway applications.');
    } elseif ($status === 'rejected') {
        createNotification($merchantId, 'Website Review', 'Your website URL needs correction. Please update it in Settings → Website & App.');
    }
}
