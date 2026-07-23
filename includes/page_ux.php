<?php
declare(strict_types=1);

/** Shared page UX helpers — CSRF companions, a11y labels, print, pagination, CSV export links. */

function uxFieldId(string $name): string
{
    return 'ux-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
}

function uxFormLabel(string $for, string $text, bool $required = false): string
{
    $req = $required ? ' <span class="text-red-400" aria-hidden="true">*</span>' : '';
    return '<label for="' . e($for) . '" class="text-sm text-gray-400">' . e($text) . $req . '</label>';
}

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

function renderPrintButton(string $label = 'Print'): string
{
    return '<button type="button" class="no-print text-sm bg-gray-800/80 text-gray-300 px-4 py-1.5 rounded-lg hover:bg-gray-700/80 transition print-keep" onclick="window.print()" aria-label="' . e($label) . ' page">' . e($label) . '</button>';
}

function renderExportCsvLink(string $url, string $label = 'Export CSV'): string
{
    return '<a href="' . e($url) . '" class="no-print text-sm bg-brand-600/20 text-brand-400 px-4 py-1.5 rounded-lg hover:bg-brand-600/30 transition" download>' . e($label) . '</a>';
}

function renderPagination(int $page, int $perPage, int $total, array $queryParams = []): string
{
    if ($total <= $perPage) {
        return '';
    }
    $pages = (int)ceil($total / $perPage);
    $page = max(1, min($page, $pages));
    $queryParams['page'] = null;
    $base = '?' . http_build_query(array_filter($queryParams, static fn($v) => $v !== null && $v !== ''));
    $sep = str_contains($base, '?') && strlen($base) > 1 ? '&' : '';
    $html = '<nav class="no-print flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-t border-gray-800 text-sm" aria-label="Pagination">';
    $html .= '<span class="text-gray-500">' . $total . ' total · page ' . $page . ' of ' . $pages . '</span>';
    $html .= '<div class="flex gap-2">';
    if ($page > 1) {
        $html .= '<a href="' . e($base . $sep . 'page=' . ($page - 1)) . '" class="px-3 py-1.5 rounded-lg bg-dark-900 text-gray-400 hover:text-white">← Prev</a>';
    }
    if ($page < $pages) {
        $html .= '<a href="' . e($base . $sep . 'page=' . ($page + 1)) . '" class="px-3 py-1.5 rounded-lg bg-dark-900 text-gray-400 hover:text-white">Next →</a>';
    }
    $html .= '</div></nav>';
    return $html;
}
