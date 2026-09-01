<?php
declare(strict_types=1);

function fetchTransactionDetail(string $txnId, ?int $merchantId = null, bool $adminView = false): ?array
{
    if (function_exists('ensurePaymentPackSchema')) {
        ensurePaymentPackSchema();
    }
    $db = getDB();
    $sqlFull = "SELECT t.*, m.business_name, m.merchant_code, m.email AS merchant_email, m.phone AS merchant_phone,
            m.collection_mode AS merchant_collection_mode, m.account_mode,
            pl.link_id, pl.description AS link_description, pl.customer_name AS link_customer_name,
            pl.customer_phone AS link_customer_phone, pl.payment_method AS link_payment_method,
            pl.gateway_code AS link_gateway, pl.link_label
            FROM transactions t
            JOIN merchants m ON t.merchant_id = m.id
            LEFT JOIN payment_links pl ON t.payment_link_id = pl.id
            WHERE t.txn_id = ?";
    $sqlBasic = "SELECT t.*, m.business_name, m.merchant_code, m.email AS merchant_email, m.phone AS merchant_phone,
            m.collection_mode AS merchant_collection_mode, m.account_mode,
            pl.link_id, pl.description AS link_description, pl.customer_name AS link_customer_name,
            pl.customer_phone AS link_customer_phone, pl.payment_method AS link_payment_method,
            pl.gateway_code AS link_gateway
            FROM transactions t
            JOIN merchants m ON t.merchant_id = m.id
            LEFT JOIN payment_links pl ON t.payment_link_id = pl.id
            WHERE t.txn_id = ?";
    $params = [$txnId];
    if ($merchantId !== null && !$adminView) {
        $sqlFull .= ' AND t.merchant_id = ?';
        $sqlBasic .= ' AND t.merchant_id = ?';
        $params[] = $merchantId;
    }
    $row = null;
    foreach ([$sqlFull, $sqlBasic] as $sql) {
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch();
            break;
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'link_label') && !str_contains($e->getMessage(), '42S22')) {
                throw $e;
            }
        }
    }
    if (!$row) {
        return null;
    }
    if (!array_key_exists('link_label', $row)) {
        $row['link_label'] = null;
    }

    $walletTxn = $db->prepare('SELECT * FROM wallet_transactions WHERE merchant_id = ? AND (transaction_id = ? OR reference = ?) ORDER BY id DESC LIMIT 1');
    $walletTxn->execute([(int)$row['merchant_id'], (int)$row['id'], $txnId]);
    $row['wallet_entry'] = $walletTxn->fetch() ?: null;

    try {
        $splits = $db->prepare('SELECT * FROM transaction_splits WHERE transaction_id = ?');
        $splits->execute([(int)$row['id']]);
        $row['splits'] = $splits->fetchAll();
    } catch (Throwable $e) {
        $row['splits'] = [];
    }

    try {
        $refunds = $db->prepare('SELECT refund_id, amount, status, provider, provider_status, provider_refund_id, failure_reason, created_at, processed_at FROM refunds WHERE transaction_id=? ORDER BY created_at ASC');
        $refunds->execute([(int)$row['id']]);
        $row['refunds'] = $refunds->fetchAll();
    } catch (Throwable $e) {
        $row['refunds'] = [];
    }

    $row['chargebacks'] = [];
    if (function_exists('listChargebacksForTransaction')) {
        $row['chargebacks'] = listChargebacksForTransaction((int)$row['id']);
    } elseif (is_file(__DIR__ . '/chargebacks.php')) {
        require_once __DIR__ . '/chargebacks.php';
        if (function_exists('listChargebacksForTransaction')) {
            $row['chargebacks'] = listChargebacksForTransaction((int)$row['id']);
        }
    }

    $row['ledger_status'] = 'not_applicable';
    $row['ledger_journal'] = null;
    if (!function_exists('getTransactionLedgerStatus') && is_file(__DIR__ . '/financial_integrity.php')) {
        require_once __DIR__ . '/financial_integrity.php';
    }
    if (function_exists('getTransactionLedgerStatus')) {
        $mode = !empty($row['is_test']) ? 'test' : 'live';
        $row['ledger_status'] = getTransactionLedgerStatus(
            (int)$row['id'],
            (string)($row['txn_id'] ?? ''),
            (int)$row['merchant_id'],
            (string)($row['status'] ?? '')
        );
        if (function_exists('fetchPaymentCaptureLedgerJournal')) {
            $row['ledger_journal'] = fetchPaymentCaptureLedgerJournal((string)($row['txn_id'] ?? ''), $mode);
        }
    }

    return $row;
}

