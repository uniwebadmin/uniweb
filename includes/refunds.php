<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

function ensureRefundsEngine(): void
{
    // Schema changes are versioned under migrations/. Request-time DDL is forbidden.
}

/** Map txn payment_method / order binding → refund provider key. */
function resolveRefundProvider(array $txn): string
{
    $method = strtolower(trim((string)($txn['payment_method'] ?? '')));
    if (str_starts_with($method, 'razorpay')) {
        return 'razorpay';
    }
    if (str_starts_with($method, 'cashfree')) {
        return 'cashfree';
    }
    if (str_starts_with($method, 'payu')) {
        return 'payu';
    }
    if (!empty($txn['is_test'])) {
        return 'sandbox';
    }
    $ctx = resolveTransactionRefundContext($txn);
    $provider = strtolower(trim((string)($ctx['provider'] ?? '')));
    if (in_array($provider, ['razorpay', 'cashfree', 'payu'], true)) {
        return $provider;
    }
    return '';
}

/**
 * Provider order id + payment id for partner refund APIs.
 *
 * @return array{provider:string,provider_order_id:string,payment_id:string}
 */
function resolveTransactionRefundContext(array $txn): array
{
    $method = strtolower(trim((string)($txn['payment_method'] ?? '')));
    $provider = '';
    if (str_starts_with($method, 'razorpay')) {
        $provider = 'razorpay';
    } elseif (str_starts_with($method, 'cashfree')) {
        $provider = 'cashfree';
    } elseif (str_starts_with($method, 'payu')) {
        $provider = 'payu';
    }
    $paymentId = trim((string)($txn['utr'] ?? ''));
    $providerOrderId = '';
    $txnId = (int)($txn['id'] ?? 0);
    if ($txnId > 0) {
        try {
            $st = getDB()->prepare(
                'SELECT po.provider, po.provider_order_id, po.provider_payment_id
                 FROM payment_order_transactions pot
                 JOIN payment_orders po ON po.id = pot.payment_order_id
                 WHERE pot.transaction_id = ?
                 LIMIT 1'
            );
            $st->execute([$txnId]);
            $row = $st->fetch();
            if ($row) {
                $provider = strtolower(trim((string)($row['provider'] ?? $provider)));
                $providerOrderId = trim((string)($row['provider_order_id'] ?? ''));
                if ($paymentId === '') {
                    $paymentId = trim((string)($row['provider_payment_id'] ?? ''));
                }
            }
        } catch (Throwable $e) { /* ok */ }
    }
    return [
        'provider' => $provider,
        'provider_order_id' => $providerOrderId,
        'payment_id' => $paymentId,
    ];
}

/** Whether live refund API is wired for this provider (honest — no fake success). */
function refundProviderCapabilityLabel(string $provider): string
{
    return match (strtolower($provider)) {
        'razorpay', 'cashfree', 'payu' => 'supported',
        'sandbox' => 'test',
        default => 'parked',
    };
}

/**
 * Send refund to partner once; persist provider_refund_id. Idempotent if provider_refund_id already set.
 *
 * @return array{ok:bool,provider_refund_id?:string,provider_status?:string,error?:string,error_code?:string}
 */
