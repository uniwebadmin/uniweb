<?php
declare(strict_types=1);

/**
 * Shared UX atoms for overnight bucket-3 polish (search/export/print/a11y/empty states).
 * Guarded so double-include via config $__includes + cloud_modules never fatals.
 */

if (!function_exists('uxEmptyState')) {
    function uxEmptyState(string $title, string $hint = '', ?string $actionHtml = null): string
    {
        $html = '<div class="px-6 py-14 text-center" role="status">'
            . '<p class="font-semibold text-white mb-1">' . e($title) . '</p>';
        if ($hint !== '') {
            $html .= '<p class="text-sm text-gray-500 max-w-md mx-auto">' . e($hint) . '</p>';
        }
        if ($actionHtml !== null && $actionHtml !== '') {
            $html .= '<div class="mt-4">' . $actionHtml . '</div>';
        }
        return $html . '</div>';
    }

    function uxEmptyCta(string $url, string $label): string
    {
        return '<a href="' . e($url) . '" class="inline-block mt-4 btn-primary text-sm px-5 py-2.5">' . e($label) . '</a>';
    }

    /** Never show PHP/SQL internals to merchants or customers. Log the raw error separately. */
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

    function uxPrintToolbar(string $extraClass = ''): string
    {
        $cls = trim('no-print flex flex-wrap gap-2 mb-4 justify-end ' . $extraClass);
        return '<div class="' . e($cls) . '">'
            . '<button type="button" onclick="window.print()" class="glass px-3 py-2 rounded-lg text-xs text-gray-300 hover:text-white">Print</button>'
            . '</div>';
    }

    function uxExportCsvLink(array $queryParams = [], string $label = 'Export CSV'): string
    {
        $params = array_merge($queryParams, ['export' => 'csv']);
        return '<a href="?' . e(http_build_query($params)) . '" class="glass px-3 py-2 rounded-lg text-xs text-brand-400 hover:text-brand-300 no-print">' . e($label) . '</a>';
    }

    function uxListToolbar(?string $exportHtml = null, bool $withPrint = true): string
    {
        $parts = [];
        if ($exportHtml !== null && $exportHtml !== '') {
            $parts[] = $exportHtml;
        }
        if ($withPrint) {
            $parts[] = '<button type="button" onclick="window.print()" class="glass px-3 py-2 rounded-lg text-xs text-gray-300 hover:text-white">Print</button>';
        }
        if ($parts === []) {
            return '';
        }
        return '<div class="no-print flex flex-wrap gap-2 mb-4 justify-end">' . implode('', $parts) . '</div>';
    }

    function uxTableCaption(string $text): string
    {
        return '<caption class="sr-only">' . e($text) . '</caption>';
    }

    function uxLabel(string $for, string $text, bool $required = false): string
    {
        $req = $required ? ' <span class="text-red-400" aria-hidden="true">*</span>' : '';
        return '<label for="' . e($for) . '" class="text-sm text-gray-400">' . e($text) . $req . '</label>';
    }

    function uxPageNav(int $page, int $totalPages, array $queryParams = []): string
    {
        if ($totalPages <= 1) {
            return '';
        }
        $page = max(1, min($page, $totalPages));
        $html = '<nav class="no-print flex flex-wrap items-center justify-between gap-3 px-5 py-4 border-t border-gray-800 text-xs text-gray-500" aria-label="Pagination">';
        $html .= '<span>Page ' . (int)$page . ' of ' . (int)$totalPages . '</span><div class="flex gap-2">';
        if ($page > 1) {
            $prev = array_merge($queryParams, ['page' => $page - 1]);
            $html .= '<a href="?' . e(http_build_query($prev)) . '" class="glass px-3 py-1.5 rounded-lg hover:text-white">Previous</a>';
        }
        if ($page < $totalPages) {
            $next = array_merge($queryParams, ['page' => $page + 1]);
            $html .= '<a href="?' . e(http_build_query($next)) . '" class="glass px-3 py-1.5 rounded-lg hover:text-white">Next</a>';
        }
        return $html . '</div></nav>';
    }

    function uxPaginateSlice(array $rows, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = count($rows);
        $totalPages = (int)max(1, ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;
        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    function sendCsvDownload(array $headers, iterable $rows, string $filename): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Cache-Control: no-store');
        }
        $out = fopen('php://output', 'w');
        if ($out === false) {
            exit;
        }
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, is_array($row) ? $row : (array)$row);
        }
        fclose($out);
        exit;
    }

    function uxCsvRow(array $assoc, array $keys): array
    {
        $row = [];
        foreach ($keys as $key) {
            $row[] = $assoc[$key] ?? '';
        }
        return $row;
    }

    function uxFieldId(string $name): string
    {
        return 'ux-' . preg_replace('/[^a-z0-9_-]+/i', '-', $name);
    }

    function uxFormLabel(string $for, string $text, bool $required = false): string
    {
        return uxLabel($for, $text, $required);
    }

    function renderPrintButton(string $label = 'Print'): string
    {
        return '<button type="button" class="no-print text-sm glass px-4 py-1.5 rounded-lg text-gray-300 hover:text-white" onclick="window.print()" aria-label="' . e($label) . ' page">' . e($label) . '</button>';
    }

    function renderExportCsvLink(string $url, string $label = 'Export CSV'): string
    {
        return '<a href="' . e($url) . '" class="no-print text-sm bg-brand-600/20 text-brand-400 px-4 py-1.5 rounded-lg hover:bg-brand-600/30 transition">' . e($label) . '</a>';
    }

    function renderPagination(int $page, int $perPage, int $total, array $queryParams = []): string
    {
        if ($total <= $perPage) {
            return '';
        }
        $pages = (int)max(1, ceil($total / $perPage));
        return uxPageNav($page, $pages, $queryParams);
    }

    /** Pagination params used by merchant/staff list pages (Agent D overnight). */
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

    /** Alias used by refunds/invoices/reports overnight polish. */
    function renderPrintStylesheet(): string
    {
        return renderPagePrintStyles();
    }
}
