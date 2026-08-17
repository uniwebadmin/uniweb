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
if (!function_exists('uniwebMerchantSearchPages')) {
    require_once __DIR__ . '/includes/sidebar_nav.php';
}
$idPrefix = uniwebSearchIdPrefix($q);
$minLen = 2;
if (mb_strlen($q) < $minLen) {
    echo json_encode(['ok' => true, 'results' => [], 'hint' => 'Type at least 2 characters, or an ID like TXN / LNK / GSTIN / PAN']);
    exit;
}

$db = getDB();
$needle = mb_strtolower($q);
$like = '%' . $needle . '%';
$results = [];
$MAX_PER_TYPE = 20;
$MAX_TOTAL = 60;

$add = static function (string $type, string $title, string $subtitle, string $url) use (&$results, $MAX_TOTAL): void {
    if (count($results) >= $MAX_TOTAL) {
        return;
    }
    $key = $type . '|' . $url;
    foreach ($results as $row) {
        if (($row['_key'] ?? '') === $key) {
            return;
        }
    }
    $results[] = ['_key' => $key, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url];
};

$fetchRows = static function (string $sql, array $params) use ($db): array {
    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
};

$canPage = static function (string $page): bool {
    if (function_exists('isSuperAdmin') && isSuperAdmin()) {
        return true;
    }
    return function_exists('staffCanAccess') ? staffCanAccess($page) : true;
};

/* ── 1) FEATURE / PAGE JUMP (1:1 with header nav, role-scoped) ── */
if ($isMerchant) {
    $featurePages = uniwebMerchantSearchPages();
} elseif (function_exists('isSuperAdmin') && isSuperAdmin()) {
    $featurePages = uniwebAdminSearchPages();
} else {
    $featurePages = function_exists('staffNavForRole') ? staffNavForRole(adminRole()) : uniwebAdminSearchPages();
}

$featureAliases = [
    'registry' => 'admin_gateway_registry.php',
    'partners' => 'admin_gateway_registry.php',
    'partner registry' => 'admin_gateway_registry.php',
    'today' => 'admin_kyc.php',
    'owner today' => 'admin_kyc.php',
    'orchestrator' => 'admin_gateway_registry.php',
    'gateway orchestrator' => 'admin_gateway_registry.php',
    'forward queue' => 'admin_forward_queue.php',
    'reason maps' => 'admin_reason_map.php',
    'platform status' => 'admin_platform_status.php',
    'platform settings' => 'gateway_settings.php',
    'smtp' => 'gateway_settings.php',
    'cron' => 'admin_platform_status.php',
    'watchdog' => 'admin_watchdog.php',
    'link audit' => 'admin_watchdog.php',
    'mdr' => 'admin_partner_commercial.php',
    'commercial' => 'admin_partner_commercial.php',
    '2fa' => $isMerchant ? 'merchant_2fa.php' : 'admin_security.php',
    'totp' => $isMerchant ? 'merchant_2fa.php' : 'admin_security.php',
    'encrypt pii' => 'admin_encrypt_pii.php',
    'pii' => 'admin_encrypt_pii.php',
    'payment links' => $isMerchant ? 'payment_links.php' : 'admin_payment_links.php',
    'payment link' => $isMerchant ? 'payment_links.php' : 'admin_payment_links.php',
    'qr' => $isMerchant ? 'qr_code.php' : 'admin_qr_codes.php',
    'qr codes' => $isMerchant ? 'qr_code.php' : 'admin_qr_codes.php',
    'settlements' => $isMerchant ? 'settlements.php' : 'admin_settlements.php',
    'settlement balance' => 'wallet.php',
    'wallet' => $isMerchant ? 'wallet.php' : 'admin_wallet.php',
    'launch' => 'merchant_launch.php',
    'launch center' => 'merchant_launch.php',
    'recurring' => 'merchant_recurring.php',
    'mandates' => 'merchant_recurring.php',
    'api keys' => $isMerchant ? 'api_settings.php' : 'admin_website.php',
    'merchant api' => $isMerchant ? 'api_settings.php' : 'admin_website.php',
    'kyc' => $isMerchant ? 'kyc.php' : 'admin_kyc.php',
    'video kyc' => $isMerchant ? 'kyc.php?section=video' : 'admin_kyc.php',
    'gstin' => $isMerchant ? 'kyc.php' : 'manage_merchant.php',
    'pan' => $isMerchant ? 'kyc.php' : 'manage_merchant.php',
    'staff' => 'admin_manage_staff.php',
    'employees' => 'admin_manage_staff.php',
    'tickets' => $isMerchant ? 'support.php' : 'admin_support.php',
    'complaints' => $isMerchant ? 'merchant_customer_tickets.php' : 'admin_customer_tickets.php',
    'customer complaints' => $isMerchant ? 'merchant_customer_tickets.php' : 'admin_customer_tickets.php',
    'monitor' => 'admin_transaction_monitor.php',
    'throughput' => 'admin_transaction_monitor.php',
    'tps' => 'admin_transaction_monitor.php',
    'reports' => 'admin_financial_reports.php',
    'financial reports' => 'admin_financial_reports.php',
    'aml' => 'admin_aml.php',
    'risk' => 'admin_risk.php',
    'chargeback' => 'admin_chargebacks.php',
    'chargebacks' => 'admin_chargebacks.php',
    'disputes' => $isMerchant ? 'disputes.php' : 'admin_disputes.php',
    'method request' => 'admin_method_requests.php',
    'method requests' => 'admin_method_requests.php',
    'virtual account' => 'admin_virtual_accounts.php',
    'virtual accounts' => 'admin_virtual_accounts.php',
    'va' => 'admin_virtual_accounts.php',
    'payout' => $isMerchant ? 'merchant_payout.php' : 'admin_payout.php',
    'payouts' => $isMerchant ? 'merchant_payout.php' : 'admin_payout.php',
    'checkout customize' => 'checkout_customize.php',
    'error log' => 'admin_error_log.php',
    'api docs' => 'api_docs.php',
    'webhooks' => $isMerchant ? 'api_settings.php' : 'admin_gateway_registry.php',
];

