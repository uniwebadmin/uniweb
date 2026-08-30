<?php
declare(strict_types=1);

/**
 * Nav audit probe — file existence for every sidebar URL.
 * Usage: php tests/probe_nav_audit.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/sidebar_nav.php';

$dead = [];
$ok = [];
$seen = [];

foreach (['merchant' => uniwebMerchantNavGroups(), 'admin' => uniwebAdminNavGroups()] as $portal => $groups) {
    foreach ($groups as $g) {
        foreach ($g['items'] ?? [] as $item) {
            [$url, $label] = $item;
            if (isset($seen[$portal . ':' . $url])) {
                continue;
            }
            $seen[$portal . ':' . $url] = true;
            $path = $root . '/' . $url;
            $st = is_file($path) ? 'OK' : 'DEAD';
            if ($st === 'DEAD') {
                $dead[] = [$portal, $label, $url, $st];
            } else {
                $ok[] = [$portal, $label, $url, $st];
            }
        }
    }
}

// Staff URLs from staff.php matrix (no session/auth required)
$staffFile = (string)file_get_contents($root . '/includes/staff.php');
if (preg_match_all("/'([a-z0-9_]+\.php)'\\s*=>\\s*\\['([^']+)'/", $staffFile, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
        [$full, $url, $label] = $row;
        $key = 'staff:' . $url;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $st = is_file($root . '/' . $url) ? 'OK' : 'DEAD';
        if ($st === 'DEAD') {
            $dead[] = ['staff', $label, $url, $st];
        } else {
            $ok[] = ['staff', $label, $url, $st];
        }
    }
}

echo 'OK=' . count($ok) . ' DEAD=' . count($dead) . PHP_EOL;
foreach ($dead as $d) {
    echo implode(' | ', $d) . PHP_EOL;
}
exit(count($dead) > 0 ? 1 : 0);
