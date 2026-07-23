<?php
declare(strict_types=1);

/** List/search/pagination/export helpers for merchant portal pages. */

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

function renderPrintStylesheet(): string
{
    return '<style>@media print{header,footer,nav,.sidebar,#sidebar-toggle,#profile-menu-btn,.theme-toggle-btn,.btn-primary:not(.print-keep){display:none!important}body{background:#fff!important;color:#000!important}.glass{background:#fff!important;border:1px solid #ddd!important}table{font-size:11px}canvas{max-height:200px!important}}</style>';
}

function renderExportCsvLink(string $href, string $label = 'Export CSV'): string
{
    return '<a href="' . e($href) . '" class="text-sm bg-brand-600/20 text-brand-400 px-4 py-1.5 rounded-lg hover:bg-brand-600/30 transition">' . e($label) . '</a>';
}