$qlower = mb_strtolower($q);
$pageHits = 0;
foreach ($featurePages as [$url, $label]) {
    if ($pageHits >= 12) {
        break;
    }
    $labelLower = mb_strtolower((string)$label);
    if (str_contains($labelLower, $qlower) || str_contains(mb_strtolower((string)$url), $qlower)) {
        $add('Page', (string)$label, (string)$url, (string)$url);
        $pageHits++;
    }
}
foreach ($featureAliases as $alias => $url) {
    if ($pageHits >= 12) {
        break;
    }
    if (!str_contains($alias, $qlower) && $alias !== $qlower) {
        continue;
    }
    $baseUrl = strtok($url, '?') ?: $url;
    $matchedLabel = null;
    foreach ($featurePages as [$furl, $flabel]) {
        if ($furl === $baseUrl || $furl === $url) {
            $matchedLabel = (string)$flabel;
            break;
        }
    }
    if ($matchedLabel === null && $isAdmin && $canPage($baseUrl)) {
        $matchedLabel = ucwords(str_replace(['_', '.php'], [' ', ''], basename($baseUrl)));
    }
    if ($matchedLabel !== null) {
        $add('Page', $matchedLabel, (string)$url, (string)$url);
        $pageHits++;
    }
}

if (function_exists('uwDetectIdKind') && function_exists('uwResolveIdUrl')) {
    $idKind = uwDetectIdKind($q);
    if ($idKind) {
        $idUrl = uwResolveIdUrl($q);
        if ($idUrl) {
            $add('ID', strtoupper($q), $idKind . ' · Open', $idUrl);
        }
    }
}

