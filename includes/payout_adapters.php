<?php
declare(strict_types=1);

/**
 * C2: PayoutAdapter interface + live partner implementations.
 *
 * Adapters encapsulate partner-specific payout API calls.
 * Live money moves only when payoutLiveMoneyAllowed() is true
 * (Partner Registry keys + admin payout_live_enabled).
 *
 * Registered adapters:
 *   - mock:      Test / gated mode — UTR prefixed UNIWEB_TEST (never live money)
 *   - razorpayx: RazorpayX Payouts API (contacts → fund account → payout)
 *   - cashfree:  Cashfree Payouts API (authorize → beneficiary → transfer)
 */

require_once __DIR__ . '/payout_partner_api.php';

interface PayoutAdapterInterface
{
    /**
     * @param array<string, mixed> $job Row from payout_jobs
     * @param array<string, mixed> $beneficiary Beneficiary details
     * @return array{ok:bool,partner_ref?:string,utr?:string,error?:string,pending?:bool}
     */
    public function dispatch(array $job, array $beneficiary): array;

    /**
     * @param array<string, mixed> $job Row from payout_jobs
     * @return array{status:string,utr?:string,error?:string}
     */
    public function checkStatus(array $job): array;

    /**
     * @param array<string, mixed> $job Row from payout_jobs
     * @return array{ok:bool,error?:string}
     */
    public function cancel(array $job): array;

    public function isLive(): bool;

    /** True when live partner API dispatch is implemented (not just keys pasted). */
    public function dispatchImplemented(): bool;

    public function name(): string;
}

/**
 * Mock PayoutAdapter — test / gated mode only; UTR is clearly synthetic.
 */
class MockPayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'mock'; }

    public function isLive(): bool { return false; }

    public function dispatchImplemented(): bool { return true; }

    public function dispatch(array $job, array $beneficiary): array
    {
        $utr = 'UNIWEB_TEST_' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $partnerRef = 'MOCK_REF_' . ($job['job_id'] ?? '0');

        return [
            'ok' => true,
            'partner_ref' => $partnerRef,
            'utr' => $utr,
        ];
    }

    public function checkStatus(array $job): array
    {
        return [
            'status' => 'success',
            'utr' => (string)($job['utr'] ?? ''),
        ];
    }

    public function cancel(array $job): array
    {
        return ['ok' => true];
    }
}

/**
 * RazorpayX PayoutAdapter — live dispatch via createRazorpayXPayout().
 */
class RazorpayXPayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'razorpayx'; }

    public function dispatchImplemented(): bool { return true; }

    private function keysConfigured(): bool
    {
        payoutPartnerRequireControl();
        $key = trim(getPartnerSetting('razorpayx', 'razorpayx_key_id', ''));
        $secret = trim(getPartnerSetting('razorpayx', 'razorpayx_key_secret', ''));
        if ($key === '' || $secret === '' || str_contains(strtolower($key), 'pending')) {
            $key = trim(getPartnerSetting('razorpay', 'razorpay_key_id', ''));
            $secret = trim(getPartnerSetting('razorpay', 'razorpay_key_secret', ''));
        }
        return $key !== '' && $secret !== '' && !str_contains(strtolower($key), 'pending');
    }

    public function isLive(): bool
    {
        return $this->keysConfigured()
            && $this->dispatchImplemented()
            && razorpayxPlatformAccountNumber() !== ''
            && payoutLiveMoneyAllowed();
    }

    public function dispatch(array $job, array $beneficiary): array
    {
        if (!payoutLiveMoneyAllowed()) {
            return ['ok' => false, 'error' => payoutActivationMessage()];
        }
        if (!$this->keysConfigured()) {
            return ['ok' => false, 'error' => 'RazorpayX keys not configured in Partner Registry.'];
        }
        return razorpayxDispatchPayoutJob($job, $beneficiary);
    }

    public function checkStatus(array $job): array
    {
        if (!$this->keysConfigured()) {
            return ['status' => 'pending', 'error' => 'RazorpayX keys not configured.'];
        }
        return razorpayxCheckPayoutStatus($job);
    }

    public function cancel(array $job): array
    {
        // RazorpayX queued payouts may be cancelled before processing — not wired in v1.
        return ['ok' => false, 'error' => 'Cancel is not supported for RazorpayX payouts in this build.'];
    }
}

