<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/release_helpers.php')) {
    require_once __DIR__ . '/release_helpers.php';
}

/**
 * Settlement Engine — Option B (Platform PG pool) & Option C (Axis VA)
 * Live settlement via processMerchantSettlement when gateway keys are configured.
 */

function ensureSettlementEngine(): void
{
    // Schema changes are versioned under migrations/. Request-time DDL is forbidden.
}

function isValidBankTransferReference(string $utr): bool
{
    $utr = strtoupper(trim($utr));
    if (strlen($utr) < 8 || strlen($utr) > 22) {
        return false;
    }
    if (str_starts_with($utr, 'STL')) {
        return false;
    }
    return (bool)preg_match('/^[A-Z0-9]+$/', $utr);
}

function getSettlementBatchIntervals(): array
{
    return [
        60 => 'T+0 style — 1 Hour',
        90 => '1.5 Hours',
        120 => '2 Hours',
        180 => '3 Hours',
        360 => '6 Hours (same-day / T+0)',
        1440 => 'T+1 — 24 Hours (default)',
        2880 => 'T+2 — 48 Hours',
    ];
}

/**
 * PPT settlement cycles Admin decides: T+0 / T+1 / T+2.
 * Bound to existing default_batch_interval_minutes + settlement_cycle settings.
 *
 * @return array<string, array{minutes:int,label:string}>
 */
function getSettlementCycleOptions(): array
{
    return [
        'T+0' => ['minutes' => 60, 'label' => 'T+0 — same day (fast batch)'],
        'T+1' => ['minutes' => 1440, 'label' => 'T+1 — next day (platform default)'],
        'T+2' => ['minutes' => 2880, 'label' => 'T+2 — two days'],
    ];
}

function settlementCycleFromMinutes(int $minutes): string
{
    if ($minutes >= 2880) {
        return 'T+2';
    }
    if ($minutes >= 1440) {
        return 'T+1';
    }
    return 'T+0';
}

function settlementMinutesFromCycle(string $cycle): int
{
    $cycle = strtoupper(trim($cycle));
    $opts = getSettlementCycleOptions();
    return $opts[$cycle]['minutes'] ?? 1440;
}

function normalizeSettlementCycle(string $cycle): string
{
    $cycle = strtoupper(trim($cycle));
    return isset(getSettlementCycleOptions()[$cycle]) ? $cycle : 'T+1';
}

/** Platform default cycle — Owner default is T+1 when unset. */
function getPlatformSettlementCycle(): string
{
    $raw = strtoupper(trim((string)getSetting('settlement_cycle', '')));
    if (isset(getSettlementCycleOptions()[$raw])) {
        return $raw;
    }
    return settlementCycleFromMinutes((int)getSetting('default_batch_interval_minutes', '1440'));
}

function settlementCycleLabel(string $cycle): string
{
    $cycle = normalizeSettlementCycle($cycle);
    return getSettlementCycleOptions()[$cycle]['label'] ?? $cycle;
}

/** Persist cycle + matching batch minutes on existing gateway_settings keys. */
function syncSettlementCycleSetting(string $cycle): void
{
    ensureSettlementEngine();
    $cycle = normalizeSettlementCycle($cycle);
    $minutes = settlementMinutesFromCycle($cycle);
    $db = getDB();
    foreach ([
        'settlement_cycle' => $cycle,
        'default_batch_interval_minutes' => (string)$minutes,
    ] as $k => $v) {
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$k, $v, $v]);
        if (function_exists('clearSettingCache')) {
            clearSettingCache($k);
        }
    }
}

function getSettlementRails(): array
{
    return [
        'platform_pg' => [
            'label' => 'Platform PG Pool (Option B)',
            'short' => 'PayU / Razorpay / Cashfree pool → batched bank payout',
            'api' => 'payu_split / razorpay_route / cashfree_route',
            'ready' => isGatewayConfigured('payu') || isGatewayConfigured('razorpay') || isGatewayConfigured('cashfree'),
        ],
        'axis_va' => [
            'label' => 'Axis Virtual Account (Option C)',
            'short' => 'VA collections → scheduled sweep to merchant bank',
            'api' => 'axis_va',
            'ready' => isGatewayConfigured('axis'),
        ],
        'wallet' => [
            'label' => 'Wallet Transfer (Manual / Test)',
            'short' => 'Internal wallet → merchant bank (demo & test mode)',
            'api' => 'internal',
            'ready' => true,
        ],
    ];
}

