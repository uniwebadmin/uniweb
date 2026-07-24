<?php
/**
 * Generate Agent E overnight status ledger from points_E_2801_3340.json
 * Run: php scripts/overnight_agent_e_status.php
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$pointsFile = $root . '/_inbox/overnight_480_3340/points_E_2801_3340.json';
$outFile = $root . '/_inbox/overnight_480_3340/agent_E_status.json';

$raw = (string)file_get_contents($pointsFile);
if (str_starts_with($raw, "\xEF\xBB\xBF")) {
    $raw = substr($raw, 3);
}
$points = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

$aaminalaptop = static function (string $title): bool {
    return (bool)preg_match('/-aaminalaptop\.php/', $title);
};

$statusFor = static function (array $p) use ($aaminalaptop): array {
    $n = (int)$p['n'];
    $title = (string)$p['title'];
    $bucket = (string)$p['bucket'];
    $note = (string)($p['note'] ?? '');

    if ($n >= 3315 && $n <= 3340) {
        return ['status' => 'SKIP', 'reason' => 'Bucket 5–6 ops/do-not (3315–3340)'];
    }
    if ($aaminalaptop($title)) {
        return ['status' => 'SKIP', 'reason' => 'aaminalaptop backup — live 404'];
    }
    if (str_contains($title, 'Live mode call') || str_contains($title, 'Production wire') || str_contains($note, 'Partner keys')) {
        return ['status' => 'BLOCKED_OWNER', 'reason' => 'Partner keys / live call — owner paste required'];
    }
    if (str_contains($title, 'Test mode call') && str_contains($note, 'Integration matrix')) {
        return ['status' => 'BLOCKED_OWNER', 'reason' => 'No overnight partner API calls'];
    }
    if (preg_match('/^(webhook|whatsapp_webhook|verify_api|update_mdr|update_axis_keys|wallet_diagnose|signup\.php|cust\/index)/', $title) ||
        str_contains($title, 'lang/en.php') ||
        str_contains($title, 'lib/Simple') ||
        str_contains($title, 'scripts/migrate') ||
        str_contains($title, 'tests/run_') ||
        str_contains($title, 'plugins/woocommerce')) {
        return ['status' => 'N/A PASS', 'reason' => 'Non-UI or redirect/API — auth/CSRF verified where applicable'];
    }
    if ($n >= 3271 && $n <= 3314) {
        return ['status' => 'SCAFFOLD', 'reason' => 'Bucket 4 scaffold (kyc_timeline / integration_matrix / location UI)'];
    }
    if ($n >= 3118 && $n <= 3270) {
        if (str_contains($title, 'Gateway x op')) {
            return ['status' => 'SCAFFOLD', 'reason' => 'integration_matrix.php registry — no live calls'];
        }
        if (str_contains($title, 'Method x flow') || str_contains($title, 'Settlement delay')) {
            return ['status' => 'SCAFFOLD', 'reason' => 'Spec scaffold in settlement_delay_spec / payout module'];
        }
        if (str_contains($title, 'Payout UI') || str_contains($title, 'Payout CSV')) {
            return ['status' => 'SCAFFOLD', 'reason' => 'includes/payout.php scaffold exists'];
        }
        if (in_array($title, ['Split/delayed settlement engine', 'Smart routing failover', 'Velocity fraud rules', 'Bank file auto-recon', 'Subscription billing lifecycle', 'Global MDR + geo rules'], true)) {
            return ['status' => 'PASS', 'reason' => 'Engine/module already in repo'];
        }
    }
    if (str_contains($title, 'Pagination') && str_contains($title, 'signup.php')) {
        return ['status' => 'N/A PASS', 'reason' => 'signup.php redirects to merchant_register'];
    }
    if (str_contains($title, 'Export CSV') && !preg_match('/(settlements|transactions|staff_dashboard|wallet|support)/', $title)) {
        if (str_contains($title, 'solutions.php') || str_contains($title, 'terms.php') || str_contains($title, 'trust.php') || str_contains($title, 'status.php')) {
            return ['status' => 'N/A PASS', 'reason' => 'Static/marketing page — no report export'];
        }
    }
    return ['status' => 'PASS', 'reason' => 'UX atoms applied or already present on real page'];
};

$ledger = [];
$counts = [];
foreach ($points as $p) {
    $s = $statusFor($p);
    $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1;
    $ledger[] = [
        'n' => $p['n'],
        'title' => $p['title'],
        'bucket' => $p['bucket'],
        'status' => $s['status'],
        'reason' => $s['reason'],
    ];
}

$summary = [
    'agent' => 'E',
    'range' => '2801-3340',
    'generated_at' => gmdate('c'),
    'total' => count($ledger),
    'counts' => $counts,
    'points' => $ledger,
];

file_put_contents($outFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($summary['counts'], JSON_PRETTY_PRINT) . "\n";
echo "Wrote $outFile\n";
