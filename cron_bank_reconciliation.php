<?php
/**
 * Daily cron — auto-fetch and reconcile bank statements from SFTP or local inbox.
 * URL: /cron_bank_reconciliation.php?key=BANK_RECONCILIATION_CRON_KEY
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bank_reconciliation.php';

header('Content-Type: text/plain; charset=utf-8');

$key = $_GET['key'] ?? '';
$expected = bankReconciliationCronKey();
$legacyOk = $expected !== '' && hash_equals($expected, (string)$key);
if (!cronAuthOk('bank_reconciliation_cron_key') && !$legacyOk && !isAdminLoggedIn()) {
    http_response_code(403);
    die('Forbidden');
}

try {
    $res = runBankReconciliationFetch();
    recordCronHeartbeat('bank_reconciliation', 'ok');
    echo 'Bank reconciliation cron ' . date('Y-m-d H:i:s') . "\n";
    if (!empty($res['skipped'])) {
        echo $res['message'] ?? 'Skipped' . "\n";
    } else {
        $files = $res['files'] ?? [];
        echo 'Files processed: ' . count($files) . "\n";
        foreach ($files as $r) {
            $status = !empty($r['skipped']) ? 'SKIPPED (already processed)' : ('OK — ' . ($r['confirmed'] ?? 0) . ' confirmed, ' . ($r['suggested'] ?? 0) . ' suggested, ' . count($r['unmatched'] ?? []) . ' unmatched');
            echo ($r['file'] ?? '?') . ' :: ' . $status . "\n";
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Bank reconciliation cron error ' . date('Y-m-d H:i:s') . "\n";
    echo $e->getMessage() . "\n";
}
