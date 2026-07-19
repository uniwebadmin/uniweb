<?php
declare(strict_types=1);

function fetchTransactionDetail(string $txnId, ?int $merchantId = null, bool $adminView = false): ?array
{
    $db = getDB();
    $sql = "SELECT t.*, m.business_name, m.merchant_code, m.email AS merchant_email, m.phone AS merchant_phone,
            m.collection_mode AS merchant_collection_mode, m.account_mode,
            pl.link_id, pl.description AS link_description, pl.customer_name AS link_customer_name,
            pl.customer_phone AS link_customer_phone, pl.payment_method AS link_payment_method,
            pl.gateway_code AS link_gateway, pl.link_label
            FROM transactions t
            JOIN merchants m ON t.merchant_id = m.id
            LEFT JOIN payment_links pl ON t.payment_link_id = pl.id
            WHERE t.txn_id = ?";
    $params = [$txnId];
    if ($merchantId !== null && !$adminView) {
        $sql .= ' AND t.merchant_id = ?';
        $params[] = $merchantId;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    if (!$row) return null;

    $walletTxn = $db->prepare('SELECT * FROM wallet_transactions WHERE merchant_id = ? AND (transaction_id = ? OR reference = ?) ORDER BY id DESC LIMIT 1');
    $walletTxn->execute([(int)$row['merchant_id'], (int)$row['id'], $txnId]);
    $row['wallet_entry'] = $walletTxn->fetch() ?: null;

    $splits = $db->prepare('SELECT * FROM split_payments WHERE transaction_id = ?');
    $splits->execute([(int)$row['id']]);
    $row['splits'] = $splits->fetchAll();

    return $row;
}

function transactionDetailUrl(string $txnId): string
{
    return 'transaction_detail.php?txn=' . rawurlencode($txnId);
}

function paymentMethodLabel(?string $method): string
{
    $map = [
        'upi' => 'UPI', 'upi_p2m' => 'UPI P2M (Direct)', 'payu' => 'PayU Gateway',
        'card' => 'Card', 'netbanking' => 'Net Banking', 'wallet' => 'Wallet',
        'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'axis_va' => 'Axis Virtual Account',
        'qr' => 'QR Code',
    ];
    return $map[$method ?? ''] ?? ucfirst(str_replace('_', ' ', $method ?? '—'));
}
