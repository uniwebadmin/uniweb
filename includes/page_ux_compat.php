<?php
declare(strict_types=1);

/**
 * Compat helpers — always safe to load after page_ux.php.
 * If an older cached page_ux omitted these, this file still defines them.
 */

if (!function_exists('listPageParams')) {
    function listPageParams(int $defaultPerPage = 20): array
    {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(5, min(100, (int)($_GET['per_page'] ?? $defaultPerPage)));
        return [
            'page' => $page,
            'perPage' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'q' => mb_substr(trim($_GET['q'] ?? ''), 0, 100),
        ];
    }
}

if (!function_exists('renderListPagination')) {
    function renderListPagination(int $page, int $total, int $perPage, array $query = []): string
    {
        if ($total <= $perPage) {
            return '';
        }
        $pages = (int)ceil($total / $perPage);
        $page = max(1, min($page, $pages));
        $query = array_filter($query, static fn($v) => $v !== null && $v !== '');
        unset($query['page']);
        $base = '?' . http_build_query($query);
        $sep = $base === '?' ? '' : '&';

        $html = '<nav class="flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-t border-gray-800 text-sm" aria-label="Pagination">';
        $html .= '<span class="text-gray-500">' . number_format($total) . ' total · page ' . $page . ' of ' . $pages . '</span>';
        $html .= '<div class="flex gap-2">';
        if ($page > 1) {
            $html .= '<a href="' . e($base . $sep . 'page=' . ($page - 1)) . '" class="px-3 py-1.5 rounded-lg bg-dark-900 text-gray-300 hover:text-white">← Prev</a>';
        }
        if ($page < $pages) {
            $html .= '<a href="' . e($base . $sep . 'page=' . ($page + 1)) . '" class="px-3 py-1.5 rounded-lg bg-brand-600/20 text-brand-400 hover:bg-brand-600/30">Next →</a>';
        }
        $html .= '</div></nav>';
        return $html;
    }
}

if (!function_exists('renderPagePrintStyles')) {
    function renderPagePrintStyles(): string
    {
        return <<<'CSS'
<style media="print">
@media print {
    nav, footer, .no-print, .theme-toggle-btn, #public-menu-btn, #sidebar-toggle, #admin-sidebar-toggle, #profile-menu-btn, [data-spotlight-open], button[type="submit"]:not(.print-keep) { display: none !important; }
    body { background: #fff !important; color: #111 !important; }
    .glass, .stat-card { border: 1px solid #ccc !important; box-shadow: none !important; background: #fff !important; }
    a[href]::after { content: " (" attr(href) ")"; font-size: 0.75rem; color: #555; }
    a[href^="#"]::after, a[href^="javascript"]::after { content: ""; }
}
</style>
CSS;
    }
}

if (!function_exists('renderPrintStylesheet')) {
    function renderPrintStylesheet(): string
    {
        return renderPagePrintStyles();
    }
}

if (!function_exists('userFacingError')) {
    function userFacingError(Throwable|string $error, string $fallback, string $nextStep = ''): string
    {
        $raw = is_string($error) ? $error : $error->getMessage();
        $internal = $raw === '' || (bool)preg_match(
            '/SQLSTATE|Call to undefined|TypeError|Argument #|Stack trace|PDOException|Fatal error|\/home\/|\/var\/www/i',
            $raw
        );
        $msg = $internal ? $fallback : $raw;
        if ($nextStep !== '') {
            $msg = rtrim($msg, '.') . '. ' . $nextStep;
        }
        return $msg;
    }
}

