<?php
declare(strict_types=1);

if (is_file(__DIR__ . '/txn_partner.php')) {
    require_once __DIR__ . '/txn_partner.php';
}

/** Partners with settlement CSV upload wired in admin_reconciliation. */
function reconcilePartnerSettlementFileWired(string $partnerKey): bool
{
    return in_array(normalizeTxnPartnerKey($partnerKey), ['razorpay', 'cashfree', 'payu', 'axis'], true);
}

/** Partners with server poll path for manual payment reconcile. */
function reconcilePartnerPollApiWired(string $partnerKey): bool
{
    $key = normalizeTxnPartnerKey($partnerKey);
    if ($key === 'sandbox') {
        return false;
    }
    if (function_exists('partnerGatewayConfigured')) {
        return partnerGatewayConfigured($key);
    }
    return in_array($key, ['razorpay', 'cashfree', 'payu', 'decentro'], true);
}

/**
 * @return array{mode:string,label:string,detail:string}
 */
function reconcilePartnerOpsStatus(string $partnerKey): array
{
    $key = normalizeTxnPartnerKey($partnerKey);
    if ($key === '') {
        return ['mode' => 'unknown', 'label' => 'Partner unknown', 'detail' => 'No partner_key on transaction.'];
    }
    if ($key === 'sandbox') {
        return ['mode' => 'test', 'label' => 'Sandbox (test)', 'detail' => 'Test payments — no live settlement file reconcile.'];
    }
    $fileWired = reconcilePartnerSettlementFileWired($key);
    $pollWired = reconcilePartnerPollApiWired($key);
    if ($fileWired || $pollWired) {
        $bits = [];
        if ($fileWired) {
            $bits[] = 'settlement CSV';
        }
        if ($pollWired) {
            $bits[] = 'status poll';
        }
        return [
            'mode' => 'wired',
            'label' => transactionPartnerLabel($key),
            'detail' => 'Reconcile via ' . implode(' + ', $bits) . '.',
        ];
    }
    return [
        'mode' => 'manual',
        'label' => transactionPartnerLabel($key),
        'detail' => 'Not wired — manual review only (no auto-match).',
    ];
}

/**
 * Registry partners that are Active or have txn volume in the window.
 *
 * @return list<array{partner_key:string,label:string,txn_count:int,success_count:int,mode:string}>
 */
function reconcileRegistryPartnerFilterOptions(int $days = 30): array
{
    $days = max(1, min(90, $days));
    $volumes = getReconciliationPartnerVolumes($days);
    $seen = [];
    foreach ($volumes as $row) {
        $pk = (string)($row['partner_key'] ?? '');
        if ($pk === '') {
            continue;
        }
        $seen[$pk] = $row;
    }
    if (function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
        foreach (getPartnerRegistry() as $pk => $meta) {
            $pk = normalizeTxnPartnerKey((string)$pk);
            if ($pk === '' || isset($seen[$pk])) {
                continue;
            }
            if (!empty($meta['is_active']) || !empty($meta['active'])) {
                $seen[$pk] = [
                    'partner_key' => $pk,
                    'label' => transactionPartnerLabel($pk),
                    'txn_count' => 0,
                    'success_count' => 0,
                    'ops' => reconcilePartnerOpsStatus($pk),
                ];
            }
        }
    }
    uasort($seen, static fn(array $a, array $b): int => ((int)($b['txn_count'] ?? 0)) <=> ((int)($a['txn_count'] ?? 0)));
    return array_values($seen);
}

/**
 * @return list<array{partner_key:string,label:string,txn_count:int,success_count:int,pending_count:int,failed_count:int,total_amount:float,success_amount:float,ops:array}>
 */
