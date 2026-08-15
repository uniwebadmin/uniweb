<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isAdmin = isAdminLoggedIn();
$isMerchant = !$isAdmin && isLoggedIn();
if (!$isAdmin && !$isMerchant) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'results' => [], 'error' => 'Login required']);
    exit;
}

$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'results' => []]);
    exit;
}

$db = getDB();
$needle = mb_strtolower($q);
$like = '%' . $needle . '%';
$results = [];
$MAX_PER_TYPE = 20;
$MAX_TOTAL = 60;

$add = static function (string $type, string $title, string $subtitle, string $url) use (&$results, $MAX_TOTAL): void {
    if (count($results) >= $MAX_TOTAL) return;
    $key = $type . '|' . $url;
    foreach ($results as $row) {
        if (($row['_key'] ?? '') === $key) return;
    }
    $results[] = ['_key' => $key, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url];
};

/* ── 1) FEATURE / PAGE JUMP (role-aware) ────────────────────── */
$featurePages = [];

if ($isMerchant) {
    $featurePages = [
        ['dashboard.php', 'Dashboard'],
        ['payment_links.php', 'Payment Links'],
        ['merchant_website.php', 'Sales Website'],
        ['merchant_payment_pack.php', 'Payment Pack'],
        ['qr_code.php', 'QR Codes'],
        ['qr_analytics.php', 'QR Analytics'],
        ['collection_settings.php', 'Collection Mode'],
        ['payment_methods.php', 'Payment Methods'],
        ['orders.php', 'Orders'],
        ['transactions.php', 'Transactions'],
        ['reports.php', 'Reports'],
        ['refunds.php', 'Refunds'],
        ['disputes.php', 'Disputes'],
        ['chargebacks.php', 'Chargebacks'],
        ['wallet.php', 'Wallet'],
        ['settlements.php', 'Settlements'],
        ['merchant_payout.php', 'Payouts'],
        ['merchant_payout_keys.php', 'Payout API Keys'],
        ['merchant_settlement_settings.php', 'Settlement Settings'],
        ['beneficiaries.php', 'Beneficiaries'],
        ['merchant_recurring.php', 'Recurring & Mandates'],
        ['invoices.php', 'Invoices'],
        ['agents.php', 'Agents'],
        ['merchant_team.php', 'Team'],
        ['merchant_customer_tickets.php', 'Customer Complaints'],
        ['checkout_customize.php', 'Checkout Customize'],
        ['merchant_agreement.php', 'Agreement'],
        ['merchant_notify_settings.php', 'Notification Settings'],
        ['kyc.php', 'KYC'],
        ['video_kyc.php', 'Video KYC'],
        ['merchant_shop_photos.php', 'Shop Photos'],
        ['merchant_settings.php', 'Settings'],
        ['api_settings.php', 'API Settings'],
        ['merchant_2fa.php', '2FA Security'],
        ['notifications.php', 'Notifications'],
        ['support.php', 'Support'],
        ['merchant_launch.php', 'Launch Center'],
    ];
} else {
    $adminPages = [
        ['admin_dashboard.php', 'Dashboard'],
        ['manage_merchant.php', 'All Merchants'],
        ['add_merchant.php', 'Add Merchant'],
        ['admin_kyc.php', 'KYC Review'],
        ['admin_onboarding_invite.php', 'Onboarding Invites'],
        ['admin_website_reviews.php', 'Website Reviews'],
        ['admin_sub_merchants.php', 'Sub Merchants'],
        ['admin_merchant_health.php', 'Merchant Health'],
        ['admin_customer_view.php', 'Customer Lookup'],
        ['admin_gateway_registry.php', 'Partner Registry'],
        ['gateway_settings.php', 'Platform Settings'],
        ['admin_method_requests.php', 'Method Requests'],
        ['admin_forward_queue.php', 'KYC Forward Queue'],
        ['admin_reason_map.php', 'Reason Maps'],
        ['admin_gateway_submit.php', 'KYC Submissions'],
        ['admin_integration_matrix.php', 'Integration Matrix'],
        ['admin_gateway_matrix.php', 'Gateway Matrix'],
        ['admin_gateway_health.php', 'Gateway Health'],
        ['admin_virtual_accounts.php', 'Virtual Accounts'],
        ['admin_auto_kyc.php', 'Auto KYC Engine'],
        ['admin_partner_requests.php', 'Partner Requests'],
        ['admin_partner_commercial.php', 'Partner Commercial'],
        ['admin_circuit_breaker.php', 'Circuit Breaker'],
        ['admin_webhook_reliability.php', 'Webhook Reliability'],
        ['admin_transactions.php', 'Transactions'],
        ['admin_refunds.php', 'Refunds'],
        ['admin_disputes.php', 'Disputes'],
        ['admin_chargebacks.php', 'Chargebacks'],
        ['admin_financial_reports.php', 'Financial Reports'],
        ['admin_pg_webhooks.php', 'PG Webhooks'],
        ['admin_reconciliation.php', 'PG Reconciliation'],
        ['admin_settlements.php', 'Settlements'],
        ['admin_settlement_settings.php', 'Settlement Engine'],
        ['admin_settlement_batches.php', 'Settlement Batches'],
        ['admin_bulk_payout.php', 'Bulk Payout'],
        ['admin_payout.php', 'Payout Requests'],
        ['admin_bank_reconciliation.php', 'Bank Reconciliation'],
        ['admin_bank_holidays.php', 'Bank Holidays'],
        ['admin_rolling_reserve.php', 'Rolling Reserve'],
        ['admin_support.php', 'Support Tickets'],
        ['admin_customer_tickets.php', 'Customer Complaints'],
        ['admin_aml.php', 'AML Compliance'],
        ['admin_risk.php', 'Risk & AML'],
        ['admin_risk_engine.php', 'Risk Engine'],
        ['admin_grievance.php', 'Grievance Officer'],
        ['admin_platform_status.php', 'Platform Status + Cron Jobs'],
        ['admin_transaction_monitor.php', 'Transaction Monitor'],
        ['admin_throughput.php', 'Throughput Monitor'],
        ['admin_website.php', 'Website & API Keys'],
        ['admin_wallet.php', 'Platform Bank'],
        ['admin_platform_wallet.php', 'Platform Fee Wallet'],
        ['admin_nodal_accounts.php', 'Nodal Accounts'],
        ['admin_reports.php', 'Reports'],
        ['admin_incidents.php', 'Incidents'],
        ['admin_watchdog.php', 'Link Watchdog'],
        ['admin_link_audit.php', 'Link Audit'],
        ['admin_error_log.php', 'Error Log'],
        ['admin_audit_log.php', 'Audit Log'],
        ['admin_ledger_state.php', 'Ledger State Machine'],
        ['admin_encrypt_pii.php', 'Encrypt PII Backfill'],
        ['admin_manage_staff.php', 'Staff / Employees'],
        ['admin_staff_activity.php', 'Staff Activity Log'],
        ['admin_security.php', 'Security & Password'],
        ['admin_security_hardening.php', 'Security Hardening'],
    ];
    foreach ($adminPages as $pg) {
        if (function_exists('staffCanAccess') && !staffCanAccess($pg[0])) continue;
        $featurePages[] = $pg;
    }
}