function submitProviderRefund(string $provider, array $txn, string $refundId, float $amount, string $reason, int $refundRowId): array
{
    if (!function_exists('createRazorpayRefund') && is_file(__DIR__ . '/gateways.php')) {
        require_once __DIR__ . '/gateways.php';
    }
    if (!function_exists('logPartnerErrorAndMap') && is_file(__DIR__ . '/partner_error_mapping.php')) {
        require_once __DIR__ . '/partner_error_mapping.php';
    }

    $db = getDB();
    $existing = $db->prepare('SELECT provider_refund_id, status FROM refunds WHERE id=? LIMIT 1');
    $existing->execute([$refundRowId]);
    $row = $existing->fetch();
    if ($row && !empty($row['provider_refund_id']) && ($row['status'] ?? '') === 'pending') {
        return [
            'ok' => true,
            'provider_refund_id' => (string)$row['provider_refund_id'],
            'provider_status' => 'pending',
            'duplicate' => true,
        ];
    }

    $ctx = resolveTransactionRefundContext($txn);
    $provider = strtolower(trim($provider));

    if (!function_exists('pgOutboundCircuitBlocked') && is_file(__DIR__ . '/circuit_breaker.php')) {
        require_once __DIR__ . '/circuit_breaker.php';
    }
    $circuitBlock = function_exists('pgOutboundCircuitBlocked') ? pgOutboundCircuitBlocked($provider, 'refund_create') : null;
    if ($circuitBlock !== null) {
        return $circuitBlock;
    }

    if ($provider === 'razorpay') {
        $paymentId = (string)($ctx['payment_id'] ?? '');
        if ($paymentId === '') {
            return ['ok' => false, 'error' => 'Payment reference missing for Razorpay refund.', 'error_code' => 'validation_error'];
        }
        $providerRefund = createRazorpayRefund($paymentId, $amount, $refundId);
        if (!$providerRefund || empty($providerRefund['id'])) {
            $mapped = logPartnerErrorAndMap('razorpay', 'create_refund', $providerRefund ?? 'empty');
            return ['ok' => false, 'error' => $mapped['error'], 'error_code' => $mapped['error_code']];
        }
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('razorpay', true);
        }
        if ((string)($providerRefund['payment_id'] ?? '') !== $paymentId
            || abs(((float)($providerRefund['amount'] ?? 0) / 100) - $amount) > 0.001
        ) {
            return ['ok' => false, 'error' => 'Provider refund response did not match the request.', 'error_code' => 'validation_error'];
        }
        return [
            'ok' => true,
            'provider_refund_id' => (string)$providerRefund['id'],
            'provider_status' => strtolower((string)($providerRefund['status'] ?? 'pending')),
        ];
    }

    if ($provider === 'cashfree') {
        $orderId = (string)($ctx['provider_order_id'] ?? '');
        if ($orderId === '') {
            return ['ok' => false, 'error' => 'Cashfree order id missing for refund.', 'error_code' => 'validation_error'];
        }
        $providerRefund = createCashfreeRefund($orderId, $amount, $refundId, $reason);
        if (!$providerRefund) {
            $mapped = logPartnerErrorAndMap('cashfree', 'create_refund', 'create failed');
            return ['ok' => false, 'error' => $mapped['error'], 'error_code' => $mapped['error_code']];
        }
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('cashfree', true);
        }
        $cfRefundId = (string)($providerRefund['cf_refund_id'] ?? $providerRefund['refund_id'] ?? $refundId);
        return [
            'ok' => true,
            'provider_refund_id' => $cfRefundId,
            'provider_status' => strtolower((string)($providerRefund['refund_status'] ?? $providerRefund['status'] ?? 'pending')),
        ];
    }

    if ($provider === 'payu') {
        $paymentId = (string)($ctx['payment_id'] ?? '');
        if ($paymentId === '') {
            return ['ok' => false, 'error' => 'PayU payment id missing for refund.', 'error_code' => 'validation_error'];
        }
        $providerRefund = createPayuRefund($paymentId, $amount, $refundId);
        if (!$providerRefund) {
            $mapped = logPartnerErrorAndMap('payu', 'create_refund', 'create failed');
            return ['ok' => false, 'error' => $mapped['error'], 'error_code' => $mapped['error_code']];
        }
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('payu', true);
        }
        return [
            'ok' => true,
            'provider_refund_id' => (string)($providerRefund['provider_refund_id'] ?? $refundId),
            'provider_status' => 'pending',
        ];
    }

    return ['ok' => false, 'error' => 'This payment provider does not have an activated refund API.', 'error_code' => 'refund_not_allowed'];
}

