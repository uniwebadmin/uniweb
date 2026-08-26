<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

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

/** MySQL DDL (CREATE/ALTER) silently ends the current transaction; never throw on commit. */
function uniwebPdoCommit(PDO $db): void
{
    try {
        if ($db->inTransaction()) {
            $db->commit();
        }
    } catch (Throwable $e) {
        /* already closed by implicit commit */
    }
}

/** Same as commit: rollback after DDL would throw "There is no active transaction". */
function uniwebPdoRollback(PDO $db): void
{
    try {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    } catch (Throwable $e) {
        /* already closed by implicit commit */
    }
}

/** Run CREATE/ALTER before money transactions so Instant Test Pay cannot lose the txn. */
function uniwebPreparePaymentCaptureSchema(): void
{
    if (function_exists('ensurePricingSnapshotColumns')) {
        ensurePricingSnapshotColumns();
    }
    if (!function_exists('ensureSplitSettlementTable') && is_file(__DIR__ . '/split_settlement.php')) {
        require_once __DIR__ . '/split_settlement.php';
    }
    if (function_exists('ensureSplitSettlementTable')) {
        ensureSplitSettlementTable();
    }
    if (!function_exists('ensureAuditLogTable') && is_file(__DIR__ . '/audit_log.php')) {
        require_once __DIR__ . '/audit_log.php';
    }
    if (function_exists('ensureAuditLogTable')) {
        ensureAuditLogTable();
    }
    if (function_exists('ensureErrorCatcher')) {
        ensureErrorCatcher();
    }
    if (function_exists('ensureWalletEngine')) {
        ensureWalletEngine();
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
    if (!providerCredentialsMatchOrderMode($provider, $mode)) {
        throw new RuntimeException(ucfirst($provider) . ' credentials do not match the payment order mode.');
    }
}

function providerCredentialsMatchOrderMode(string $provider, string $mode): bool
{
    $provider = strtolower($provider);
    if ($provider === 'razorpay') {
        $keyId = getPartnerSetting('razorpay', 'razorpay_key_id', '');
        $credentialMode = str_starts_with((string)$keyId, 'rzp_live_') ? 'live' : 'test';
    } elseif ($provider === 'cashfree') {
        $credentialMode = cashfreeActiveCredentialMode();
    } else {
        return true;
    }
    return $credentialMode === $mode;
}

function createBoundGatewayCheckoutOrder(array $link, string $provider, string $cashfreeReturnUrl = ''): ?array
{
    $provider = strtolower($provider);
    $order = createBoundPaymentOrder($link, $provider);
    if (!providerCredentialsMatchOrderMode($provider, (string)$order['mode'])) {
        return null;
    }
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
        uniwebPdoCommit($db);
        return $result;
    } catch (Throwable $e) {
        uniwebPdoRollback($db);
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
                uniwebPdoCommit($db);
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
            uniwebPdoCommit($db);
        }
        return ['ok' => true, 'journal_id' => $journalId, 'balance' => $after, 'duplicate' => abs($after - $before) < 0.001];
    } catch (Throwable $e) {
        if ($ownsTransaction) {
            uniwebPdoRollback($db);
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
    // D10: mask PII before storing response
    $body = json_encode($response, JSON_UNESCAPED_SLASHES);
    if (!function_exists('maskPiiInString') && is_file(__DIR__ . '/partner_payload.php')) {
        require_once __DIR__ . '/partner_payload.php';
    }
    if (function_exists('maskPiiInString')) {
        $body = maskPiiInString($body);
    }
    getDB()->prepare('UPDATE api_idempotency_keys SET response_code=?,response_body=?,completed_at=NOW(),locked_until=NULL WHERE id=?')
        ->execute([$statusCode, $body, $id]);
}

function transactionHasPaymentLedger(int $transactionId, string $txnRef, int $merchantId): bool
{
    $db = getDB();
    $st = $db->prepare(
        "SELECT id FROM ledger_journals WHERE business_type='payment_capture'
         AND (business_reference=? OR business_reference=? OR metadata LIKE ?) LIMIT 1"
    );
    $st->execute([$txnRef, 'txn:' . $txnRef, '%"transaction_id":' . $transactionId . '%']);
    if ((int)$st->fetchColumn() > 0) {
        return true;
    }
    $legacy = $db->prepare(
        "SELECT id FROM ledger_journals WHERE business_type='wallet_credit' AND business_reference=? LIMIT 1"
    );
    $legacy->execute(['merchant:' . $merchantId . ':' . $txnRef]);
    return (int)$legacy->fetchColumn() > 0;
}

/**
 * Chain B primary ledger write — ONE path for payment_capture journal + merchant wallet sync.
 * Idempotent via business_reference = txn_id. Fail-closed: returns ok=false on write error.
 *
 * @param array<string,mixed> $split platform_fee, merchant_net
 * @param array<string,mixed> $metadata extra journal metadata
 * @return array{ok:bool,duplicate?:bool,journal_id?:int,ledger_posted?:bool,error?:string}
 */
function postPrimaryPaymentCaptureLedger(
    int $transactionId,
    string $txnRef,
    int $merchantId,
    float $amount,
    array $split,
    string $provider,
    string $mode,
    string $currency,
    string $description,
    array $metadata = []
): array {
    requireFinancialTables();
    if ($transactionId < 1 || $txnRef === '' || $merchantId < 1) {
        return ['ok' => false, 'error' => 'Invalid payment capture ledger inputs.'];
    }
    if (transactionHasPaymentLedger($transactionId, $txnRef, $merchantId)) {
        return ['ok' => true, 'duplicate' => true, 'ledger_posted' => false];
    }

    try {
        $providerAccount = getOrCreateLedgerAccount(
            'provider_receivable:' . $provider,
            'provider',
            null,
            'asset',
            $mode,
            $currency
        );
        $merchantAccount = getOrCreateLedgerAccount(
            'merchant_payable:' . $merchantId,
            'merchant',
            $merchantId,
            'liability',
            $mode,
            $currency
        );
        $entries = [
            ['account_id' => $providerAccount, 'side' => 'debit', 'amount' => round($amount, 2)],
            ['account_id' => $merchantAccount, 'side' => 'credit', 'amount' => (float)$split['merchant_net']],
        ];
        if ((float)$split['platform_fee'] > 0) {
            $feeAccount = getOrCreateLedgerAccount('platform_fee_revenue', 'platform', null, 'revenue', $mode, $currency);
            $entries[] = ['account_id' => $feeAccount, 'side' => 'credit', 'amount' => (float)$split['platform_fee']];
        }
        $journalId = postBalancedJournal(
            'payment_capture',
            $txnRef,
            $mode,
            $currency,
            $entries,
            $description,
            array_merge(['transaction_id' => $transactionId, 'merchant_id' => $merchantId], $metadata)
        );

        $db = getDB();
        $merchantBalance = merchantLedgerBalance($merchantId, $mode);
        $wtExists = $db->prepare("SELECT id FROM wallet_transactions WHERE transaction_id=? AND type='credit' LIMIT 1");
        $wtExists->execute([$transactionId]);
        if (!$wtExists->fetchColumn()) {
            $db->prepare(
                'INSERT INTO wallet_transactions (merchant_id,type,amount,balance_after,reference,description,transaction_id) VALUES (?,?,?,?,?,?,?)'
            )->execute([
                $merchantId,
                'credit',
                (float)$split['merchant_net'],
                $merchantBalance,
                $txnRef,
                'Payment capture',
                $transactionId,
            ]);
        }
        $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')->execute([$merchantBalance, $merchantId]);
        try {
            $db->prepare('UPDATE transactions SET wallet_credited=1 WHERE id=?')->execute([$transactionId]);
        } catch (Throwable $e) {
            // column may not exist yet
        }

        return ['ok' => true, 'duplicate' => false, 'journal_id' => $journalId, 'ledger_posted' => true];
    } catch (Throwable $e) {
        if (function_exists('logPlatformError')) {
            logPlatformError('error', 'Primary payment_capture ledger failed', [
                'transaction_id' => $transactionId,
                'txn_ref' => $txnRef,
                'error' => $e->getMessage(),
            ]);
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Split record, platform fee wallet, partner route — idempotent where helpers allow. */
function applyPaymentCaptureSplitAndRoute(int $transactionId, int $merchantId, array $split, string $provider, string $feeLabel): void
{
    if ((float)($split['platform_fee'] ?? 0) > 0) {
        if (function_exists('recordSplitPayment')) {
            recordSplitPayment($transactionId, $merchantId, $split, $provider);
        }
        if (function_exists('creditPlatformFeeWallet')) {
            creditPlatformFeeWallet((float)$split['platform_fee'], $transactionId, 'Commission from ' . $feeLabel);
        }
    }
    if (function_exists('executePartnerRouteSplit')) {
        try {
            executePartnerRouteSplit($transactionId, $merchantId, $split, $provider);
        } catch (Throwable $splitEx) {
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'Partner route split deferred', [
                    'transaction_id' => $transactionId,
                    'error' => $splitEx->getMessage(),
                ]);
            }
        }
    }
}

/**
 * Canonical post-success chain: ledger (payment_capture) → wallet → settlement → notify → audit.
 * Idempotent — safe to call from webhooks, checkout, and legacy collection paths.
 */
function finalizeSuccessfulPaymentTransaction(int $transactionId, array $opts = []): array
{
    requireFinancialTables();
    if (!function_exists('ensureWalletEngine') && is_file(__DIR__ . '/wallet.php')) {
        require_once __DIR__ . '/wallet.php';
    }
    ensureWalletEngine();

    $db = getDB();
    $st = $db->prepare(
        'SELECT t.*, m.commission_rate, m.collection_mode FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE t.id=?'
    );
    $st->execute([$transactionId]);
    $txn = $st->fetch();
    if (!$txn || ($txn['status'] ?? '') !== 'success') {
        return ['ok' => false, 'error' => 'Transaction not successful.'];
    }

    $txnRef = (string)($txn['txn_id'] ?? '');
    $merchantId = (int)$txn['merchant_id'];
    $amount = round((float)$txn['amount'], 2);
    $isTest = !empty($txn['is_test']);
    $mode = $isTest ? 'test' : 'live';
    $currency = 'INR';
    $provider = strtolower(trim((string)($opts['provider'] ?? $txn['payment_method'] ?? 'sandbox')));
    if ($provider === '') {
        $provider = 'sandbox';
    }

    $split = [
        'platform_fee' => (float)($txn['platform_fee'] ?? 0),
        'merchant_net' => (float)($txn['split_amount'] ?? 0),
        'mdr_m' => (float)($txn['mdr_m'] ?? 0),
        'mdr_p' => (float)($txn['mdr_p'] ?? 0),
        'partner_fee' => (float)($txn['partner_fee'] ?? 0),
        'pricing_snapshot' => $txn['pricing_snapshot'] ?? null,
    ];
    if ($split['merchant_net'] <= 0 && $split['platform_fee'] <= 0) {
        $link = [
            'merchant_id' => $merchantId,
            'amount' => $amount,
            'commission_rate' => $txn['commission_rate'],
            'collection_mode' => $txn['collection_mode'],
        ];
        $calc = calculateSplitBreakdown($amount, $link);
        $split = array_merge($split, $calc);
    }

    $ledgerPosted = false;
    if (empty($opts['skip_ledger'])) {
        $ledger = postPrimaryPaymentCaptureLedger(
            $transactionId,
            $txnRef,
            $merchantId,
            $amount,
            $split,
            $provider,
            $mode,
            $currency,
            'Payment capture for ' . $txnRef
        );
        if (!$ledger['ok'] && empty($ledger['duplicate'])) {
            return ['ok' => false, 'error' => 'Ledger write failed: ' . ($ledger['error'] ?? 'unknown')];
        }
        $ledgerPosted = !empty($ledger['ledger_posted']);
        if (!$ledgerPosted && !isTransactionWalletCredited($transactionId)) {
            creditWalletsFromTransaction($transactionId);
        }
    } elseif (!isTransactionWalletCredited($transactionId)) {
        creditWalletsFromTransaction($transactionId);
    }

    applyPaymentCaptureSplitAndRoute($transactionId, $merchantId, $split, $provider, $txnRef);

    if (function_exists('addTransactionToSettlementBatch')) {
        try {
            addTransactionToSettlementBatch($transactionId, $merchantId);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    if (!empty($opts['run_risk_hooks'])) {
        if (function_exists('recordTransactionRisk')) {
            recordTransactionRisk(
                $transactionId,
                $merchantId,
                $amount,
                ['email' => (string)($txn['customer_email'] ?? ''), 'phone' => (string)($txn['customer_phone'] ?? '')]
            );
        }
        if (function_exists('evaluateTransactionRiskFull')) {
            evaluateTransactionRiskFull(
                $merchantId,
                $amount,
                ['email' => (string)($txn['customer_email'] ?? ''), 'phone' => (string)($txn['customer_phone'] ?? '')],
                $transactionId
            );
        }
        if (function_exists('recordNodalCollection')) {
            recordNodalCollection($transactionId, $merchantId, $amount, 'Customer collection from ' . ($txn['customer_email'] ?? 'customer'));
        }
        if (function_exists('updateMerchantRiskScore')) {
            updateMerchantRiskScore($merchantId);
        }
        if (function_exists('applyRollingReserveHold')) {
            applyRollingReserveHold($merchantId, $transactionId, $amount);
        }
    }

    if (empty($opts['skip_audit']) && function_exists('uwRecordAuditEvent')) {
        uwRecordAuditEvent('payment_capture', [
            'merchant_id' => $merchantId,
            'actor_type' => 'system',
            'resource_type' => 'transaction',
            'resource_id' => $txnRef,
            'reason' => 'Payment capture finalized for ' . $txnRef,
            'after_state' => [
                'amount' => $amount,
                'merchant_net' => $split['merchant_net'],
                'platform_fee' => $split['platform_fee'],
                'transaction_id' => $transactionId,
                'ledger_posted' => $ledgerPosted,
            ],
        ]);
    }

    if (empty($opts['skip_notify'])) {
        notifyMerchantPaymentCaptured($merchantId, $txn, isset($opts['link_id']) ? (string)$opts['link_id'] : null);
    }

    return ['ok' => true, 'transaction_id' => $transactionId, 'ledger_posted' => $ledgerPosted];
}

/** In-app notify + outbound webhook/email for a verified payment (dedup via pay_txn_* event_key). */
function notifyMerchantPaymentCaptured(int $merchantId, array $txn, ?string $linkId = null): void
{
    $txnRef = (string)($txn['txn_id'] ?? '');
    $amount = (float)($txn['amount'] ?? 0);
    $message = formatMoney($amount) . ' payment verified. ' . $txnRef;
    if ($txnRef === '') {
        $message = formatMoney($amount) . ' payment verified.';
    }
    if (function_exists('notifyMerchant')) {
        notifyMerchant($merchantId, 'Payment Received', $message, 'pay_txn_' . ($txnRef !== '' ? $txnRef : ('id' . (int)($txn['id'] ?? 0))));
    } elseif (function_exists('createNotification')) {
        createNotification($merchantId, 'Payment Received', $message, 'pay_txn_' . $txnRef);
    }
    if (!function_exists('notifyMerchantPaymentSuccess') && is_file(__DIR__ . '/merchant_webhooks.php')) {
        require_once __DIR__ . '/merchant_webhooks.php';
    }
    if (function_exists('notifyMerchantPaymentSuccess')) {
        notifyMerchantPaymentSuccess($merchantId, $txn, $linkId);
    }
    if (function_exists('sendTemplatedEmail')) {
        sendTemplatedEmail($merchantId, 'payment_received', [
            'amount' => formatMoney($amount),
            'txn_id' => $txnRef,
        ]);
    }
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
    uniwebPreparePaymentCaptureSchema();
    $db->beginTransaction();
    $transactionId = 0;
    $link = null;
    try {
        $orderSt = $db->prepare(
            'SELECT o.*, pl.link_id, pl.description AS link_description, pl.link_collection_mode, pl.qr_code_id,
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
            uniwebPdoCommit($db);
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
        $txnValues = [
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
            (int)($order['qr_code_id'] ?? 0) > 0 ? (int)$order['qr_code_id'] : null,
        ];
        try {
            $db->prepare(
                'INSERT INTO transactions
                 (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id,platform_fee,split_amount,is_test,collection_mode,wallet_credited,customer_name,customer_email,customer_phone,qr_code_id,mdr_m,mdr_p,partner_fee,pricing_snapshot)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?,?,?)'
            )->execute(array_merge($txnValues, [
                (float)($split['mdr_m'] ?? 0),
                (float)($split['mdr_p'] ?? 0),
                (float)($split['partner_fee'] ?? 0),
                $split['pricing_snapshot'] ?? null,
            ]));
        } catch (Throwable $e) {
            try {
                $db->prepare(
                    'INSERT INTO transactions
                     (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id,platform_fee,split_amount,is_test,collection_mode,wallet_credited,customer_name,customer_email,customer_phone,qr_code_id)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?,?)'
                )->execute($txnValues);
            } catch (Throwable $e2) {
                $db->prepare(
                    'INSERT INTO transactions
                     (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id)
                     VALUES (?,?,?,?,?,?,?,?)'
                )->execute([
                    $txnRef,
                    (int)$order['merchant_id'],
                    $amount,
                    'success',
                    strtolower((string)$verification['provider']),
                    $link['description'],
                    (string)($verification['reference'] ?? $verification['provider_payment_id']),
                    (int)$order['payment_link_id'],
                ]);
            }
        }
        $transactionId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO payment_order_transactions (payment_order_id,transaction_id) VALUES (?,?)')
            ->execute([(int)$order['id'], $transactionId]);

        $ledger = postPrimaryPaymentCaptureLedger(
            $transactionId,
            $txnRef,
            (int)$order['merchant_id'],
            $amount,
            $split,
            strtolower((string)$verification['provider']),
            (string)$order['mode'],
            (string)$order['currency'],
            'Verified payment capture for ' . $order['order_ref'],
            ['payment_order_id' => (int)$order['id']]
        );
        if (!$ledger['ok'] && empty($ledger['duplicate'])) {
            throw new RuntimeException('Ledger write failed — payment not marked settled: ' . ($ledger['error'] ?? 'unknown'));
        }

        applyPaymentCaptureSplitAndRoute(
            $transactionId,
            (int)$order['merchant_id'],
            $split,
            (string)$verification['provider'],
            (string)$order['order_ref']
        );

        $db->prepare("UPDATE payment_orders SET status='paid', paid_at=NOW() WHERE id=?")->execute([(int)$order['id']]);
        $db->prepare("UPDATE payment_links SET status='paid', paid_at=NOW() WHERE id=?")->execute([(int)$order['payment_link_id']]);

        uwRecordAuditEvent('payment_capture', [
            'merchant_id' => (int)$order['merchant_id'],
            'actor_type' => 'system',
            'resource_type' => 'transaction',
            'resource_id' => (string)$txnRef,
            'reason' => 'Verified payment capture for ' . $order['order_ref'],
            'after_state' => ['amount' => $amount, 'merchant_net' => $split['merchant_net'], 'platform_fee' => $split['platform_fee'], 'transaction_id' => $transactionId],
        ]);

        uniwebPdoCommit($db);
    } catch (Throwable $e) {
        uniwebPdoRollback($db);
        throw $e;
    }

    try {
        addTransactionToSettlementBatch($transactionId, (int)$link['merchant_id']);
        $txnSt = getDB()->prepare('SELECT * FROM transactions WHERE id=?');
        $txnSt->execute([$transactionId]);
        $txnRow = $txnSt->fetch() ?: [];
        notifyMerchantPaymentCaptured(
            (int)$link['merchant_id'],
            $txnRow,
            isset($link['link_id']) ? (string)$link['link_id'] : null
        );
    } catch (Throwable $e) {
        logPlatformError('warning', 'Verified payment post-processing failed.', [
            'transaction_id' => $transactionId,
            'error' => $e->getMessage(),
        ]);
    }
    return ['ok' => true, 'duplicate' => false, 'transaction_id' => $transactionId];
}

/**
 * Persist a partner payment failure onto the bound order + merchant-visible txn.
 * Maps error_code → clear English via mapGatewayFailureReason(); never invents bank stories.
 *
 * Expected keys: provider, provider_order_id; optional provider_payment_id, error_code,
 * error_description / failure_reason, amount, currency, reference.
 */
function recordPaymentOrderFailure(array $payload): array
{
    requireFinancialTables();
    if (!function_exists('ensureFailureReasonColumns')) {
        // no-op if schema helper not loaded
    } else {
        ensureFailureReasonColumns();
    }
    $provider = strtolower(trim((string)($payload['provider'] ?? '')));
    $providerOrderId = trim((string)($payload['provider_order_id'] ?? ''));
    if ($provider === '' || $providerOrderId === '') {
        throw new InvalidArgumentException('provider and provider_order_id are required to record a payment failure.');
    }

    $errorCode = isset($payload['error_code']) ? (string)$payload['error_code'] : null;
    $rawMessage = (string)($payload['error_description']
        ?? $payload['failure_reason']
        ?? $payload['failure_message']
        ?? $payload['error_message']
        ?? '');
    if (($errorCode === null || $errorCode === '') && function_exists('extractGatewayErrorFields')) {
        [$extractedCode, $extractedMsg] = extractGatewayErrorFields($payload);
        $errorCode = $extractedCode;
        if ($rawMessage === '' && $extractedMsg) {
            $rawMessage = $extractedMsg;
        }
    }
    $reason = function_exists('mapGatewayFailureReason')
        ? mapGatewayFailureReason($errorCode, $rawMessage !== '' ? $rawMessage : null)
        : ($rawMessage !== '' ? mb_substr($rawMessage, 0, 500) : 'Technical issue from bank side. Please try again later.');
    $reason = mb_substr($reason, 0, 500);
    $providerPaymentId = trim((string)($payload['provider_payment_id'] ?? $payload['reference'] ?? ''));
    if ($providerPaymentId === '') {
        $providerPaymentId = 'fail:' . $providerOrderId;
    }

    $db = getDB();
    uniwebPreparePaymentCaptureSchema();
    $db->beginTransaction();
    $transactionId = 0;
    try {
        $orderSt = $db->prepare(
            'SELECT o.*, pl.link_id, pl.description AS link_description, pl.link_collection_mode, pl.qr_code_id,
                    m.commission_rate, m.collection_mode
             FROM payment_orders o
             JOIN payment_links pl ON pl.id=o.payment_link_id
             JOIN merchants m ON m.id=o.merchant_id
             WHERE o.provider=? AND o.provider_order_id=? FOR UPDATE'
        );
        $orderSt->execute([$provider, $providerOrderId]);
        $order = $orderSt->fetch();
        if (!$order) {
            throw new RuntimeException('No bound payment order matches the provider order.');
        }

        // Never overwrite a successful capture.
        if ($order['status'] === 'paid') {
            $mapped = $db->prepare('SELECT transaction_id FROM payment_order_transactions WHERE payment_order_id=?');
            $mapped->execute([(int)$order['id']]);
            $transactionId = (int)$mapped->fetchColumn();
            uniwebPdoCommit($db);
            return ['ok' => true, 'ignored' => true, 'reason' => 'already_paid', 'transaction_id' => $transactionId];
        }

        $amount = isset($payload['amount']) ? round((float)$payload['amount'], 2) : (float)$order['expected_amount'];
        $currency = strtoupper((string)($payload['currency'] ?? $order['currency'] ?? 'INR'));

        $attempt = $db->prepare(
            'INSERT INTO payment_attempts
             (payment_order_id,provider,provider_payment_id,provider_order_id,amount,currency,status,signature_verified,provider_verified,captured,failure_code,failure_message,raw_reference,verified_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE status=VALUES(status),failure_code=VALUES(failure_code),failure_message=VALUES(failure_message),verified_at=NOW()'
        );
        $attempt->execute([
            (int)$order['id'],
            $provider,
            mb_substr($providerPaymentId, 0, 120),
            $providerOrderId,
            $amount,
            $currency,
            'failed',
            !empty($payload['signature_verified']) ? 1 : 0,
            !empty($payload['provider_verified']) ? 1 : 0,
            0,
            $errorCode !== null && $errorCode !== '' ? mb_substr(normalizeGatewayErrorCode($errorCode) ?: (string)$errorCode, 0, 100) : null,
            $reason,
            mb_substr((string)($payload['reference'] ?? $providerPaymentId), 0, 190) ?: null,
        ]);

        $db->prepare("UPDATE payment_orders SET status='failed' WHERE id=? AND status IN ('created','pending','authorized','failed')")
            ->execute([(int)$order['id']]);

        $mapped = $db->prepare('SELECT transaction_id FROM payment_order_transactions WHERE payment_order_id=?');
        $mapped->execute([(int)$order['id']]);
        $existingTxnId = (int)$mapped->fetchColumn();
        if ($existingTxnId > 0) {
            $db->prepare("UPDATE transactions SET status='failed', failure_reason=? WHERE id=? AND status IN ('pending','processing','initiated','failed')")
                ->execute([$reason, $existingTxnId]);
            $transactionId = $existingTxnId;
            uniwebPdoCommit($db);
            return ['ok' => true, 'duplicate' => true, 'transaction_id' => $transactionId, 'failure_reason' => $reason];
        }

        $txnRef = generateId('TXN');
        $collectionMode = $order['link_collection_mode'] ?: $order['collection_mode'] ?: 'platform_pg';
        $txnInsert = $db->prepare(
            'INSERT INTO transactions
             (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id,platform_fee,split_amount,is_test,collection_mode,wallet_credited,customer_name,customer_email,customer_phone,failure_reason,qr_code_id)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?)'
        );
        try {
            $txnInsert->execute([
                $txnRef,
                (int)$order['merchant_id'],
                $amount,
                'failed',
                $provider,
                $order['link_description'] ?: $order['description'],
                mb_substr($providerPaymentId, 0, 64),
                (int)$order['payment_link_id'],
                0,
                0,
                $order['mode'] === 'test' ? 1 : 0,
                $collectionMode,
                mb_substr(trim((string)($order['customer_name'] ?? '')), 0, 160) ?: null,
                mb_substr(trim((string)($order['customer_email'] ?? '')), 0, 190) ?: null,
                mb_substr(trim((string)($order['customer_phone'] ?? '')), 0, 32) ?: null,
                $reason,
                (int)($order['qr_code_id'] ?? 0) > 0 ? (int)$order['qr_code_id'] : null,
            ]);
        } catch (Throwable $e) {
            // Older DBs without failure_reason column — insert without it, then best-effort UPDATE.
            $txnInsert2 = $db->prepare(
                'INSERT INTO transactions
                 (txn_id,merchant_id,amount,status,payment_method,description,utr,payment_link_id,platform_fee,split_amount,is_test,collection_mode,wallet_credited,customer_name,customer_email,customer_phone)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)'
            );
            $txnInsert2->execute([
                $txnRef,
                (int)$order['merchant_id'],
                $amount,
                'failed',
                $provider,
                $order['link_description'] ?: $order['description'],
                mb_substr($providerPaymentId, 0, 64),
                (int)$order['payment_link_id'],
                0,
                0,
                $order['mode'] === 'test' ? 1 : 0,
                $collectionMode,
                mb_substr(trim((string)($order['customer_name'] ?? '')), 0, 160) ?: null,
                mb_substr(trim((string)($order['customer_email'] ?? '')), 0, 190) ?: null,
                mb_substr(trim((string)($order['customer_phone'] ?? '')), 0, 32) ?: null,
            ]);
            try {
                $db->prepare('UPDATE transactions SET failure_reason=? WHERE txn_id=?')->execute([$reason, $txnRef]);
            } catch (Throwable $ignored) { /* column missing */ }
        }
        $transactionId = (int)$db->lastInsertId();
        $db->prepare('INSERT INTO payment_order_transactions (payment_order_id,transaction_id) VALUES (?,?)')
            ->execute([(int)$order['id'], $transactionId]);
        uniwebPdoCommit($db);
    } catch (Throwable $e) {
        uniwebPdoRollback($db);
        throw $e;
    }

    try {
        createNotification(
            (int)$order['merchant_id'],
            'Payment Failed',
            formatMoney($amount) . ' payment failed — ' . $reason
        );
    } catch (Throwable $e) {
        // non-fatal
    }

    return ['ok' => true, 'duplicate' => false, 'transaction_id' => $transactionId, 'failure_reason' => $reason];
}

// ──────────────────────────────────────────────────────────────────────────────
// A1: Ledger State Machine — strict state transitions + admin rebuild tool
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Allowed ledger state transitions for merchant money.
 * States: available → in_transit → settled (success path)
 *         in_transit → available (reversal / failed payout)
 *         available → hold (rolling reserve / dispute hold)
 *         hold → available (release)
 */
function getAllowedLedgerTransitions(): array
{
    return [
        'capture'         => ['available'],      // +available
        'settle_request'  => ['available', 'in_transit'],  // available → in_transit
        'settle_success'  => ['in_transit', 'settled'],    // in_transit → settled
        'settle_fail'     => ['in_transit', 'available'],   // in_transit → available (reversal)
        'payout_request'  => ['available', 'in_transit'],
        'payout_success'  => ['in_transit', 'settled'],
        'payout_fail'     => ['in_transit', 'available'],
        'hold'            => ['available', 'hold'],
        'release_hold'    => ['hold', 'available'],
        'commission_cut'  => ['available'],      // platform fee at capture
        'refund'          => ['available', 'in_transit'],   // reverse from available
        'chargeback'      => ['available', 'hold'],
    ];
}

/**
 * Validate that a ledger state transition is allowed.
 * Throws if the transition is not in the allowed list.
 */
function validateLedgerTransition(string $action, string $fromState, string $toState): void
{
    $transitions = getAllowedLedgerTransitions();
    if (!isset($transitions[$action])) {
        throw new InvalidArgumentException("Unknown ledger action: {$action}");
    }
    $allowed = $transitions[$action];
    if (!in_array($fromState, $allowed, true)) {
        throw new RuntimeException(
            "Invalid ledger transition: action={$action}, from={$fromState}, to={$toState}. "
            . "Expected from-state to be one of: " . implode(', ', $allowed)
        );
    }
}

/**
 * Get merchant balance breakdown by state (available, in_transit, hold, settled).
 * Reads from ledger entries — never from ad-hoc SUM of payments.
 */
function getMerchantBalanceBreakdown(int $merchantId, string $mode = 'live'): array
{
    requireFinancialTables();
    $merchantAccount = getOrCreateLedgerAccount('merchant_payable:' . $merchantId, 'merchant', $merchantId, 'liability', $mode);
    $totalBalance = ledgerAccountBalance($merchantAccount);

    // Calculate in_transit (pending settlements)
    $inTransit = 0.0;
    try {
        $st = getDB()->prepare("SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE merchant_id=? AND status IN ('pending','processing') AND is_test=?");
        $st->execute([$merchantId, $mode === 'test' ? 1 : 0]);
        $inTransit = (float)$st->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    // Calculate hold (pending transactions + rolling reserve)
    $hold = 0.0;
    try {
        $st = getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE merchant_id=? AND status='pending' AND is_test=?");
        $st->execute([$merchantId, $mode === 'test' ? 1 : 0]);
        $hold += (float)$st->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    try {
        $st = getDB()->prepare("SELECT COALESCE(SUM(amount),0) FROM rolling_reserve_holds WHERE merchant_id=? AND status='active'");
        $st->execute([$merchantId]);
        $hold += (float)$st->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    $available = max(0.0, $totalBalance - $inTransit - $hold);
    $settled = 0.0;
    try {
        $st = getDB()->prepare("SELECT COALESCE(SUM(net_amount),0) FROM settlements WHERE merchant_id=? AND status='success' AND is_test=?");
        $st->execute([$merchantId, $mode === 'test' ? 1 : 0]);
        $settled = (float)$st->fetchColumn();
    } catch (Throwable $e) { /* ok */ }

    return [
        'total'      => round($totalBalance, 2),
        'available'  => round($available, 2),
        'in_transit' => round($inTransit, 2),
        'hold'       => round($hold, 2),
        'settled'    => round($settled, 2),
        'mode'       => $mode,
    ];
}

/**
 * Admin tool: rebuild merchant balance from ledger entries.
 * Recalculates wallet_balance from ledger_journals and updates the merchants table.
 * Returns the rebuilt balance and a diff from the stored value.
 */
function rebuildMerchantBalanceFromLedger(int $merchantId): array
{
    requireFinancialTables();
    $db = getDB();

    $merchantSt = $db->prepare('SELECT id, account_mode, kyc_status, wallet_balance FROM merchants WHERE id=?');
    $merchantSt->execute([$merchantId]);
    $merchant = $merchantSt->fetch();
    if (!$merchant) {
        throw new RuntimeException('Merchant not found.');
    }

    $mode = isMerchantTest($merchant) ? 'test' : 'live';
    $oldBalance = (float)$merchant['wallet_balance'];

    // Recalculate from ledger
    $newBalance = merchantLedgerBalance($merchantId, $mode);

    // Update merchants table
    $db->prepare('UPDATE merchants SET wallet_balance=? WHERE id=?')
        ->execute([$newBalance, $merchantId]);

    // Log the rebuild
    try {
        $db->prepare('INSERT INTO platform_errors (error_type, error_message, context_json, created_at) VALUES (?,?,?,NOW())')
            ->execute([
                'ledger_rebuild',
                'Admin rebuilt balance for merchant #' . $merchantId,
                json_encode(['merchant_id' => $merchantId, 'old' => $oldBalance, 'new' => $newBalance, 'mode' => $mode]),
            ]);
    } catch (Throwable $e) { /* ok */ }

    uwRecordAuditEvent('balance_rebuild', [
        'merchant_id' => $merchantId,
        'actor_type' => 'admin',
        'resource_type' => 'merchant_balance',
        'resource_id' => (string)$merchantId,
        'reason' => 'Admin rebuilt balance from ledger',
        'before_state' => ['wallet_balance' => $oldBalance],
        'after_state' => ['wallet_balance' => $newBalance, 'mode' => $mode],
    ]);

    return [
        'merchant_id'  => $merchantId,
        'mode'         => $mode,
        'old_balance'  => round($oldBalance, 2),
        'new_balance'  => round($newBalance, 2),
        'diff'         => round($newBalance - $oldBalance, 2),
        'breakdown'    => getMerchantBalanceBreakdown($merchantId, $mode),
    ];
}

/**
 * Admin tool: rebuild balances for ALL merchants.
 */
function rebuildAllMerchantBalancesFromLedger(): array
{
    requireFinancialTables();
    $db = getDB();
    $merchants = $db->query('SELECT id FROM merchants ORDER BY id')->fetchAll();
    $results = [];
    $totalDiff = 0.0;
    foreach ($merchants as $m) {
        try {
            $r = rebuildMerchantBalanceFromLedger((int)$m['id']);
            $results[] = $r;
            $totalDiff += abs($r['diff']);
        } catch (Throwable $e) {
            $results[] = ['merchant_id' => (int)$m['id'], 'error' => $e->getMessage()];
        }
    }
    return [
        'merchants_checked' => count($merchants),
        'total_abs_diff'    => round($totalDiff, 2),
        'details'           => $results,
    ];
}

// ──────────────────────────────────────────────────────────────────────────────
// B2: Payment Order Status Machine — Created → Pending → Paid/Failed/Expired
// ──────────────────────────────────────────────────────────────────────────────

function getAllowedPaymentOrderTransitions(): array
{
    return [
        'created'  => ['pending', 'paid', 'failed', 'expired'],
        'pending'  => ['paid', 'failed', 'expired', 'authorized'],
        'authorized' => ['paid', 'failed', 'expired'],
        'paid'     => [],  // terminal
        'failed'   => ['pending'],  // allow retry
        'expired'  => [],  // terminal
    ];
}

function validatePaymentOrderTransition(string $fromStatus, string $toStatus): void
{
    $allowed = getAllowedPaymentOrderTransitions();
    $from = strtolower(trim($fromStatus));
    $to = strtolower(trim($toStatus));
    if (!isset($allowed[$from])) {
        throw new RuntimeException("Unknown payment order status: {$from}");
    }
    if (!in_array($to, $allowed[$from], true)) {
        throw new RuntimeException("Invalid payment order transition: {$from} → {$to}");
    }
}

/**
 * Update payment order status with transition validation.
 */
function updatePaymentOrderStatus(int $orderId, string $newStatus, ?string $reason = null): void
{
    $db = getDB();
    $st = $db->prepare('SELECT status FROM payment_orders WHERE id=?');
    $st->execute([$orderId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException("Payment order #{$orderId} not found.");
    }
    $currentStatus = strtolower(trim($row['status']));
    $newStatus = strtolower(trim($newStatus));
    if ($currentStatus === $newStatus) {
        return; // no-op
    }
    validatePaymentOrderTransition($currentStatus, $newStatus);

    $sql = "UPDATE payment_orders SET status=? WHERE id=?";
    $params = [$newStatus, $orderId];
    if ($newStatus === 'expired') {
        $sql = "UPDATE payment_orders SET status=?, expired_at=NOW() WHERE id=?";
    } elseif ($newStatus === 'paid') {
        $sql = "UPDATE payment_orders SET status=?, paid_at=COALESCE(paid_at, NOW()) WHERE id=?";
    }
    $db->prepare($sql)->execute($params);

    uwRecordAuditEvent('order_status_change', [
        'resource_type' => 'payment_order',
        'resource_id' => (string)$orderId,
        'reason' => $reason ?? "Status: {$currentStatus} → {$newStatus}",
        'before_state' => ['status' => $currentStatus],
        'after_state' => ['status' => $newStatus],
    ]);
}

/**
 * Auto-expire stale payment orders past their expires_at.
 * Called from cron / auto_audit.
 */
function expireStalePaymentOrders(): array
{
    $db = getDB();
    $expired = 0;
    $errors = [];

    try {
        $st = $db->prepare(
            "SELECT id, order_ref, status, expires_at FROM payment_orders
             WHERE status IN ('created', 'pending', 'authorized')
             AND expires_at IS NOT NULL
             AND expires_at < NOW()
             LIMIT 200"
        );
        $st->execute();
        $staleOrders = $st->fetchAll();

        foreach ($staleOrders as $order) {
            try {
                updatePaymentOrderStatus((int)$order['id'], 'expired', 'Auto-expired: past expires_at');
                $expired++;
            } catch (Throwable $e) {
                $errors[] = ['order_id' => (int)$order['id'], 'error' => $e->getMessage()];
            }
        }
    } catch (Throwable $e) {
        $errors[] = ['error' => $e->getMessage()];
    }

    return ['expired' => $expired, 'errors' => $errors];
}

/**
 * Enforce non-negative available balance before any debit.
 * Call before settlement/payout/refund operations.
 */
function enforceSufficientAvailableBalance(int $merchantId, float $amount, string $mode = 'live'): void
{
    $breakdown = getMerchantBalanceBreakdown($merchantId, $mode);
    if ($breakdown['available'] < $amount - 0.001) {
        throw new RuntimeException(
            'Insufficient available balance. Required: ' . formatMoney($amount)
            . ', Available: ' . formatMoney($breakdown['available'])
            . ', In-transit: ' . formatMoney($breakdown['in_transit'])
            . ', Hold: ' . formatMoney($breakdown['hold'])
        );
    }
}

// ──────────────────────────────────────────────────────────────────────────────
// B6: Test/Live Isolation — enforce test mode never mixes with live money
// ──────────────────────────────────────────────────────────────────────────────

/**
 * Check if a merchant is in test mode.
 */
function isMerchantTestMode(int $merchantId): bool
{
    try {
        $st = getDB()->prepare('SELECT account_mode, email FROM merchants WHERE id=?');
        $st->execute([$merchantId]);
        $m = $st->fetch();
        if (!$m) return false;
        if (strcasecmp((string)($m['account_mode'] ?? ''), 'test') === 0) return true;
        if (strcasecmp((string)($m['email'] ?? ''), 'demo@uniweb.co.in') === 0) return true;
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * B6: Enforce that a transaction's test/live flag matches the merchant's mode.
 */
function enforceTestLiveIsolation(int $merchantId, bool $isTestTransaction): void
{
    $merchantIsTest = isMerchantTestMode($merchantId);
    if ($merchantIsTest && !$isTestTransaction) {
        throw new RuntimeException(
            'Test mode merchant cannot create live transactions. Merchant ID: ' . $merchantId
        );
    }
    if (!$merchantIsTest && $isTestTransaction) {
        throw new RuntimeException(
            'Live mode merchant cannot create test transactions. Merchant ID: ' . $merchantId
        );
    }
}

/**
 * B6: Verify that a settlement is in the correct mode.
 */
function enforceSettlementModeIsolation(int $merchantId, string $mode): void
{
    $merchantIsTest = isMerchantTestMode($merchantId);
    $isTestMode = ($mode === 'test');
    if ($merchantIsTest && !$isTestMode) {
        throw new RuntimeException('Test merchant cannot perform live settlement.');
    }
    if (!$merchantIsTest && $isTestMode) {
        throw new RuntimeException('Live merchant cannot perform test settlement.');
    }
}

/**
 * Mark sandbox Instant Test Pay rows as is_test=1 when the merchant is still in Test.
 * Live merchants keeping old test rows is expected — they went Live after sandbox.
 */
function healTestLiveIsolationFlags(): int
{
    try {
        return (int)getDB()->exec(
            "UPDATE transactions t
             JOIN merchants m ON m.id = t.merchant_id
             SET t.is_test = 1
             WHERE COALESCE(t.is_test, 0) = 0
             AND (m.account_mode = 'test' OR m.email = 'demo@uniweb.co.in')
             AND (
                t.utr LIKE 'TEST%'
                OR t.utr LIKE 'SIM%'
                OR t.utr LIKE 'PG-TEST%'
                OR t.payment_method IN ('sandbox', 'test', 'instant')
             )"
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * B6: Audit check — real mix only. Do not alarm because a Live merchant still has older Test payments.
 */
function auditTestLiveIsolation(): array
{
    $db = getDB();
    $violations = [];
    healTestLiveIsolationFlags();

    try {
        $st = $db->query(
            "SELECT t.id, t.merchant_id, t.txn_id, t.is_test, m.account_mode, m.email
             FROM transactions t
             JOIN merchants m ON m.id = t.merchant_id
             WHERE COALESCE(t.is_test, 0) = 0
             AND t.status = 'success'
             AND (m.account_mode = 'test' OR m.email = 'demo@uniweb.co.in')
             AND NOT (
                t.utr LIKE 'TEST%'
                OR t.utr LIKE 'SIM%'
                OR t.utr LIKE 'PG-TEST%'
                OR t.payment_method IN ('sandbox', 'test', 'instant')
             )
             LIMIT 50"
        );
        foreach ($st->fetchAll() as $row) {
            $violations[] = [
                'type' => 'test_merchant_live_txn',
                'transaction_id' => (int)$row['id'],
                'merchant_id' => (int)$row['merchant_id'],
                'txn_id' => $row['txn_id'],
                'detail' => 'Test merchant has a live (non-sandbox) success payment',
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $db->query(
            "SELECT s.id, s.settlement_id, s.merchant_id, s.amount, m.account_mode
             FROM settlements s
             JOIN merchants m ON m.id = s.merchant_id
             WHERE m.account_mode = 'test' AND s.amount > 100
             AND COALESCE(s.utr, '') NOT LIKE 'SIM%'
             AND COALESCE(s.utr, '') NOT LIKE 'PG-TEST%'
             AND COALESCE(s.api_status, '') NOT IN ('simulated', 'sandbox')
             LIMIT 50"
        );
        foreach ($st->fetchAll() as $row) {
            $violations[] = [
                'type' => 'test_merchant_large_settlement',
                'settlement_id' => $row['settlement_id'],
                'merchant_id' => (int)$row['merchant_id'],
                'amount' => (float)$row['amount'],
                'detail' => 'Test merchant has a large non-sandbox settlement',
            ];
        }
    } catch (Throwable $e) {}

    return $violations;
}
