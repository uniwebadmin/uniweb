<?php
declare(strict_types=1);

/**
 * Virtual Account (VA) manager — canonical store: merchant_virtual_accounts.
 *
 * merchants.axis_va_* columns are an auto-synced mirror of the primary active VA
 * for legacy SELECTs (checkout, webhooks). Never update axis_va_* outside this file.
 */

/** Gateways with a live VA-creation adapter today (others fail gracefully). */
function vaSupportedCreationGateways(): array
{
    $gateways = ['axis'];
    if (!function_exists('isRblOperational') && is_file(__DIR__ . '/rbl_workflow.php')) {
        require_once __DIR__ . '/rbl_workflow.php';
    }
    if (function_exists('isRblOperational') && isRblOperational()) {
        $gateways[] = 'rbl';
    }
    return $gateways;
}

/** Human label for VA gateway key (admin UI + merchant list). */
function vaGatewayDisplayName(string $gateway): string
{
    return match (strtolower(trim($gateway))) {
        'axis' => 'Axis Bank',
        'rbl' => 'RBL Bank',
        default => ucfirst($gateway),
    };
}

/** Ensure multi-VA table exists (migration 031). Safe to call from admin UI. */
function ensureMerchantVirtualAccountsTable(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS merchant_virtual_accounts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            merchant_id INT NOT NULL,
            gateway VARCHAR(32) NOT NULL DEFAULT 'axis',
            va_id VARCHAR(64) DEFAULT NULL,
            va_number VARCHAR(64) NOT NULL,
            ifsc VARCHAR(20) DEFAULT NULL,
            upi_id VARCHAR(120) DEFAULT NULL,
            label VARCHAR(120) DEFAULT NULL,
            status ENUM('active','disabled') NOT NULL DEFAULT 'active',
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            txn_count_today INT UNSIGNED NOT NULL DEFAULT 0,
            txn_count_total INT UNSIGNED NOT NULL DEFAULT 0,
            fail_count_today INT UNSIGNED NOT NULL DEFAULT 0,
            last_assigned_at DATETIME DEFAULT NULL,
            counters_reset_on DATE DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_va_number (va_number),
            INDEX idx_va_merchant (merchant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        // migration runner may apply full 031 later
    }
    backfillMerchantVirtualAccountsFromLegacy();
}

/** Idempotent: import merchants.axis_va_* into merchant_virtual_accounts + sync mirror. */
function backfillMerchantVirtualAccountsFromLegacy(): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;
    try {
        $db = getDB();
        $db->exec(
            "INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
             SELECT m.id, 'axis', m.axis_va_id, m.axis_va_number, m.axis_va_ifsc, m.axis_va_upi, 'Primary', 'active', 1, CURDATE()
             FROM merchants m
             WHERE m.axis_va_number IS NOT NULL AND m.axis_va_number != ''
               AND NOT EXISTS (
                 SELECT 1 FROM merchant_virtual_accounts v WHERE v.va_number = m.axis_va_number
               )"
        );
        $merchants = $db->query(
            "SELECT DISTINCT merchant_id FROM merchant_virtual_accounts WHERE status = 'active'"
        )->fetchAll();
        foreach ($merchants as $row) {
            syncMerchantPrimaryVaMirror((int)$row['merchant_id']);
        }
    } catch (Throwable $e) { /* ok */ }
}