/**
 * Cashfree PayoutAdapter — live dispatch via Cashfree Payout v1 API.
 */
class CashfreePayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'cashfree'; }

    public function dispatchImplemented(): bool { return true; }

    private function keysConfigured(): bool
    {
        $creds = cashfreePayoutCredentials();
        return $creds['client_id'] !== ''
            && $creds['client_secret'] !== ''
            && !str_contains(strtolower($creds['client_id']), 'pending');
    }

    public function isLive(): bool
    {
        return $this->keysConfigured() && $this->dispatchImplemented() && payoutLiveMoneyAllowed();
    }

    public function dispatch(array $job, array $beneficiary): array
    {
        if (!payoutLiveMoneyAllowed()) {
            return ['ok' => false, 'error' => payoutActivationMessage()];
        }
        if (!$this->keysConfigured()) {
            return ['ok' => false, 'error' => 'Cashfree Payout keys not configured in Partner Registry.'];
        }
        return cashfreeDispatchPayoutJob($job, $beneficiary);
    }

    public function checkStatus(array $job): array
    {
        if (!$this->keysConfigured()) {
            return ['status' => 'pending', 'error' => 'Cashfree Payout keys not configured.'];
        }
        return cashfreeCheckPayoutStatus($job);
    }

    public function cancel(array $job): array
    {
        return ['ok' => false, 'error' => 'Cancel is not supported for Cashfree payouts in this build.'];
    }
}

function getPayoutAdapter(?string $adapterName = null): PayoutAdapterInterface
{
    if (!payoutLiveMoneyAllowed()) {
        return new MockPayoutAdapter();
    }

    $name = $adapterName ?? resolveDefaultPayoutAdapterName() ?? 'mock';

    return match ($name) {
        'razorpayx' => new RazorpayXPayoutAdapter(),
        'cashfree'  => new CashfreePayoutAdapter(),
        default     => new MockPayoutAdapter(),
    };
}

function getRegisteredPayoutAdapters(): array
{
    return [
        'mock'      => 'Mock (Test Mode — UNIWEB_TEST UTR only)',
        'razorpayx' => 'RazorpayX Payouts (live API)',
        'cashfree'  => 'Cashfree Payouts (live API)',
    ];
}

/**
 * Load beneficiary row for a payout order (used by legacy dispatchPayoutOrder path).
 *
 * @return array<string, mixed>|null
 */
function payoutBeneficiaryForOrder(array $order): ?array
{
    $db = getDB();
    $beneficiaryId = (int)($order['beneficiary_id'] ?? 0);
    if ($beneficiaryId <= 0) {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM payout_beneficiaries WHERE id=? AND merchant_id=? LIMIT 1');
        $st->execute([$beneficiaryId, (int)$order['merchant_id']]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Unified dispatch for payout_orders row (legacy + batch paths).
 *
 * @return array{ok:bool,utr?:string,partner_ref?:string,error?:string}
 */
function payoutAdapterDispatchOrder(array $order, ?string $adapterName = null): array
{
    $beneficiary = payoutBeneficiaryForOrder($order);
    if (!$beneficiary) {
        return ['ok' => false, 'error' => 'Beneficiary not found for payout order.'];
    }

    $job = [
        'id' => (int)($order['id'] ?? 0),
        'job_id' => (string)($order['payout_id'] ?? ''),
        'merchant_id' => (int)($order['merchant_id'] ?? 0),
        'amount' => (float)($order['amount'] ?? 0),
        'payout_order_id' => (int)($order['id'] ?? 0),
    ];

    $adapter = getPayoutAdapter($adapterName);
    $result = $adapter->dispatch($job, $beneficiary);
    if (!empty($result['ok'])) {
        return [
            'ok' => true,
            'utr' => (string)($result['utr'] ?? ''),
            'partner_ref' => (string)($result['partner_ref'] ?? ''),
        ];
    }
    return ['ok' => false, 'error' => (string)($result['error'] ?? 'Payout dispatch failed.')];
}