function getSettlementModes(): array
{
    return [
        'manual' => 'Manual — Settle when you click the button',
        'scheduled' => 'Scheduled — Auto batch every X hours',
    ];
}

function getPlatformSettlementDefaults(): array
{
    ensureSettlementEngine();
    $cycle = getPlatformSettlementCycle();
    $interval = (int)getSetting('default_batch_interval_minutes', (string)settlementMinutesFromCycle($cycle));
    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = settlementMinutesFromCycle($cycle);
    }
    // Keep cycle and minutes aligned (existing settings bind)
    if (settlementCycleFromMinutes($interval) !== $cycle) {
        $interval = settlementMinutesFromCycle($cycle);
    }
    return [
        'mode' => getSetting('default_settlement_mode', 'manual') === 'scheduled' ? 'scheduled' : 'manual',
        'rail' => (string)getSetting('default_settlement_rail', 'platform_pg'),
        'interval_minutes' => $interval,
        'cycle' => $cycle,
        'cycle_label' => settlementCycleLabel($cycle),
        'batch_enabled' => getSetting('settlement_batch_enabled', '1') === '1',
    ];
}

function getMerchantSettlementPrefs(array $merchant): array
{
    ensureSettlementEngine();
    $platform = getPlatformSettlementDefaults();
    $usePlatform = !isset($merchant['settlement_use_platform_default']) || (int)$merchant['settlement_use_platform_default'] === 1;

    $mode = $usePlatform ? $platform['mode'] : ($merchant['settlement_mode'] ?? 'manual');
    $rail = $usePlatform ? $platform['rail'] : ($merchant['settlement_rail'] ?? 'wallet');
    $interval = $usePlatform ? $platform['interval_minutes'] : (int)($merchant['batch_interval_minutes'] ?? $platform['interval_minutes']);

    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = $platform['interval_minutes'];
    }
    if (!in_array($mode, ['manual', 'scheduled'], true)) {
        $mode = 'manual';
    }
    if (!isset(getSettlementRails()[$rail])) {
        $rail = 'wallet';
    }

    $cycle = $usePlatform ? $platform['cycle'] : settlementCycleFromMinutes($interval);

    return [
        'use_platform_default' => $usePlatform,
        'mode' => $mode,
        'rail' => $rail,
        'interval_minutes' => $interval,
        'interval_label' => getSettlementBatchIntervals()[$interval] ?? settlementCycleLabel($cycle),
        'cycle' => $cycle,
        'cycle_label' => settlementCycleLabel($cycle),
        'next_batch_at' => $merchant['next_batch_at'] ?? null,
        'last_batch_at' => $merchant['last_batch_at'] ?? null,
        'batch_enabled' => $platform['batch_enabled'],
        'status_line' => $mode === 'manual'
            ? ('Cycle ' . $cycle . ' · Manual — you click Settle when ready')
            : ('Cycle ' . $cycle . ' · Scheduled · ' . (getSettlementBatchIntervals()[$interval] ?? $cycle)),
    ];
}

