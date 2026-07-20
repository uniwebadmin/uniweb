<?php
declare(strict_types=1);

function financialTablesReady(): bool
{
    try {
        getDB()->query('SELECT 1 FROM payment_orders LIMIT 1');
        getDB()->query('SELECT 1 FROM ledger_entries LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function requireFinancialTables(): void
{
    if (!financialTablesReady()) {
        throw new RuntimeException('Financial integrity migration has not been applied.');
    }
}

function paymentModeForLink(array $link): string
{
    return !empty($link['is_test']) || merchantAccountMode($link) === 'test' ? 'test' : 'live';
}

function createBoundPaymentOrder(array $link, string $provider, ?string $idempotencyKey = null, array $customer = []): array
{
    requireFinancialTables();
    $db = getDB();
    $merchantId = (int)($link['merchant_id'] ?? 0);
    $linkId = (int)($link['id'] ?? 0);
    $mode = paymentModeForLink($link);
    $amount = sanitizePaymentAmount((float)($link['amount'] ?? 0), $mode === 'test');
    if ($merchantId <= 0 || $linkId <= 0 || $amount <= 0) {
        throw new InvalidArgumentException('A valid merchant, payment link and amount are required.');
    }
    $provider = strtolower(preg_replace('/[^a-z0-9_]/i', '', $provider) ?: '');
    if ($provider === '') {
        throw new InvalidArgumentException('Payment provider is required.');
    }
    $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
    if ($idempotencyKey === '') {
        $idempotencyKey = null;
    }
    if ($idempotencyKey !== null && strlen($idempotencyKey) > 100) {
        throw new InvalidArgumentException('Idempotency key is too long.');
    }

    if ($idempotencyKey !== null) {
        $existing = $db->prepare('SELECT * FROM payment_orders WHERE merchant_id=? AND mode=? AND idempotency_key=? LIMIT 1');
        $existing->execute([$merchantId, $mode, $idempotencyKey]);
        $row = $existing->fetch();
        if ($row) {
            if ((int)$row['payment_link_id'] !== $linkId || abs((float)$row['expected_amount'] - $amount) > 0.001) {
                throw new RuntimeException('Idempotency key was already used for a different payment order.');
            }
            return $row;
        }
    }

    $orderRef = generateId('ORD');
    $expiresAt = $link['expires_at'] ?? date('Y-m-d H:i:s', time() + 1800);
    $metadata = json_encode([
        'link_id' => $link['link_id'] ?? null,
        'collection_mode' => getMerchantCollectionMode($link),
    ], JSON_UNESCAPED_SLASHES);
    $insert = $db->prepare(
        'INSERT INTO payment_orders
        (order_ref,merchant_id,payment_link_id,mode,expected_amount,currency,provider,idempotency_key,status,description,customer_name,customer_email,customer_phone,metadata,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $insert->execute([
        $orderRef,
        $merchantId,
        $linkId,
        $mode,
        $amount,
        'INR',
        $provider,
        $idempotencyKey,
        'created',
        mb_substr((string)($link['description'] ?? ''), 0, 255),
        mb_substr(trim((string)($customer['name'] ?? $link['customer_name'] ?? '')), 0, 160) ?: null,
        mb_substr(trim((string)($customer['email'] ?? $link['customer_email'] ?? '')), 0, 190) ?: null,
        mb_substr(trim((string)($customer['phone'] ?? $link['customer_phone'] ?? '')), 0, 32) ?: null,
        $metadata,
        $expiresAt,
    ]);
    $id = (int)$db->lastInsertId();
    $select = $db->prepare('SELECT * FROM payment_orders WHERE id=?');
    $select->execute([$id]);
    return $select->fetch();
}

function bindProviderOrder(int $paymentOrderId, string $provider, string $providerOrderId): void
{
    $providerOrderId = trim($providerOrderId);
    if ($providerOrderId === '') {
        throw new InvalidArgumentException('Provider order ID is required.');
    }
    $st = getDB()->prepare("UPDATE payment_orders SET provider=?, provider_order_id=?, status='pending' WHERE id=? AND status IN ('created','pending')");
    $st->execute([strtolower($provider), $providerOrderId, $paymentOrderId]);
    if ($st->rowCount() < 1) {
        $check = getDB()->prepare('SELECT provider, provider_order_id FROM payment_orders WHERE id=?');
        $check->execute([$paymentOrderId]);
        $row = $check->fetch();
        if (!$row || $row['provider'] !== strtolower($provider) || $row['provider_order_id'] !== $providerOrderId) {
            throw new RuntimeException('Payment order cannot be rebound.');
        }
    }
}

function assertProviderModeMatches(string $provider, string $mode): void
{
    if ($provider === 'razorpay') {
        $keyId = getSetting('razorpay_key_id', '');
        $credentialMode = str_starts_with($keyId, 'rzp_live_') ? 'live' : 'test';
    } elseif ($provider === 'cashfree') {
        $credentialMode = getSetting('cashfree_environment', 'production') === 'sandbox' ? 'test' : 'live';
    } else {
        throw new InvalidArgumentException('Unsupported checkout provider.');
    }
    if ($credentialMode !== $mode) {
        throw new RuntimeException(ucfirst($provider) . ' credentials do not match the payment order mode.');
    }
}

function createBoundGatewayCheckoutOrder(array $link, string $provider, string $cashfreeReturnUrl = ''): ?array
{
    $provider = strtolower($provider);
    $order = createBoundPaymentOrder($link, $provider);
    assertProviderModeMatches($provider, (string)$order['mode']);
    if ($provider === 'razorpay') {
        $response = createRazorpayOrder(
            (float)$order['expected_amount'],
            (string)$order['order_ref'],
            ['payment_order_ref' => $order['order_ref'], 'link_id' => $link['link_id'] ?? '']
        );
        if (!is_array($response) || empty($response['id'])) {
            return null;
        }
        bindProviderOrder((int)$order['id'], 'razorpay', (string)$response['id']);
        $response['_uniweb_order_ref'] = $order['order_ref'];
        return $response;
    }
    if ($provider === 'cashfree') {
        $returnUrl = $cashfreeReturnUrl !== '' ? $cashfreeReturnUrl : APP_URL . '/payment_cashfree_return.php';
        $response = createCashfreeOrder(
            (string)$order['order_ref'],
            (float)$order['expected_amount'],
            (string)($link['customer_phone'] ?? ''),
            (string)($link['customer_email'] ?? ''),
            $returnUrl,
            (string)($link['link_id'] ?? '')
        );
        if (!is_array($response) || empty($response['payment_session_id']) || empty($response['order_id'])) {
            return null;
        }
        bindProviderOrder((int)$order['id'], 'cashfree', (string)$response['order_id']);
        $response['_uniweb_order_ref'] = $order['order_ref'];
        return $response;
    }
    throw new InvalidArgumentException('Unsupported checkout provider.');
}

function registerGatewayEvent(string $provider, string $eventId, string $eventType, string $rawPayload, bool $signatureValid): array
{
    requireFinancialTables();
    $provider = strtolower(trim($provider));
    $eventId = trim($eventId);
    if ($eventId === '') {
        $eventId = 'hash_' . hash('sha256', $rawPayload);
    }
    $hash = hash('sha256', $rawPayload);
    try {
        $st = getDB()->prepare('INSERT INTO gateway_events (provider,event_id,event_type,payload_hash,signature_valid,processing_status) VALUES (?,?,?,?,?,?)');
        $st->execute([$provider, $eventId, $eventType, $hash, $signatureValid ? 1 : 0, $signatureValid ? 'received' : 'rejected']);
        return ['id' => (int)getDB()->lastInsertId(), 'duplicate' => false];
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $st = getDB()->prepare('SELECT id, payload_hash, processing_status FROM gateway_events WHERE provider=? AND event_id=?');
        $st->execute([$provider, $eventId]);
        $row = $st->fetch();
        if (!$row || !hash_equals((string)$row['payload_hash'], $hash)) {
            throw new RuntimeException('Gateway event ID collision.');
        }
        return ['id' => (int)$row['id'], 'duplicate' => true, 'status' => $row['processing_status']];
    }
}

function setGatewayEventStatus(int $eventDbId, string $status, ?int $orderId = null, ?string $error = null): void
{
    if (!in_array($status, ['processed', 'duplicate', 'rejected', 'failed'], true)) {
        throw new InvalidArgumentException('Invalid gateway event status.');
    }
    getDB()->prepare('UPDATE gateway_events SET processing_status=?, payment_order_id=?, error_message=?, processed_at=NOW() WHERE id=?')
        ->execute([$status, $orderId, $error ? mb_substr($error, 0, 500) : null, $eventDbId]);
}

function getOrCreateLedgerAccount(string $code, string $ownerType, ?int $ownerId, string $accountType, string $mode, string $currency = 'INR'): int
{
    $db = getDB();
    $db->prepare('INSERT IGNORE INTO ledger_accounts (account_code,owner_type,owner_id,account_type,currency,mode) VALUES (?,?,?,?,?,?)')
        ->execute([$code, $ownerType, $ownerId, $accountType, $currency, $mode]);
    $st = $db->prepare('SELECT id FROM ledger_accounts WHERE account_code=? AND currency=? AND mode=?');
    $st->execute([$code, $currency, $mode]);
    $id = (int)$st->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('Ledger account is unavailable.');
    }
    return $id;
}

function postBalancedJournal(string $businessType, string $businessReference, string $mode, string $currency, array $entries, string $description = '', array $metadata = []): int
{
    $debits = 0.0;
    $credits = 0.0;
    foreach ($entries as $entry) {
        $amount = round((float)($entry['amount'] ?? 0), 2);
        $side = $entry['side'] ?? '';
        if ($amount <= 0 || !in_array($side, ['debit', 'credit'], true) || empty($entry['account_id'])) {
            throw new InvalidArgumentException('Invalid ledger entry.');
        }
        $side === 'debit' ? $debits += $amount : $credits += $amount;
    }
    if (abs($debits - $credits) > 0.001 || $debits <= 0) {
        throw new RuntimeException('Ledger journal is not balanced.');
    }

    $db = getDB();
    $existing = $db->prepare('SELECT id FROM ledger_journals WHERE business_type=? AND business_reference=? AND mode=?');
    $existing->execute([$businessType, $businessReference, $mode]);
    $existingId = (int)$existing->fetchColumn();
    if ($existingId > 0) {
        return $existingId;
    }

    $journalRef = generateId('JRN');
    $st = $db->prepare('INSERT INTO ledger_journals (journal_ref,business_type,business_reference,mode,currency,description,metadata) VALUES (?,?,?,?,?,?,?)');
    $st->execute([$journalRef, $businessType, $businessReference, $mode, $currency, mb_substr($description, 0, 500), json_encode($metadata, JSON_UNESCAPED_SLASHES)]);
    $journalId = (int)$db->lastInsertId();
    $entryInsert = $db->prepare('INSERT INTO ledger_entries (journal_id,account_id,entry_side,amount) VALUES (?,?,?,?)');
    foreach ($entries as $entry) {
        $entryInsert->execute([$journalId, (int)$entry['account_id'], $entry['side'], round((float)$entry['amount'], 2)]);
    }
    return $journalId;
}

function ledgerAccountBalance(int $accountId): float
{
    $st = getDB()->prepare(
        "SELECT a.account_type,
            COALESCE(SUM(CASE WHEN e.entry_side='debit' THEN e.amount ELSE 0 END),0) AS debits,
            COALESCE(SUM(CASE WHEN e.entry_side='credit' THEN e.amount ELSE 0 END),0) AS credits
         FROM ledger_accounts a LEFT JOIN ledger_entries e ON e.account_id=a.id
         WHERE a.id=? GROUP BY a.id,a.account_type"
    );
    $st->execute([$accountId]);
    $row = $st->fetch();
    if (!$row) {
        return 0.0;
    }
    $debits = (float)$row['debits'];
    $credits = (float)$row['credits'];
    return round(in_array($row['account_type'], ['asset', 'expense'], true) ? $debits - $credits : $credits - $debits, 2);
}

function merchantLedgerBalance(int $merchantId, string $mode): float
{
    $account = getOrCreateLedgerAccount('merchant_payable:' . $merchantId, 'merchant', $merchantId, 'liability', $mode);
    return ledgerAccountBalance($account);
}

function backfillLegacyWalletOpeningBalances(): array
{
    requireFinancialTables();
    $db = getDB();
    $done = $db->prepare('SELECT result_json FROM financial_backfills WHERE backfill_key=?');
    $done->execute(['legacy_wallet_opening_v1']);
    $existing = $done->fetchColumn();
    if ($existing !== false) {
        return json_decode((string)$existing, true) ?: ['already_applied' => true];
    }

    $lock = $db->query("SELECT GET_LOCK('uniweb_legacy_wallet_backfill', 15)")->fetchColumn();
    if ((int)$lock !== 1) {
        throw new RuntimeException('Could not acquire wallet backfill lock.');
    }
    $count = 0;
    $total = 0.0;
    $db->beginTransaction();
    try {
        $rows = $db->query(
            "SELECT m.id, m.account_mode, m.kyc_status, COALESCE(SUM(w.amount),0) AS legacy_balance
             FROM merchants m LEFT JOIN wallet_transactions w ON w.merchant_id=m.id
             GROUP BY m.id,m.account_mode,m.kyc_status"
        )->fetchAll();
        foreach ($rows as $row) {
            $amount = round((float)$row['legacy_balance'], 2);
            if ($amount <= 0) {
                continue;
            }
            $merchantId = (int)$row['id'];
            $mode = isMerchantTest($row) ? 'test' : 'live';
            $merchantAccount = getOrCreateLedgerAccount('merchant_payable:' . $merchantId, 'merchant', $merchantId, 'liability', $mode);
            $clearingAccount = getOrCreateLedgerAccount('platform_clearing', 'platform', null, 'asset', $mode);
            postBalancedJournal(
                'opening_balance',
                'merchant:' . $merchantId,
                $mode,
                'INR',
                [
                    ['account_id' => $clearingAccount, 'side' => 'debit', 'amount' => $amount],
                    ['account_id' => $merchantAccount, 'side' => 'credit', 'amount' => $amount],
                ],
                'Opening balance migrated from legacy wallet transactions',
                ['legacy_balance' => $amount]
            );
            $count++;
            $total += $amount;
        }
        $result = ['merchants' => $count, 'total' => round($total, 2)];
        $db->prepare('INSERT INTO financial_backfills (backfill_key,result_json) VALUES (?,?)')
            ->execute(['legacy_wallet_opening_v1', json_encode($result)]);
        $db->commit();
        return $result;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    } finally {
        $db->query("SELECT RELEASE_LOCK('uniweb_legacy_wallet_backfill')");
    }
}

function postMerchantWalletMovement(
    int $merchantId,
    float $signedAmount,
    string $businessType,
    string $businessReference,
    string $description,
    ?int $legacyTransactionId = null
): array {
    requireFinancialTables();
    $signedAmount = round($signedAmount, 2);
    if ($merchantId <= 0 || abs($signedAmount) < 0.01) {
        throw new InvalidArgumentException('A non-zero wallet movement is required.');
    }
    $db = getDB();
    $merchantSt = $db->prepare('SELECT account_mode, kyc_status FROM merchants WHERE id=?');
    $merchantSt->execute([$merchantId]);
    $merchant = $merchantSt->fetch();
    if (!$merchant) {
        throw new RuntimeException('Merchant not found.');
    }
    $mode = isMerchantTest($merchant) ? 'test' : 'live';
    $merchantAccount = getOrCreateLedgerAccount('merchant_payable:' . $merchantId, 'merchant', $merchantId, 'liability', $mode);
    $clearingAccount = getOrCreateLedgerAccount('platform_clearing', 'platform', null, 'asset', $mode);
    $ownsTransaction = !$db->inTransaction();
    if ($ownsTransaction) {
        $db->beginTransaction();
    }
    try {
        $lock = $db->prepare('SELECT id FROM ledger_accounts WHERE id=? FOR UPDATE');
        $lock->execute([$merchantAccount]);
        $before = ledgerAccountBalance($merchantAccount);
        $existingJournal = $db->prepare('SELECT id FROM ledger_journals WHERE business_type=? AND business_reference=? AND mode=?');
        $existingJournal->execute([$businessType, $businessReference, $mode]);
        $existingJournalId = (int)$existingJournal->fetchColumn();
        if ($existingJournalId > 0) {
            if ($ownsTransaction) {
                $db->commit();
            }
            return ['ok' => true, 'journal_id' => $existingJournalId, 'balance' => $before, 'duplicate' => true];
        }
        if ($signedAmount < 0 && $before + $signedAmount < -0.001) {
            throw new RuntimeException('Insufficient ledger balance.');
        }
        $entries = $signedAmount > 0
            ? [
                ['account_id' => $clearingAccount, 'side' => 'debit', 'amount' => $signedAmount],
                ['account_id' => $merchantAccount, 'side' => 'credit', 'amount' => $signedAmount],
            ]
            : [
                ['account_id' => $merchantAccount, 'side' => 'debit', 'amount' => abs($signedAmount)],
                ['account_id' => $clearingAccount, 'side' => 'credit', 'amount' => abs($signedAmount)],
            ];
        $journalId = postBalancedJournal(
            $businessType,
            $businessReference,
            $mode,
            'INR',
            $entries,
            $description,
            ['merchant_id' => $merchantId, 'legacy_transaction_id' => $legacyTransactionId]
        );
        $after = merchantLedgerBalance($merchantId, $mode);
        $exists = $db->prepare('SELECT id FROM wallet_transactions WHERE merchant_id=? AND reference=? LIMIT 1');
        $exists->execute([$merchantId, $businessReference]);
        if (!$exists->fetchColumn()) {
            $type = $signedAmount > 0 ? 'credit' : ($businessType === 'settlement' ? 'settlement' : 'debit');
            $db->prepare('INSERT INTO wallet_transactions (merchant_id,type,amount,balance_after,reference,description,transaction_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$merchantId, $type, $signedAmount, $after, $businessReference, $description, $legacyTransactionId]);
        }
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$after, $merchantId]);
        if ($ownsTransaction) {
            $db->commit();
        }
        return ['ok' => true, 'journal_id' => $journalId, 'balance' => $after, 'duplicate' => abs($after - $before) < 0.001];
    } catch (Throwable $e) {
        if ($ownsTransaction && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function claimApiIdempotency(int $merchantId, string $mode, string $key, array $request): array
{
    $key = trim($key);
    if ($key === '' || strlen($key) > 100) {
        throw new InvalidArgumentException('A valid Idempotency-Key header is required for write operations.');
    }
    $hash = hash('sha256', json_encode($request, JSON_UNESCAPED_SLASHES));
    $db = getDB();
    try {
        $st = $db->prepare(
            'INSERT INTO api_idempotency_keys
             (merchant_id,mode,idempotency_key,request_hash,locked_until,expires_at)
             VALUES (?,?,?,?,DATE_ADD(NOW(),INTERVAL 30 SECOND),DATE_ADD(NOW(),INTERVAL 24 HOUR))'
        );
        $st->execute([$merchantId, $mode, $key, $hash]);
        return ['id' => (int)$db->lastInsertId(), 'replay' => false];
    } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') {
            throw $e;
        }
        $st = $db->prepare('SELECT * FROM api_idempotency_keys WHERE merchant_id=? AND mode=? AND idempotency_key=?');
        $st->execute([$merchantId, $mode, $key]);
        $row = $st->fetch();
        if (!$row || !hash_equals((string)$row['request_hash'], $hash)) {
            throw new RuntimeException('Idempotency key was reused with a different request.');
        }
        if ($row['completed_at'] !== null) {
            return [
                'id' => (int)$row['id'],
                'replay' => true,
                'response_code' => (int)$row['response_code'],
                'response_body' => (string)$row['response_body'],
            ];
        }
        if ($row['locked_until'] && strtotime((string)$row['locked_until']) > time()) {
            throw new RuntimeException('An identical request is already in progress.');
        }
        $db->prepare('UPDATE api_idempotency_keys SET locked_until=DATE_ADD(NOW(),INTERVAL 30 SECOND) WHERE id=?')->execute([(int)$row['id']]);
        return ['id' => (int)$row['id'], 'replay' => false];
    }
}

function completeApiIdempotency(int $id, int $statusCode, array $response): void
{
    getDB()->prepare('UPDATE api_idempotency_keys SET response_code=?,response_body=?,completed_at=NOW(),locked_until=NULL WHERE id=?')
        ->execute([$statusCode, json_encode($response, JSON_UNESCAPED_SLASHES), $id]);
}

function captureVerifiedPaymentOrder(array $verification): array
{
    requireFinancialTables();
    $required = ['provider', 'provider_order_id', 'provider_payment_id', 'amount', 'currency'];
    foreach ($required as $key) {
        if (!isset($verification[$key]) || $verification[$key] === '') {
            throw new InvalidArgumentException('Missing verification field: ' . $key);
        }
    }
    if (empty($verification['signature_verified']) || empty($verification['provider_verified']) || empty($verification['captured'])) {
        throw new RuntimeException('Provider signature, server verification and capture status are required.');
    }

    $db = getDB();
    $db->beginTransaction();
    $transactionId = 0;
    $link = null;
    try {
        $orderSt = $db->prepare(
            'SELECT o.*, pl.link_id, pl.description AS link_description, pl.link_collection_mode,
                    m.commission_rate, m.collection_mode, m.business_name
             FROM payment_orders o
             JOIN payment_links pl ON pl.id=o.payment_link_id
             JOIN merchants m ON m.id=o.merchant_id
             WHERE o.provider=? AND o.provider_order_id=? FOR UPDATE'
        );
        $orderSt->execute([strtolower((string)$verification['provider']), (string)$verification['provider_order_id']]);
        $order = $orderSt->fetch();
        if (!$order) {
            throw new RuntimeException('No bound payment order matches the provider order.');
        }
        if ($order['status'] === 'paid') {
            $mapped = $db->prepare('SELECT transaction_id FROM payment_order_transactions WHERE payment_order_id=?');
            $mapped->execute([(int)$order['id']]);
            $transactionId = (int)$mapped->fetchColumn();
            $db->commit();
            return ['ok' => true, 'duplicate' => true, 'transaction_id' => $transactionId, 'order' => $order];
        }
        if (!in_array($order['status'], ['created', 'pending', 'authorized'], true)) {
            throw new RuntimeException('Payment order is not payable.');
        }
        if ($order['expires_at'] && strtotime((string)$order['expires_at']) < time()) {
            throw new RuntimeException('Payment order has expired.');
        }
        $amount = round((float)$verification['amount'], 2);
        if (abs($amount - (float)$order['expected_amount']) > 0.001) {
            throw new RuntimeException('Provider amount does not match the bound order.');
        }
        if (strtoupper((string)$verification['currency']) !== strtoupper((string)$order['currency'])) {
            throw new RuntimeException('Provider currency does not match the bound order.');
        }

        $attempt = $db->prepare(
            'INSERT INTO payment_attempts
             (payment_order_id,provider,provider_payment_id,provider_order_id,amount,currency,status,signature_verified,provider_verified,captured,raw_reference,verified_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE status=VALUES(status),signature_verified=VALUES(signature_verified),provider_verified=VALUES(provider_verified),captured=VALUES(captured),verified_at=NOW()'
        );
        $attempt->execute([
            (int)$order['id'],
            strtolower((string)$verification['provider']),
            (string)$verification['provider_payment_id'],
            (string)$verification['provider_order_id'],
            $amount,
            strtoupper((string)$verification['currency']),
            'captured',
            1,
            1,
            1,
            mb_substr((string)($verification['reference'] ?? ''), 0, 190) ?: null,
        ]);

        $link = [
            'id' => (int)$order['payment_link_id'],
            'link_id' => $order['link_id'],
            'merchant_id' => (int)$order['merchant_id'],
            'amount' => $amount,
            'description' => $order['link_description'] ?: $order['description'],
            'commission_rate' => $order['commission_rate'],
            'collection_mode' => $order['link_collection_mode'] ?: $order['collection_mode'],
        ];
        $split = calculateSplitBreakdown($amount, $link);
        $txnRef = generateId('TXN');
        $txnInsert = $db->prepare(
            'INSERT INTO transactions
             (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id,platform_fee,split_amount,is_test,collection_mode,wallet_credited,customer_name,customer_email,customer_phone)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)'
        );
        $txnInsert->execute([
            $txnRef,
            (int)$order['merchant_id'],
            $amount,
            'success',
            strtolower((string)$verification['provider']),
            $link['description'],
            (string)($verification['reference'] ?? $verification['provider_payment_id']),
            (int)$order['payment_link_id'],
            $split['platform_fee'],
            $split['merchant_net'],
            $order['mode'] === 'test' ? 1 : 0,
            $link['collection_mode'] ?: 'platform_pg',
            mb_substr(trim((string)($order['customer_name'] ?? '')), 0, 160) ?: null,
            mb_substr(trim((string)($order['customer_email'] ?? '')), 0, 190) ?: null,
            mb_substr(trim((string)($order['customer_phone'] ?? '')), 0, 32) ?: null,
        ]);
        $transactionId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO payment_order_transactions (payment_order_id,transaction_id) VALUES (?,?)')
            ->execute([(int)$order['id'], $transactionId]);

        $providerAccount = getOrCreateLedgerAccount(
            'provider_receivable:' . strtolower((string)$verification['provider']),
            'provider',
            null,
            'asset',
            $order['mode'],
            $order['currency']
        );
        $merchantAccount = getOrCreateLedgerAccount(
            'merchant_payable:' . (int)$order['merchant_id'],
            'merchant',
            (int)$order['merchant_id'],
            'liability',
            $order['mode'],
            $order['currency']
        );
        $entries = [
            ['account_id' => $providerAccount, 'side' => 'debit', 'amount' => $amount],
            ['account_id' => $merchantAccount, 'side' => 'credit', 'amount' => (float)$split['merchant_net']],
        ];
        if ((float)$split['platform_fee'] > 0) {
            $feeAccount = getOrCreateLedgerAccount('platform_fee_revenue', 'platform', null, 'revenue', $order['mode'], $order['currency']);
            $entries[] = ['account_id' => $feeAccount, 'side' => 'credit', 'amount' => (float)$split['platform_fee']];
        }
        postBalancedJournal(
            'payment_capture',
            (string)$verification['provider'] . ':' . (string)$verification['provider_payment_id'],
            $order['mode'],
            $order['currency'],
            $entries,
            'Verified payment capture for ' . $order['order_ref'],
            ['payment_order_id' => (int)$order['id'], 'transaction_id' => $transactionId]
        );

        $merchantBalance = merchantLedgerBalance((int)$order['merchant_id'], $order['mode']);
        $db->prepare('INSERT INTO wallet_transactions (merchant_id,type,amount,balance_after,reference,description,transaction_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int)$order['merchant_id'], 'credit', (float)$split['merchant_net'], $merchantBalance, $txnRef, 'Verified payment capture', $transactionId]);
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$merchantBalance, (int)$order['merchant_id']]);

        if ((float)$split['platform_fee'] > 0) {
            recordSplitPayment($transactionId, (int)$order['merchant_id'], $split, (string)$verification['provider']);
        }
        $db->prepare("UPDATE payment_orders SET status='paid', paid_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
        $db->prepare("UPDATE payment_links SET status='paid', paid_at=NOW() WHERE id=?")->execute([(int)$order['payment_link_id']]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }

    try {
        addTransactionToSettlementBatch($transactionId, (int)$link['merchant_id']);
        createNotification((int)$link['merchant_id'], 'Payment Received', formatMoney((float)$link['amount']) . ' payment verified.');
    } catch (Throwable $e) {
        logPlatformError('warning', 'Verified payment post-processing failed.', [
            'transaction_id' => $transactionId,
            'error' => $e->getMessage(),
        ]);
    }
    return ['ok' => true, 'duplicate' => false, 'transaction_id' => $transactionId];
}
