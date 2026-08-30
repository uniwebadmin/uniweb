<?php
declare(strict_types=1);

/**
 * Branded error shell — no stack traces, no partner CTAs, context-aware primary action.
 *
 * @param list<array{0:string,1:string}> $extraActions [href, label] after primary CTA
 */
function renderUniwebErrorShell(int $code, string $heading, string $detail, array $extraActions = []): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    $primary = ['index.php', 'Home'];
    if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
        $isSuper = function_exists('isSuperAdmin') && isSuperAdmin();
        $primary = $isSuper ? ['admin_dashboard.php', 'Admin dashboard'] : ['staff_dashboard.php', 'Dashboard'];
    } elseif (function_exists('isLoggedIn') && isLoggedIn()) {
        $primary = ['dashboard.php', 'Dashboard'];
    } elseif (function_exists('isCustomerLoggedIn') && isCustomerLoggedIn()) {
        $primary = ['customer_portal.php', 'My account'];
    }

    $safeCode = (int)$code;
    $safeHeading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $safeDetail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    $appName = defined('APP_NAME') ? (string)APP_NAME : 'UniWeb';
    $appUrl = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow">'
        . '<title>' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . ' — ' . $safeHeading . '</title>'
        . '<link rel="icon" href="' . htmlspecialchars($appUrl . '/favicon.svg', ENT_QUOTES, 'UTF-8') . '" type="image/svg+xml">'
        . '<style>'
        . 'body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0f172a;color:#e2e8f0;'
        . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
        . '.box{max-width:440px;width:100%;background:#1e293b;border:1px solid #334155;border-radius:16px;padding:32px;text-align:center}'
        . '.brand{font-size:.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#34d399;margin:0 0 16px}'
        . '.code{font-size:2.5rem;font-weight:800;color:#34d399;margin:0 0 8px;line-height:1}'
        . 'h1{font-size:1.25rem;margin:0 0 12px;font-weight:600}'
        . 'p{font-size:.9rem;line-height:1.55;color:#94a3b8;margin:0 0 20px}'
        . '.actions{display:flex;flex-wrap:wrap;gap:8px;justify-content:center}'
        . 'a.btn{display:inline-block;background:#059669;color:#fff;text-decoration:none;padding:10px 20px;border-radius:9px;font-size:.9rem;font-weight:600}'
        . 'a.link{display:inline-block;color:#94a3b8;text-decoration:none;padding:10px 14px;font-size:.875rem}'
        . 'a.link:hover{color:#e2e8f0}'
        . '</style></head><body><div class="box">'
        . '<p class="brand">' . htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p class="code">' . $safeCode . '</p>'
        . '<h1>' . $safeHeading . '</h1><p>' . $safeDetail . '</p>'
        . '<div class="actions">'
        . '<a class="btn" href="' . htmlspecialchars($primary[0], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($primary[1], ENT_QUOTES, 'UTF-8') . '</a>';

    foreach ($extraActions as [$href, $label]) {
        echo '<a class="link" href="' . htmlspecialchars((string)$href, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars((string)$label, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    echo '</div></div></body></html>';
}
