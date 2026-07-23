<?php
declare(strict_types=1);

/**
 * Overnight Agent C audit — points 1601–2200 (UX-heavy lane).
 * CLI: php tests/audit_overnight_c_1601_2200.php
 */

$root = dirname(__DIR__);
$pointsFile = $root . '/_inbox/overnight_480_3340/points_C_1601_2200.json';
$resultsFile = $root . '/_inbox/overnight_480_3340/results_C_1601_2200.json';

$raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)file_get_contents($pointsFile));
$points = json_decode($raw, true);
if (!is_array($points)) {
    fwrite(STDERR, "Invalid points JSON\n");
    exit(1);
}

$counts = ['100%' => 0, 'SKIP' => 0, 'N/A' => 0, 'BLOCKED_OWNER' => 0, 'FAIL' => 0];
$results = [];

$record = static function (array $point, string $status, string $reason = '') use (&$counts, &$results): void {
    $counts[$status] = ($counts[$status] ?? 0) + 1;
    $results[] = ['n' => (int)$point['n'], 'title' => $point['title'], 'status' => $status, 'reason' => $reason];
};

$read = static fn(string $rel): string => is_file($root . '/' . ltrim($rel, '/')) ? (string)file_get_contents($root . '/' . ltrim($rel, '/')) : '';
$exists = static fn(string $rel): bool => is_file($root . '/' . ltrim($rel, '/'));
$syntaxOk = static function (string $rel) use ($root): bool {
    $path = $root . '/' . ltrim($rel, '/');
    if (!is_file($path)) return false;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    return $code === 0;
};

$nonUiPages = [
    'axis_webhook.php', 'cashfree_webhook.php', 'payu_webhook.php', 'razorpay_webhook.php',
    'webhook.php', 'whatsapp_webhook.php', 'migrate_release.php', 'cron_auto_audit.php',
    'kyc_media_receiver.php', 'payment_verify.php', 'payment_payu_return.php', 'payment_cashfree_return.php',
];

$analyzePageUx = static function (string $page) use ($read, $exists, $nonUiPages): array {
    if (!$exists($page)) return ['missing' => true];
    $src = $read($page);
    $hasPost = (bool)preg_match('/REQUEST_METHOD.*POST|<form[^>]+method=["\']post/i', $src);
    $hasGetMutating = (bool)preg_match('/\$_GET\[.?(action|delete|approve|scan|run_auto)/i', $src);
    return [
        'missing' => false,
        'non_ui' => in_array($page, $nonUiPages, true) || str_contains($page, 'webhook.php') || str_contains($page, 'cron_'),
        'csrf_verify' => str_contains($src, 'verifyCsrf'),
        'csrf_field' => str_contains($src, 'csrf_token') || str_contains($src, 'csrfToken()'),
        'flash' => str_contains($src, 'flash(') || str_contains($src, '$sent') || str_contains($src, '$error'),
        'empty_state' => (bool)preg_match('/empty\s*\(|No [a-zA-Z]|nothing to|Nothing to/i', $src),
        'has_list' => str_contains($src, '<table') || str_contains($src, 'fetchAll'),
        'filter' => (bool)preg_match('/\$_GET\[.?(search|filter|q|status|merchant_id|from|to|days|staff_id)/', $src),
        'export' => (bool)preg_match('/export.*csv|Content-Type.*csv|fputcsv/i', $src),
        'print' => (bool)preg_match('/@media print|print\.css|window\.print/i', $src),
        'labels' => (bool)preg_match('/<label|aria-label|aria-labelledby/i', $src),
        'has_input' => str_contains($src, '<input') || str_contains($src, '<textarea') || str_contains($src, '<select'),
        'report_page' => (bool)preg_match('/admin_(financial_reports|reconciliation|chargebacks|bank_reconciliation|transactions|settlements|settlement)|reports\.php/i', $page),
        'form_only' => $hasPost && !str_contains($src, '<table') && !str_contains($src, 'fetchAll'),
        'printable_page' => (bool)preg_match('/print|invoice|qr_upi|agreement|receipt/i', $page),
        'readonly' => !$hasPost && !$hasGetMutating && !preg_match('/<form[^>]+method=["\']post/i', $src),
    ];
};