function notifyMerchantRefundProcessed(int $merchantId, string $refundId, string $txnRef, float $amount): void
{
    $message = $refundId . ' — ' . formatMoney($amount) . ' refunded for ' . $txnRef . '.';
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Refund Processed', $message, 'refund_ok_' . $refundId);
    } elseif (function_exists('createNotification')) {
        createNotification($merchantId, 'Refund Processed', $message, 'refund_ok_' . $refundId);
    }
    if (!function_exists('wiringDeepLinkRefundActionUrl') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
        require_once __DIR__ . '/wiring_deep_link_workflow.php';
    }
    $refundPath = function_exists('wiringDeepLinkRefundActionUrl')
        ? (wiringDeepLinkRefundActionUrl('Refund Processed', $refundId, false) ?? 'refunds.php')
        : 'refunds.php';
    $refundUrl = rtrim(APP_URL, '/') . '/' . ltrim($refundPath, '/');
    if (function_exists('notifyMerchantEmail')) {
        notifyMerchantEmail(
            $merchantId,
            'Refund processed — ' . formatMoney($amount),
            $message . "\n\nOpen refund: {$refundUrl}",
            'refund',
            'email_refund_ok_' . $refundId
        );
    }
}

function notifyMerchantRefundCreated(int $merchantId, string $refundId, string $txnRef, float $amount): void
{
    $message = $refundId . ' — ' . formatMoney($amount) . ' refund initiated for ' . $txnRef . '. Status: pending (not completed yet).';
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Refund Initiated', $message, 'refund_pending_' . $refundId);
    }
    if (!function_exists('wiringDeepLinkRefundActionUrl') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
        require_once __DIR__ . '/wiring_deep_link_workflow.php';
    }
    $refundPath = function_exists('wiringDeepLinkRefundActionUrl')
        ? (wiringDeepLinkRefundActionUrl('Refund Initiated', $refundId, false) ?? 'refunds.php')
        : 'refunds.php';
    $refundUrl = rtrim(APP_URL, '/') . '/' . ltrim($refundPath, '/');
    if (function_exists('notifyMerchantEmail')) {
        notifyMerchantEmail(
            $merchantId,
            'Refund initiated — ' . formatMoney($amount),
            $message . "\n\nTrack refund: {$refundUrl}",
            'refund',
            'email_refund_pending_' . $refundId
        );
    }
}

function notifyMerchantRefundFailed(int $merchantId, string $refundId, string $txnRef, float $amount, string $reason): void
{
    $reason = trim($reason);
    $message = $refundId . ' — ' . formatMoney($amount) . ' refund for ' . $txnRef . ' could not be completed.';
    if ($reason !== '') {
        $message .= ' ' . mb_substr($reason, 0, 200);
    }
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Refund Failed', $message, 'refund_fail_' . $refundId);
    } elseif (function_exists('createNotification')) {
        createNotification($merchantId, 'Refund Failed', $message, 'refund_fail_' . $refundId);
    }
    if (!function_exists('wiringDeepLinkRefundActionUrl') && is_file(__DIR__ . '/wiring_deep_link_workflow.php')) {
        require_once __DIR__ . '/wiring_deep_link_workflow.php';
    }
    $refundPath = function_exists('wiringDeepLinkRefundActionUrl')
        ? (wiringDeepLinkRefundActionUrl('Refund Failed', $refundId, false) ?? 'refunds.php')
        : 'refunds.php';
    $refundUrl = rtrim(APP_URL, '/') . '/' . ltrim($refundPath, '/');
    if (function_exists('notifyMerchantEmail')) {
        notifyMerchantEmail(
            $merchantId,
            'Refund failed — ' . formatMoney($amount),
            $message . "\n\nOpen refund: {$refundUrl}",
            'refund',
            'email_refund_fail_' . $refundId
        );
    }
    if (function_exists('dispatchMerchantWebhook')) {
        dispatchMerchantWebhook($merchantId, 'refund.failed', [
            'refund_id' => $refundId,
            'txn_id' => $txnRef,
            'amount' => $amount,
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 240),
        ], 'refund_fail_wh_' . $refundId);
    }
}

