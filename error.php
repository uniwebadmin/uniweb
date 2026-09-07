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
    $errorPage = __DIR__ . '/includes/error_page.php';
    if (is_file($errorPage)) {
        require_once $errorPage;
        renderUniwebErrorShell($code, $heading, $detail, $extraActions);
    } else {
        if (!headers_sent()) {
            http_response_code($code);
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>UniWeb</title></head><body style="font-family:system-ui;background:#0f172a;color:#e2e8f0;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
            . '<div style="max-width:420px;padding:24px;text-align:center"><h1 style="font-size:1.25rem">' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>'
            . '<p style="color:#94a3b8">' . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<a href="index.php" style="color:#38bdf8">Home</a></div></body></html>';
    }
}
exit;