function importLegacyMerchantVaRow(int $merchantId): bool
{
    if ($merchantId < 1) {
        return false;
    }
    ensureMerchantVirtualAccountsTable();
    try {
        $db = getDB();
        $st = $db->prepare(
            'SELECT axis_va_id, axis_va_number, axis_va_ifsc, axis_va_upi
             FROM merchants WHERE id=? LIMIT 1'
        );
        $st->execute([$merchantId]);
        $m = $st->fetch();
        if (!$m || empty($m['axis_va_number'])) {
            return false;
        }
        $chk = $db->prepare('SELECT id FROM merchant_virtual_accounts WHERE va_number=? LIMIT 1');
        $chk->execute([(string)$m['axis_va_number']]);
        if ($chk->fetch()) {
            return false;
        }
        $db->prepare(
            "INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
             VALUES (?,?,?,?,?,?,?,?,?,CURDATE())"
        )->execute([
            $merchantId, 'axis',
            $m['axis_va_id'] ?? '', (string)$m['axis_va_number'],
            $m['axis_va_ifsc'] ?? null, $m['axis_va_upi'] ?? null,
            'Primary', 'active', 1,
        ]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function vaRowToPayload(array $row): array
{
    $defaultIfsc = function_exists('axisPartnerSetting')
        ? axisPartnerSetting('axis_va_ifsc', 'UTIB0000000')
        : 'UTIB0000000';

    return [
        'va_number' => (string)($row['va_number'] ?? ''),
        'va_ifsc' => (string)($row['ifsc'] ?? $row['va_ifsc'] ?? $defaultIfsc),
        'va_upi' => (string)($row['upi_id'] ?? $row['va_upi'] ?? ''),
        'axis_va_id' => (string)($row['va_id'] ?? $row['axis_va_id'] ?? ''),
        'va_row_id' => isset($row['id']) ? (int)$row['id'] : (isset($row['va_row_id']) ? (int)$row['va_row_id'] : null),
    ];
}

/** Primary active VA row (canonical read). */
function getMerchantPrimaryVirtualAccount(int $merchantId): ?array
{
    if ($merchantId < 1) {
        return null;
    }
    ensureMerchantVirtualAccountsTable();
    importLegacyMerchantVaRow($merchantId);
    try {
        $st = getDB()->prepare(
            "SELECT * FROM merchant_virtual_accounts
             WHERE merchant_id = ? AND status = 'active'
             ORDER BY is_primary DESC, id ASC
             LIMIT 1"
        );
        $st->execute([$merchantId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function getMerchantPrimaryVaNumber(int $merchantId): string
{
    $row = getMerchantPrimaryVirtualAccount($merchantId);
    return $row ? (string)($row['va_number'] ?? '') : '';
}

/** Mirror primary active VA onto merchants.axis_va_* (legacy SELECT compatibility). */
function syncMerchantPrimaryVaMirror(int $merchantId): void
{
    if ($merchantId < 1) {
        return;
    }
    try {
        $db = getDB();
        $st = $db->prepare(
            "SELECT * FROM merchant_virtual_accounts
             WHERE merchant_id = ? AND status = 'active'
             ORDER BY is_primary DESC, id ASC
             LIMIT 1"
        );
        $st->execute([$merchantId]);
        $primary = $st->fetch();
        if ($primary) {
            $db->prepare(
                'UPDATE merchants SET axis_va_id=?, axis_va_number=?, axis_va_ifsc=?, axis_va_upi=? WHERE id=?'
            )->execute([
                $primary['va_id'] ?? '',
                $primary['va_number'],
                $primary['ifsc'] ?? null,
                $primary['upi_id'] ?? null,
                $merchantId,
            ]);
            return;
        }
        $db->prepare(
            'UPDATE merchants SET axis_va_id=NULL, axis_va_number=NULL, axis_va_ifsc=NULL, axis_va_upi=NULL WHERE id=?'
        )->execute([$merchantId]);
    } catch (Throwable $e) { /* ok */ }
}

function promoteNextPrimaryVirtualAccount(int $merchantId): void
{
    if ($merchantId < 1) {
        return;
    }
    try {
        $db = getDB();
        $db->prepare('UPDATE merchant_virtual_accounts SET is_primary = 0 WHERE merchant_id = ?')
            ->execute([$merchantId]);
        $st = $db->prepare(
            "SELECT id FROM merchant_virtual_accounts
             WHERE merchant_id = ? AND status = 'active'
             ORDER BY id ASC LIMIT 1"
        );
        $st->execute([$merchantId]);
        $next = $st->fetch();
        if ($next) {
            $db->prepare('UPDATE merchant_virtual_accounts SET is_primary = 1 WHERE id = ?')
                ->execute([(int)$next['id']]);
        }
    } catch (Throwable $e) { /* ok */ }
}

/** All VAs for a merchant. Primary first. */
function getMerchantVirtualAccounts(int $merchantId): array
{
    ensureMerchantVirtualAccountsTable();
    importLegacyMerchantVaRow($merchantId);
    try {
        $st = getDB()->prepare(
            'SELECT * FROM merchant_virtual_accounts WHERE merchant_id = ? ORDER BY is_primary DESC, id ASC'
        );
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function findVirtualAccountByNumber(string $vaNumber): ?array
{
    $vaNumber = trim($vaNumber);
    if ($vaNumber === '') {
        return null;
    }
    ensureMerchantVirtualAccountsTable();
    try {
        $st = getDB()->prepare('SELECT * FROM merchant_virtual_accounts WHERE va_number = ? LIMIT 1');
        $st->execute([$vaNumber]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function countActiveMerchantVirtualAccounts(int $merchantId): int
{
    ensureMerchantVirtualAccountsTable();
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) FROM merchant_virtual_accounts WHERE merchant_id = ? AND status = 'active'"
        );
        $st->execute([$merchantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Ensure merchant has at least one active VA — create via Axis when missing.
 * Canonical entry point (replaces legacy ensureAxisVirtualAccount writes).
 */
function ensureMerchantVirtualAccount(int $merchantId): ?array
{
    if ($merchantId < 1) {
        return null;
    }
    ensureMerchantVirtualAccountsTable();
    importLegacyMerchantVaRow($merchantId);

    $primary = getMerchantPrimaryVirtualAccount($merchantId);
    if ($primary) {
        syncMerchantPrimaryVaMirror($merchantId);
        return vaRowToPayload($primary);
    }

    if (!function_exists('createAxisVirtualAccount')) {
        if (is_file(__DIR__ . '/axis.php')) {
            require_once __DIR__ . '/axis.php';
        }
    }
    if (function_exists('axisCredentials')) {
        $axisCreds = axisCredentials();
        $mockOk = function_exists('axisAllowMock') && axisAllowMock();
        if (($axisCreds['client_id'] ?? '') === '' && !$mockOk) {
            return null;
        }
    }

    $result = createAdditionalVirtualAccount($merchantId, 'axis', 'Primary');
    if (!($result['ok'] ?? false)) {
        return null;
    }
    $created = getMerchantPrimaryVirtualAccount($merchantId);
    return $created ? vaRowToPayload($created) : null;
}

/**
 * Create ONE MORE virtual account for a merchant via the given gateway.
 */
function createAdditionalVirtualAccount(int $merchantId, string $gateway = 'axis', string $label = ''): array
{
    ensureMerchantVirtualAccountsTable();
    importLegacyMerchantVaRow($merchantId);

    $db = getDB();
    $m = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $m->execute([$merchantId]);
    $merchant = $m->fetch();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    if (!function_exists('vaCreationGateCheck')) {
        require_once __DIR__ . '/va_workflow.php';
    }
    $gate = vaCreationGateCheck($gateway);
    if (!$gate['ok']) {
        return ['ok' => false, 'error' => (string)($gate['error'] ?? vaUnsupportedCreationReason($gateway))];
    }

    if ($gateway === 'axis' && !function_exists('createAxisVirtualAccount')) {
        return ['ok' => false, 'error' => 'Axis VA adapter not loaded.'];
    }
    if ($gateway === 'rbl' && !function_exists('createRblVirtualAccount') && is_file(__DIR__ . '/rbl.php')) {
        require_once __DIR__ . '/rbl.php';
    }
    if ($gateway === 'rbl' && !function_exists('createRblVirtualAccount')) {
        return ['ok' => false, 'error' => 'RBL VA adapter not loaded.'];
    }

    if ($gateway === 'axis') {
        if (!function_exists('axisCredentials')) {
            require_once __DIR__ . '/axis.php';
        }
        $axisCreds = axisCredentials();
        $mockOk = function_exists('axisAllowMock') && axisAllowMock();
        if (($axisCreds['client_id'] ?? '') === '' && !$mockOk) {
            return ['ok' => false, 'error' => 'Axis keys not saved yet. Open Partner Registry → Axis Bank → Keys tab, paste UAT/live keys, then try again.'];
        }
    }

    if ($gateway === 'rbl') {
        if (!function_exists('isRblOperational') && is_file(__DIR__ . '/rbl_workflow.php')) {
            require_once __DIR__ . '/rbl_workflow.php';
        }
        if (!function_exists('createRblVirtualAccount') && is_file(__DIR__ . '/rbl.php')) {
            require_once __DIR__ . '/rbl.php';
        }
        if (!function_exists('isRblOperational') || !isRblOperational()) {
            $reason = function_exists('rblGateBlockedReason') ? rblGateBlockedReason() : 'RBL keys incomplete — paste Corp ID and Master Account in Partner Registry (no demo defaults).';
            return ['ok' => false, 'error' => $reason];
        }
    }

    if ($gateway === 'axis') {
    $va = createAxisVirtualAccount($merchant);
    if (!$va || empty($va['va_number'])) {
            $hint = function_exists('axisAllowMock') && axisAllowMock()
                ? 'Mock VA is ON but creation still failed — check Error Log.'
                : 'Axis did not return a VA in ~15s. Check Partner Registry → Axis keys, IP whitelist, and Error Log.';
            return ['ok' => false, 'error' => $hint];
        }
    } elseif ($gateway === 'rbl') {
        $va = createRblVirtualAccount($merchant);
        if (!$va || empty($va['va_number'])) {
            $detail = function_exists('rblLastVaCreateError') ? rblLastVaCreateError() : '';
            $hint = $detail !== ''
                ? ('RBL VA create failed: ' . $detail)
                : 'RBL did not return a VA. Check Partner Registry → RBL keys and Error Log.';
            return ['ok' => false, 'error' => $hint];
        }
    } else {
        return ['ok' => false, 'error' => vaUnsupportedCreationReason($gateway)];
    }

    $countSt = $db->prepare('SELECT COUNT(*) FROM merchant_virtual_accounts WHERE merchant_id = ?');
    $countSt->execute([$merchantId]);
    $isFirst = (int)$countSt->fetchColumn() === 0;

    try {
        $db->prepare(
            'INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
            VALUES (?,?,?,?,?,?,?,?,?,CURDATE())'
        )->execute([
                $merchantId, $gateway,
                $va['va_id'] ?? '', $va['va_number'], $va['ifsc'] ?? null, $va['upi_id'] ?? null,
                $label !== '' ? $label : ('VA ' . (countActiveMerchantVirtualAccounts($merchantId) + 1)),
                'active', $isFirst ? 1 : 0,
            ]);
    } catch (Throwable $e) {
        $existing = findVirtualAccountByNumber((string)($va['va_number'] ?? ''));
        if ($existing && (int)($existing['merchant_id'] ?? 0) === $merchantId) {
            syncMerchantPrimaryVaMirror($merchantId);
            return [
                'ok' => true,
                'reused' => true,
                'va' => [
                    'va_number' => (string)($existing['va_number'] ?? ''),
                    'va_id' => (string)($existing['va_id'] ?? ''),
                    'ifsc' => $existing['ifsc'] ?? null,
                    'upi_id' => $existing['upi_id'] ?? null,
                ],
            ];
        }
        return ['ok' => false, 'error' => 'Could not save virtual account. Try Create VA again — a new account number will be requested.'];
    }

    syncMerchantPrimaryVaMirror($merchantId);

    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('va_created', null, 'merchant', (string)$merchantId, $va['va_number']);
    }

    return ['ok' => true, 'va' => $va];
}

/** Enable/disable a VA row; re-elect primary + sync merchant mirror. */
function setMerchantVirtualAccountStatus(int $vaRowId, int $merchantId, string $status): bool
{
    if ($vaRowId < 1 || $merchantId < 1) {
        return false;
    }
    $status = $status === 'active' ? 'active' : 'disabled';
    try {
        $db = getDB();
        $st = $db->prepare(
            'SELECT is_primary FROM merchant_virtual_accounts WHERE id = ? AND merchant_id = ? LIMIT 1'
        );
        $st->execute([$vaRowId, $merchantId]);
        $row = $st->fetch();
        if (!$row) {
            return false;
        }
        $wasPrimary = (int)($row['is_primary'] ?? 0) === 1;
        $db->prepare(
            'UPDATE merchant_virtual_accounts SET status = ? WHERE id = ? AND merchant_id = ?'
        )->execute([$status, $vaRowId, $merchantId]);
        if ($status === 'disabled' && $wasPrimary) {
            promoteNextPrimaryVirtualAccount($merchantId);
        }
        syncMerchantPrimaryVaMirror($merchantId);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Pick the least-busy ACTIVE virtual account for checkout load-spread. */
function pickLeastBusyVirtualAccount(int $merchantId): ?array
{
    ensureMerchantVirtualAccountsTable();
    importLegacyMerchantVaRow($merchantId);
    try {
        $st = getDB()->prepare(
            "SELECT * FROM merchant_virtual_accounts
            WHERE merchant_id = ? AND status = 'active'
            ORDER BY txn_count_today ASC, last_assigned_at ASC, id ASC
            LIMIT 1"
        );
        $st->execute([$merchantId]);
        $va = $st->fetch();
        return $va ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function recordVirtualAccountUsage(int $vaRowId): void
{
    try {
        getDB()->prepare(
            'UPDATE merchant_virtual_accounts
            SET txn_count_today = txn_count_today + 1, txn_count_total = txn_count_total + 1, last_assigned_at = NOW()
            WHERE id = ?'
        )->execute([$vaRowId]);
    } catch (Throwable $e) { /* ok */ }
}

function recordVirtualAccountFailure(int $vaRowId, int $autoDisableThreshold = 10): void
{
    $db = getDB();
    try {
        $db->prepare(
            'UPDATE merchant_virtual_accounts SET fail_count_today = fail_count_today + 1 WHERE id = ?'
        )->execute([$vaRowId]);
        $st = $db->prepare('SELECT * FROM merchant_virtual_accounts WHERE id = ?');
        $st->execute([$vaRowId]);
        $va = $st->fetch();
        if ($va && (int)$va['fail_count_today'] >= $autoDisableThreshold && $va['status'] === 'active') {
            $wasPrimary = (int)($va['is_primary'] ?? 0) === 1;
            $merchantId = (int)$va['merchant_id'];
            $db->prepare("UPDATE merchant_virtual_accounts SET status = 'disabled' WHERE id = ?")
                ->execute([$vaRowId]);
            if ($wasPrimary) {
                promoteNextPrimaryVirtualAccount($merchantId);
            }
            syncMerchantPrimaryVaMirror($merchantId);
            if (function_exists('createNotification')) {
                createNotification(
                    $merchantId,
                    'Virtual Account auto-disabled',
                    'VA ' . $va['va_number'] . ' had ' . $va['fail_count_today'] . ' failures today and was auto-disabled. Traffic moved to your other VAs. Contact support to re-enable.'
                );
            }
            if (function_exists('logPlatformError')) {
                logPlatformError(
                    'warning',
                    'VA auto-disabled after failures: ' . $va['va_number'] . ' (merchant ' . $merchantId . ')'
                );
            }
        }
    } catch (Throwable $e) { /* ok */ }
}

function findMerchantByVirtualAccountNumber(string $vaNumber): ?array
{
    $vaNumber = trim($vaNumber);
    if ($vaNumber === '') {
        return null;
    }
    ensureMerchantVirtualAccountsTable();
    try {
        $st = getDB()->prepare(
            'SELECT v.id AS va_row_id, v.merchant_id, m.* FROM merchant_virtual_accounts v
            JOIN merchants m ON m.id = v.merchant_id WHERE v.va_number = ? LIMIT 1'
        );
        $st->execute([$vaNumber]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    } catch (Throwable $e) { /* ok */ }

    $st = getDB()->prepare('SELECT *, NULL AS va_row_id FROM merchants WHERE axis_va_number = ? LIMIT 1');
    $st->execute([$vaNumber]);
    $legacy = $st->fetch();
    if ($legacy) {
        importLegacyMerchantVaRow((int)$legacy['id']);
    }
    return $legacy ?: null;
}

function resetVirtualAccountDailyCountersIfNeeded(): int
{
    try {
        $st = getDB()->prepare(
            "UPDATE merchant_virtual_accounts
            SET txn_count_today = 0, fail_count_today = 0, counters_reset_on = CURDATE()
            WHERE counters_reset_on IS NULL OR counters_reset_on < CURDATE()"
        );
        $st->execute();
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
