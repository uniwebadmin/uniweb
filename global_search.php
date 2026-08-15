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
    'gstin' => $isMerchant ? 'kyc.php' : 'manage_merchant.php',
    'pan' => $isMerchant ? 'kyc.php' : 'manage_merchant.php',
    'staff' => 'admin_manage_staff.php',
    'tickets' => $isMerchant ? 'support.php' : 'admin_support.php',
    'complaints' => $isMerchant ? 'merchant_customer_tickets.php' : 'admin_customer_tickets.php',
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
    if (str_contains($alias, $qlower)) {
        foreach ($featurePages as [$furl, $flabel]) {
            if ($furl === $url) {
                $add('Page', (string)$flabel, (string)$url, (string)$url);
                $pageHits++;
                break;
            }
        }
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

    if ($canStaff) {
        foreach ($fetchRows(
            "SELECT id, username, name, email, role FROM admins WHERE (
            LOWER(TRIM(COALESCE(username,''))) LIKE ? OR LOWER(TRIM(COALESCE(name,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(role,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY name ASC LIMIT 10",
            [$like, $like, $like, $like, $like]
        ) as $row) {
            $add('Staff', (string)($row['name'] ?: $row['username']), ucfirst((string)$row['role']) . ' · ' . ($row['email'] ?: $row['username']), 'admin_manage_staff.php?q=' . rawurlencode((string)$row['username']));
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
