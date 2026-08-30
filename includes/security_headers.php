<?php
declare(strict_types=1);

/**
 * Central HTTP security headers for HTML/JSON app responses.
 * Apache .htaccess sets the same baseline for static files; this covers PHP bootstrap.
 * Partner webhook POST endpoints skip CSP/frame headers so signature verify is unaffected.
 */

/** @return list<string> basename patterns — webhooks + JSON API callbacks */
function uniwebSecurityHeaderSkipScripts(): array
{
    return [
        'webhook.php',
        'payu_webhook.php',
        'axis_webhook.php',
        'razorpay_webhook.php',
        'decentro_webhook.php',
        'cashfree_webhook.php',
        'method_partner_webhook.php',
        'whatsapp_webhook.php',
        'api.php',
    ];
}

function uniwebShouldSendSecurityHeaders(): bool
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return false;
    }
    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    foreach (uniwebSecurityHeaderSkipScripts() as $skip) {
        if ($script === $skip) {
            return false;
        }
    }
    return true;
}

/** Baseline Content-Security-Policy — checkout uses inline scripts + partner iframes. */
function uniwebContentSecurityPolicy(): string
{
    return "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "connect-src 'self' https:; "
        . "frame-src 'self' https:; "
        . "form-action 'self' https:; "
        . "base-uri 'self'; "
        . "frame-ancestors 'self'; "
        . "object-src 'none'";
}

/**
 * Send hardened headers once per request.
 *
 * @param bool $sensitivePage Dashboard, keys, txn detail — no-store cache.
 */
function sendUniwebSecurityHeaders(bool $sensitivePage = false): void
{
    if (!uniwebShouldSendSecurityHeaders()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self), payment=(self)');
    header('Content-Security-Policy: ' . uniwebContentSecurityPolicy());

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if ($sensitivePage) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
    }
}

/** Detect logged-in portal pages for Cache-Control: no-store. */
function uniwebIsSensitivePortalPage(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    if (!empty($_SESSION['merchant_id']) || !empty($_SESSION['admin_id'])) {
        return true;
    }
    if (function_exists('isLoggedIn') && function_exists('isAdminLoggedIn')) {
        return isLoggedIn() || isAdminLoggedIn();
    }
    return false;
}