function transactionDetailUrl(string $txnId): string
{
    return 'transaction_detail.php?txn=' . rawurlencode($txnId);
}

/**
 * Human-readable "why this status" for a transaction. Prefers partner-mapped
 * failure_reason (auto-populated from webhooks), otherwise maps any raw error
 * code, otherwise a plain-language explanation by status. Never invents fake bank reasons.
 * @return array{tone:string,title:string,text:string}
 */
function transactionStatusExplainer(array $txn): array
{
    $status = strtolower(trim((string)($txn['status'] ?? '')));
    $stored = '';
    $rawCode = '';
    foreach (['failure_reason', 'failure_message', 'status_reason', 'gateway_response', 'remarks', 'error_description'] as $col) {
        if (!empty($txn[$col])) {
            $stored = trim((string)$txn[$col]);
            break;
        }
    }
    foreach (['error_code', 'failure_code', 'gateway_error_code'] as $col) {
        if (!empty($txn[$col])) {
            $rawCode = trim((string)$txn[$col]);
            break;
        }
    }
    if (function_exists('mapGatewayFailureReasonLocalized') && ($rawCode !== '' || $stored !== '')) {
        // A8: Use DB-backed localized map (has Hindi), fall back to PHP dictionary
        $mapped = mapGatewayFailureReasonLocalized($rawCode !== '' ? $rawCode : null, $stored !== '' ? $stored : null, 'en');
        if ($mapped !== '') {
            $stored = $mapped;
        }
    } elseif (function_exists('mapGatewayFailureReason') && ($rawCode !== '' || $stored !== '')) {
        $mapped = mapGatewayFailureReason($rawCode !== '' ? $rawCode : null, $stored !== '' ? $stored : null);
        if ($mapped !== '') {
            $stored = $mapped;
        }
    }
    switch ($status) {
        case 'success':
        case 'paid':
        case 'captured':
            return ['tone' => 'success', 'title' => 'Payment successful', 'text' => $stored ?: 'The payment was received and confirmed.'];
        case 'pending':
        case 'processing':
        case 'initiated':
            return ['tone' => 'warning', 'title' => 'Payment pending', 'text' => $stored ?: 'Awaiting confirmation from the bank / payment gateway. This can take a few minutes for UPI. No action is needed unless it stays pending beyond 30 minutes.'];
        case 'failed':
        case 'error':
            return ['tone' => 'danger', 'title' => 'Payment failed', 'text' => $stored ?: (defined('GATEWAY_REASON_FALLBACK') ? GATEWAY_REASON_FALLBACK : 'Technical issue from bank side. Please try again later.') . ' Any amount debited is usually auto-reversed by the bank in 3–5 working days.'];
        case 'expired':
            return ['tone' => 'muted', 'title' => 'Link expired', 'text' => $stored ?: 'The payment link/session expired before the customer paid. Share a fresh link.'];
        case 'refunded':
            return ['tone' => 'muted', 'title' => 'Refunded', 'text' => $stored ?: 'This payment was refunded to the customer.'];
        case 'cancelled':
        case 'canceled':
            return ['tone' => 'muted', 'title' => 'Cancelled', 'text' => $stored ?: 'The payment was cancelled before completion.'];
        default:
            return ['tone' => 'muted', 'title' => ucfirst(str_replace('_', ' ', $status ?: 'unknown')), 'text' => $stored];
    }
}