/* ── 2) RECORD SEARCH ──────────────────────────────────────── */
if ($isMerchant) {
    $merchant = getMerchant();
    $merchantId = (int)$merchant['id'];
    $isTest = isDashboardTestMode($merchant) ? 1 : 0;

    foreach ($fetchRows(
        "SELECT txn_id, amount, status, customer_name, customer_phone, utr
        FROM transactions WHERE merchant_id=? AND is_test=? AND (
        LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(utr,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_phone,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(customer_email,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT {$MAX_PER_TYPE}",
        [$merchantId, $isTest, $like, $like, $like, $like, $like, $like]
    ) as $row) {
        $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: $row['customer_phone'] ?: $row['utr'] ?: 'Customer'), transactionDetailUrl((string)$row['txn_id']));
    }

    $settlementParts = ["LOWER(TRIM(COALESCE(settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(utr,''))) LIKE ?"];
    $settlementParams = [$merchantId, $like, $like];
    if (is_numeric($q)) {
        $settlementParts[] = 'net_amount = ?';
        $settlementParams[] = (float)$q;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) {
        $settlementParts[] = 'DATE(created_at) = ?';
        $settlementParams[] = $q;
    }
    foreach ($fetchRows(
        'SELECT settlement_id, net_amount, status, utr FROM settlements WHERE merchant_id=? AND (' . implode(' OR ', $settlementParts) . ') ORDER BY created_at DESC LIMIT 10',
        $settlementParams
    ) as $row) {
        $add('Settlement', (string)$row['settlement_id'], formatMoney((float)$row['net_amount']) . ' · ' . ucfirst((string)$row['status']) . ($row['utr'] ? ' · ' . $row['utr'] : ''), 'settlement_detail.php?id=' . rawurlencode((string)$row['settlement_id']));
    }

    foreach ($fetchRows(
        "SELECT link_id, amount, description, status FROM payment_links WHERE merchant_id=? AND is_test=? AND (
        LOWER(TRIM(COALESCE(link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(description,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(status,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $isTest, $like, $like, $like, $like, $like]
    ) as $row) {
        $add('Payment Link', (string)$row['link_id'], formatMoney((float)$row['amount']) . ' · ' . ($row['description'] ?: ucfirst((string)$row['status'])), 'payment_links.php?q=' . rawurlencode((string)$row['link_id']));
    }

    foreach ($fetchRows(
        "SELECT id, qr_code, label, status FROM merchant_qr_codes WHERE merchant_id=? AND (
            LOWER(TRIM(COALESCE(qr_code,''))) LIKE ? OR LOWER(TRIM(COALESCE(label,''))) LIKE ?
            OR LOWER(TRIM(COALESCE(description,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like, $like]
    ) as $row) {
        $add('QR Code', (string)($row['qr_code'] ?: 'QR #' . $row['id']), trim((string)($row['label'] ?? '')) . ' · ' . ucfirst((string)$row['status']), 'qr_code.php?q=' . rawurlencode((string)($row['qr_code'] ?: $row['id'])));
    }

    foreach ($fetchRows(
        "SELECT refund_id, amount, status, txn_id FROM refunds WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(refund_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(txn_id,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Refund', (string)$row['refund_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['txn_id'], 'refunds.php?q=' . rawurlencode((string)$row['refund_id']));
    }

    foreach ($fetchRows(
        "SELECT invoice_id, customer_name, total_amount, status FROM invoices WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(invoice_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR CAST(total_amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Invoice', (string)$row['invoice_id'], formatMoney((float)$row['total_amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: ''), 'invoice_view.php?id=' . rawurlencode((string)$row['invoice_id']));
    }

    foreach ($fetchRows(
        "SELECT ticket_id, subject, status FROM support_tickets WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(ticket_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(subject,''))) LIKE ? OR LOWER(TRIM(COALESCE(status,''))) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Ticket', (string)$row['ticket_id'], ucfirst((string)$row['status']) . ' · ' . (string)$row['subject'], 'support.php?q=' . rawurlencode((string)$row['ticket_id']));
    }

    foreach ($fetchRows(
        "SELECT ticket_id, subject, status, customer_name FROM customer_tickets WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(ticket_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(subject,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_name,''))) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Complaint', (string)$row['ticket_id'], ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: $row['subject']), 'merchant_customer_tickets.php?q=' . rawurlencode((string)$row['ticket_id']));
    }

    foreach ($fetchRows(
        "SELECT mandate_ref, status, max_amount, customer_name FROM mandates WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(mandate_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_phone,''))) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Mandate', (string)$row['mandate_ref'], formatMoney((float)$row['max_amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: ''), 'merchant_recurring.php?q=' . rawurlencode((string)$row['mandate_ref']));
    }

    foreach ($fetchRows(
        "SELECT dispute_id, status, reason FROM disputes WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(dispute_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(status,''))) LIKE ? OR LOWER(TRIM(COALESCE(reason,''))) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Dispute', (string)$row['dispute_id'], ucfirst((string)$row['status']) . ' · ' . mb_substr((string)$row['reason'], 0, 80), 'disputes.php?q=' . rawurlencode((string)$row['dispute_id']));
    }

    foreach ($fetchRows(
        "SELECT name, email, role, status FROM merchant_team_members WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(name,''))) LIKE ? OR LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(role,''))) LIKE ?)
        ORDER BY created_at DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Team', (string)$row['name'], ucfirst((string)$row['role']) . ' · ' . (string)$row['email'] . ' · ' . ucfirst((string)$row['status']), 'merchant_team.php');
    }

    foreach ($fetchRows(
        "SELECT id, label, account_holder, status FROM payout_beneficiaries WHERE merchant_id=? AND (
        LOWER(TRIM(COALESCE(label,''))) LIKE ? OR LOWER(TRIM(COALESCE(account_holder,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
        ORDER BY id DESC LIMIT 10",
        [$merchantId, $like, $like, $like]
    ) as $row) {
        $add('Beneficiary', (string)($row['label'] ?: $row['account_holder'] ?: ('#' . $row['id'])), ucfirst((string)($row['status'] ?? 'active')) . ' · ' . ($row['account_holder'] ?: ''), 'merchant_payout.php?q=' . rawurlencode((string)($row['label'] ?: $row['id'])));
    }

} else {
    $canMerchants = $canPage('manage_merchant.php');
    $canTransactions = $canPage('admin_transactions.php');
    $canSettlements = $canPage('admin_settlements.php');
    $canStaff = $canPage('admin_manage_staff.php');
    $canTickets = $canPage('admin_support.php');
    $canComplaints = $canPage('admin_customer_tickets.php');

    if ($canMerchants) {
        foreach ($fetchRows(
            "SELECT id, merchant_code, name, business_name, email, phone FROM merchants WHERE status!='deleted' AND (
            LOWER(TRIM(COALESCE(name,''))) LIKE ? OR LOWER(TRIM(COALESCE(business_name,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(merchant_code,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY created_at DESC LIMIT {$MAX_PER_TYPE}",
            [$like, $like, $like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['id'])) {
                continue;
            }
            $add('Merchant', (string)($row['business_name'] ?: $row['name']), (string)$row['merchant_code'] . ' · ' . ($row['email'] ?: $row['phone']), adminMerchantUrl((int)$row['id']));
        }
    }

    if ($canMerchants && function_exists('pii_hash') && defined('ENCRYPTION_KEY') && constant('ENCRYPTION_KEY') !== '') {
        $normalizedQuery = strtoupper(preg_replace('/\s+/', '', $q) ?? '');
        $hashVal = null;
        try {
            $hashVal = pii_hash($normalizedQuery);
        } catch (Throwable $e) {
        }
        if ($hashVal !== null) {
            $hashCols = [
                'pan_hash' => 'PAN',
                'gstin_hash' => 'GSTIN',
                'cin_hash' => 'CIN/LLPIN',
                'aadhaar_hash' => 'Aadhaar',
            ];
            foreach ($hashCols as $col => $label) {
                foreach ($fetchRows(
                    "SELECT id, merchant_code, business_name, name FROM merchants WHERE {$col} = ? AND status!='deleted' LIMIT 5",
                    [$hashVal]
                ) as $row) {
                    if (!staffHasMerchantAccess((int)$row['id'])) {
                        continue;
                    }
                    $add('Merchant', (string)($row['business_name'] ?: $row['name']), $label . ' match · ' . (string)$row['merchant_code'], adminMerchantUrl((int)$row['id']));
                }
            }
        }
    }

    if ($canTransactions) {
        foreach ($fetchRows(
            "SELECT t.txn_id, t.merchant_id, t.amount, t.status, t.customer_name, t.customer_phone, m.business_name
            FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE (
            LOWER(TRIM(COALESCE(t.txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.utr,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.customer_phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_email,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR
            CAST(t.amount AS CHAR) LIKE ?)
            ORDER BY t.created_at DESC LIMIT {$MAX_PER_TYPE}",
            [$like, $like, $like, $like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], transactionDetailUrl((string)$row['txn_id']));
        }
    }

    if ($canSettlements) {
        $settlementParts = ["LOWER(TRIM(COALESCE(s.settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(s.utr,''))) LIKE ?", "LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?"];
        $settlementParams = [$like, $like, $like];
        if (is_numeric($q)) {
            $settlementParts[] = 's.net_amount = ?';
            $settlementParams[] = (float)$q;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) {
            $settlementParts[] = 'DATE(s.created_at) = ?';
            $settlementParams[] = $q;
        }
        foreach ($fetchRows(
            'SELECT s.settlement_id, s.merchant_id, s.net_amount, s.status, s.utr, m.business_name
            FROM settlements s JOIN merchants m ON m.id=s.merchant_id WHERE (' . implode(' OR ', $settlementParts) . ')
            ORDER BY s.created_at DESC LIMIT 10',
            $settlementParams
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Settlement', (string)$row['settlement_id'], formatMoney((float)$row['net_amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'settlement_detail.php?id=' . rawurlencode((string)$row['settlement_id']));
        }
    }

    $canPaymentLinks = $canPage('admin_payment_links.php');
    $canQrCodes = $canPage('admin_qr_codes.php');

    if ($canPaymentLinks) {
    foreach ($fetchRows(
        "SELECT pl.link_id, pl.amount, pl.description, pl.status, pl.merchant_id, m.business_name
            FROM payment_links pl JOIN merchants m ON m.id=pl.merchant_id WHERE (
            LOWER(TRIM(COALESCE(pl.link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(pl.description,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(pl.link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)
            ORDER BY pl.created_at DESC LIMIT 10",
        [$like, $like, $like, $like]
    ) as $row) {
        if (!staffHasMerchantAccess((int)($row['merchant_id'] ?? 0))) {
            continue;
        }
        $add('Payment Link', (string)$row['link_id'], formatMoney((float)$row['amount']) . ' · ' . ($row['description'] ?: ucfirst((string)$row['status'])) . ' · ' . $row['business_name'], 'admin_payment_links.php?q=' . rawurlencode((string)$row['link_id']));
    }
    }

    if ($canQrCodes) {
    foreach ($fetchRows(
        "SELECT q.id, q.qr_code, q.label, q.status, q.merchant_id, m.business_name
            FROM merchant_qr_codes q JOIN merchants m ON m.id=q.merchant_id WHERE (
            LOWER(TRIM(COALESCE(q.qr_code,''))) LIKE ? OR LOWER(TRIM(COALESCE(q.label,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(q.id AS CHAR) LIKE ?)
            ORDER BY q.created_at DESC LIMIT 10",
        [$like, $like, $like, $like]
    ) as $row) {
        if (!staffHasMerchantAccess((int)($row['merchant_id'] ?? 0))) {
            continue;
        }
        $add('QR Code', (string)($row['qr_code'] ?: 'QR #' . $row['id']), trim((string)($row['label'] ?? '')) . ' · ' . $row['business_name'], 'admin_qr_codes.php?q=' . rawurlencode((string)($row['qr_code'] ?: $row['id'])));
    }
    }

    $canRefunds = $canPage('admin_refunds.php');
    $canKyc = $canPage('admin_kyc.php');
    $canForward = $canPage('admin_forward_queue.php');
    $canDisputes = $canPage('admin_disputes.php');
    $canChargebacks = $canPage('admin_chargebacks.php');
    $canMethodRequests = $canPage('admin_method_requests.php');
    $canVas = $canPage('admin_virtual_accounts.php');
    $canAml = $canPage('admin_aml.php') || $canPage('admin_risk.php');
    $canPayouts = $canPage('admin_payout.php') || $canPage('admin_bulk_payout.php');

    if ($canRefunds) {
        foreach ($fetchRows(
            "SELECT r.refund_id, r.amount, r.status, r.txn_id, r.merchant_id, m.business_name
                FROM refunds r JOIN merchants m ON m.id=r.merchant_id WHERE (
                LOWER(TRIM(COALESCE(r.refund_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(r.txn_id,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(r.amount AS CHAR) LIKE ?)
                ORDER BY r.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)($row['merchant_id'] ?? 0))) {
                continue;
            }
            $add('Refund', (string)$row['refund_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_refunds.php?q=' . rawurlencode((string)$row['refund_id']));
        }
    }

    if ($canTransactions) {
        foreach ($fetchRows(
            "SELECT mandate_ref, status, merchant_id, max_amount, customer_name FROM mandates WHERE (
                LOWER(TRIM(COALESCE(mandate_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(customer_name,''))) LIKE ? OR CAST(max_amount AS CHAR) LIKE ?)
                ORDER BY created_at DESC LIMIT 10",
            [$like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Mandate', (string)$row['mandate_ref'], formatMoney((float)$row['max_amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: ''), 'admin_transactions.php?q=' . rawurlencode((string)$row['mandate_ref']));
        }
    }

    if ($canForward) {
        foreach ($fetchRows(
            "SELECT fq.id, fq.partner_key, fq.status, fq.merchant_id, m.business_name
                FROM partner_forward_queue fq JOIN merchants m ON m.id=fq.merchant_id WHERE (
                LOWER(TRIM(COALESCE(fq.partner_key,''))) LIKE ? OR LOWER(TRIM(COALESCE(fq.status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(fq.id AS CHAR) LIKE ?)
                ORDER BY fq.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Forward Queue', '#' . $row['id'] . ' · ' . $row['partner_key'], ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_forward_queue.php?q=' . rawurlencode((string)$row['id']));
        }
    }

    if ($canKyc) {
        foreach ($fetchRows(
            "SELECT kv.id, kv.verification_status, kv.merchant_id, m.business_name
                FROM kyc_verifications kv JOIN merchants m ON m.id=kv.merchant_id WHERE (
                LOWER(TRIM(COALESCE(kv.verification_status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(kv.id AS CHAR) LIKE ?)
                ORDER BY kv.created_at DESC LIMIT 10",
            [$like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('KYC', '#' . $row['id'] . ' · ' . $row['business_name'], ucfirst((string)$row['verification_status']), 'admin_kyc.php?q=' . rawurlencode((string)$row['id']));
        }
    }

    if ($canChargebacks) {
        foreach ($fetchRows(
            "SELECT c.id, c.chargeback_ref, c.status, c.merchant_id, c.amount, m.business_name
                FROM chargebacks c JOIN merchants m ON m.id=c.merchant_id WHERE (
                LOWER(TRIM(COALESCE(c.chargeback_ref,''))) LIKE ? OR LOWER(TRIM(COALESCE(c.status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(c.provider_dispute_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR
                CAST(c.id AS CHAR) LIKE ? OR CAST(c.amount AS CHAR) LIKE ?)
                ORDER BY c.created_at DESC LIMIT 10",
            [$like, $like, $like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Chargeback', $ref, formatMoney((float)($row['amount'] ?? 0)) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_chargebacks.php?q=' . rawurlencode($ref));
        }
    }

    if ($canDisputes) {
        foreach ($fetchRows(
            "SELECT d.dispute_id, d.status, d.merchant_id, d.reason, m.business_name
                FROM disputes d JOIN merchants m ON m.id=d.merchant_id WHERE (
                LOWER(TRIM(COALESCE(d.dispute_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(d.status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(d.reason,''))) LIKE ?)
                ORDER BY d.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Dispute', (string)$row['dispute_id'], ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_disputes.php?q=' . rawurlencode((string)$row['dispute_id']));
        }
    }

    if ($canMethodRequests) {
        foreach ($fetchRows(
            "SELECT mr.id, mr.method_key, mr.status, mr.merchant_id, m.business_name
                FROM merchant_method_requests mr JOIN merchants m ON m.id=mr.merchant_id WHERE (
                LOWER(TRIM(COALESCE(mr.method_key,''))) LIKE ? OR LOWER(TRIM(COALESCE(mr.status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(mr.id AS CHAR) LIKE ?)
                ORDER BY mr.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Method Request', (string)$row['method_key'] . ' · #' . $row['id'], ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_method_requests.php?q=' . rawurlencode((string)$row['id']));
        }
    }

    if ($canVas) {
        foreach ($fetchRows(
            "SELECT v.id, v.va_number, v.label, v.status, v.merchant_id, m.business_name
                FROM merchant_virtual_accounts v JOIN merchants m ON m.id=v.merchant_id WHERE (
                LOWER(TRIM(COALESCE(v.va_number,''))) LIKE ? OR LOWER(TRIM(COALESCE(v.label,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(v.id AS CHAR) LIKE ?)
                ORDER BY v.id DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Virtual Account', (string)($row['va_number'] ?: 'VA #' . $row['id']), ucfirst((string)$row['status']) . ' · ' . ($row['label'] ?: '') . ' · ' . $row['business_name'], 'admin_virtual_accounts.php?q=' . rawurlencode((string)($row['va_number'] ?: $row['id'])));
        }
    }

    if ($canAml) {
        foreach ($fetchRows(
            "SELECT af.id, af.flag_type, af.severity, af.status, af.merchant_id, m.business_name
                FROM aml_flags af JOIN merchants m ON m.id=af.merchant_id WHERE (
                LOWER(TRIM(COALESCE(af.flag_type,''))) LIKE ? OR LOWER(TRIM(COALESCE(af.severity,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(af.status,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(af.id AS CHAR) LIKE ?)
                ORDER BY af.created_at DESC LIMIT 10",
            [$like, $like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $amlUrl = $canPage('admin_aml.php') ? 'admin_aml.php' : 'admin_risk.php';
            $add('AML Flag', '#' . $row['id'] . ' · ' . $row['flag_type'], ucfirst((string)$row['severity']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], $amlUrl);
        }
    }

    if ($canPayouts) {
        foreach ($fetchRows(
            "SELECT o.id, o.payout_id, o.status, o.amount, o.merchant_id, m.business_name
                FROM payout_orders o JOIN merchants m ON m.id=o.merchant_id WHERE (
                LOWER(TRIM(COALESCE(o.payout_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(o.status,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(o.id AS CHAR) LIKE ? OR CAST(o.amount AS CHAR) LIKE ?)
                ORDER BY o.id DESC LIMIT 10",
            [$like, $like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $ref = (string)($row['payout_id'] ?: ('PO #' . $row['id']));
            $add('Payout', $ref, formatMoney((float)($row['amount'] ?? 0)) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], 'admin_payout.php?q=' . rawurlencode($ref));
        }

        foreach ($fetchRows(
            "SELECT b.id, b.label, b.account_holder, b.status, b.merchant_id, m.business_name
                FROM payout_beneficiaries b JOIN merchants m ON m.id=b.merchant_id WHERE (
                LOWER(TRIM(COALESCE(b.label,''))) LIKE ? OR LOWER(TRIM(COALESCE(b.account_holder,''))) LIKE ? OR
                LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR CAST(b.id AS CHAR) LIKE ?)
                ORDER BY b.id DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Beneficiary', (string)($row['label'] ?: $row['account_holder'] ?: ('#' . $row['id'])), ($row['business_name'] ?? '') . ' · ' . ucfirst((string)($row['status'] ?? '')), 'admin_payout.php?q=' . rawurlencode((string)($row['label'] ?: $row['id'])));
        }
    }

    if ($canPaymentLinks) {
        foreach ($fetchRows(
            "SELECT pl.pack_id, pl.merchant_id, m.business_name, COUNT(*) AS link_count
                FROM payment_links pl JOIN merchants m ON m.id=pl.merchant_id WHERE pl.pack_id IS NOT NULL AND pl.pack_id != '' AND (
                LOWER(TRIM(COALESCE(pl.pack_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)
                GROUP BY pl.pack_id, pl.merchant_id, m.business_name
                ORDER BY MAX(pl.created_at) DESC LIMIT 10",
            [$like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Payment Pack', (string)$row['pack_id'], (int)$row['link_count'] . ' links · ' . $row['business_name'], 'admin_payment_links.php?q=' . rawurlencode((string)$row['pack_id']));
        }
    }

    if ($canStaff) {
        foreach ($fetchRows(
            "SELECT id, username, name, email, role FROM admins WHERE (
            LOWER(TRIM(COALESCE(username,''))) LIKE ? OR LOWER(TRIM(COALESCE(name,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(role,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY name ASC LIMIT 10",
            [$like, $like, $like, $like, $like]
        ) as $row) {
            $add('Staff', (string)($row['name'] ?: $row['username']), ucfirst((string)$row['role']) . ' · ' . ($row['email'] ?: $row['username']), adminStaffDetailUrl((int)$row['id']));
        }
    }

    if ($canTickets) {
        foreach ($fetchRows(
            "SELECT st.ticket_id, st.subject, st.status, st.merchant_id, m.business_name
            FROM support_tickets st JOIN merchants m ON m.id=st.merchant_id WHERE (
            LOWER(TRIM(COALESCE(st.ticket_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(st.subject,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)
            ORDER BY st.created_at DESC LIMIT 10",
            [$like, $like, $like]
        ) as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Ticket', (string)$row['ticket_id'], ucfirst((string)$row['status']) . ' · ' . $row['business_name'] . ' · ' . (string)$row['subject'], 'admin_support.php?q=' . rawurlencode((string)$row['ticket_id']));
        }
    }

    if ($canComplaints) {
        foreach ($fetchRows(
            "SELECT ct.ticket_id, ct.subject, ct.status, ct.merchant_id, ct.customer_name, m.business_name
            FROM customer_tickets ct LEFT JOIN merchants m ON m.id=ct.merchant_id WHERE (
            LOWER(TRIM(COALESCE(ct.ticket_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(ct.subject,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(ct.customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?)
            ORDER BY ct.created_at DESC LIMIT 10",
            [$like, $like, $like, $like]
        ) as $row) {
            if (!empty($row['merchant_id']) && !staffHasMerchantAccess((int)$row['merchant_id'])) {
                continue;
            }
            $add('Complaint', (string)$row['ticket_id'], ucfirst((string)$row['status']) . ' · ' . ($row['business_name'] ?: $row['customer_name'] ?: $row['subject']), 'admin_customer_tickets.php?q=' . rawurlencode((string)$row['ticket_id']));
        }
    }
}

/* ── 3) Search audit log (masked, no full PII) ─────────────── */
try {
    $auditType = $isAdmin ? 'admin' : 'merchant';
    $maskedQ = mb_strlen($q) > 4 ? mb_substr($q, 0, 2) . '**' . mb_substr($q, -2) : 'short';
    if (function_exists('logStaffActivity') && $isAdmin) {
        logStaffActivity('search', "Global search: type={$auditType} q_masked={$maskedQ}", null, null, null);
    }
} catch (Throwable $e) {
}

foreach ($results as &$row) {
    unset($row['_key']);
}
echo json_encode(['ok' => true, 'query' => $q, 'results' => $results, 'prefix' => $idPrefix], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
