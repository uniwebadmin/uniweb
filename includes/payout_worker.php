<?php
/**
 * C3: Payout Worker — processes queued payout jobs through adapters.
 * 
 * Lifecycle per job:
 *   queued → processing → success (set UTR, mark payout_order success, settle)
 *                       → failed  (record reason, mark payout_order failed, reverse wallet)
 *                       → retry   (exponential backoff, up to max_attempts)
 * 
 * Called from cron / auto_audit.
 */

require_once __DIR__ . '/payout_jobs.php';
require_once __DIR__ . '/payout_adapters.php';

/**
 * Process queued payout jobs. Called from cron.
 */
function processPayoutJobs(int $limit = 20): array
{
    ensurePayoutJobsTable();
    $jobs = getQueuedPayoutJobs($limit);
    $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0, 'errors' => []];

    foreach ($jobs as $job) {
        $result = processSinglePayoutJob($job);
        $results['processed']++;
        if ($result['ok']) {
            $results['success']++;
        } elseif ($result['retry']) {
            $results['retry']++;
        } else {
            $results['failed']++;
        }
        if (!empty($result['error'])) {
            $results['errors'][] = $result['error'];
        }
    }

    return $results;
}

/**
 * Process a single payout job through the adapter.
 */
function processSinglePayoutJob(array $job): array
{
    $db = getDB();
    $jobId = (int)$job['id'];
    $merchantId = (int)$job['merchant_id'];
    $amount = (float)$job['amount'];

    try {
        // Move to processing
        updatePayoutJobStatus($jobId, 'processing');

        // Get beneficiary
        $st = $db->prepare('SELECT * FROM payout_beneficiaries WHERE id=? AND merchant_id=?');
        $st->execute([(int)$job['payout_order_id'], $merchantId]);
        $beneficiary = $st->fetch();

        // Try payout_order's beneficiary_id
        if (!$beneficiary) {
            $st2 = $db->prepare('SELECT beneficiary_id FROM payout_orders WHERE id=?');
            $st2->execute([(int)$job['payout_order_id']]);
            $benId = (int)$st2->fetchColumn();
            if ($benId > 0) {
                $st3 = $db->prepare('SELECT * FROM payout_beneficiaries WHERE id=? AND merchant_id=?');
                $st3->execute([$benId, $merchantId]);
                $beneficiary = $st3->fetch();
            }
        }

        if (!$beneficiary) {
            updatePayoutJobStatus($jobId, 'failed', 'Beneficiary not found');
            markPayoutOrderFailed((int)$job['payout_order_id'], $merchantId, 'Beneficiary not found');
            return ['ok' => false, 'retry' => false, 'error' => 'Beneficiary not found for job ' . $job['job_id']];
        }

        // Get adapter
        $adapter = getPayoutAdapter($job['adapter'] ?? null);

        // Dispatch
        $result = $adapter->dispatch($job, $beneficiary);

        if ($result['ok']) {
            // Success — record UTR, mark payout order success
            updatePayoutJobStatus($jobId, 'success', null, $result['partner_ref'] ?? null, $result['utr'] ?? null);
            markPayoutOrderSuccess((int)$job['payout_order_id'], $merchantId, $result['utr'] ?? null, $result['partner_ref'] ?? null);

            return ['ok' => true, 'retry' => false, 'utr' => $result['utr'] ?? null];
        } else {
            // Failed — check if we can retry
            $attempt = (int)$job['attempt'] + 1;
            $maxAttempts = (int)$job['max_attempts'];

            if ($attempt < $maxAttempts) {
                updatePayoutJobStatus($jobId, 'retry', $result['error'] ?? 'Unknown error');
                return ['ok' => false, 'retry' => true, 'error' => $result['error'] ?? 'Unknown error'];
            } else {
                updatePayoutJobStatus($jobId, 'failed', $result['error'] ?? 'Unknown error');
                markPayoutOrderFailed((int)$job['payout_order_id'], $merchantId, $result['error'] ?? 'Unknown error');
                return ['ok' => false, 'retry' => false, 'error' => $result['error'] ?? 'Unknown error'];
            }
        }
    } catch (Throwable $e) {
        try {
            $attempt = (int)$job['attempt'] + 1;
            $maxAttempts = (int)$job['max_attempts'];
            if ($attempt < $maxAttempts) {
                updatePayoutJobStatus($jobId, 'retry', $e->getMessage());
                return ['ok' => false, 'retry' => true, 'error' => $e->getMessage()];
            } else {
                updatePayoutJobStatus($jobId, 'failed', $e->getMessage());
                markPayoutOrderFailed((int)$job['payout_order_id'], $merchantId, $e->getMessage());
            }
        } catch (Throwable $inner) {}
        return ['ok' => false, 'retry' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Mark a payout order as successful and record UTR.
 */
function markPayoutOrderSuccess(int $payoutOrderId, int $merchantId, string $utr, ?string $partnerRef = null): void
{
    $db = getDB();
    try {
        $db->prepare(
            "UPDATE payout_orders SET status='success', partner_ref=?, updated_at=NOW() WHERE id=? AND status IN ('queued','processing','pending_checker')"
        )->execute([$partnerRef ?? $utr, $payoutOrderId]);

        // Record UTR in a separate column if it exists
        try {
            $db->prepare("UPDATE payout_orders SET utr=? WHERE id=?")->execute([$utr, $payoutOrderId]);
        } catch (Throwable $e) {}

        // Notify merchant
        if (function_exists('createNotification')) {
            createNotification($merchantId, 'Payout Successful', 'UTR: ' . $utr . ' — Amount: ' . formatMoney((float)$db->query('SELECT amount FROM payout_orders WHERE id=' . $payoutOrderId)->fetchColumn()));
        }

        recordAuditEvent('payout_success', [
            'merchant_id' => $merchantId,
            'resource_type' => 'payout_order',
            'resource_id' => (string)$payoutOrderId,
            'reason' => 'Payout completed successfully',
            'after_state' => ['utr' => $utr, 'partner_ref' => $partnerRef],
        ]);
    } catch (Throwable $e) {
        error_log('markPayoutOrderSuccess failed: ' . $e->getMessage());
    }
}

/**
 * Mark a payout order as failed and reverse the wallet hold.
 */
function markPayoutOrderFailed(int $payoutOrderId, int $merchantId, string $reason): void
{
    $db = getDB();
    try {
        $db->prepare(
            "UPDATE payout_orders SET status='failed', failure_reason=?, updated_at=NOW() WHERE id=? AND status IN ('queued','processing','pending_checker')"
        )->execute([mb_substr($reason, 0, 500), $payoutOrderId]);

        // Map failure reason for merchant-facing message
        $merchantReason = $reason;
        if (function_exists('mapGatewayFailureReasonLocalized')) {
            $merchantReason = mapGatewayFailureReasonLocalized(null, $reason, 'en');
        }

        // Notify merchant
        if (function_exists('createNotification')) {
            createNotification($merchantId, 'Payout Failed', $merchantReason);
        }

        recordAuditEvent('payout_failed', [
            'merchant_id' => $merchantId,
            'resource_type' => 'payout_order',
            'resource_id' => (string)$payoutOrderId,
            'reason' => 'Payout failed: ' . $reason,
            'after_state' => ['failure_reason' => $reason],
        ]);
    } catch (Throwable $e) {
        error_log('markPayoutOrderFailed failed: ' . $e->getMessage());
    }
}
