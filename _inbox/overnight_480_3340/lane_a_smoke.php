<?php
declare(strict_types=1);
/**
 * Lane A smoke runner — points 541–1000 file list.
 * Usage: php _inbox/overnight_480_3340/lane_a_smoke.php [--base=http://127.0.0.1:8000]
 */
$root = dirname(__DIR__, 2);
$base = 'http://127.0.0.1:8000';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with((string)$arg, '--base=')) {
        $base = rtrim(substr((string)$arg, 7), '/');
    }
}

$json = $root . '/_inbox/overnight_480_3340/points_A_541_1000.json';
$raw = file_get_contents($json);
if ($raw === false) {
    fwrite(STDERR, "Cannot read $json\n");
    exit(1);
}
if (str_starts_with($raw, "\xEF\xBB\xBF")) {
    $raw = substr($raw, 3);
}
$points = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$files = [];
foreach ($points as $p) {
    $title = (string)$p['title'];
    $file = str_contains($title, ': ') ? substr($title, strpos($title, ': ') + 2) : $title;
    $files[$file] = true;
}

$publicSmoke = [
    'index.php', 'signup.php', 'demo.php', 'contact.php', 'pricing.php', 'faq.php',
    'terms.php', 'privacy.php', 'refund_policy.php', 'login.php', 'merchant_register.php',
    'staff_login.php', 'admin_login.php', 'payment_status.php', 'checkout.php',
    'api_docs.php', 'blog.php', 'trust.php', 'status.php', 'error_404.php',
];
$skipPatterns = ['aaminalaptop', 'db_probe', 'db_wizard', 'my_secret_setup_xyz'];
$webhookCron = ['webhook', 'cron_', 'migrate_release', 'ping.php', 'diag.php'];

$results = ['pass' => 0, 'skip' => 0, 'na' => 0, 'fail' => 0, 'details' => []];

foreach (array_keys($files) as $rel) {
    $skip = false;
    foreach ($skipPatterns as $pat) {
        if (str_contains($rel, $pat)) {
            $results['skip']++;
            $results['details'][] = ['file' => $rel, 'status' => 'SKIP', 'note' => 'hard skip'];
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        $results['na']++;
        $results['details'][] = ['file' => $rel, 'status' => 'N/A', 'note' => 'file not in repo'];
        continue;
    }

    if (!str_ends_with($rel, '.php')) {
        $results['na']++;
        $results['details'][] = ['file' => $rel, 'status' => 'N/A', 'note' => 'non-PHP'];
        continue;
    }

    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $lintOut, $lintCode);
    if ($lintCode !== 0) {
        $results['fail']++;
        $results['details'][] = ['file' => $rel, 'status' => 'FAIL', 'note' => implode(' ', $lintOut)];
        continue;
    }

    $isWebhook = false;
    foreach ($webhookCron as $w) {
        if (str_contains($rel, $w)) {
            $isWebhook = true;
            break;
        }
    }
    if (str_starts_with($rel, 'includes/') || $isWebhook || $rel === 'config.dev.php') {
        $results['na']++;
        $results['details'][] = ['file' => $rel, 'status' => 'N/A_PASS', 'note' => 'lib/webhook/cron — syntax OK'];
        continue;
    }

    if (in_array(basename($rel), $publicSmoke, true) || in_array($rel, $publicSmoke, true)) {
        $url = $base . '/' . ltrim($rel, '/');
        if ($rel === 'checkout.php') {
            $url .= '?link=demo';
        }
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        if ($body !== false && $code >= 200 && $code < 500) {
            $results['pass']++;
            $results['details'][] = ['file' => $rel, 'status' => 'PASS', 'note' => "HTTP $code"];
        } else {
            $results['fail']++;
            $results['details'][] = ['file' => $rel, 'status' => 'FAIL', 'note' => "HTTP $code"];
        }
        continue;
    }

    // Auth-gated / internal pages: syntax + CSRF audit for POST handlers
    $src = file_get_contents($path) ?: '';
    $hasPost = str_contains($src, "REQUEST_METHOD") && str_contains($src, 'POST');
    $hasCsrf = str_contains($src, 'verifyCsrf') || str_contains($src, 'csrf_token');
    $isApi = $rel === 'api.php' || str_contains($rel, 'payment_verify.php');
    if ($hasPost && !$hasCsrf && !$isApi && !str_contains($rel, 'webhook')) {
        $results['fail']++;
        $results['details'][] = ['file' => $rel, 'status' => 'FAIL', 'note' => 'POST without CSRF'];
        continue;
    }

    $results['pass']++;
    $results['details'][] = ['file' => $rel, 'status' => 'PASS', 'note' => 'syntax + static audit OK'];
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
exit($results['fail'] > 0 ? 1 : 0);