function saveMerchantSettlementPrefs(int $merchantId, array $data): void
{
    ensureSettlementEngine();
    $db = getDB();
    $usePlatform = !empty($data['use_platform_default']) ? 1 : 0;
    $mode = ($data['mode'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual';
    $rail = $data['rail'] ?? 'wallet';
    if (!isset(getSettlementRails()[$rail])) {
        $rail = 'wallet';
    }

    if (!empty($data['settlement_cycle'])) {
        $interval = settlementMinutesFromCycle((string)$data['settlement_cycle']);
    } else {
        $interval = (int)($data['interval_minutes'] ?? getPlatformSettlementDefaults()['interval_minutes']);
    }
    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = settlementMinutesFromCycle('T+1');
    }

    $nextBatch = null;
    if ($mode === 'scheduled') {
        $nextBatch = date('Y-m-d H:i:s', time() + ($interval * 60));
    }

    $db->prepare('UPDATE merchants SET settlement_mode=?, settlement_rail=?, batch_interval_minutes=?, settlement_use_platform_default=?, next_batch_at=? WHERE id=?')
        ->execute([$mode, $rail, $interval, $usePlatform, $nextBatch, $merchantId]);
}

function savePlatformSettlementDefaults(array $data): void
{
    ensureSettlementEngine();
    $db = getDB();
    if (!empty($data['cycle'])) {
        syncSettlementCycleSetting((string)$data['cycle']);
        $interval = settlementMinutesFromCycle(normalizeSettlementCycle((string)$data['cycle']));
    } else {
        $interval = (int)($data['interval_minutes'] ?? settlementMinutesFromCycle('T+1'));
        if (!isset(getSettlementBatchIntervals()[$interval])) {
            $interval = settlementMinutesFromCycle('T+1');
        }
        syncSettlementCycleSetting(settlementCycleFromMinutes($interval));
    }
    $pairs = [
        'default_settlement_mode' => ($data['mode'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual',
        'default_settlement_rail' => $data['rail'] ?? 'platform_pg',
        'default_batch_interval_minutes' => (string)$interval,
        'settlement_batch_enabled' => !empty($data['batch_enabled']) ? '1' : '0',
    ];
    foreach ($pairs as $k => $v) {
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$k, $v, $v]);
        if (function_exists('clearSettingCache')) {
            clearSettingCache($k);
        }
    }
}

function resolveSettlementRailForMerchant(array $merchant): string
{
    $prefs = getMerchantSettlementPrefs($merchant);
    $rail = $prefs['rail'];
    if ($rail === 'platform_pg' || $rail === 'axis_va') {
        if (isMerchantTest($merchant)) {
            return 'wallet';
        }
    }
    return $rail;
}

function getOrCreateOpenBatch(int $merchantId, string $rail): array
{
    ensureSettlementEngine();
    $db = getDB();
    $st = $db->prepare("SELECT * FROM settlement_batches WHERE merchant_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$merchantId]);
    $batch = $st->fetch();
    if ($batch) {
        return $batch;
    }

    $code = generateId('BAT');
    $db->prepare("INSERT INTO settlement_batches (batch_code, merchant_id, settlement_rail, batch_type, status, period_start, scheduled_at) VALUES (?,?,?,?,?,?,?)")
        ->execute([
            $code,
            $merchantId,
            $rail,
            'scheduled',
            'open',
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', time() + 3600),
        ]);
    $id = (int)$db->lastInsertId();
    $st->execute([$merchantId]);
    return $st->fetch() ?: ['id' => $id, 'batch_code' => $code];
}

function addTransactionToSettlementBatch(int $transactionId, int $merchantId): void
{
    ensureSettlementEngine();
    $db = getDB();
    $mst = $db->prepare('SELECT * FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch();
    if (!$merchant) {
        return;
    }

    $prefs = getMerchantSettlementPrefs($merchant);
    if ($prefs['mode'] !== 'scheduled' || !$prefs['batch_enabled']) {
        return;
    }

    $txn = $db->prepare('SELECT id, amount, split_amount, is_test, payment_method, status, settlement_batch_id FROM transactions WHERE id=? AND merchant_id=?');
    $txn->execute([$transactionId, $merchantId]);
    $row = $txn->fetch();
    if (!$row || $row['status'] !== 'success' || !empty($row['settlement_batch_id'])) {
        return;
    }

    $rail = resolveSettlementRailForMerchant($merchant);
    $batch = getOrCreateOpenBatch($merchantId, $rail);
    $batchId = (int)$batch['id'];
    $isTest = !empty($row['is_test']) || isMerchantTest($merchant);
    $cap = walletCreditCap($isTest);
    $amount = min((float)($row['split_amount'] ?? $row['amount']), $cap);

    try {
        $db->prepare('INSERT INTO settlement_batch_items (batch_id, transaction_id, amount, payment_method) VALUES (?,?,?,?)')
            ->execute([$batchId, $transactionId, $amount, $row['payment_method'] ?? 'upi']);
    } catch (Throwable $e) {
        return;
    }

    $db->prepare('UPDATE transactions SET settlement_batch_id=? WHERE id=?')->execute([$batchId, $transactionId]);
    recalculateBatchTotals($batchId);
}

function recalculateBatchTotals(int $batchId): void
{
    $db = getDB();
    $sum = $db->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS t FROM settlement_batch_items WHERE batch_id=?');
    $sum->execute([$batchId]);
    $row = $sum->fetch();
    $count = (int)($row['c'] ?? 0);
    $gross = round((float)($row['t'] ?? 0), 2);
    $fee = 0.0;
    $net = $gross;

    $db->prepare('UPDATE settlement_batches SET txn_count=?, gross_amount=?, fee_amount=?, net_amount=? WHERE id=?')
        ->execute([$count, $gross, $fee, $net, $batchId]);
}

function getMerchantOpenBatch(int $merchantId): ?array
{
    ensureSettlementEngine();
    $st = getDB()->prepare("SELECT * FROM settlement_batches WHERE merchant_id=? AND status='open' ORDER BY id DESC LIMIT 1");
    $st->execute([$merchantId]);
    $batch = $st->fetch();
    return $batch ?: null;
}

function getMerchantBatchHistory(int $merchantId, int $limit = 20): array
{
    ensureSettlementEngine();
    $st = getDB()->prepare('SELECT * FROM settlement_batches WHERE merchant_id=? AND status != ? ORDER BY created_at DESC LIMIT ?');
    $st->bindValue(1, $merchantId, PDO::PARAM_INT);
    $st->bindValue(2, 'open', PDO::PARAM_STR);
    $st->bindValue(3, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}

function getBatchItems(int $batchId): array
{
    ensureSettlementEngine();
    $st = getDB()->prepare('SELECT bi.*, t.txn_id, t.created_at AS txn_at FROM settlement_batch_items bi JOIN transactions t ON bi.transaction_id=t.id WHERE bi.batch_id=? ORDER BY bi.id DESC');
    $st->execute([$batchId]);
    return $st->fetchAll();
}

function merchantsDueForScheduledBatch(): array
{
    ensureSettlementEngine();
    return getDB()->query("SELECT * FROM merchants WHERE settlement_mode='scheduled' OR (settlement_use_platform_default=1 AND '" . getSetting('default_settlement_mode', 'manual') . "'='scheduled')")->fetchAll();
}

function isMerchantDueForBatch(array $merchant): bool
{
    $prefs = getMerchantSettlementPrefs($merchant);
    if ($prefs['mode'] !== 'scheduled') {
        return false;
    }
    $next = $merchant['next_batch_at'] ?? null;
    if (!$next) {
        // First run: respect vertical delay so brand-new merchants are not swept instantly.
        if (function_exists('merchantSettlementDelayMinutes')) {
            $created = strtotime((string)($merchant['created_at'] ?? '')) ?: 0;
            $delaySec = merchantSettlementDelayMinutes($merchant) * 60;
            if ($created > 0 && (time() - $created) < $delaySec) {
                return false;
            }
        }
        return true;
    }
    return strtotime($next) <= time();
}

function scheduleNextBatch(int $merchantId, int $intervalMinutes): void
{
    $nextTs = time() + ($intervalMinutes * 60);

    if (!function_exists('isBankHoliday')) {
        require_once __DIR__ . '/bank_holidays.php';
    }

    while (isBankHoliday(date('Y-m-d', $nextTs))) {
        $nextTs = strtotime('+1 day 09:00', $nextTs);
    }

    getDB()->prepare('UPDATE merchants SET next_batch_at=?, last_batch_at=NOW() WHERE id=?')
        ->execute([date('Y-m-d H:i:s', $nextTs), $merchantId]);
}

/**
 * Close open batch and create bank settlement (wallet rail now; API stub for B/C).
 */
function closeBatchAndSettle(int $merchantId, string $batchType = 'scheduled'): array
{
    ensureSettlementEngine();
    $db = getDB();
    $mst = $db->prepare('SELECT * FROM merchants WHERE id=?');
    $mst->execute([$merchantId]);
    $merchant = $mst->fetch();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    $batch = getMerchantOpenBatch($merchantId);
    if (!$batch || (int)$batch['txn_count'] < 1) {
        if ($batchType === 'manual') {
            return processMerchantSettlement($merchantId, $merchant, 0);
        }
        return ['ok' => false, 'error' => 'No transactions in current batch.'];
    }

    $batchId = (int)$batch['id'];
    $net = walletAmount((float)$batch['net_amount']);
    if ($net <= 0) {
        return ['ok' => false, 'error' => 'Batch amount is zero.'];
    }

    $rail = (string)$batch['settlement_rail'];
    $db->prepare("UPDATE settlement_batches SET status='processing', batch_type=?, period_end=NOW() WHERE id=?")
        ->execute([$batchType, $batchId]);

    $apiResult = dispatchSettlementRailPayout($merchant, $batch, $net);

  if (!$apiResult['ok']) {
        $db->prepare("UPDATE settlement_batches SET status='failed', api_status='failed', api_message=? WHERE id=?")
            ->execute([$apiResult['error'] ?? 'Payout failed', $batchId]);
        if (function_exists('sendTemplatedEmail')) {
            sendTemplatedEmail($merchantId, 'payout_failed', [
                'amount' => formatMoney($net),
                'batch_code' => $batch['batch_code'],
                'reason' => $apiResult['error'] ?? 'Payout failed',
            ]);
        }
        return $apiResult;
    }

    $isFinal = !empty($apiResult['final']);
    $db->prepare("UPDATE settlement_batches SET status=?, processed_at=?, utr=?, api_status=?, api_message=?, settlement_id=?,provider_payout_id=?,provider_status=? WHERE id=?")
        ->execute([
            $isFinal ? 'settled' : 'processing',
            $isFinal ? date('Y-m-d H:i:s') : null,
            $apiResult['utr'] ?? null,
            $apiResult['api_status'] ?? 'submitted',
            $apiResult['message'] ?? 'Payout submitted',
            $apiResult['settlement_id'] ?? null,
            $apiResult['provider_payout_id'] ?? null,
            $apiResult['provider_status'] ?? null,
            $batchId,
        ]);

    $prefs = getMerchantSettlementPrefs($merchant);
    if ($prefs['mode'] === 'scheduled') {
        scheduleNextBatch($merchantId, (int)$prefs['interval_minutes']);
    }

    createNotification(
        $merchantId,
        $isFinal ? 'Settlement Batch Complete' : 'Settlement Batch Submitted',
        formatMoney($net) . ' — ' . (int)$batch['txn_count'] . ' transaction(s) in batch ' . $batch['batch_code']
    );
    if ($isFinal && function_exists('sendTemplatedEmail')) {
        sendTemplatedEmail($merchantId, 'settlement_completed', [
            'amount' => formatMoney($net),
            'batch_code' => $batch['batch_code'],
            'txn_count' => (int)$batch['txn_count'],
        ]);
    }

    return [
        'ok' => true,
        'batch_id' => $batch['batch_code'],
        'amount' => $net,
        'txn_count' => (int)$batch['txn_count'],
        'status' => $isFinal ? 'settled' : 'processing',
        'message' => $isFinal
            ? 'Batch ' . $batch['batch_code'] . ' settled: ' . formatMoney($net)
            : 'Batch ' . $batch['batch_code'] . ' submitted; awaiting provider confirmation.',
    ];
}

/** API hooks — live integration later */
function dispatchSettlementRailPayout(array $merchant, array $batch, float $amount): array
{
    $rail = (string)($batch['settlement_rail'] ?? 'wallet');
    $merchantId = (int)$merchant['id'];

    if ($rail === 'platform_pg') {
        return dispatchPlatformPgPayout($merchant, $batch, $amount);
    }
    if ($rail === 'axis_va') {
        return dispatchAxisVaSweep($merchant, $batch, $amount);
    }

    $result = processMerchantSettlement($merchantId, $merchant, $amount);
    if (!$result['ok']) {
        return $result;
    }
    $sandbox = function_exists('isSettlementSandbox') ? isSettlementSandbox($merchant) : isMerchantTest($merchant);
    return [
        'ok' => true,
        'utr' => $sandbox ? 'SIM' . time() : null,
        'api_status' => $sandbox ? 'simulated' : 'awaiting_manual_transfer',
        'message' => $result['message'] ?? 'Wallet settlement created',
        'settlement_id' => $result['settlement_id'] ?? null,
        'final' => $sandbox,
    ];
}

function dispatchPlatformPgPayout(array $merchant, array $batch, float $amount): array
{
    if (function_exists('isSettlementSandbox') ? isSettlementSandbox($merchant) : isMerchantTest($merchant)) {
        $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
        return [
            'ok' => $result['ok'],
            'error' => $result['error'] ?? null,
            'utr' => 'PG-TEST-' . time(),
            'api_status' => 'simulated',
            'message' => 'Platform PG batch (test mode)',
            'final' => true,
        ];
    }
    $razorpayxAcct = razorpayxAccountNumber();
    $razorpayxKey = razorpayxKeyId();
    if (!$razorpayxAcct || !$razorpayxKey) {
        return ['ok' => false, 'error' => 'RazorpayX payout rail is not activated.'];
    }
    if (!function_exists('pgOutboundCircuitBlocked') && is_file(__DIR__ . '/circuit_breaker.php')) {
        require_once __DIR__ . '/circuit_breaker.php';
    }
    $circuitBlock = function_exists('pgOutboundCircuitBlocked') ? pgOutboundCircuitBlocked('razorpay', 'settlement_payout') : null;
    if ($circuitBlock !== null) {
        return ['ok' => false, 'error' => $circuitBlock['error'], 'error_code' => 'partner_unavailable'];
    }
    $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
    if (!$result['ok']) {
        return $result;
    }
    $bankSt = getDB()->prepare("SELECT * FROM bank_accounts WHERE merchant_id=? AND is_primary=1 AND status='active' LIMIT 1");
    $bankSt->execute([(int)$merchant['id']]);
    $bank = $bankSt->fetch();
    $payout = $bank ? createRazorpayXPayout($merchant, $bank, $amount, (string)$batch['batch_code']) : null;
    if (!$payout || empty($payout['id'])) {
        if (function_exists('pgOutboundCircuitRecord')) {
            pgOutboundCircuitRecord('razorpay', false);
        }
        creditMerchantWallet((int)$merchant['id'], $amount, 'refund', null, 'REV-' . $result['settlement_id'], 'Payout submission failed — funds released');
        getDB()->prepare("UPDATE settlements SET status='failed',processed_at=NOW() WHERE settlement_id=?")->execute([$result['settlement_id']]);
        return ['ok' => false, 'error' => 'RazorpayX did not accept the payout request. Funds were released.', 'error_code' => 'partner_unavailable'];
    }
    if (function_exists('pgOutboundCircuitRecord')) {
        pgOutboundCircuitRecord('razorpay', true);
    }
    $providerStatus = strtolower((string)($payout['status'] ?? 'queued'));
    $utr = trim((string)($payout['utr'] ?? ''));
    $final = $providerStatus === 'processed' && $utr !== '';
    if ($final) {
        getDB()->prepare("UPDATE settlements SET status='completed',utr=?,processed_at=NOW() WHERE settlement_id=?")->execute([$utr, $result['settlement_id']]);
    }
    return [
        'ok' => true,
        'utr' => $final ? $utr : null,
        'api_status' => $final ? 'confirmed' : 'submitted',
        'provider_status' => $providerStatus,
        'provider_payout_id' => (string)$payout['id'],
        'message' => $final ? 'RazorpayX payout confirmed.' : 'RazorpayX payout accepted; awaiting final confirmation.',
        'settlement_id' => $result['settlement_id'] ?? null,
        'final' => $final,
    ];
}

function dispatchAxisVaSweep(array $merchant, array $batch, float $amount): array
{
    if (function_exists('isSettlementSandbox') ? isSettlementSandbox($merchant) : isMerchantTest($merchant)) {
        $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
        return [
            'ok' => $result['ok'],
            'error' => $result['error'] ?? null,
            'utr' => 'AXIS-TEST-' . time(),
            'api_status' => 'simulated',
            'message' => 'Axis VA sweep (test mode)',
            'final' => true,
        ];
    }
    if (!function_exists('getMerchantPrimaryVaNumber')) {
        require_once __DIR__ . '/va_manager.php';
    }
    $va = getMerchantPrimaryVaNumber((int)$merchant['id']);
    if (!$va && !isGatewayConfigured('axis')) {
        return ['ok' => false, 'error' => 'Axis Virtual Account not provisioned and Axis API not configured.'];
    }
    if (!$va) {
        $payload = ensureMerchantVirtualAccount((int)$merchant['id']);
        $va = (string)($payload['va_number'] ?? '');
    }
    $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
    if (!$result['ok']) {
        return $result;
    }
    return [
        'ok' => true,
        'utr' => null,
        'api_status' => 'wallet_only',
        'message' => 'Awaiting verified manual bank transfer.' . ($va ? ' (VA ' . $va . ')' : ''),
        'settlement_id' => $result['settlement_id'] ?? null,
        'final' => false,
    ];
}

function runScheduledSettlementBatches(): array
{
    ensureSettlementEngine();
    $report = [];
    $merchants = getDB()->query("SELECT * FROM merchants WHERE status='active'")->fetchAll();
    foreach ($merchants as $merchant) {
        if (!isMerchantDueForBatch($merchant)) {
            continue;
        }
        $open = getMerchantOpenBatch((int)$merchant['id']);
        if (!$open || (int)$open['txn_count'] < 1) {
            $prefs = getMerchantSettlementPrefs($merchant);
            scheduleNextBatch((int)$merchant['id'], (int)$prefs['interval_minutes']);
            continue;
        }
        $report[] = closeBatchAndSettle((int)$merchant['id'], 'scheduled');
    }
    return $report;
}

function getSettlementCronKey(): string
{
    $key = trim(getSetting('settlement_cron_key', ''));
    return $key !== '' ? $key : 'uniweb-settle';
}

function countMerchantsDueForBatch(): int
{
    ensureSettlementEngine();
    try {
        $n = 0;
        foreach (getDB()->query("SELECT * FROM merchants WHERE status='active'")->fetchAll() as $merchant) {
            if (!isMerchantDueForBatch($merchant)) {
                continue;
            }
            $open = getMerchantOpenBatch((int)$merchant['id']);
            if ($open && (int)$open['txn_count'] > 0) {
                $n++;
            }
        }
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

function logSettlementCronRun(array $results): void
{
    $ok = count(array_filter($results, fn($r) => !empty($r['ok'])));
    $db = getDB();
    $ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?');
    foreach ([
        ['settlement_cron_last_run', date('Y-m-d H:i:s')],
        ['settlement_cron_last_total', (string)count($results)],
        ['settlement_cron_last_ok', (string)$ok],
        ['settlement_cron_last_due', (string)countMerchantsDueForBatch()],
    ] as [$k, $v]) {
        $ins->execute([$k, $v, $v]);
        clearSettingCache($k);
    }
}

/** @return array{enabled:bool,last_run:?string,last_total:int,last_ok:int,due_now:int,cron_url:string,key:string} */
function getSettlementCronStatus(): array
{
    try {
        ensureSettlementEngine();
        $key = getSettlementCronKey();
        return [
            'enabled' => getSetting('settlement_batch_enabled', '1') === '1',
            'last_run' => getSetting('settlement_cron_last_run', '') ?: null,
            'last_total' => (int)getSetting('settlement_cron_last_total', '0'),
            'last_ok' => (int)getSetting('settlement_cron_last_ok', '0'),
            'due_now' => countMerchantsDueForBatch(),
            'cron_url' => APP_URL . '/cron_settlements.php?key=' . rawurlencode($key),
            'key' => $key,
        ];
    } catch (Throwable $e) {
        error_log('UniWeb settlement cron status: ' . $e->getMessage());
        return [
            'enabled' => false,
            'last_run' => null,
            'last_total' => 0,
            'last_ok' => 0,
            'due_now' => 0,
            'cron_url' => APP_URL . '/cron_settlements.php',
            'key' => '',
        ];
    }
}

function requestManualSettlement(int $merchantId, array $merchant): array
{
    ensureSettlementEngine();
    $open = getMerchantOpenBatch($merchantId);
    if ($open && (int)$open['txn_count'] > 0) {
        return closeBatchAndSettle($merchantId, 'manual');
    }
    return processMerchantSettlement($merchantId, $merchant, 0);
}

function settlementRailBadge(string $rail): string
{
    $rails = getSettlementRails();
    $label = $rails[$rail]['label'] ?? ucfirst($rail);
    $colors = [
        'platform_pg' => 'bg-violet-500/20 text-violet-300 border-violet-500/30',
        'axis_va' => 'bg-cyan-500/20 text-cyan-300 border-cyan-500/30',
        'wallet' => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/30',
    ];
    $c = $colors[$rail] ?? 'bg-gray-500/20 text-gray-300';
    return '<span class="px-2 py-0.5 rounded-full text-[10px] font-semibold border ' . $c . '">' . e($label) . '</span>';
}

function settlementBatchStatusBadge(string $status): string
{
    return match (strtolower(trim($status))) {
        'open' => '<span class="text-amber-400">● Open</span>',
        'processing' => '<span class="text-cyan-400">⟳ Processing</span>',
        'settled', 'completed', 'complete', 'success' => '<span class="text-emerald-400">✓ Settled</span>',
        'failed', 'fail', 'rejected', 'cancelled', 'canceled', 'error' => '<span class="text-red-400">✗ Failed</span>',
        'pending', '' => '<span class="text-amber-400">● Pending</span>',
        default => '<span class="text-gray-400" title="' . e($status) . '">Unknown</span>',
    };
}
