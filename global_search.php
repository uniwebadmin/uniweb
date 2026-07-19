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

$add = static function (string $type, string $title, string $subtitle, string $url) use (&$results): void {
    if (count($results) >= 12) return;
    $key = $type . '|' . $url;
    foreach ($results as $row) {
        if (($row['_key'] ?? '') === $key) return;
    }
    $results[] = ['_key' => $key, 'type' => $type, 'title' => $title, 'subtitle' => $subtitle, 'url' => $url];
};

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
        ORDER BY created_at DESC LIMIT 6");
    $stmt->execute([$merchantId, $isTest, $like, $like, $like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . ($row['customer_name'] ?: $row['customer_phone'] ?: $row['utr'] ?: 'Customer'), transactionDetailUrl((string)$row['txn_id']));
    }

    $settlementParts = ["LOWER(TRIM(COALESCE(settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(utr,''))) LIKE ?"];
    $settlementParams = [$merchantId, $like, $like];
    if (is_numeric($q)) { $settlementParts[] = 'net_amount = ?'; $settlementParams[] = (float)$q; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) { $settlementParts[] = 'DATE(created_at) = ?'; $settlementParams[] = $q; }
    $stmt = $db->prepare("SELECT settlement_id, net_amount, status, utr FROM settlements WHERE merchant_id=? AND (" . implode(' OR ', $settlementParts) . ")
        ORDER BY created_at DESC LIMIT 4");
    $stmt->execute($settlementParams);
    foreach ($stmt->fetchAll() as $row) {
        $add('Settlement', (string)$row['settlement_id'], formatMoney((float)$row['net_amount']) . ' · ' . ucfirst((string)$row['status']) . ($row['utr'] ? ' · ' . $row['utr'] : ''), 'settlement_detail.php?id=' . rawurlencode((string)$row['settlement_id']));
    }

    $stmt = $db->prepare("SELECT link_id, amount, description, status FROM payment_links WHERE merchant_id=? AND is_test=? AND (
        LOWER(TRIM(COALESCE(link_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(description,''))) LIKE ? OR
        LOWER(TRIM(COALESCE(link_label,''))) LIKE ? OR LOWER(TRIM(COALESCE(status,''))) LIKE ? OR CAST(amount AS CHAR) LIKE ?)
        ORDER BY created_at DESC LIMIT 4");
    $stmt->execute([$merchantId, $isTest, $like, $like, $like, $like, $like]);
    foreach ($stmt->fetchAll() as $row) {
        $add('Payment Link', (string)$row['link_id'], formatMoney((float)$row['amount']) . ' · ' . ($row['description'] ?: ucfirst((string)$row['status'])), 'payment_links.php?q=' . rawurlencode((string)$row['link_id']));
    }
} else {
    $canMerchants = function_exists('staffCanAccess') ? staffCanAccess('manage_merchant.php') : true;
    $canTransactions = function_exists('staffCanAccess') ? staffCanAccess('admin_transactions.php') : true;
    $canSettlements = function_exists('staffCanAccess') ? staffCanAccess('admin_settlements.php') : true;

    if ($canMerchants) {
        $stmt = $db->prepare("SELECT id, merchant_code, name, business_name, email, phone FROM merchants WHERE status!='deleted' AND (
            LOWER(TRIM(COALESCE(name,''))) LIKE ? OR LOWER(TRIM(COALESCE(business_name,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(email,''))) LIKE ? OR LOWER(TRIM(COALESCE(phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(merchant_code,''))) LIKE ? OR CAST(id AS CHAR) LIKE ?)
            ORDER BY created_at DESC LIMIT 12");
        $stmt->execute([$like, $like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['id'])) continue;
            $add('Merchant', (string)($row['business_name'] ?: $row['name']), (string)$row['merchant_code'] . ' · ' . ($row['email'] ?: $row['phone']), adminMerchantUrl((int)$row['id']));
        }
    }

    if ($canTransactions) {
        $stmt = $db->prepare("SELECT t.txn_id, t.merchant_id, t.amount, t.status, t.customer_name, t.customer_phone, m.business_name
            FROM transactions t JOIN merchants m ON m.id=t.merchant_id WHERE (
            LOWER(TRIM(COALESCE(t.txn_id,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.utr,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_name,''))) LIKE ? OR LOWER(TRIM(COALESCE(t.customer_phone,''))) LIKE ? OR
            LOWER(TRIM(COALESCE(t.customer_email,''))) LIKE ? OR LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ? OR
            CAST(t.amount AS CHAR) LIKE ? OR LOWER(CAST(COALESCE(t.metadata,'') AS CHAR)) LIKE ?)
            ORDER BY t.created_at DESC LIMIT 12");
        $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like]);
        foreach ($stmt->fetchAll() as $row) {
            if (!staffHasMerchantAccess((int)$row['merchant_id'])) continue;
            $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']) . ' · ' . $row['business_name'], transactionDetailUrl((string)$row['txn_id']));
        }
    }

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
}

// Lightweight typo tolerance: if exact substring search misses, match recent names/IDs within 1–2 edits.
if (!$results && mb_strlen($needle) >= 3) {
    $distanceLimit = mb_strlen($needle) <= 5 ? 1 : 2;
    if ($isMerchant) {
        $merchantId = (int)getMerchant()['id'];
        $stmt = $db->prepare("SELECT txn_id, customer_name, amount, status FROM transactions WHERE merchant_id=? ORDER BY created_at DESC LIMIT 100");
        $stmt->execute([$merchantId]);
        foreach ($stmt->fetchAll() as $row) {
            foreach ([(string)$row['txn_id'], (string)$row['customer_name']] as $candidate) {
                if ($candidate !== '' && levenshtein($needle, mb_strtolower($candidate)) <= $distanceLimit) {
                    $add('Transaction', (string)$row['txn_id'], formatMoney((float)$row['amount']) . ' · ' . ucfirst((string)$row['status']), transactionDetailUrl((string)$row['txn_id']));
                    break;
                }
            }
        }
    } elseif ($canMerchants ?? false) {
        $rows = $db->query("SELECT id, merchant_code, name, business_name, email FROM merchants WHERE status!='deleted' ORDER BY created_at DESC LIMIT 100")->fetchAll();
        foreach ($rows as $row) {
            if (!staffHasMerchantAccess((int)$row['id'])) continue;
            foreach ([(string)$row['name'], (string)$row['business_name'], (string)$row['merchant_code']] as $candidate) {
                if ($candidate !== '' && levenshtein($needle, mb_strtolower($candidate)) <= $distanceLimit) {
                    $add('Merchant', (string)($row['business_name'] ?: $row['name']), (string)$row['merchant_code'] . ' · ' . $row['email'], adminMerchantUrl((int)$row['id']));
                    break;
                }
            }
        }
    }
}

foreach ($results as &$row) unset($row['_key']);
echo json_encode(['ok' => true, 'query' => $q, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
