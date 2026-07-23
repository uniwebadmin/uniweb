<?php
declare(strict_types=1);

/**
 * Branded global error page served through Apache ErrorDocument (see .htaccess).
 * Covers 403/404/500 (and other 4xx/5xx) that never reach normal app routes.
 * Degrades to a self-contained fallback if config/header bootstrap fails.
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
// Default to 500 (not 404) when the real status is unknown: a genuine
// crash silently mislabeled "page not found" hides real bugs behind a
// misleading message, whereas "something went wrong" is honest either way.
if ($code < 400 || $code > 599) {
    $code = 500;
}
[$heading, $detail] = $statusMap[$code] ?? ['Error ' . $code, 'An unexpected error occurred. Please try again.'];

if (!headers_sent()) {
    http_response_code($code);
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

$rendered = false;
if (PHP_SAPI !== 'cli') {
    ob_start();
    try {
        require_once __DIR__ . '/config.php';
        $pageTitle = $heading;
        require __DIR__ . '/header.php';
        echo '<section class="pt-28 pb-20 px-4"><div class="max-w-lg mx-auto glass rounded-2xl p-8 text-center">'
            . '<p class="text-5xl font-extrabold text-brand-400 mb-2">' . (int)$code . '</p>'
            . '<h1 class="text-xl font-semibold mb-2">' . e($heading) . '</h1>'
            . '<p class="text-sm text-gray-400 mb-6">' . e($detail) . '</p>'
            . '<a href="index.php" class="inline-block btn-primary px-5 py-2.5 text-sm">Home</a>'
            . ' <a href="demo.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Demo</a>'
            . ' <a href="support.php" class="inline-block ml-2 text-sm text-gray-400 hover:text-white">Support</a>'
            . '</div></section>';
        require __DIR__ . '/footer.php';
        $rendered = true;
        ob_end_flush();
    } catch (Throwable $e) {
        ob_end_clean();
        $rendered = false;
    }
}

if (!$rendered) {
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>UniWeb — ' . $safeHeading . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
        . '.box{max-width:440px;background:#1e293b;border:1px solid #334155;border-radius:16px;padding:32px;text-align:center}'
        . '.code{font-size:2.5rem;font-weight:800;color:#34d399;margin:0 0 8px}'
        . 'h1{font-size:1.25rem;margin:0 0 12px}p{font-size:.9rem;line-height:1.5;color:#94a3b8;margin:0 0 20px}'
        . 'a{display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:10px 20px;border-radius:9px;font-size:.9rem;margin:4px}'
        . 'a.alt{background:transparent;color:#94a3b8}</style></head><body><div class="box">'
        . '<p class="code">' . (int)$code . '</p>'
        . '<h1>' . $safeHeading . '</h1><p>' . $safeDetail . '</p>'
        . '<a href="/index.php">Home</a> <a class="alt" href="/demo.php">Demo</a> <a class="alt" href="/support.php">Support</a>'
        . '</div></body></html>';
}
exit;
