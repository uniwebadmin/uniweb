<?php
declare(strict_types=1);

/**
 * Point #1 — Payment capture idempotency (double-credit prevention).
 *
 * Layers (all must pass before a new wallet/ledger credit):
 *   1. gateway_events (provider, event_id) UNIQUE
 *   2. payment_orders.status = paid + payment_order_transactions 1:1
 *   3. ledger_journals (business_type, business_reference, mode) UNIQUE
 *   4. wallet_transactions (merchant_id, transaction_id) UNIQUE + wallet_credited flag
 */

if (!function_exists('financialTablesReady') && is_file(__DIR__ . '/financial_integrity.php')) {
    require_once __DIR__ . '/financial_integrity.php';
}

/** @return list<string> */
function paymentCaptureIdempotencyLayers(): array
{
    return ['gateway_event', 'payment_order', 'ledger_journal', 'wallet_credit'];
}

/**
 * True when this success txn already has ledger and/or wallet credit — safe to skip re-credit.
 */
function paymentCaptureIsFinalized(int $transactionId, string $txnRef = '', int $merchantId = 0): bool
{
    if ($transactionId < 1) {
        return false;
    }

    if (function_exists('isTransactionWalletCredited') && isTransactionWalletCredited($transactionId)) {
        return true;
    }

    if ($txnRef === '' || $merchantId < 1) {
        try {
            $st = getDB()->prepare('SELECT txn_id, merchant_id FROM transactions WHERE id=? LIMIT 1');
            $st->execute([$transactionId]);
            $row = $st->fetch();
            if ($row) {
                $txnRef = $txnRef !== '' ? $txnRef : (string)($row['txn_id'] ?? '');
                $merchantId = $merchantId > 0 ? $merchantId : (int)($row['merchant_id'] ?? 0);
            }
        } catch (Throwable $e) {
            return false;
        }
    }

    if ($txnRef !== '' && $merchantId > 0 && function_exists('transactionHasPaymentLedger') && function_exists('financialTablesReady') && financialTablesReady()) {
        if (transactionHasPaymentLedger($transactionId, $txnRef, $merchantId)) {
            return true;
        }
    }

    try {
        $wt = getDB()->prepare("SELECT id FROM wallet_transactions WHERE transaction_id=? AND type='credit' LIMIT 1");
        $wt->execute([$transactionId]);
        if ($wt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        /* table may not exist on very old DB */
    }

    try {
        $pt = getDB()->prepare('SELECT id FROM platform_wallet_transactions WHERE transaction_id=? AND amount > 0 LIMIT 1');
        $pt->execute([$transactionId]);
        if ($pt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        /* ok */
    }

    return false;
}

/**
 * Mark wallet_credited when ledger/wallet row already exists (repair flag drift).
 */
function syncPaymentCaptureCreditedFlag(int $transactionId): void
{
    if ($transactionId < 1 || !function_exists('paymentCaptureIsFinalized') || !paymentCaptureIsFinalized($transactionId)) {
        return;
    }
    if (function_exists('markTransactionWalletCredited')) {
        markTransactionWalletCredited($transactionId);
    }
}