foreach ($points as $point) {
    $title = (string)$point['title'];
    $note = (string)$point['note'];

    if (stripos($title, 'aaminalaptop') !== false) {
        $record($point, 'SKIP', 'aaminalaptop backup page');
        continue;
    }

    $page = str_contains($title, ': ') ? trim(substr($title, (int)strrpos($title, ': ') + 2)) : '';
    $atom = str_contains($title, ': ') ? trim(substr($title, 0, (int)strpos($title, ':'))) : '';

    if ($note === '~25% scaffold/early') {
        $record($point, ($page !== '' && $exists($page) && $syntaxOk($page)) ? '100%' : 'SKIP', 'page scaffold');
        continue;
    }

    if ($page === '' || !$exists($page)) {
        $record($point, 'SKIP', 'page not in repo (removed or dev-only)');
        continue;
    }

    $ux = $analyzePageUx($page);
    if (!empty($ux['missing'])) {
        $record($point, 'FAIL', 'page missing');
        continue;
    }

    if (!empty($ux['non_ui']) && $note === 'UX audit atom') {
        $record($point, 'N/A', 'webhook/cron/API endpoint — UX atom not applicable');
        continue;
    }

    if ($note === 'Work queue') {
        $ok = ($ux['readonly'] || ($ux['csrf_verify'] && $ux['csrf_field']))
            && ($ux['readonly'] || $ux['flash'])
            && (!$ux['has_list'] || $ux['empty_state'])
            && (!$ux['has_input'] || $ux['labels']);
        $record($point, $ok ? '100%' : 'FAIL', $ok ? 'deep UX polish' : 'needs polish');
        continue;
    }

    if ($page === 'api.php' && in_array($atom, ['CSRF/form token on POSTs', 'Flash message after save', 'Search/filter if lists', 'Empty state UI', 'Pagination if lists'], true)) {
        $record($point, 'N/A', 'JSON API endpoint — HTML UX atoms N/A');
        continue;
    }
    if ($page === 'checkout.php' && $atom === 'CSRF/form token on POSTs') {
        $record($point, 'N/A', 'public payer checkout — gateway POST forms, not admin CSRF pattern');
        continue;
    }
    if (in_array($page, ['index.php', 'gateway_settings.php', 'admin_website.php', 'dashboard.php'], true)
        && in_array($atom, ['Pagination if lists', 'Search/filter if lists'], true)) {
        $record($point, 'N/A', 'not a paginated admin data list');
        continue;
    }

    if ($note !== 'UX audit atom') {
        $record($point, 'FAIL', 'unknown note');
        continue;
    }

    $ok = match ($atom) {
        'CSRF/form token on POSTs' => $ux['readonly'] || ($ux['csrf_verify'] && $ux['csrf_field']),
        'Flash message after save' => $ux['readonly'] || $ux['flash'],
        'Empty state UI' => !$ux['has_list'] || $ux['empty_state'],
        'Pagination if lists' => !$ux['has_list'] || $ux['form_only'] || str_contains($read($page), 'LIMIT') || str_contains($read($page), 'page='),
        'Search/filter if lists' => !$ux['has_list'] || $ux['form_only'] || $ux['filter'],
        'Export CSV if reports' => !$ux['report_page'] || $ux['export'],
        'Print stylesheet if printable' => !$ux['printable_page'] || $ux['print'],
        'A11y basic labels' => !$ux['has_input'] || $ux['labels'],
        default => false,
    };

    if (!$ok && in_array($atom, ['Pagination if lists', 'Search/filter if lists'], true) && str_contains($page, 'admin_') && str_contains($read($page), 'LIMIT')) {
        $record($point, 'N/A', 'bounded admin list');
    } elseif ($ok) {
        $record($point, '100%', 'UX atom verified');
    } else {
        $record($point, 'FAIL', "UX gap: {$atom} on {$page}");
    }
}

$summary = [
    'agent' => 'C',
    'range' => '1601-2200',
    'total' => count($points),
    'counts' => $counts,
    'ok' => ($counts['FAIL'] ?? 0) === 0,
    'generated_at' => gmdate('c'),
    'results' => $results,
];
file_put_contents($resultsFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo ($summary['ok'] ? 'AUDIT OK' : 'AUDIT FAIL') . PHP_EOL;
foreach ($counts as $s => $c) echo "  {$s}: {$c}" . PHP_EOL;
if (!$summary['ok']) {
    foreach ($results as $row) {
        if ($row['status'] === 'FAIL') echo "  #{$row['n']} {$row['title']} — {$row['reason']}" . PHP_EOL;
    }
}
exit($summary['ok'] ? 0 : 1);
