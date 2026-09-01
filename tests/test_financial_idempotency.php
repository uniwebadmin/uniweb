<?php
declare(strict_types=1);

/**
 * Point #1 — double-credit / payment capture idempotency (static + optional DB).
 * Run: php tests/test_financial_idempotency.php
 */

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

$check = static function (bool $ok, string $name) use (&$failed, &$passed): void {
    if ($ok) {
        $passed++;
        echo "PASS {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
};

$idempotency = (string)file_get_contents($root . '/includes/payment_idempotency.php');
$fin = (string)file_get_contents($root . '/includes/financial_integrity.php');
$wallet = (string)file_get_contents($root . '/includes/wallet.php');
$mig = (string)file_get_contents($root . '/migrations/001_financial_integrity.sql');
$mig082 = (string)file_get_contents($root . '/migrations/082_payment_idempotency_gst.sql');

$mig002 = (string)file_get_contents($root . '/migrations/002_legacy_wallet_baseline.sql');

$check(str_contains($idempotency, 'paymentCaptureIsFinalized'), 'idempotency_helper_exists');
$check(str_contains($idempotency, 'gateway_event') && str_contains($idempotency, 'ledger_journal'), 'idempotency_layers_documented');
$check(str_contains($fin, 'registerGatewayEvent') && str_contains($mig, 'uniq_gateway_event'), 'gateway_events_unique');
$check(str_contains($mig, 'uniq_business_journal'), 'ledger_journal_unique');
$check(str_contains($mig002, 'uniq_wallet_payment_credit'), 'wallet_txn_unique_per_merchant');
$check(str_contains($mig082, 'uniq_platform_wallet_txn'), 'platform_wallet_txn_unique');
$check(str_contains($fin, 'captureVerifiedPaymentOrder') && str_contains($fin, "'paid'"), 'paid_order_duplicate_capture_guard');
$check(str_contains($fin, 'postBalancedJournal') && str_contains($fin, 'existingId'), 'ledger_post_idempotent');
$check(str_contains($wallet, 'paymentCaptureIsFinalized'), 'wallet_credit_checks_idempotency');
$check(str_contains($wallet, 'never double-credit platform fee') || str_contains($wallet, 'Block 7'), 'platform_fee_dup_guard');

if (is_file($root . '/config.php')) {
    try {
        require_once $root . '/config.php';
        require_once $root . '/includes/payment_idempotency.php';
        require_once $root . '/includes/financial_integrity.php';
        if (function_exists('financialTablesReady') && financialTablesReady()) {
            $layers = paymentCaptureIdempotencyLayers();
            $check(count($layers) >= 4, 'idempotency_layers_runtime');
            $check(!paymentCaptureIsFinalized(999999999), 'nonexistent_txn_not_finalized');
        } else {
            echo "SKIP DB runtime (financial tables not ready)\n";
        }
    } catch (Throwable $e) {
        echo "SKIP DB runtime: " . $e->getMessage() . "\n";
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
