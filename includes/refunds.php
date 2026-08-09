<?php
declare(strict_types=1);

function ensureRefundsEngine(): void
{
    // Schema changes are versioned under migrations/. Request-time DDL is forbidden.
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
    if ($txn['status'] !== 'success') {
        return ['ok' => false, 'error' => 'Only successful transactions can be refunded.'];
    }

    $isTest = !empty($txn['is_test']);
    $capturedAmount = sanitizePaymentAmount((float)$txn['amount'], $isTest);
    $usedSt = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM refunds WHERE transaction_id=? AND status IN ('pending','completed')");
    $usedSt->execute([$transactionId]);
    $alreadyRefunding = round((float)$usedSt->fetchColumn(), 2);
    $max = max(0, round($capturedAmount - $alreadyRefunding, 2));
    if ($amount <= 0) {
        $amount = $max;
    }
    $amount = round(min($amount, $max), 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid refund amount.'];
    }

    $refundId = generateId('RFD');
    $provider = str_starts_with(strtolower((string)$txn['payment_method']), 'razorpay') ? 'razorpay' : ($isTest ? 'sandbox' : '');
    if ($provider === '') {
        return ['ok' => false, 'error' => 'This payment provider does not have an activated refund API.'];
    }
    $db->prepare('INSERT INTO refunds (refund_id, merchant_id, transaction_id, amount, status, reason, admin_note) VALUES (?,?,?,?,?,?,?)')
        ->execute([$refundId, (int)$txn['merchant_id'], $transactionId, $amount, 'pending', $reason, $adminId ? 'admin:' . $adminId : null]);

    if ($isTest) {
        $db->prepare("UPDATE refunds SET provider='sandbox',provider_refund_id=?,provider_status='processed' WHERE refund_id=?")
            ->execute(['sandbox_' . $refundId, $refundId]);
        completeProviderRefund($refundId, 'sandbox_' . $refundId);
        return ['ok' => true, 'refund_id' => $refundId, 'amount' => $amount, 'status' => 'completed'];
    }

    $providerRefund = createRazorpayRefund((string)$txn['utr'], $amount, $refundId);
    if (!$providerRefund || empty($providerRefund['id'])) {
        $db->prepare("UPDATE refunds SET status='failed',provider='razorpay',provider_status='request_failed',failure_reason=?,processed_at=NOW() WHERE refund_id=?")
            ->execute(['Razorpay refund request failed.', $refundId]);
        return ['ok' => false, 'error' => 'Razorpay refund request failed. No wallet balance was changed.'];
    }
    if ((string)($providerRefund['payment_id'] ?? '') !== (string)$txn['utr']
        || abs(((float)($providerRefund['amount'] ?? 0) / 100) - $amount) > 0.001
    ) {
        $db->prepare("UPDATE refunds SET status='failed',provider='razorpay',provider_refund_id=?,provider_status='mismatch',failure_reason=?,processed_at=NOW() WHERE refund_id=?")
            ->execute([(string)$providerRefund['id'], 'Provider refund response did not match the request.', $refundId]);
        return ['ok' => false, 'error' => 'Provider refund response mismatch. Support has been notified.'];
    }
    $providerStatus = strtolower((string)($providerRefund['status'] ?? 'pending'));
    $db->prepare("UPDATE refunds SET provider='razorpay',provider_refund_id=?,provider_status=? WHERE refund_id=?")
        ->execute([(string)$providerRefund['id'], $providerStatus, $refundId]);
    if ($providerStatus === 'processed') {
        completeProviderRefund($refundId, (string)$providerRefund['id']);
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

        // F5: Record refund reversal transfer (idempotent)
        if ($snapshot && !empty($snapshot['partner_key']) && function_exists('recordRefundReversalTransfer')) {
            try {
                recordRefundReversalTransfer((int)$refund['transaction_id'], (int)$refund['merchant_id'], (string)$snapshot['partner_key'], $refundAmount);
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
    createNotification((int)$refund['merchant_id'], 'Refund Processed', formatMoney((float)$refund['amount']) . ' refunded for ' . $refund['txn_id']);
    sendTemplatedEmail((int)$refund['merchant_id'], 'refund_processed', [
        'amount' => formatMoney((float)$refund['amount']),
        'txn_id' => $refund['txn_id'],
        'refund_id' => $refundId,
        'reason' => $refund['reason'] ?? '',
    ]);
    dispatchMerchantWebhook((int)$refund['merchant_id'], 'refund.completed', [
        'refund_id' => $refundId,
        'txn_id' => $refund['txn_id'],
        'amount' => (float)$refund['amount'],
        'provider_reference' => $providerReference,
    ]);
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