function markProviderRefundFailed(int $refundRowId, string $failureReason): array
{
    $db = getDB();
    $st = $db->prepare(
        'SELECT r.*, t.txn_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.id=? LIMIT 1'
    );
    $st->execute([$refundRowId]);
    $refund = $st->fetch();
    if (!$refund) {
        return ['ok' => false, 'error' => 'Refund not found.'];
    }
    if (($refund['status'] ?? '') === 'failed') {
        return ['ok' => true, 'duplicate' => true];
    }
    $failureReason = mb_substr(trim($failureReason), 0, 500);
    $db->prepare(
        "UPDATE refunds SET status='failed',provider_status='failed',failure_reason=?,processed_at=NOW() WHERE id=? AND status='pending'"
    )->execute([$failureReason, $refundRowId]);
    notifyMerchantRefundFailed(
        (int)$refund['merchant_id'],
        (string)$refund['refund_id'],
        (string)$refund['txn_id'],
        (float)$refund['amount'],
        $failureReason
    );
    $txnSt = getDB()->prepare('SELECT * FROM transactions WHERE id=?');
    $txnSt->execute([(int)$refund['transaction_id']]);
    $txnRow = $txnSt->fetch() ?: [];
    if ($txnRow) {
        notifyCustomerRefundTerminal($refund, $txnRow, 'failed', $failureReason);
    }
    if (function_exists('uwRecordAuditEvent')) {
        uwRecordAuditEvent('refund_failed', [
            'merchant_id' => (int)$refund['merchant_id'],
            'actor_type' => 'system',
            'resource_type' => 'refund',
            'resource_id' => (string)$refund['refund_id'],
            'reason' => $failureReason,
        ]);
    }
    return ['ok' => true, 'status' => 'failed'];
}

/**
 * Honest customer notify on terminal refund — email when address exists; track link in body.
 * No fake SMS when channel not configured.
 */
function notifyCustomerRefundTerminal(array $refund, array $txn, string $terminalStatus, string $detailReason = ''): void
{
    if (!function_exists('buildPaymentTrackUrl') && is_file(__DIR__ . '/customer_portal.php')) {
        require_once __DIR__ . '/customer_portal.php';
    }
    $email = trim((string)($txn['customer_email'] ?? ''));
    $txnRef = (string)($txn['txn_id'] ?? '');
    $refundId = (string)($refund['refund_id'] ?? '');
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $txnRef === '') {
        return;
    }
    $dedupeKey = 'cust_refund_' . $terminalStatus . '_' . ($refundId !== '' ? $refundId : $txnRef);
    $scope = 'customer:' . hash('sha256', strtolower($email));
    if (function_exists('notifyChannelWasSent') && notifyChannelWasSent($scope, $dedupeKey)) {
        return;
    }
    $amount = formatMoney((float)($refund['amount'] ?? 0));
    $trackUrl = buildPaymentTrackUrl($txnRef);
    $receiptUrl = function_exists('buildSignedReceiptUrl') ? buildSignedReceiptUrl($txnRef) : $trackUrl;
    $merchantName = trim((string)($txn['business_name'] ?? APP_NAME));

    if (!function_exists('sendPlatformEmail') && is_file(__DIR__ . '/mailer.php')) {
        require_once __DIR__ . '/mailer.php';
    }
    if (function_exists('sendPlatformEmail')) {
        $subject = $terminalStatus === 'completed'
            ? 'Refund processed — ' . $merchantName
            : 'Refund update — ' . $merchantName;
        $statusLine = $terminalStatus === 'completed'
            ? 'Your refund is processed.'
            : 'Your refund could not be completed.';
        $body = '<p>Payment <strong>' . e($txnRef) . '</strong> — refund <strong>' . e($refundId) . '</strong> — <strong>' . e($amount) . '</strong>.</p>'
            . '<p>' . e($statusLine) . '</p>'
            . ($detailReason !== '' ? ('<p>' . e(mb_substr($detailReason, 0, 300)) . '</p>') : '')
            . '<p><a href="' . e($trackUrl) . '">Track payment status</a>'
            . ($terminalStatus === 'completed' ? (' · <a href="' . e($receiptUrl) . '">View receipt</a>') : '')
            . '</p>';
        if (sendPlatformEmail($email, $subject, $body, true) && function_exists('markNotifyChannelSent')) {
            markNotifyChannelSent($scope, $dedupeKey);
        }
    }
}

function refundRemainingAmount(int $transactionId): float
{
    $db = getDB();
    $st = $db->prepare('SELECT amount, is_test FROM transactions WHERE id=?');
    $st->execute([$transactionId]);
    $txn = $st->fetch();
    if (!$txn) {
        return 0.0;
    }
    $cap = sanitizePaymentAmount((float)$txn['amount'], !empty($txn['is_test']));
    $usedSt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE transaction_id=? AND status IN ('pending','completed')");
    $usedSt->execute([$transactionId]);
    return max(0, round($cap - (float)$usedSt->fetchColumn(), 2));
}

