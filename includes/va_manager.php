<?php
declare(strict_types=1);

/**
 * Multiple Virtual Account (VA) manager.
 *
 * Backward compatible with the single-VA columns on `merchants`
 * (axis_va_number / axis_va_ifsc / axis_va_upi / axis_va_id) which stay as
 * the "primary" VA for old code paths. This file adds the ability for a
 * merchant to hold MANY active VAs and spreads new payment load across them
 * (least-busy assignment) instead of a single VA becoming a bottleneck.
 */

/** Gateways with a live VA-creation adapter today (others fail gracefully). */
function vaSupportedCreationGateways(): array
{
    return ['axis'];
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
}

/** All VAs for a merchant, most-used-last-reset first. Includes the primary. */
function getMerchantVirtualAccounts(int $merchantId): array
{
    try {
        $st = getDB()->prepare('SELECT * FROM merchant_virtual_accounts WHERE merchant_id = ? ORDER BY is_primary DESC, id ASC');
        $st->execute([$merchantId]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function countActiveMerchantVirtualAccounts(int $merchantId): int
{
    try {
        $st = getDB()->prepare("SELECT COUNT(*) FROM merchant_virtual_accounts WHERE merchant_id = ? AND status = 'active'");
        $st->execute([$merchantId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Create ONE MORE virtual account for a merchant (in addition to any existing
 * ones) via the given gateway. Currently only 'axis' is wired to a real API;
 * other gateway keys are accepted for forward compatibility (e.g. 'decentro'
 * once its adapter lands) and will simply fail gracefully until implemented.
 */
function createAdditionalVirtualAccount(int $merchantId, string $gateway = 'axis', string $label = ''): array
{
    $db = getDB();
    $m = $db->prepare('SELECT * FROM merchants WHERE id = ?');
    $m->execute([$merchantId]);
    $merchant = $m->fetch();
    if (!$merchant) {
        return ['ok' => false, 'error' => 'Merchant not found.'];
    }

    if (!in_array($gateway, vaSupportedCreationGateways(), true) || !function_exists('createAxisVirtualAccount')) {
        return ['ok' => false, 'error' => 'Gateway "' . $gateway . '" VA creation is not live yet. Supported: ' . implode(', ', vaSupportedCreationGateways()) . '.'];
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

    if ($gateway !== 'axis') {
        return ['ok' => false, 'error' => 'Only axis is wired for VA creation today.'];
    }

    $va = createAxisVirtualAccount($merchant);
    if (!$va || empty($va['va_number'])) {
        $hint = function_exists('axisAllowMock') && axisAllowMock()
            ? 'Mock VA is ON but creation still failed — check Error Log.'
            : 'Axis did not return a VA in ~15s. Check Partner Registry → Axis keys, IP whitelist, and Error Log.';
        return ['ok' => false, 'error' => $hint];
    }

    $isFirst = countActiveMerchantVirtualAccounts($merchantId) === 0 && empty($merchant['axis_va_number']);
    try {
        $db->prepare('INSERT INTO merchant_virtual_accounts (merchant_id, gateway, va_id, va_number, ifsc, upi_id, label, status, is_primary, counters_reset_on)
            VALUES (?,?,?,?,?,?,?,?,?,CURDATE())')
            ->execute([
                $merchantId, $gateway,
                $va['va_id'] ?? '', $va['va_number'], $va['ifsc'] ?? null, $va['upi_id'] ?? null,
                $label !== '' ? $label : ('VA ' . (countActiveMerchantVirtualAccounts($merchantId) + 1)),
                'active', $isFirst ? 1 : 0,
            ]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save virtual account: ' . $e->getMessage()];
    }

    // Keep legacy single-VA columns populated for old code paths when this is
    // the merchant's first-ever VA.
    if ($isFirst) {
        try {
            $db->prepare('UPDATE merchants SET axis_va_id=?, axis_va_number=?, axis_va_ifsc=?, axis_va_upi=? WHERE id=?')
                ->execute([$va['va_id'] ?? '', $va['va_number'], $va['ifsc'] ?? null, $va['upi_id'] ?? null, $merchantId]);
        } catch (Throwable $e) {
            // non-fatal
        }
    }

    if (function_exists('recordImmutableAudit')) {
        recordImmutableAudit('va_created', null, 'merchant', (string)$merchantId, $va['va_number']);
    }

    return ['ok' => true, 'va' => $va];
}

/**
 * Smart assignment: pick the least-busy ACTIVE virtual account for a
 * merchant so no single VA gets overloaded. Falls back to null when the
 * merchant has no VA rows yet (caller should use the legacy single-VA path
 * via ensureAxisVirtualAccount()).
 */
function pickLeastBusyVirtualAccount(int $merchantId): ?array
{
    try {
        $st = getDB()->prepare("SELECT * FROM merchant_virtual_accounts
            WHERE merchant_id = ? AND status = 'active'
            ORDER BY txn_count_today ASC, last_assigned_at ASC, id ASC
            LIMIT 1");
        $st->execute([$merchantId]);
        $va = $st->fetch();
        return $va ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Call after a payment is successfully routed through a specific VA row. */
function recordVirtualAccountUsage(int $vaRowId): void
{
    try {
        getDB()->prepare('UPDATE merchant_virtual_accounts
            SET txn_count_today = txn_count_today + 1, txn_count_total = txn_count_total + 1, last_assigned_at = NOW()
            WHERE id = ?')->execute([$vaRowId]);
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Call when a VA-routed payment fails/bounces. Auto-disables the VA (and
 * alerts) once it crosses a failure threshold in a single day, so traffic
 * moves to the merchant's other VAs automatically.
 */
function recordVirtualAccountFailure(int $vaRowId, int $autoDisableThreshold = 10): void
{
    $db = getDB();
    try {
        $db->prepare('UPDATE merchant_virtual_accounts SET fail_count_today = fail_count_today + 1 WHERE id = ?')
            ->execute([$vaRowId]);
        $st = $db->prepare('SELECT * FROM merchant_virtual_accounts WHERE id = ?');
        $st->execute([$vaRowId]);
        $va = $st->fetch();
        if ($va && (int)$va['fail_count_today'] >= $autoDisableThreshold && $va['status'] === 'active') {
            $db->prepare("UPDATE merchant_virtual_accounts SET status = 'disabled' WHERE id = ?")->execute([$vaRowId]);
            if (function_exists('createNotification')) {
                createNotification((int)$va['merchant_id'], 'Virtual Account auto-disabled',
                    'VA ' . $va['va_number'] . ' had ' . $va['fail_count_today'] . ' failures today and was auto-disabled. Traffic moved to your other VAs. Contact support to re-enable.');
            }
            if (function_exists('logPlatformError')) {
                logPlatformError('warning', 'VA auto-disabled after failures: ' . $va['va_number'] . ' (merchant ' . $va['merchant_id'] . ')');
            }
        }
    } catch (Throwable $e) {
        // non-fatal
    }
}

/** Look up which merchant + VA row a bank VA number belongs to (multi-VA aware). */
function findMerchantByVirtualAccountNumber(string $vaNumber): ?array
{
    $db = getDB();
    try {
        $st = $db->prepare('SELECT v.id AS va_row_id, v.merchant_id, m.* FROM merchant_virtual_accounts v
            JOIN merchants m ON m.id = v.merchant_id WHERE v.va_number = ? LIMIT 1');
        $st->execute([$vaNumber]);
        $row = $st->fetch();
        if ($row) {
            return $row;
        }
    } catch (Throwable $e) {
        // table may not exist yet — fall through to legacy lookup
    }
    // Legacy fallback: single VA stored directly on merchants.
    $st = $db->prepare('SELECT *, NULL AS va_row_id FROM merchants WHERE axis_va_number = ? LIMIT 1');
    $st->execute([$vaNumber]);
    return $st->fetch() ?: null;
}

/** Daily counter reset — safe to call on every cron tick, only resets once/day. */
function resetVirtualAccountDailyCountersIfNeeded(): int
{
    try {
        $st = getDB()->prepare("UPDATE merchant_virtual_accounts
            SET txn_count_today = 0, fail_count_today = 0, counters_reset_on = CURDATE()
            WHERE counters_reset_on IS NULL OR counters_reset_on < CURDATE()");
        $st->execute();
        return $st->rowCount();
    } catch (Throwable $e) {
        return 0;
    }
}
