<?php
declare(strict_types=1);

/**
 * Branded global error page served through Apache ErrorDocument (see .htaccess).
 * Covers 403/404/500 (and other 4xx/5xx) that never reach normal app routes.
 * Uses a minimal UniWeb shell — no portal sidebar, no stack traces.
 */

$statusMap = [
    400 => ['Bad request', 'That request could not be understood. Please check the link and try again.'],
    401 => ['Sign in required', 'You need to sign in to view this page.'],
    403 => ['Access denied', 'You do not have permission to view this page. If you reached this from a link, it may be restricted.'],
    404 => ['Page not found', 'The page you are looking for has moved or no longer exists. The link may be mistyped or out of date.'],
    410 => ['No longer available', 'This page is no longer available.'],
    429 => ['Too many requests', 'You have made too many requests in a short time. Please wait a moment and try again.'],
    500 => ['Something went wrong', 'We hit an unexpected error on our side. Please try again in a moment.'],
    503 => ['Temporarily unavailable', 'We are performing maintenance. Please try again shortly.'],
];

$code = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
if ($code < 400 || $code > 599) {
    $code = (int)($_GET['code'] ?? 0);
}
if ($code < 400 || $code > 599) {
    $code = 500;
}

$reason = strtolower(trim((string)($_GET['reason'] ?? '')));
if ($reason === 'token' || $reason === 'expired_token') {
    $code = 401;
    $statusMap[401] = [
        'Link expired',
        'This sign-in link has expired or was already used. Request a fresh link or sign in again.',
    ];
}

[$heading, $detail] = $statusMap[$code] ?? ['Error ' . $code, 'An unexpected error occurred. Please try again.'];

$extraActions = [];
if ($code === 401 || $code === 403) {
    $extraActions[] = ['login.php', 'Merchant login'];
    $extraActions[] = ['support.php', 'Support'];
} elseif ($code === 404) {
    $extraActions[] = ['support.php', 'Support'];
}

$rendered = false;
if (PHP_SAPI !== 'cli') {
    try {
        require_once __DIR__ . '/config.php';
        require_once __DIR__ . '/includes/error_page.php';
        if (function_exists('sendUniwebSecurityHeaders')) {
            sendUniwebSecurityHeaders(false);
        }
        renderUniwebErrorShell($code, $heading, $detail, $extraActions);
        $rendered = true;
    } catch (Throwable $e) {
        $rendered = false;
    }
}

if (!$rendered) {
    require_once __DIR__ . '/includes/error_page.php';
    renderUniwebErrorShell($code, $heading, $detail, $extraActions);
}
exit;