$featureAliases = [
    'registry' => 'admin_gateway_registry.php',
    'partners' => 'admin_gateway_registry.php',
    'forward queue' => 'admin_forward_queue.php',
    'reason maps' => 'admin_reason_map.php',
    'platform status' => 'admin_platform_status.php',
    'cron' => 'admin_platform_status.php',
    'payment links' => $isMerchant ? 'payment_links.php' : 'admin_payment_links.php',
    'qr' => $isMerchant ? 'qr_code.php' : 'admin_qr_codes.php',
    'settlements' => $isMerchant ? 'settlements.php' : 'admin_settlements.php',
    'recurring' => 'merchant_recurring.php',
    'api keys' => $isMerchant ? 'api_settings.php' : 'admin_website.php',
    'kyc' => $isMerchant ? 'kyc.php' : 'admin_kyc.php',
];

$qlower = mb_strtolower($q);
foreach ($featurePages as [$url, $label]) {
    if (count($results) >= 5) break;
    $labelLower = mb_strtolower($label);
    if (str_contains($labelLower, $qlower) || str_contains(mb_strtolower($url), $qlower)) {
        $add('Page', $label, $url, $url);
    }
}
foreach ($featureAliases as $alias => $url) {
    if (count($results) >= 5) break;
    if (str_contains($alias, $qlower)) {
        foreach ($featurePages as [$furl, $flabel]) {
            if ($furl === $url) {
                $add('Page', $flabel, $url, $url);
                break;
            }
        }
    }
}