function paymentMethodLabel(?string $method): string
{
    $map = [
        'upi' => 'UPI', 'upi_p2m' => 'UPI P2M (Direct)', 'payu' => 'PayU Gateway',
        'card' => 'Card', 'netbanking' => 'Net Banking', 'wallet' => 'Wallet',
        'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'axis_va' => 'Axis Virtual Account',
        'qr' => 'QR Code',
    ];
    return $map[$method ?? ''] ?? ucfirst(str_replace('_', ' ', $method ?? '—'));
}

/**
 * Last confirmation path for Admin / merchant txn detail — webhook, checkout verify, or reconcile retry.
 *
 * @return array{source:string,label:string,at:?string}
 */
function transactionConfirmationSourceSummary(int $transactionId, string $txnRef, string $utr, string $paymentMethod): array
{
    $source = 'unknown';
    $label = 'Confirmation source not recorded yet';
    $at = null;
    $provider = strtolower(trim($paymentMethod));
    $needles = array_values(array_filter([trim($utr), trim($txnRef)]));

    try {
        if ($transactionId > 0) {
            if (function_exists('ensurePaymentReconcileColumns') && is_file(__DIR__ . '/payment_reconcile.php')) {
                require_once __DIR__ . '/payment_reconcile.php';
                ensurePaymentReconcileColumns();
            }
            $metaSt = getDB()->prepare(
                'SELECT last_reconcile_source, last_reconcile_at FROM transactions WHERE id=? LIMIT 1'
            );
            $metaSt->execute([$transactionId]);
            $meta = $metaSt->fetch();
            if ($meta && !empty($meta['last_reconcile_source'])) {
                $source = (string)$meta['last_reconcile_source'];
                $label = function_exists('paymentReconcileSourceLabel')
                    ? paymentReconcileSourceLabel($source)
                    : $source;
                $at = (string)($meta['last_reconcile_at'] ?? '') ?: null;
                return ['source' => $source, 'label' => $label, 'at' => $at];
            }
        }

        if (function_exists('requireFinancialTables')) {
            requireFinancialTables();
        }
        $db = getDB();

        if ($provider !== '' && $needles !== []) {
            foreach ($needles as $needle) {
                $st = $db->prepare(
                    "SELECT processed_at FROM gateway_events
                     WHERE provider=? AND signature_valid=1
                       AND processing_status IN ('processed','duplicate')
                       AND (event_id LIKE ? OR payload_hash LIKE ?)
                     ORDER BY id DESC LIMIT 1"
                );
                $like = '%' . $needle . '%';
                $st->execute([$provider, $like, $like]);
                $row = $st->fetch();
                if ($row) {
                    $source = 'webhook';
                    $label = 'Partner webhook (signature verified)';
                    $at = (string)($row['processed_at'] ?? '') ?: null;
                    break;
                }
            }
        }

        if ($source === 'unknown') {
            $st = $db->prepare(
                "SELECT po.paid_at FROM payment_order_transactions pot
                 JOIN payment_orders po ON po.id = pot.payment_order_id
                 WHERE pot.transaction_id=? AND po.status='paid'
                 ORDER BY po.paid_at DESC LIMIT 1"
            );
            $st->execute([$transactionId]);
            $paidAt = (string)($st->fetchColumn() ?: '');
            if ($paidAt !== '') {
                $source = 'checkout';
                $label = 'Checkout / server verify path';
                $at = $paidAt;
            }
        }

        if ($source === 'unknown' && function_exists('financialTablesReady') && financialTablesReady()) {
            $st = $db->prepare(
                "SELECT posted_at FROM ledger_journals
                 WHERE business_type='payment_capture' AND business_reference=?
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$txnRef !== '' ? $txnRef : 'txn:' . $transactionId]);
            $posted = (string)($st->fetchColumn() ?: '');
            if ($posted !== '') {
                $source = 'reconcile';
                $label = 'Ledger reconcile / backfill';
                $at = $posted;
            }
        }
    } catch (Throwable $e) {
        /* read-only helper — never break txn detail */
    }

    return ['source' => $source, 'label' => $label, 'at' => $at];
}