function getReconciliationPartnerVolumes(int $days = 7, ?string $filterPartner = null): array
{
    if (function_exists('ensureTransactionPartnerKeyColumn')) {
        require_once __DIR__ . '/schema_ensure.php';
        ensureTransactionPartnerKeyColumn();
    }
    $days = max(1, min(90, $days));
    $db = getDB();
    $sql = "SELECT id, partner_key, payment_method, status, amount
            FROM transactions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)";
    $params = [$days];
    if ($filterPartner !== null && $filterPartner !== '') {
        $filterPartner = normalizeTxnPartnerKey($filterPartner);
        $sql .= ' AND (partner_key = ? OR (partner_key IS NULL AND payment_method = ?))';
        $params[] = $filterPartner;
        $params[] = $filterPartner;
    }
    $st = $db->prepare($sql);
    $st->execute($params);
    $buckets = [];
    while ($row = $st->fetch()) {
        $pk = function_exists('resolveTxnPartnerKeyFromTransaction')
            ? resolveTxnPartnerKeyFromTransaction($row)
            : normalizeTxnPartnerKey((string)($row['partner_key'] ?? $row['payment_method'] ?? ''));
        if ($pk === '') {
            $pk = 'unknown';
        }
        if (!isset($buckets[$pk])) {
            $buckets[$pk] = [
                'partner_key' => $pk,
                'label' => transactionPartnerLabel($pk),
                'txn_count' => 0,
                'success_count' => 0,
                'pending_count' => 0,
                'failed_count' => 0,
                'total_amount' => 0.0,
                'success_amount' => 0.0,
            ];
        }
        $buckets[$pk]['txn_count']++;
        $amt = (float)($row['amount'] ?? 0);
        $buckets[$pk]['total_amount'] += $amt;
        $status = strtolower((string)($row['status'] ?? ''));
        if (in_array($status, ['success', 'paid', 'captured'], true)) {
            $buckets[$pk]['success_count']++;
            $buckets[$pk]['success_amount'] += $amt;
        } elseif (in_array($status, ['pending', 'processing', 'initiated'], true)) {
            $buckets[$pk]['pending_count']++;
        } elseif (in_array($status, ['failed', 'error'], true)) {
            $buckets[$pk]['failed_count']++;
        }
    }
    foreach ($buckets as $pk => $bucket) {
        $buckets[$pk]['ops'] = reconcilePartnerOpsStatus($pk);
    }
    uasort($buckets, static fn(array $a, array $b): int => $b['txn_count'] <=> $a['txn_count']);
    return array_values($buckets);
}