function processRefund(int $transactionId, float $amount, string $reason, ?int $adminId = null): array
{
    ensureRefundsEngine();
    ensureWalletEngine();
    $db = getDB();

    $st = $db->prepare('SELECT t.*, m.email, m.business_name FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.id=?');
    $st->execute([$transactionId]);
    $txn = $st->fetch();
    if (!$txn) {
        return ['ok' => false, 'error' => 'Transaction not found.'];
    }
    if ($txn['status'] !== 'success' && $txn['status'] !== 'refunded') {
        return ['ok' => false, 'error' => 'Only successful or partially refunded transactions can be refunded.'];
    }

    $pendingDup = $db->prepare("SELECT refund_id FROM refunds WHERE transaction_id=? AND status='pending' LIMIT 1");
    $pendingDup->execute([$transactionId]);
    $existingPending = $pendingDup->fetchColumn();
    if ($existingPending) {
        return ['ok' => false, 'error' => 'A refund is already pending for this transaction (' . $existingPending . '). Wait for completion or failure.'];
    }

    $isTest = !empty($txn['is_test']);
    $capturedAmount = sanitizePaymentAmount((float)$txn['amount'], $isTest);
    $max = refundRemainingAmount($transactionId);
    if ($amount <= 0) {
        $amount = $max;
    }
    $amount = round(min($amount, $max), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid refund amount.'];
    }

    $refundId = generateId('RFD');
    $provider = resolveRefundProvider($txn);
    if ($provider === '') {
        return ['ok' => false, 'error' => 'This payment provider does not have an activated refund API.'];
    }
    $db->prepare('INSERT INTO refunds (refund_id, merchant_id, transaction_id, amount, status, reason, admin_note, provider) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$refundId, (int)$txn['merchant_id'], $transactionId, $amount, 'pending', $reason, $adminId ? 'admin:' . $adminId : null, $provider !== 'sandbox' ? $provider : null]);

    $refundRowSt = $db->prepare('SELECT id FROM refunds WHERE refund_id=? LIMIT 1');
    $refundRowSt->execute([$refundId]);
    $refundRowId = (int)$refundRowSt->fetchColumn();

    if (function_exists('uwRecordAuditEvent')) {
        uwRecordAuditEvent('refund_requested', [
            'merchant_id' => (int)$txn['merchant_id'],
            'actor_type' => $adminId ? 'staff' : 'system',
            'actor_id' => $adminId ? (string)$adminId : null,
            'resource_type' => 'refund',
            'resource_id' => $refundId,
            'reason' => $reason,
            'after_state' => ['transaction_id' => $transactionId, 'txn_ref' => (string)$txn['txn_id'], 'amount' => $amount],
        ]);
    }

    if ($isTest) {
        $db->prepare("UPDATE refunds SET provider='sandbox',provider_refund_id=?,provider_status='processed' WHERE refund_id=?")
            ->execute(['sandbox_' . $refundId, $refundId]);
        completeProviderRefund($refundId, 'sandbox_' . $refundId);
        return ['ok' => true, 'refund_id' => $refundId, 'amount' => $amount, 'status' => 'completed'];
    }

    $submit = submitProviderRefund($provider, $txn, $refundId, $amount, $reason, $refundRowId);
    if (empty($submit['ok'])) {
        $failReason = (string)($submit['error'] ?? 'Partner refund request failed.');
        $db->prepare("UPDATE refunds SET status='failed',provider=?,provider_status='request_failed',failure_reason=?,processed_at=NOW() WHERE refund_id=?")
            ->execute([$provider, mb_substr($failReason, 0, 500), $refundId]);
        notifyMerchantRefundFailed((int)$txn['merchant_id'], $refundId, (string)$txn['txn_id'], $amount, $failReason);
        notifyCustomerRefundTerminal(['refund_id' => $refundId, 'amount' => $amount], $txn, 'failed', $failReason);
        if (function_exists('uwRecordAuditEvent')) {
            uwRecordAuditEvent('refund_failed', [
                'merchant_id' => (int)$txn['merchant_id'],
                'actor_type' => 'system',
                'resource_type' => 'refund',
                'resource_id' => $refundId,
                'reason' => $failReason,
            ]);
        }
        return ['ok' => false, 'error' => $failReason, 'error_code' => $submit['error_code'] ?? 'refund_failed'];
    }

    $providerRefundId = (string)($submit['provider_refund_id'] ?? '');
    $providerStatus = strtolower((string)($submit['provider_status'] ?? 'pending'));
    $db->prepare("UPDATE refunds SET provider=?,provider_refund_id=?,provider_status=? WHERE refund_id=?")
        ->execute([$provider, $providerRefundId, $providerStatus, $refundId]);
    notifyMerchantRefundCreated((int)$txn['merchant_id'], $refundId, (string)$txn['txn_id'], $amount);

    if (in_array($providerStatus, ['processed', 'success', 'successful', 'completed'], true)) {
        completeProviderRefund($refundId, $providerRefundId);
        return ['ok' => true, 'refund_id' => $refundId, 'amount' => $amount, 'status' => 'completed'];
    }
    return ['ok' => true, 'refund_id' => $refundId, 'amount' => $amount, 'status' => 'pending'];
}

function completeProviderRefund(string $refundId, string $providerReference): array
{
    requireFinancialTables();
    $db = getDB();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT r.*,t.txn_id,t.amount AS transaction_amount,t.is_test,t.merchant_id,t.payment_method FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.refund_id=? FOR UPDATE');
        $st->execute([$refundId]);
        $refund = $st->fetch();
        if (!$refund) {
            throw new RuntimeException('Refund not found.');
        }
        if ($refund['status'] === 'completed') {
            $db->commit();
            return ['ok' => true, 'duplicate' => true];
        }
        if ($refund['status'] === 'failed') {
            throw new RuntimeException('Failed refund cannot be completed.');
        }
        $mode = !empty($refund['is_test']) ? 'test' : 'live';
        $refundAmount = (float)$refund['amount'];

        // F5: Get original snapshot for proportional reversal
        $snapshot = null;
        if (function_exists('getTransactionSnapshot')) {
            $snapshot = getTransactionSnapshot((int)$refund['transaction_id']);
        }
        $reversalSplit = null;
        if ($snapshot) {
            $reversalSplit = calculateRefundReversalSplit($refundAmount, $snapshot);
        }

        $merchantAccount = getOrCreateLedgerAccount('merchant_payable:' . (int)$refund['merchant_id'], 'merchant', (int)$refund['merchant_id'], 'liability', $mode);
        $providerAccount = getOrCreateLedgerAccount('provider_receivable:' . ($refund['provider'] ?: 'sandbox'), 'provider', null, 'asset', $mode);

        // F4/F5: Post ledger reversal — debit merchant_payable, credit provider_receivable
        // Use proportional amounts from snapshot if available
        $merchantReversal = $reversalSplit ? (float)$reversalSplit['merchant_reversal'] : $refundAmount;
        $platformReversal = $reversalSplit ? (float)$reversalSplit['platform_reversal'] : 0.0;

        $entries = [
            ['account_id' => $merchantAccount, 'side' => 'debit', 'amount' => $merchantReversal],
            ['account_id' => $providerAccount, 'side' => 'credit', 'amount' => $refundAmount],
        ];

        // F5: If platform_fee was collected, reverse it too
        if ($platformReversal > 0) {
            $feeAccount = getOrCreateLedgerAccount('platform_fee_revenue', 'platform', null, 'revenue', $mode);
            $entries[] = ['account_id' => $feeAccount, 'side' => 'debit', 'amount' => $platformReversal];
            // Adjust merchant debit to keep balanced: merchant_debit + platform_debit = refundAmount
            $entries[0]['amount'] = round($refundAmount - $platformReversal, 2);
        }

        postBalancedJournal(
            'refund',
            $refundId,
            $mode,
            'INR',
            $entries,
            'Provider-confirmed refund for ' . $refund['txn_id'],
            ['provider_reference' => $providerReference, 'snapshot_used' => $snapshot !== null]
        );

        // F5: Record + live reverse partner Route / Split legs when enabled
        if ($snapshot && function_exists('recordRefundReversalTransfer')) {
            try {
                $partnerKey = (string)($snapshot['partner_key'] ?? '');
                if ($partnerKey === '') {
                    $txnSt = getDB()->prepare('SELECT payment_method FROM transactions WHERE id=?');
                    $txnSt->execute([(int)$refund['transaction_id']]);
                    $partnerKey = strtolower((string)($txnSt->fetchColumn() ?: ''));
                }
                recordRefundReversalTransfer((int)$refund['transaction_id'], (int)$refund['merchant_id'], $partnerKey, $refundAmount);
                if ($partnerKey !== '' && is_file(__DIR__ . '/route_split_partner_api.php')) {
                    require_once __DIR__ . '/route_split_partner_api.php';
                    if (function_exists('executePartnerRouteRefundReversal')) {
                        $reversal = calculateRefundReversalSplit($refundAmount, $snapshot);
                        executePartnerRouteRefundReversal(
                            (int)$refund['transaction_id'],
                            (int)$refund['merchant_id'],
                            $partnerKey,
                            (float)($reversal['merchant_reversal'] ?? $refundAmount),
                            (float)($reversal['platform_reversal'] ?? 0),
                            $refundId
                        );
                    }
                }
            } catch (Throwable $e) { /* non-fatal */ }
        }

        $db->prepare("UPDATE refunds SET status='completed',provider_status='processed',provider_reference=?,processed_at=NOW() WHERE id=?")
            ->execute([$providerReference, (int)$refund['id']]);
        $totalSt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE transaction_id=? AND status='completed'");
        $totalSt->execute([(int)$refund['transaction_id']]);
        $totalRefunded = round((float)$totalSt->fetchColumn(), 2);
        if ($totalRefunded + 0.001 >= (float)$refund['transaction_amount']) {
            $db->prepare("UPDATE transactions SET status='refunded' WHERE id=?")->execute([(int)$refund['transaction_id']]);
        }
        $balance = merchantLedgerBalance((int)$refund['merchant_id'], $mode);
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$balance, (int)$refund['merchant_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
    notifyMerchantRefundProcessed(
        (int)$refund['merchant_id'],
        $refundId,
        (string)$refund['txn_id'],
        (float)$refund['amount']
    );
    if (function_exists('uwRecordAuditEvent')) {
        uwRecordAuditEvent('refund_completed', [
            'merchant_id' => (int)$refund['merchant_id'],
            'actor_type' => 'system',
            'resource_type' => 'refund',
            'resource_id' => $refundId,
            'reason' => 'Provider-confirmed refund for ' . $refund['txn_id'],
            'after_state' => ['amount' => (float)$refund['amount'], 'provider_reference' => $providerReference],
        ]);
    }
    if (function_exists('sendTemplatedEmail')) {
        sendTemplatedEmail((int)$refund['merchant_id'], 'refund_processed', [
            'amount' => formatMoney((float)$refund['amount']),
            'txn_id' => $refund['txn_id'],
            'refund_id' => $refundId,
            'reason' => $refund['reason'] ?? '',
        ]);
    }
    dispatchMerchantWebhook((int)$refund['merchant_id'], 'refund.completed', [
        'refund_id' => $refundId,
        'txn_id' => $refund['txn_id'],
        'amount' => (float)$refund['amount'],
        'provider_reference' => $providerReference,
    ], 'refund_ok_wh_' . $refundId);
    $txnSt = getDB()->prepare('SELECT * FROM transactions WHERE id=?');
    $txnSt->execute([(int)$refund['transaction_id']]);
    $txnRow = $txnSt->fetch() ?: [];
    if ($txnRow) {
        notifyCustomerRefundTerminal($refund, $txnRow, 'completed', (string)($refund['reason'] ?? ''));
    }
    return ['ok' => true, 'duplicate' => false];
}

function getMerchantRefunds(int $merchantId, int $limit = 30): array
{
    ensureRefundsEngine();
    $st = getDB()->prepare('SELECT r.*, t.txn_id FROM refunds r JOIN transactions t ON t.id=r.transaction_id WHERE r.merchant_id=? ORDER BY r.created_at DESC LIMIT ?');
    $st->bindValue(1, $merchantId, PDO::PARAM_INT);
    $st->bindValue(2, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
