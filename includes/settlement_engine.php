<?php
declare(strict_types=1);

/**
 * Settlement Engine — Option B (Platform PG pool) & Option C (Axis VA)
 * Live settlement via processMerchantSettlement when gateway keys are configured.
 */

function ensureSettlementEngine(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensureWalletEngine();
    $db = getDB();

    $migrations = [
        "ALTER TABLE merchants ADD COLUMN settlement_mode VARCHAR(16) NOT NULL DEFAULT 'manual'",
        "ALTER TABLE merchants ADD COLUMN settlement_rail VARCHAR(24) NOT NULL DEFAULT 'wallet'",
        "ALTER TABLE merchants ADD COLUMN batch_interval_minutes INT NOT NULL DEFAULT 120",
        "ALTER TABLE merchants ADD COLUMN settlement_use_platform_default TINYINT(1) NOT NULL DEFAULT 1",
        "ALTER TABLE merchants ADD COLUMN next_batch_at DATETIME NULL",
        "ALTER TABLE merchants ADD COLUMN last_batch_at DATETIME NULL",
        "ALTER TABLE transactions ADD COLUMN settlement_batch_id INT NULL",
    ];
    foreach ($migrations as $sql) {
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            /* ok */
        }
    }

    try {
        $db->exec("CREATE TABLE IF NOT EXISTS settlement_batches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_code VARCHAR(32) NOT NULL UNIQUE,
            merchant_id INT NOT NULL,
            settlement_rail ENUM('platform_pg','axis_va','wallet') NOT NULL DEFAULT 'wallet',
            batch_type ENUM('scheduled','manual') NOT NULL DEFAULT 'scheduled',
            txn_count INT NOT NULL DEFAULT 0,
            gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('open','processing','settled','failed') NOT NULL DEFAULT 'open',
            settlement_id INT NULL,
            period_start DATETIME NULL,
            period_end DATETIME NULL,
            scheduled_at DATETIME NULL,
            processed_at DATETIME NULL,
            utr VARCHAR(64) NULL,
            api_provider VARCHAR(32) NULL,
            api_status VARCHAR(32) NOT NULL DEFAULT 'pending',
            api_message VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (merchant_id),
            INDEX (status),
            INDEX (scheduled_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS settlement_batch_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            batch_id INT NOT NULL,
            transaction_id INT NOT NULL,
            amount DECIMAL(12,2) NOT NULL DEFAULT 0,
            payment_method VARCHAR(32) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_txn (transaction_id),
            INDEX (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { /* ok */ }

    $defaults = [
        ['default_settlement_mode', 'manual'],
        ['default_settlement_rail', 'platform_pg'],
        ['default_batch_interval_minutes', '120'],
        ['settlement_batch_enabled', '1'],
        ['axis_batch_enabled', '1'],
        ['platform_pg_batch_enabled', '1'],
    ];
    $ins = $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=setting_value');
    foreach ($defaults as [$k, $v]) {
        try {
            $ins->execute([$k, $v]);
        } catch (Throwable $e) {
            /* ok */
        }
    }
}

function getSettlementBatchIntervals(): array
{
    return [
        60 => '1 Hour',
        90 => '1.5 Hours',
        120 => '2 Hours',
        180 => '3 Hours',
        360 => '6 Hours',
        1440 => '24 Hours (T+1 style)',
    ];
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
    $interval = (int)getSetting('default_batch_interval_minutes', '120');
    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = 120;
    }
    return [
        'mode' => getSetting('default_settlement_mode', 'manual') === 'scheduled' ? 'scheduled' : 'manual',
        'rail' => (string)getSetting('default_settlement_rail', 'platform_pg'),
        'interval_minutes' => $interval,
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
    $interval = $usePlatform ? $platform['interval_minutes'] : (int)($merchant['batch_interval_minutes'] ?? 120);

    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = 120;
    }
    if (!in_array($mode, ['manual', 'scheduled'], true)) {
        $mode = 'manual';
    }
    if (!isset(getSettlementRails()[$rail])) {
        $rail = 'wallet';
    }

    return [
        'use_platform_default' => $usePlatform,
        'mode' => $mode,
        'rail' => $rail,
        'interval_minutes' => $interval,
        'interval_label' => getSettlementBatchIntervals()[$interval],
        'next_batch_at' => $merchant['next_batch_at'] ?? null,
        'last_batch_at' => $merchant['last_batch_at'] ?? null,
        'batch_enabled' => $platform['batch_enabled'],
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
    $interval = (int)($data['interval_minutes'] ?? 120);
    if (!isset(getSettlementBatchIntervals()[$interval])) {
        $interval = 120;
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
    $pairs = [
        'default_settlement_mode' => ($data['mode'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual',
        'default_settlement_rail' => $data['rail'] ?? 'platform_pg',
        'default_batch_interval_minutes' => (string)(int)($data['interval_minutes'] ?? 120),
        'settlement_batch_enabled' => !empty($data['batch_enabled']) ? '1' : '0',
    ];
    foreach ($pairs as $k => $v) {
        $db->prepare('INSERT INTO gateway_settings (setting_key, setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=?')
            ->execute([$k, $v, $v]);
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
        return true;
    }
    return strtotime($next) <= time();
}

function scheduleNextBatch(int $merchantId, int $intervalMinutes): void
{
    getDB()->prepare('UPDATE merchants SET next_batch_at=?, last_batch_at=NOW() WHERE id=?')
        ->execute([date('Y-m-d H:i:s', time() + ($intervalMinutes * 60)), $merchantId]);
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
        return $apiResult;
    }

    $db->prepare("UPDATE settlement_batches SET status='settled', processed_at=NOW(), utr=?, api_status=?, api_message=?, settlement_id=? WHERE id=?")
        ->execute([
            $apiResult['utr'] ?? null,
            $apiResult['api_status'] ?? 'simulated',
            $apiResult['message'] ?? 'Batch settled',
            $apiResult['settlement_id'] ?? null,
            $batchId,
        ]);

    $prefs = getMerchantSettlementPrefs($merchant);
    if ($prefs['mode'] === 'scheduled') {
        scheduleNextBatch($merchantId, (int)$prefs['interval_minutes']);
    }

    createNotification(
        $merchantId,
        'Settlement Batch Complete',
        formatMoney($net) . ' — ' . (int)$batch['txn_count'] . ' transaction(s) in batch ' . $batch['batch_code']
    );

    return [
        'ok' => true,
        'batch_id' => $batch['batch_code'],
        'amount' => $net,
        'txn_count' => (int)$batch['txn_count'],
        'message' => 'Batch ' . $batch['batch_code'] . ' settled: ' . formatMoney($net) . ' (' . (int)$batch['txn_count'] . ' txns)',
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
    return [
        'ok' => true,
        'utr' => 'SIM' . time(),
        'api_status' => (function_exists('isSettlementSandbox') ? isSettlementSandbox($merchant) : isMerchantTest($merchant)) ? 'simulated' : 'internal',
        'message' => $result['message'] ?? 'Wallet settlement created',
        'settlement_id' => null,
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
        ];
    }
    if (!isGatewayConfigured('payu') && !isGatewayConfigured('razorpay') && !isGatewayConfigured('cashfree')) {
        return ['ok' => false, 'error' => 'Platform PG keys not configured. Add gateway keys in Admin → Gateway Settings.'];
    }
    $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
    if (!$result['ok']) {
        return $result;
    }
    return [
        'ok' => true,
        'utr' => null,
        'api_status' => 'wallet_only',
        'message' => 'Wallet debited. Live bank payout API not wired yet — manual NEFT/IMPS required.',
        'settlement_id' => $result['settlement_id'] ?? null,
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
        ];
    }
    $va = $merchant['axis_va_number'] ?? '';
    if (!$va && !isGatewayConfigured('axis')) {
        return ['ok' => false, 'error' => 'Axis Virtual Account not provisioned and Axis API not configured.'];
    }
    if (!$va) {
        ensureAxisVirtualAccount((int)$merchant['id']);
        $mst = getDB()->prepare('SELECT axis_va_number FROM merchants WHERE id=?');
        $mst->execute([(int)$merchant['id']]);
        $va = (string)($mst->fetchColumn() ?: '');
    }
    $result = processMerchantSettlement((int)$merchant['id'], $merchant, $amount);
    if (!$result['ok']) {
        return $result;
    }
    return [
        'ok' => true,
        'utr' => null,
        'api_status' => 'wallet_only',
        'message' => 'Wallet debited. Axis VA sweep API pending — manual transfer required.' . ($va ? ' (VA ' . $va . ')' : ''),
        'settlement_id' => $result['settlement_id'] ?? null,
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
    return match ($status) {
        'open' => '<span class="text-amber-400">● Open</span>',
        'processing' => '<span class="text-cyan-400">⟳ Processing</span>',
        'settled' => '<span class="text-emerald-400">✓ Settled</span>',
        'failed' => '<span class="text-red-400">✗ Failed</span>',
        default => '<span class="text-gray-400">' . e($status) . '</span>',
    };
}
