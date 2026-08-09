<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/link_watchdog.php';
$s = runFullLinkWatchdog(false);
echo json_encode($s['summary'], JSON_PRETTY_PRINT) . PHP_EOL;
foreach ($s['pages'] as $p) {
    if ($p['ok'] === false) {
        echo "ISSUE: {$p['file']} exists=" . ($p['exists'] ? 'Y' : 'N') . " syntax=" . json_encode($p['syntax_ok']) . " broken={$p['broken_link_count']} issues=" . json_encode($p['issues']) . PHP_EOL;
    }
}
