<?php
declare(strict_types=1);

/** Money-path partner identity on transactions (Phase R2). */

function txnRegistryPartnerKeys(): array
{
    static $keys = null;
    if ($keys !== null) {
        return $keys;
    }
    $keys = ['razorpay', 'cashfree', 'payu', 'decentro', 'rbl', 'axis', 'worldline', 'ccavenue', 'sandbox', 'payu_split'];
    if (function_exists('getCheckoutPgPartnerKeys')) {
        require_once __DIR__ . '/partner_engine.php';
        $fromRegistry = getCheckoutPgPartnerKeys();
        if (is_array($fromRegistry) && $fromRegistry !== []) {
            $keys = array_values(array_unique(array_merge($keys, array_map('strtolower', $fromRegistry))));
        }
    }
    return $keys;
}

function normalizeTxnPartnerKey(string $raw): string
{
    return strtolower(preg_replace('/[^a-z0-9_]/i', '', trim($raw)) ?: '');
}

function isTxnRegistryPartnerKey(string $key): bool
{
    $key = normalizeTxnPartnerKey($key);
    return $key !== '' && in_array($key, txnRegistryPartnerKeys(), true);
}

function inferTxnPartnerKeyFromPaymentMethod(string $method): string
{
    $method = strtolower(trim($method));
    if ($method === '') {
        return '';
    }
    if (isTxnRegistryPartnerKey($method)) {
        return $method;
    }
    if (str_starts_with($method, 'razorpay')) {
        return 'razorpay';
    }
    if (str_starts_with($method, 'cashfree')) {
        return 'cashfree';
    }
    if (str_starts_with($method, 'payu')) {
        return 'payu';
    }
    if (str_starts_with($method, 'rbl')) {
        return 'rbl';
    }
    if (str_starts_with($method, 'axis')) {
        return 'axis';
    }
    if (str_contains($method, 'decentro')) {
        return 'decentro';
    }
    return '';
}

/**
 * @param array<string,mixed>|string $verificationOrMethod
 */
function resolveTxnCollectMethod(array|string $verificationOrMethod, string $partnerKey): string
{
    $hint = '';
    if (is_array($verificationOrMethod)) {
        $hint = strtolower(trim((string)($verificationOrMethod['payment_method'] ?? $verificationOrMethod['collect_method'] ?? '')));
    } else {
        $hint = strtolower(trim($verificationOrMethod));
    }
    $collectRails = ['upi', 'upi_p2m', 'card', 'debit_card', 'credit_card', 'netbanking', 'wallet', 'qr', 'sandbox', 'emi', 'pay_later'];
    if (in_array($hint, $collectRails, true)) {
        return $hint;
    }
    if (str_contains($hint, 'upi')) {
        return 'upi';
    }
    if (str_contains($hint, 'card')) {
        return 'card';
    }
    $partnerKey = normalizeTxnPartnerKey($partnerKey);
    return match ($partnerKey) {
        'sandbox' => 'sandbox',
        'decentro', 'rbl', 'axis' => 'upi',
        default => $hint !== '' && !isTxnRegistryPartnerKey($hint) ? $hint : '',
    };
}

function resolveTxnPartnerKeyFromTransaction(array $txn): string
{
    $stored = normalizeTxnPartnerKey((string)($txn['partner_key'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    $fromMethod = inferTxnPartnerKeyFromPaymentMethod((string)($txn['payment_method'] ?? ''));
    if ($fromMethod !== '') {
        return $fromMethod;
    }
    $txnId = (int)($txn['id'] ?? 0);
    if ($txnId > 0) {
        try {
            $st = getDB()->prepare(
                'SELECT po.provider FROM payment_order_transactions pot
                 JOIN payment_orders po ON po.id = pot.payment_order_id
                 WHERE pot.transaction_id = ? ORDER BY po.id DESC LIMIT 1'
            );
            $st->execute([$txnId]);
            $provider = normalizeTxnPartnerKey((string)($st->fetchColumn() ?: ''));
            if ($provider !== '') {
                return $provider;
            }
        } catch (Throwable $e) { /* ok */ }
    }
    return '';
}

function refundProviderHasLiveApi(string $partnerKey): bool
{
    return in_array(normalizeTxnPartnerKey($partnerKey), ['razorpay', 'cashfree', 'payu'], true);
}

function transactionPartnerLabel(string $partnerKey): string
{
    $partnerKey = normalizeTxnPartnerKey($partnerKey);
    if ($partnerKey === '') {
        return '—';
    }
    if ($partnerKey === 'sandbox') {
        return 'Sandbox (test)';
    }
    if (function_exists('getPartnerRegistry')) {
        require_once __DIR__ . '/partner_engine.php';
        $registry = getPartnerRegistry();
        if (!empty($registry[$partnerKey]['name'])) {
            return (string)$registry[$partnerKey]['name'];
        }
    }
    return ucfirst(str_replace('_', ' ', $partnerKey));
}

/**
 * @return array{partner_key:string,partner_label:string,collect_method:string,collect_method_label:string,mode:string,mode_label:string}
 */
function transactionMoneyPathSummary(array $txn): array
{
    if (!function_exists('paymentMethodLabel') && is_file(__DIR__ . '/transaction_detail.php')) {
        require_once __DIR__ . '/transaction_detail.php';
    }
    $partnerKey = resolveTxnPartnerKeyFromTransaction($txn);
    $collectMethod = strtolower(trim((string)($txn['payment_method'] ?? '')));
    if ($collectMethod !== '' && isTxnRegistryPartnerKey($collectMethod)) {
        $inferred = resolveTxnCollectMethod($collectMethod, $partnerKey);
        $collectMethod = $inferred !== '' ? $inferred : $collectMethod;
    }
    $isTest = !empty($txn['is_test']);
    return [
        'partner_key' => $partnerKey,
        'partner_label' => transactionPartnerLabel($partnerKey),
        'collect_method' => $collectMethod,
        'collect_method_label' => function_exists('paymentMethodLabel')
            ? paymentMethodLabel($collectMethod !== '' ? $collectMethod : null)
            : ucfirst($collectMethod ?: '—'),
        'mode' => $isTest ? 'test' : 'live',
        'mode_label' => $isTest ? 'Test / Sandbox' : 'Live',
    ];
}

function persistTransactionPartnerKey(int $transactionId, string $partnerKey): void
{
    if ($transactionId <= 0 || normalizeTxnPartnerKey($partnerKey) === '') {
        return;
    }
    if (!function_exists('ensureTransactionPartnerKeyColumn') && is_file(__DIR__ . '/schema_ensure.php')) {
        require_once __DIR__ . '/schema_ensure.php';
    }
    if (function_exists('ensureTransactionPartnerKeyColumn')) {
        ensureTransactionPartnerKeyColumn();
    }
    try {
        getDB()->prepare('UPDATE transactions SET partner_key=? WHERE id=? AND (partner_key IS NULL OR partner_key=\'\')')
            ->execute([normalizeTxnPartnerKey($partnerKey), $transactionId]);
    } catch (Throwable $e) { /* column missing on old DB */ }
}

/**
 * Try INSERT variants (with partner_key column first). Throws on total failure.
 *
 * @param list<array{sql:string,params:array<int,mixed>}> $variants
 */
function executeTransactionInsertVariants(PDO $db, array $variants): void
{
    $last = null;
    foreach ($variants as $variant) {
        try {
            $db->prepare($variant['sql'])->execute($variant['params']);
            return;
        } catch (Throwable $e) {
            $last = $e;
        }
    }
    if ($last !== null) {
        throw $last;
    }
    throw new RuntimeException('Transaction insert failed.');
}
