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
