<?php
/**
 * C2: PayoutAdapter interface + mock implementation.
 * 
 * Adapters encapsulate partner-specific payout API calls.
 * Each adapter implements: dispatch, checkStatus, cancel.
 * 
 * Registered adapters:
 *   - mock:    Always succeeds (test mode), generates fake UTR
 *   - razorpayx: RazorpayX Payouts (stub — needs live keys)
 *   - cashfree:  Cashfree Payouts (stub — needs live keys)
 */

interface PayoutAdapterInterface
{
    /**
     * Dispatch a payout to the partner.
     * @param array $job Row from payout_jobs
     * @param array $beneficiary Beneficiary details
     * @return array ['ok'=>bool, 'partner_ref'=>?string, 'utr'=>?string, 'error'=>?string]
     */
    public function dispatch(array $job, array $beneficiary): array;

    /**
     * Check the status of a dispatched payout.
     * @param array $job Row from payout_jobs
     * @return array ['status'=>'success'|'failed'|'pending', 'utr'=>?string, 'error'=>?string]
     */
    public function checkStatus(array $job): array;

    /**
     * Attempt to cancel a dispatched payout (may not be possible if already processed).
     * @param array $job Row from payout_jobs
     * @return array ['ok'=>bool, 'error'=>?string]
     */
    public function cancel(array $job): array;

    /**
     * Whether this adapter has live partner keys configured.
     */
    public function isLive(): bool;

    /**
     * Adapter name (e.g. 'mock', 'razorpayx', 'cashfree').
     */
    public function name(): string;
}

/**
 * Mock PayoutAdapter — always succeeds, generates fake UTR.
 * Used for test mode and development.
 */
class MockPayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'mock'; }

    public function isLive(): bool { return false; }

    public function dispatch(array $job, array $beneficiary): array
    {
        $utr = 'MOCK' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));
        $partnerRef = 'MOCK_REF_' . $job['job_id'];

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
            'utr' => $job['utr'] ?? null,
        ];
    }

    public function cancel(array $job): array
    {
        return ['ok' => true];
    }
}

/**
 * RazorpayX PayoutAdapter — stub for when live keys are configured.
 */
class RazorpayXPayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'razorpayx'; }

    public function isLive(): bool
    {
        $key = trim((string)getSetting('razorpayx_key_id', ''));
        $secret = trim((string)getSetting('razorpayx_key_secret', ''));
        return $key !== '' && $secret !== '';
    }

    public function dispatch(array $job, array $beneficiary): array
    {
        if (!$this->isLive()) {
            return ['ok' => false, 'error' => 'RazorpayX keys not configured.'];
        }

        // TODO: Implement live RazorpayX Payout API call when keys are available
        // For now, return gated response
        return ['ok' => false, 'error' => 'RazorpayX payout adapter not yet implemented for live use.'];
    }

    public function checkStatus(array $job): array
    {
        if (!$this->isLive()) {
            return ['status' => 'pending', 'error' => 'Keys not configured.'];
        }
        // TODO: Implement RazorpayX fetch payout status
        return ['status' => 'pending'];
    }

    public function cancel(array $job): array
    {
        if (!$this->isLive()) {
            return ['ok' => false, 'error' => 'Keys not configured.'];
        }
        // TODO: Implement RazorpayX cancel payout
        return ['ok' => false, 'error' => 'Not yet implemented.'];
    }
}

/**
 * Cashfree PayoutAdapter — stub for when live keys are configured.
 */
class CashfreePayoutAdapter implements PayoutAdapterInterface
{
    public function name(): string { return 'cashfree'; }

    public function isLive(): bool
    {
        $key = trim((string)getSetting('cashfree_payout_key', ''));
        $secret = trim((string)getSetting('cashfree_payout_secret', ''));
        return $key !== '' && $secret !== '';
    }

    public function dispatch(array $job, array $beneficiary): array
    {
        if (!$this->isLive()) {
            return ['ok' => false, 'error' => 'Cashfree Payout keys not configured.'];
        }
        return ['ok' => false, 'error' => 'Cashfree payout adapter not yet implemented for live use.'];
    }

    public function checkStatus(array $job): array
    {
        if (!$this->isLive()) {
            return ['status' => 'pending', 'error' => 'Keys not configured.'];
        }
        return ['status' => 'pending'];
    }

    public function cancel(array $job): array
    {
        if (!$this->isLive()) {
            return ['ok' => false, 'error' => 'Keys not configured.'];
        }
        return ['ok' => false, 'error' => 'Not yet implemented.'];
    }
}

/**
 * Get the appropriate payout adapter for a job.
 * Uses mock for test mode, real adapter for live mode.
 */
function getPayoutAdapter(?string $adapterName = null): PayoutAdapterInterface
{
    $name = $adapterName ?? 'mock';

    // For test mode or no live keys, always use mock
    if (!payoutLiveMoneyAllowed()) {
        return new MockPayoutAdapter();
    }

    return match ($name) {
        'razorpayx' => new RazorpayXPayoutAdapter(),
        'cashfree'  => new CashfreePayoutAdapter(),
        default     => new MockPayoutAdapter(),
    };
}

/**
 * Get all registered adapter names.
 */
function getRegisteredPayoutAdapters(): array
{
    return [
        'mock'      => 'Mock (Test Mode)',
        'razorpayx' => 'RazorpayX Payouts',
        'cashfree'  => 'Cashfree Payouts',
    ];
}