function resolveDisputePartnerKey(array $disputeRow, ?array $txnRow = null): string
{
    $stored = normalizeTxnPartnerKey((string)($disputeRow['partner_key'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    if ($txnRow !== null) {
        return function_exists('resolveTxnPartnerKeyFromTransaction')
            ? resolveTxnPartnerKeyFromTransaction($txnRow)
            : normalizeTxnPartnerKey((string)($txnRow['partner_key'] ?? $txnRow['payment_method'] ?? ''));
    }
    $txnId = (int)($disputeRow['transaction_id'] ?? 0);
    if ($txnId > 0) {
        try {
            $st = getDB()->prepare('SELECT partner_key, payment_method FROM transactions WHERE id=? LIMIT 1');
            $st->execute([$txnId]);
            $txn = $st->fetch();
            if ($txn) {
                return resolveTxnPartnerKeyFromTransaction($txn);
            }
        } catch (Throwable $e) { /* ok */ }
    }
    return '';
}

function disputePartnerKeyForTransaction(array $txnRow): string
{
    return function_exists('resolveTxnPartnerKeyFromTransaction')
        ? resolveTxnPartnerKeyFromTransaction($txnRow)
        : normalizeTxnPartnerKey((string)($txnRow['partner_key'] ?? $txnRow['payment_method'] ?? ''));
}

function ensureDisputePartnerKeyColumn(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (!function_exists('schemaExecQuiet') && is_file(__DIR__ . '/schema_ensure.php')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    if (function_exists('schemaExecQuiet')) {
        schemaExecQuiet('ALTER TABLE disputes ADD COLUMN partner_key VARCHAR(40) DEFAULT NULL AFTER transaction_id');
        schemaExecQuiet('ALTER TABLE disputes ADD INDEX idx_dispute_partner_key (partner_key)');
    }
}

function ensureSupportTicketPartnerTagColumn(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    if (function_exists('schemaExecQuiet')) {
        schemaExecQuiet('ALTER TABLE support_tickets ADD COLUMN partner_tag VARCHAR(40) DEFAULT NULL AFTER txn_reference');
    }
}

/**
 * Fee report by collect partner (partner_key). Report + invoice source only —
 * UniWeb does not hold customer rupees on this page.
 *
 * @return array{rows:list<array<string,mixed>>,totals:array<string,float|int>}
 */
function getPartnerFeeReport(int $days = 30): array
{
    if (function_exists('ensureTransactionPartnerKeyColumn') && is_file(__DIR__ . '/schema_ensure.php')) {
        require_once __DIR__ . '/schema_ensure.php';
        ensureTransactionPartnerKeyColumn();
    }
    $days = max(1, min(365, $days));
    $emptyRow = static fn(): array => [
        'key' => '',
        'name' => '',
        'icon' => '',
        'type' => '',
        'configured' => false,
        'total_txns' => 0,
        'success_txns' => 0,
        'failed_txns' => 0,
        'success_rate' => 0.0,
        'total_volume' => 0.0,
        'avg_amount' => 0.0,
        'uniweb_fee' => 0.0,
        'partner_fee' => 0.0,
        'gst_on_fee' => 0.0,
        'avg_settlement_hours' => 0.0,
        'settlement_hour_weight' => 0,
        'pending_settlements' => 0,
        'chargebacks' => 0,
    ];
    $buckets = [];
    $touch = static function (string $pk) use (&$buckets, $emptyRow): void {
        if ($pk === '') {
            $pk = 'unknown';
        }
        if (!isset($buckets[$pk])) {
            $row = $emptyRow();
            $row['key'] = $pk;
            $row['name'] = $pk === 'unknown' ? 'Unknown (no partner_key)' : transactionPartnerLabel($pk);
            if (function_exists('getPartnerRegistry')) {
                $reg = getPartnerRegistry()[$pk] ?? [];
                $row['icon'] = (string)($reg['icon'] ?? '');
                $row['type'] = (string)($reg['type'] ?? '');
                if ($pk !== 'unknown' && !empty($reg['name'])) {
                    $row['name'] = (string)$reg['name'];
                }
            }
            $row['configured'] = $pk !== 'unknown' && function_exists('partnerIsConfigured') && partnerIsConfigured($pk);
            $buckets[$pk] = $row;
        }
    };
    $resolveGroup = static function (array $row): string {
        $pk = function_exists('resolveTxnPartnerKeyFromTransaction')
            ? resolveTxnPartnerKeyFromTransaction($row)
            : normalizeTxnPartnerKey((string)($row['partner_key'] ?? ''));
        return $pk !== '' ? $pk : 'unknown';
    };

    if (function_exists('getPartnerRegistry')) {
        foreach (array_keys(getPartnerRegistry()) as $pk) {
            $touch(normalizeTxnPartnerKey((string)$pk));
        }
    }

    $db = getDB();
    $sqlFull = "SELECT
            COALESCE(partner_key,'') AS partner_key,
            COALESCE(payment_method,'') AS payment_method,
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('success','paid','captured') THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status IN ('failed','error') THEN 1 ELSE 0 END) AS failed,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN amount ELSE 0 END),0) AS volume,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN platform_fee ELSE 0 END),0) AS uniweb_fee,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN partner_fee ELSE 0 END),0) AS partner_fee,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN gst_on_fee ELSE 0 END),0) AS gst_on_fee
         FROM transactions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY partner_key, payment_method";
    $sqlLite = "SELECT
            COALESCE(partner_key,'') AS partner_key,
            COALESCE(payment_method,'') AS payment_method,
            COUNT(*) AS total,
            SUM(CASE WHEN status IN ('success','paid','captured') THEN 1 ELSE 0 END) AS success,
            SUM(CASE WHEN status IN ('failed','error') THEN 1 ELSE 0 END) AS failed,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN amount ELSE 0 END),0) AS volume,
            COALESCE(SUM(CASE WHEN status IN ('success','paid','captured') THEN platform_fee ELSE 0 END),0) AS uniweb_fee,
            0 AS partner_fee,
            0 AS gst_on_fee
         FROM transactions
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         GROUP BY partner_key, payment_method";
    $groups = [];
    try {
        $st = $db->prepare($sqlFull);
        $st->execute([$days]);
        $groups = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        try {
            $st = $db->prepare($sqlLite);
            $st->execute([$days]);
            $groups = $st->fetchAll() ?: [];
        } catch (Throwable $e2) {
            $groups = [];
        }
    }
    foreach ($groups as $g) {
        $pk = $resolveGroup($g);
        $touch($pk);
        $buckets[$pk]['total_txns'] += (int)($g['total'] ?? 0);
        $buckets[$pk]['success_txns'] += (int)($g['success'] ?? 0);
        $buckets[$pk]['failed_txns'] += (int)($g['failed'] ?? 0);
        $buckets[$pk]['total_volume'] += (float)($g['volume'] ?? 0);
        $buckets[$pk]['uniweb_fee'] += (float)($g['uniweb_fee'] ?? 0);
        $buckets[$pk]['partner_fee'] += (float)($g['partner_fee'] ?? 0);
        $buckets[$pk]['gst_on_fee'] += (float)($g['gst_on_fee'] ?? 0);
    }

    try {
        $st = $db->prepare(
            "SELECT t.partner_key, t.payment_method,
                    AVG(TIMESTAMPDIFF(HOUR, t.created_at, s.processed_at)) AS avg_hours,
                    COUNT(*) AS n
             FROM transactions t
             JOIN settlements s ON s.transaction_id = t.id
             WHERE s.status='completed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY t.partner_key, t.payment_method"
        );
        $st->execute([$days]);
        while ($g = $st->fetch()) {
            $pk = $resolveGroup($g);
            $touch($pk);
            $n = (int)($g['n'] ?? 0);
            $avg = (float)($g['avg_hours'] ?? 0);
            if ($n <= 0) {
                continue;
            }
            $prevW = (int)$buckets[$pk]['settlement_hour_weight'];
            $prevAvg = (float)$buckets[$pk]['avg_settlement_hours'];
            $newW = $prevW + $n;
            $buckets[$pk]['avg_settlement_hours'] = $newW > 0
                ? round((($prevAvg * $prevW) + ($avg * $n)) / $newW, 1)
                : 0.0;
            $buckets[$pk]['settlement_hour_weight'] = $newW;
        }
    } catch (Throwable $e) { /* settlements table optional */ }

    try {
        $st = $db->prepare(
            "SELECT t.partner_key, t.payment_method, COUNT(*) AS n
             FROM settlements s
             JOIN transactions t ON t.id = s.transaction_id
             WHERE s.status IN ('pending','processing')
               AND t.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY t.partner_key, t.payment_method"
        );
        $st->execute([$days]);
        while ($g = $st->fetch()) {
            $pk = $resolveGroup($g);
            $touch($pk);
            $buckets[$pk]['pending_settlements'] += (int)($g['n'] ?? 0);
        }
    } catch (Throwable $e) { /* ok */ }

    try {
        $st = $db->prepare(
            "SELECT t.partner_key, t.payment_method, COUNT(*) AS n
             FROM chargebacks c
             JOIN transactions t ON t.id = c.transaction_id
             WHERE c.created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
             GROUP BY t.partner_key, t.payment_method"
        );
        $st->execute([$days]);
        while ($g = $st->fetch()) {
            $pk = $resolveGroup($g);
            $touch($pk);
            $buckets[$pk]['chargebacks'] += (int)($g['n'] ?? 0);
        }
    } catch (Throwable $e) { /* chargebacks optional */ }

    $totals = [
        'txns' => 0,
        'success' => 0,
        'volume' => 0.0,
        'fees' => 0.0,
        'partner_fee' => 0.0,
        'gst_on_fee' => 0.0,
        'chargebacks' => 0,
    ];
    foreach ($buckets as $pk => $row) {
        $buckets[$pk]['success_rate'] = $row['total_txns'] > 0
            ? round($row['success_txns'] / $row['total_txns'] * 100, 1)
            : 0.0;
        $buckets[$pk]['avg_amount'] = $row['success_txns'] > 0
            ? round($row['total_volume'] / $row['success_txns'], 2)
            : 0.0;
        unset($buckets[$pk]['settlement_hour_weight']);
        $totals['txns'] += $row['total_txns'];
        $totals['success'] += $row['success_txns'];
        $totals['volume'] += $row['total_volume'];
        $totals['fees'] += $row['uniweb_fee'];
        $totals['partner_fee'] += $row['partner_fee'];
        $totals['gst_on_fee'] += $row['gst_on_fee'];
        $totals['chargebacks'] += $row['chargebacks'];
    }
    uasort($buckets, static fn(array $a, array $b): int => ((int)$b['total_txns']) <=> ((int)$a['total_txns']));
    if (isset($buckets['unknown']) && (int)$buckets['unknown']['total_txns'] === 0) {
        unset($buckets['unknown']);
    }

    return ['rows' => array_values($buckets), 'totals' => $totals];
}