/* ── 2) RECORD SEARCH ──────────────────────────────────────── */

/* --- Fast path: exact transaction ID match --- */
if ($isMerchant) {
    $merchant = getMerchant();
    $merchantId = (int)$merchant['id'];
    $isTest = isDashboardTestMode($merchant) ? 1 : 0;

    $stmt = $db->prepare("SELECT txn_id, amount, status, customer_name, customer_phone, utr
        FROM transactions WHERE merchant_id=? AND is_test=? AND (
        LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(utr,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_phone,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_email,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ? OR
        LOWER(CAST(COALESCE(metadata,'') AS CHAR)) LIKE ?)
        ORDER BY created_at DESC LIMIT {$MAX_PER_TYPE}");
    $stmt->execute([$merchantId, $isTest, $like, $like, $like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: $row['customer_phone'] ?: $row['utr'] ?: 'Customer'), transactionDetailUrl((string)$row['txn_id']));
    }

    $settlementParts = ["LOWER(TRIM(COALESCE(settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(utr,''))) LIKE ?"];
    $settlementParams = [$merchantId, $like, $like];
    if (is_numeric($q)) { $settlementParts[] = 'net_amount = ?'; $settlementParams[] = (float)$q; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) { $settlementParts[] = 'DATE(created_at) = ?'; $settlementParams[] = $q; }
    $stmt = $db->prepare("SELECT settlement_id, net_amount, status, utr FROM settlements WHERE merchant_id=? AND (" . implode(' OR ', $settlementParts) . ")
        ORDER BY created_at DESC LIMIT 10");
    $stmt->execute($settlementParams);
    foreach ($stmt->fetchAll() as $row) {
        $add('Settlement', (string)$row['settlement_id'], formatMoney((float)$row['net_amount']) . ' · ' . ucfirst((string)$row['status']) . ($row['utr'] ? ' · ' . $row['utr'] : ''), 'settlement_detail.php?id=' . rawurlencode((string)$row['settlement_id']));
    }

    $stmt = $db->prepare("SELECT link_id, amount, description, status FROM payment_links WHERE merchant_id=? AND is_test=? AND (
        LOWER(TRIM(COALESCE(link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(description,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(status,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$merchantId, $isTest, $like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $add('Payment Link', (string)$row['link_id'], formatMoney((float)$row['amount']) . ' · ' . ($row['description'] ?: ucfirst((string)$row['status'])), 'payment_links.php?q=' . rawurlencode((string)$row['link_id']));
    }

    $stmt = $db->prepare("SELECT id, batch_label, qr_ref, status FROM qr_code_events WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(qr_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(batch_label,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10");
    try {
        $stmt->execute([$merchantId, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $add('QR Code', (string)($row['qr_ref'] ?: 'QR #' . $row['id']), ($row['batch_label'] ?: '') . ' · ' . ucfirst((string)$row['status']), 'qr_code.php?q=' . rawurlencode((string)($row['qr_ref'] ?: $row['id'])));
        }
    } catch (Throwable $e) { /* table may not exist */ }

    $stmt = $db->prepare("SELECT refund_id, amount, status, txn_id FROM refunds WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(refund_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10");
    try {
        $stmt->execute([$merchantId, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $add('Refund', (string)$row['refund_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['txn_id'], 'refunds.php?q=' . rawurlencode((string)$row['refund_id']));
        }
    } catch (Throwable $e) { /* table may not exist */ }

} else {
    $canMerchants = function_exists('staffCanAccess') ? staffCanAccess('manage_merchant.php') : true;
    $canTransactions = function_exists('staffCanAccess') ? staffCanAccess('admin_transactions.php') : true;
    $canSettlements = function_exists('staffCanAccess') ? staffCanAccess('admin_settlements.php') : true;

    /* --- Merchants: name, email, phone, code, ID --- */
    if ($canMerchants) {
        $stmt = $db->prepare("SELECT id, merchant_code, name, business_name, email, phone FROM merchants WHERE status!='deleted' AND (
            LOWER(TRIM(COALESCE(name,''))) LIKE ? OR LOWER(TRIM(COALESCE(business_name,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(merchant_code,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY created_at DESC LIMIT {$MAX_PER_TYPE}");
        $stmt->execute([$like, $like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['id'])) continue;
            $add('Merchant', (string)($row['business_name'] ?: $row['name']), (string)$row['merchant_code'] . ' · ' . ($row['email'] ?: $row['phone']), adminMerchantUrl((int)$row['id']));
        }
    }

    /* --- Merchants by PII hash (PAN/GSTIN/CIN/Aadhaar) --- */
    if ($canMerchants && function_exists('pii_hash') && defined('ENCRYPTION_KEY') && constant('ENCRYPTION_KEY') !== '') {
        $normalizedQuery = strtoupper(preg_replace('/\s+/', '', $q));
        $hashVal = null;
        try { $hashVal = pii_hash($normalizedQuery); } catch (Throwable $e) {}

        if ($hashVal !== null) {
            $hashCols = [
                'pan_hash' => 'PAN',
                'gstin_hash' => 'GSTIN',
                'cin_hash' => 'CIN/LLPIN',
                'aadhaar_hash' => 'Aadhaar',
            ];
            foreach ($hashCols as $col => $label) {
                try {
                    $stmt = $db->prepare("SELECT id, merchant_code, business_name, name FROM merchants WHERE {$col} = ? AND status!='deleted' LIMIT 5");
                    $stmt->execute([$hashVal]);
                    foreach ($stmt->fetchAll() as $row) {
                        if (!staffHasMerchantAccess((int)$row['id'])) continue;
                        $add('Merchant', (string)($row['business_name'] ?: $row['name']), $label . ' match · ' . (string)$row['merchant_code'], adminMerchantUrl((int)$row['id']));
                    }
                } catch (Throwable $e) { /* column may not exist */ }
            }
        }
    }

    /* --- Transactions --- */
    if ($canTransactions) {
        $stmt = $db->prepare("SELECT t.txn_id, t.merchant_id, t.amount, t.status, t.customer_name, t.customer_phone, m.business_name
            FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE (
            LOWER(TRIM(COALESCE(t.txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.utr,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.customer_phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_email,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR
            CAST(t.amount AS CHAR) LIKE ? OR LOWER(CAST(COALESCE(t.metadata,'') AS CHAR)) LIKE ?)
            ORDER BY t.created_at DESC LIMIT {$MAX_PER_TYPE}");
        $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], transactionDetailUrl((string)$row['txn_id']));
        }
    }

    /* --- Settlements --- */
    if ($canSettlements) {
        $settlementParts = ["LOWER(TRIM(COALESCE(s.settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(s.utr,''))) LIKE ?", "LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?"];
        $settlementParams = [$like, $like, $like];
        if (is_numeric($q)) { $settlementParts[] = 's.net_amount = ?'; $settlementParams[] = (float)$q; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) { $settlementParts[] = 'DATE(s.created_at) = ?'; $settlementParams[] = $q; }
        $stmt = $db->prepare("SELECT s.settlement_id, s.merchant_id, s.net_amount, s.status, s.utr, m.business_name
            FROM settlements s JOIN merchants m ON m.id=s.merchant_id WHERE (" . implode(' OR ', $settlementParts) . ")
            ORDER BY s.created_at DESC LIMIT 10");
        $stmt->execute($settlementParams);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('Settlement', (string)$row['settlement_id'], formatMoney((float)$row['net_amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'settlement_detail.php?id=' . rawurlencode((string)$row['settlement_id']));
        }
    }

    /* --- Payment Links (admin) --- */
    try {
        $stmt = $db->prepare("SELECT pl.link_id, pl.amount, pl.description, pl.status, pl.merchant_id, m.business_name
            FROM payment_links pl JOIN merchants m ON m.id=pl.merchant_id WHERE (
            LOWER(TRIM(COALESCE(pl.link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.description,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(pl.link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)
            ORDER BY pl.created_at DESC LIMIT 10");
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'] ?? 0)) continue;
            $add('Payment Link', (string)$row['link_id'], formatMoney((float)$row['amount']) . ' · ' . ($row['description'] ?: ucfirst((string)$row['status'])) . ' · ' . $row['business_name'], 'admin_payment_links.php?q=' . rawurlencode((string)$row['link_id']));
        }
    } catch (Throwable $e) {}

    /* --- QR Codes (admin) --- */
    try {
        $stmt = $db->prepare("SELECT q.id, q.qr_ref, q.batch_label, q.status, m.business_name
            FROM qr_code_events q JOIN merchants m ON m.id=q.merchant_id WHERE (
            LOWER(TRIM(COALESCE(q.qr_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(q.batch_label,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(q.id AS CHAR) LIKE ?)
            ORDER BY q.created_at DESC LIMIT 10");
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            $add('QR Code', (string)($row['qr_ref'] ?: 'QR #' . $row['id']), ($row['batch_label'] ?: '') . ' · ' . $row['business_name'], 'admin_qr_codes.php?q=' . rawurlencode((string)($row['qr_ref'] ?: $row['id'])));
        }
    } catch (Throwable $e) {}

    /* --- Refunds (admin) --- */
    try {
        $stmt = $db->prepare("SELECT r.refund_id, r.amount, r.status, r.txn_id, m.business_name
            FROM refunds r JOIN merchants m ON m.id=r.merchant_id WHERE (
            LOWER(TRIM(COALESCE(r.refund_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(r.txn_id,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(r.amount AS CHAR) LIKE ?)
            ORDER BY r.created_at DESC LIMIT 10");
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'] ?? 0)) continue;
            $add('Refund', (string)$row['refund_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_refunds.php?q=' . rawurlencode((string)$row['refund_id']));
        }
    } catch (Throwable $e) {}

    /* --- Mandates (admin) --- */
    try {
        $stmt = $db->prepare("SELECT mandate_id, status, merchant_id, amount FROM mandates WHERE (
            LOWER(TRIM(COALESCE(mandate_id,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
            ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('Mandate', (string)$row['mandate_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']), 'merchant_recurring.php?q=' . rawurlencode((string)$row['mandate_id']));
        }
    } catch (Throwable $e) {}

    /* --- Forward Queue (admin) --- */
    try {
        $stmt = $db->prepare("SELECT fq.id, fq.partner_key, fq.status, m.business_name
            FROM partner_forward_queue fq JOIN merchants m ON m.id=fq.merchant_id WHERE (
            LOWER(TRIM(COALESCE(fq.partner_key,''))) LIKE ? OR LOWER(TRIM(COALESCE(fq.status,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(fq.id AS CHAR) LIKE ?)
            ORDER BY fq.created_at DESC LIMIT 10");
        $stmt->execute([$like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('Forward Queue', '#' . $row['id'] . ' · ' . $row['partner_key'], ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_forward_queue.php?q=' . rawurlencode((string)$row['id']));
        }
    } catch (Throwable $e) {}

    /* --- KYC Verifications (admin) --- */
    try {
        $stmt = $db->prepare("SELECT kv.id, kv.verification_status, kv.merchant_id, m.business_name
            FROM kyc_verifications kv JOIN merchants m ON m.id=kv.merchant_id WHERE (
            LOWER(TRIM(COALESCE(kv.verification_status,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(kv.id AS CHAR) LIKE ?)
            ORDER BY kv.created_at DESC LIMIT 10");
        $stmt->execute([$like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('KYC', '#' . $row['id'] . ' · ' . $row['business_name'], ucfirst((string)$row['verification_status']), 'admin_kyc.php?q=' . rawurlencode((string)$row['id']));
        }
    } catch (Throwable $e) {}
}

/* ── 3) Search audit log (masked, no full PII) ─────────────── */
try {
    $auditType = $isAdmin ? 'admin' : 'merchant';
    $maskedQ = mb_strlen($q) > 4 ? mb_substr($q, 0, 2) . '**' . mb_substr($q, -2) : 'short';
    if (function_exists('logStaffActivity') && $isAdmin) {
        logStaffActivity('search', "Global search: type={$auditType} q_masked={$maskedQ}", null, null, null);
    }
} catch (Throwable $e) {}

foreach ($results as &$row) unset($row['_key']);
echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
