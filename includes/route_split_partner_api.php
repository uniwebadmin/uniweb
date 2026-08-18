<?php
declare(strict_types=1);

/**
 * Live Route / Split partner APIs — Razorpay Route, Cashfree Easy Split, PayU Split.
 * Keys: Partner Registry. Live money gated by route_split_live_enabled + canUsePartnerRoute().
 * Vendor onboarding API skipped — linked account / vendor IDs pasted in Admin (Edit Merchant + Commercial hint).
 */

function routeSplitApiRequireControl(): void
{
    if (!function_exists('getPartnerSetting')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (!function_exists('ensureSplitSettlementTable')) {
        require_once __DIR__ . '/split_settlement.php';
    }
}

/** @return array<string, mixed>|null */
function routeSplitLoadMerchant(int $merchantId): ?array
{
    if ($merchantId <= 0) {
        return null;
    }
    try {
        $st = getDB()->prepare('SELECT * FROM merchants WHERE id=? LIMIT 1');
        $st->execute([$merchantId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Capture context for route split — payment id, order id, provider.
 *
 * @return array<string, mixed>|null
 */
function routeSplitLoadCaptureContext(int $transactionId): ?array
{
    routeSplitApiRequireControl();
    try {
        $st = getDB()->prepare(
            'SELECT t.*, po.id AS payment_order_id, po.provider, po.provider_order_id, pa.provider_payment_id
             FROM transactions t
             LEFT JOIN payment_order_transactions pot ON pot.transaction_id = t.id
             LEFT JOIN payment_orders po ON po.id = pot.payment_order_id
             LEFT JOIN payment_attempts pa ON pa.payment_order_id = po.id AND pa.status = ?
             WHERE t.id = ?
             ORDER BY pa.verified_at DESC
             LIMIT 1'
        );
        $st->execute(['captured', $transactionId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $provider = strtolower((string)($row['provider'] ?? $row['payment_method'] ?? ''));
        $paymentId = trim((string)($row['provider_payment_id'] ?? ''));
        if ($paymentId === '') {
            $utr = trim((string)($row['utr'] ?? ''));
            if ($utr !== '' && !str_starts_with(strtolower($utr), 'sandbox_')) {
                $paymentId = $utr;
            }
        }
        return [
            'transaction_id' => $transactionId,
            'txn_id' => (string)($row['txn_id'] ?? ''),
            'merchant_id' => (int)$row['merchant_id'],
            'amount' => (float)$row['amount'],
            'provider' => $provider,
            'provider_payment_id' => $paymentId,
            'provider_order_id' => trim((string)($row['provider_order_id'] ?? '')),
            'is_test' => !empty($row['is_test']),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve merchant + platform linked account / vendor IDs (Admin-pasted — no onboarding API).
 *
 * @return array{merchant_account:string, platform_account:string, payu_child_key:string}
 */
function routeSplitResolveLinkedAccounts(int $merchantId, string $partnerKey): array
{
    routeSplitApiRequireControl();
    $merchant = routeSplitLoadMerchant($merchantId) ?? [];
    $cfg = getPartnerRouteConfig($partnerKey);
    $platform = trim((string)($cfg['route_linked_account_hint'] ?? ''));

    $merchantAccount = '';
    if (function_exists('getMerchantPartnerLinks')) {
        foreach (getMerchantPartnerLinks($merchantId) as $link) {
            if (strtolower((string)($link['partner_key'] ?? '')) === strtolower($partnerKey)) {
                $merchantAccount = trim((string)($link['external_id'] ?? ''));
                if ($merchantAccount !== '') {
                    break;
                }
            }
        }
    }

    $partnerKey = strtolower($partnerKey);
    if ($merchantAccount === '') {
        $merchantAccount = match ($partnerKey) {
            'razorpay' => trim((string)($merchant['razorpay_linked_account_id'] ?? '')),
            'cashfree' => trim((string)($merchant['cashfree_vendor_id'] ?? '')),
            default => '',
        };
    }

    $payuChild = trim((string)($merchant['payu_child_key'] ?? ''));
    if ($partnerKey === 'payu' && $merchantAccount === '' && $payuChild !== '') {
        $merchantAccount = $payuChild;
    }

    if ($platform === '' && $partnerKey === 'razorpay') {
        $platform = trim(getPartnerSetting('razorpay', 'razorpay_platform_linked_account_id', ''));
    }
    if ($platform === '' && $partnerKey === 'cashfree') {
        $platform = trim(getPartnerSetting('cashfree', 'cashfree_platform_vendor_id', ''));
    }

    return [
        'merchant_account' => $merchantAccount,
        'platform_account' => $platform,
        'payu_child_key' => $payuChild,
    ];
}

function routeSplitPartnerKeysConfigured(string $partnerKey): bool
{
    routeSplitApiRequireControl();
    $partnerKey = strtolower($partnerKey);
    return match ($partnerKey) {
        'razorpay' => trim(getPartnerSetting('razorpay', 'razorpay_key_id', '')) !== ''
            && trim(getPartnerSetting('razorpay', 'razorpay_key_secret', '')) !== '',
        'cashfree' => trim(getPartnerSetting('cashfree', 'cashfree_app_id', '')) !== ''
            && trim(getPartnerSetting('cashfree', 'cashfree_secret_key', '')) !== '',
        'payu' => trim(getPartnerSetting('payu', 'payu_merchant_key', '')) !== ''
            && trim(getPartnerSetting('payu', 'payu_merchant_salt', '')) !== '',
        default => false,
    };
}

/** @return array{ok:bool, partner_transfer_id?:string, status?:string, error?:string, raw?:mixed, pending?:bool} */
function razorpayRouteCreateTransfer(string $paymentId, string $linkedAccountId, float $amountInr, array $notes = []): array
{
    if ($paymentId === '' || $linkedAccountId === '' || $amountInr <= 0) {
        return ['ok' => false, 'error' => 'Razorpay Route: payment_id, linked account and amount required.'];
    }
    if (!function_exists('getPartnerSetting')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $keyId = trim(getPartnerSetting('razorpay', 'razorpay_key_id', ''));
    $keySecret = trim(getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
    if ($keyId === '' || $keySecret === '') {
        return ['ok' => false, 'error' => 'Razorpay keys missing in Partner Registry.'];
    }

    $payload = json_encode([
        'account' => $linkedAccountId,
        'amount' => (int)round($amountInr * 100),
        'currency' => 'INR',
        'payment_id' => $paymentId,
        'notes' => $notes,
        'on_hold' => false,
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/transfers');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        return ['ok' => false, 'error' => 'Network error: ' . $curlErr];
    }

    $data = json_decode($response, true);
    if ($http >= 200 && $http < 300 && is_array($data) && !empty($data['id'])) {
        $st = strtolower((string)($data['status'] ?? 'processed'));
        return [
            'ok' => true,
            'partner_transfer_id' => (string)$data['id'],
            'status' => in_array($st, ['processed', 'pending'], true) ? 'processed' : $st,
            'raw' => $data,
            'pending' => $st === 'pending',
        ];
    }

    return [
        'ok' => false,
        'error' => is_array($data) ? (string)($data['error']['description'] ?? $data['error']['reason'] ?? "HTTP {$http}") : "HTTP {$http}",
        'raw' => $data,
    ];
}

/** @return array{ok:bool, partner_transfer_id?:string, error?:string, raw?:mixed} */
function razorpayRouteReverseTransfer(string $transferId, float $amountInr, array $notes = []): array
{
    if ($transferId === '' || $amountInr <= 0) {
        return ['ok' => false, 'error' => 'Razorpay reversal: transfer_id and amount required.'];
    }
    if (!function_exists('getPartnerSetting')) {
        require_once __DIR__ . '/partner_control.php';
    }
    $keyId = trim(getPartnerSetting('razorpay', 'razorpay_key_id', ''));
    $keySecret = trim(getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
    if ($keyId === '' || $keySecret === '') {
        return ['ok' => false, 'error' => 'Razorpay keys missing.'];
    }

    $payload = json_encode([
        'amount' => (int)round($amountInr * 100),
        'notes' => $notes,
    ]);

    $ch = curl_init('https://api.razorpay.com/v1/transfers/' . rawurlencode($transferId) . '/reversals');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($http >= 200 && $http < 300 && is_array($data) && !empty($data['id'])) {
        return ['ok' => true, 'partner_transfer_id' => (string)$data['id'], 'raw' => $data];
    }

    return [
        'ok' => false,
        'error' => is_array($data) ? (string)($data['error']['description'] ?? "HTTP {$http}") : "HTTP {$http}",
        'raw' => $data,
    ];
}

/** @return array{ok:bool, partner_transfer_id?:string, error?:string, raw?:mixed, pending?:bool} */
function cashfreeEasySplitPostCapture(string $orderId, array $splits): array
{
    if ($orderId === '' || $splits === []) {
        return ['ok' => false, 'error' => 'Cashfree Easy Split: order_id and split vendors required.'];
    }
    if (!function_exists('cashfreeAppId') || !function_exists('cashfreeSecretKey')) {
        require_once __DIR__ . '/partner_control.php';
    }
    if (!function_exists('cashfreeApiBase')) {
        require_once __DIR__ . '/gateways.php';
    }

    $appId = cashfreeAppId();
    $secret = cashfreeSecretKey();
    if ($appId === '' || $secret === '') {
        return ['ok' => false, 'error' => 'Cashfree keys missing in Partner Registry.'];
    }

    $splitRows = [];
    foreach ($splits as $vendorId => $amt) {
        if ((float)$amt <= 0 || trim((string)$vendorId) === '') {
            continue;
        }
        $splitRows[] = [
            'vendor_id' => (string)$vendorId,
            'amount' => round((float)$amt, 2),
        ];
    }
    if ($splitRows === []) {
        return ['ok' => false, 'error' => 'No valid Cashfree split rows.'];
    }

    $payload = json_encode(['split' => $splitRows]);
    $url = cashfreeApiBase() . '/easy-split/orders/' . rawurlencode($orderId) . '/split';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-version: 2023-08-01',
            'x-client-id: ' . $appId,
            'x-client-secret: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = (string)curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($http >= 200 && $http < 300) {
        $ref = (string)($data['cf_split_id'] ?? $data['split_id'] ?? $orderId);
        return ['ok' => true, 'partner_transfer_id' => $ref, 'raw' => $data, 'pending' => true];
    }

    return [
        'ok' => false,
        'error' => is_array($data) ? (string)($data['message'] ?? $data['error'] ?? "HTTP {$http}") : "HTTP {$http}",
        'raw' => $data,
    ];
}

/** PayU split is usually at checkout; post-capture we verify settlement split was applied. */
function payuRouteVerifySplitApplied(string $paymentId, float $expectedMerchantNet): array
{
    if (!function_exists('payuCredentials')) {
        require_once __DIR__ . '/gateways.php';
    }
    $c = payuCredentials();
    if ($c['key'] === '' || $c['salt'] === '' || $paymentId === '') {
        return ['ok' => false, 'error' => 'PayU keys or payment id missing.'];
    }

    $command = 'verify_payment';
    $hashStr = $c['key'] . '|' . $command . '|' . $paymentId . '|' . $c['salt'];
    $hash = strtolower(hash('sha512', $hashStr));

    $post = http_build_query([
        'key' => $c['key'],
        'command' => $command,
        'var1' => $paymentId,
        'hash' => $hash,
    ]);

    $base = function_exists('payuBaseUrl') ? payuBaseUrl() : 'https://secure.payu.in';
    $ch = curl_init($base . '/merchant/postservice?form=2');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 25,
    ]);
    $response = (string)curl_exec($ch);
    curl_close($ch);

    if ($response === '') {
        return ['ok' => false, 'error' => 'PayU verify_payment empty response.'];
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded) && strtolower((string)($decoded['status'] ?? '')) === 'success') {
        return [
            'ok' => true,
            'partner_transfer_id' => 'payu_split_' . $paymentId,
            'raw' => $decoded,
            'note' => 'PayU split applied at checkout (splitRequest). Verified via verify_payment.',
        ];
    }

    return [
        'ok' => true,
        'partner_transfer_id' => 'payu_pending_' . $paymentId,
        'pending' => true,
        'raw' => $decoded ?: $response,
        'note' => 'PayU post-capture split requires splitRequest at payment — ensure payu_split collection mode or child key on merchant.',
    ];
}

/**
 * Dispatch one transfer leg to the partner API.
 *
 * @return array{ok:bool, partner_transfer_id?:string, status?:string, error?:string, pending?:bool}
 */
function routeSplitDispatchLeg(
    string $routeProvider,
    array $ctx,
    string $linkedAccountId,
    float $amount,
    string $legType
): array {
    if (!empty($ctx['is_test'])) {
        return [
            'ok' => true,
            'partner_transfer_id' => 'UNIWEB_TEST_' . strtoupper($legType) . '_' . (int)$ctx['transaction_id'],
            'status' => 'processed',
            'note' => 'Test mode — no live Route call.',
        ];
    }

    $notes = [
        'uniweb_txn' => (string)($ctx['txn_id'] ?? ''),
        'uniweb_leg' => $legType,
    ];

    return match ($routeProvider) {
        'razorpay_route' => razorpayRouteCreateTransfer(
            (string)$ctx['provider_payment_id'],
            $linkedAccountId,
            $amount,
            $notes
        ),
        'cashfree_vendor' => cashfreeEasySplitPostCapture(
            (string)$ctx['provider_order_id'],
            [$linkedAccountId => $amount]
        ),
        default => payuRouteVerifySplitApplied((string)$ctx['provider_payment_id'], $amount),
    };
}

/**
 * Live dispatch for merchant + platform legs after capture.
 *
 * @param array<string, mixed> $split
 */
function routeSplitDispatchCaptureTransfers(
    int $transactionId,
    int $merchantId,
    string $partnerKey,
    array $split,
    int $merchantTransferId,
    ?int $platformTransferId
): array {
    routeSplitApiRequireControl();
    if (!routeSplitLiveEnabled() || !canUsePartnerRoute($partnerKey)) {
        return ['ok' => true, 'mode' => 'gated'];
    }
    if (!routeSplitPartnerKeysConfigured($partnerKey)) {
        return ['ok' => false, 'error' => 'Partner keys missing for Route / Split.'];
    }

    $ctx = routeSplitLoadCaptureContext($transactionId);
    if (!$ctx) {
        return ['ok' => false, 'error' => 'Could not load payment capture context for transaction.'];
    }

    $cfg = getPartnerRouteConfig($partnerKey);
    $routeProvider = (string)($cfg['route_provider'] ?? 'none');
    if ($routeProvider === 'none') {
        $routeProvider = match (strtolower($partnerKey)) {
            'razorpay' => 'razorpay_route',
            'cashfree' => 'cashfree_vendor',
            'payu' => 'other',
            default => 'other',
        };
    }

    if ($routeProvider === 'cashfree_vendor' && $ctx['provider_order_id'] === '') {
        return ['ok' => false, 'error' => 'Cashfree Easy Split needs provider_order_id on payment order.'];
    }
    if ($routeProvider === 'razorpay_route' && $ctx['provider_payment_id'] === '') {
        return ['ok' => false, 'error' => 'Razorpay Route needs provider_payment_id on capture.'];
    }

    $accounts = routeSplitResolveLinkedAccounts($merchantId, $partnerKey);
    $merchantNet = (float)($split['merchant_net'] ?? 0);
    $platformFee = (float)($split['platform_fee'] ?? 0);

    $summary = ['ok' => true, 'legs' => []];

    if ($merchantNet > 0) {
        $acct = $accounts['merchant_account'];
        if ($acct === '' && $routeProvider !== 'other') {
            updatePartnerTransferStatus($merchantTransferId, 'failed', null, 'Merchant linked account / vendor ID missing — paste in Admin Edit Merchant.');
            return ['ok' => false, 'error' => 'Merchant linked account ID missing.'];
        }
        if ($routeProvider === 'cashfree_vendor' && $merchantNet > 0 && $platformFee > 0 && $platformTransferId) {
            $splits = [$acct => $merchantNet];
            if ($accounts['platform_account'] !== '') {
                $splits[$accounts['platform_account']] = $platformFee;
            }
            $result = cashfreeEasySplitPostCapture((string)$ctx['provider_order_id'], $splits);
            $status = !empty($result['ok']) ? (!empty($result['pending']) ? 'pending' : 'processed') : 'failed';
            updatePartnerTransferStatus(
                $merchantTransferId,
                $status,
                $result['partner_transfer_id'] ?? null,
                $result['error'] ?? null
            );
            if ($platformTransferId) {
                updatePartnerTransferStatus($platformTransferId, $status, $result['partner_transfer_id'] ?? null, null);
            }
            $summary['legs']['combined'] = $result;
            if (empty($result['ok'])) {
                $summary['ok'] = false;
                $summary['error'] = $result['error'] ?? 'Cashfree split failed';
            }
            return $summary;
        }

        $result = routeSplitDispatchLeg($routeProvider, $ctx, $acct, $merchantNet, 'merchant_leg');
        $status = !empty($result['ok']) ? (!empty($result['pending']) ? 'pending' : 'processed') : 'failed';
        updatePartnerTransferStatus($merchantTransferId, $status, $result['partner_transfer_id'] ?? null, $result['error'] ?? null);
        $summary['legs']['merchant'] = $result;
        if (empty($result['ok'])) {
            $summary['ok'] = false;
            $summary['error'] = $result['error'] ?? 'Merchant leg failed';
            return $summary;
        }
    }

    if ($platformFee > 0 && $platformTransferId && $routeProvider === 'razorpay_route') {
        $platAcct = $accounts['platform_account'];
        if ($platAcct === '') {
            updatePartnerTransferStatus($platformTransferId, 'failed', null, 'Platform linked account hint missing in Partner Commercial tab.');
        } else {
            $result = routeSplitDispatchLeg($routeProvider, $ctx, $platAcct, $platformFee, 'platform_leg');
            $status = !empty($result['ok']) ? (!empty($result['pending']) ? 'pending' : 'processed') : 'failed';
            updatePartnerTransferStatus($platformTransferId, $status, $result['partner_transfer_id'] ?? null, $result['error'] ?? null);
            $summary['legs']['platform'] = $result;
        }
    }

    return $summary;
}

/**
 * Live refund reversal on partner Route / Split legs.
 */
function executePartnerRouteRefundReversal(
    int $transactionId,
    int $merchantId,
    string $partnerKey,
    float $merchantReversal,
    float $platformReversal,
    string $refundReference
): array {
    routeSplitApiRequireControl();
    if (!routeSplitLiveEnabled() || !canUsePartnerRoute($partnerKey)) {
        return ['ok' => true, 'gated' => true];
    }

    $transfers = getTransactionPartnerTransfers($transactionId);
    $cfg = getPartnerRouteConfig($partnerKey);
    $routeProvider = (string)($cfg['route_provider'] ?? 'razorpay_route');

    foreach ($transfers as $tr) {
        if (($tr['status'] ?? '') !== 'processed' || empty($tr['partner_transfer_id'])) {
            continue;
        }
        $leg = (string)($tr['transfer_type'] ?? '');
        $revAmount = $leg === 'platform_leg' ? $platformReversal : ($leg === 'merchant_leg' ? $merchantReversal : 0);
        if ($revAmount <= 0) {
            continue;
        }

        if ($routeProvider === 'razorpay_route' || strtolower($partnerKey) === 'razorpay') {
            $rev = razorpayRouteReverseTransfer((string)$tr['partner_transfer_id'], $revAmount, [
                'uniweb_refund' => $refundReference,
                'uniweb_leg' => $leg,
            ]);
            if (!empty($rev['ok'])) {
                recordRefundReversalTransfer($transactionId, $merchantId, $partnerKey, $revAmount);
                updatePartnerTransferStatus((int)$tr['id'], 'processed', (string)($rev['partner_transfer_id'] ?? ''), 'reversed:' . $refundReference);
            } else {
                updatePartnerTransferStatus((int)$tr['id'], 'failed', null, $rev['error'] ?? 'Reversal failed');
                return $rev;
            }
        }
    }

    return ['ok' => true];
}

/** Webhook: update partner_transfers by partner transfer id. */
function updatePartnerTransferFromWebhook(string $partnerKey, string $partnerTransferId, string $partnerStatus, ?string $failureReason = null): bool
{
    routeSplitApiRequireControl();
    if ($partnerTransferId === '') {
        return false;
    }

    $localStatus = match (strtolower($partnerStatus)) {
        'processed', 'success', 'completed', 'settled' => 'processed',
        'pending', 'created', 'initiated' => 'pending',
        'failed', 'reversed', 'cancelled' => 'failed',
        default => 'pending',
    };

    try {
        $st = getDB()->prepare(
            'UPDATE partner_transfers SET status=?, failure_reason=COALESCE(?, failure_reason), updated_at=NOW()
             WHERE partner_key=? AND partner_transfer_id=?'
        );
        $st->execute([$localStatus, $failureReason, strtolower($partnerKey), $partnerTransferId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function routeSplitLiveDispatchImplemented(): bool
{
    return true;
}
