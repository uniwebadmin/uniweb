<?php
require_once __DIR__ . '/payout.php';

/**
 * C1: Payout Jobs — async job queue for payout processing.
 * Jobs are created from payout_orders and processed by the payout worker (C3).
 */

function ensurePayoutJobsTable(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        getDB()->exec("CREATE TABLE IF NOT EXISTS payout_jobs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            job_id VARCHAR(40) NOT NULL UNIQUE,
            payout_order_id INT NOT NULL,
            merchant_id INT NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            status ENUM('queued','processing','success','failed','retry','cancelled') NOT NULL DEFAULT 'queued',
            adapter VARCHAR(60) DEFAULT NULL,
            partner_ref VARCHAR(120) DEFAULT NULL,
            utr VARCHAR(120) DEFAULT NULL,
            attempt INT NOT NULL DEFAULT 0,
            max_attempts INT NOT NULL DEFAULT 3,
            next_retry_at DATETIME DEFAULT NULL,
            error_message VARCHAR(500) DEFAULT NULL,
            payload JSON DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            INDEX idx_pjob_status (status, next_retry_at),
            INDEX idx_pjob_merchant (merchant_id, status),
            INDEX idx_pjob_order (payout_order_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('ensurePayoutJobsTable: ' . $e->getMessage());
    }
}

function getAllowedPayoutJobTransitions(): array
{
    return [
        'queued'     => ['processing', 'cancelled'],
        'processing' => ['success', 'failed', 'retry'],
        'retry'      => ['processing', 'cancelled'],
        'success'    => [],
        'failed'     => ['retry', 'cancelled'],
        'cancelled'  => [],
    ];
}

function validatePayoutJobTransition(string $from, string $to): void
{
    $allowed = getAllowedPayoutJobTransitions();
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if (!isset($allowed[$from])) {
        throw new RuntimeException("Unknown payout job status: {$from}");
    }
    if (!in_array($to, $allowed[$from], true)) {
        throw new RuntimeException("Invalid payout job transition: {$from} → {$to}");
    }
}

/**
 * Create a payout job from a payout order.
 */
function createPayoutJob(int $payoutOrderId, int $merchantId, float $amount, ?string $adapter = null, ?array $payload = null): array
{
    ensurePayoutJobsTable();
    if ($adapter === null) {
        require_once __DIR__ . '/payout_partner_api.php';
        $adapter = resolveDefaultPayoutAdapterName();
    }
    $db = getDB();
    $jobId = generateId('PJOB');

    try {
        $db->prepare(
            'INSERT INTO payout_jobs (job_id, payout_order_id, merchant_id, amount, status, adapter, payload)
             VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $jobId, $payoutOrderId, $merchantId, round($amount, 2),
            'queued', $adapter, $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null,
        ]);

        uwRecordAuditEvent('payout_job_created', [
            'merchant_id' => $merchantId,
            'resource_type' => 'payout_job',
            'resource_id' => $jobId,
            'reason' => 'Payout job queued for processing',
            'after_state' => ['payout_order_id' => $payoutOrderId, 'amount' => $amount, 'adapter' => $adapter],
        ]);

        return ['ok' => true, 'job_id' => $jobId];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update payout job status with transition validation.
 */
function updatePayoutJobStatus(int $jobId, string $newStatus, ?string $errorMessage = null, ?string $partnerRef = null, ?string $utr = null): void
{
    ensurePayoutJobsTable();
    $db = getDB();
    $st = $db->prepare('SELECT status FROM payout_jobs WHERE id=?');
    $st->execute([$jobId]);
    $job = $st->fetch();
    if (!$job) {
        throw new RuntimeException("Payout job #{$jobId} not found.");
    }
    $currentStatus = strtolower(trim($job['status']));
    $newStatus = strtolower(trim($newStatus));
    if ($currentStatus === $newStatus) return;
    validatePayoutJobTransition($currentStatus, $newStatus);

    $sql = "UPDATE payout_jobs SET status=?, error_message=?, partner_ref=?, utr=?";
    $params = [$newStatus, $errorMessage, $partnerRef, $utr];
    if ($newStatus === 'success') {
        $sql .= ", processed_at=NOW()";
    } elseif ($newStatus === 'retry') {
        $sql .= ", next_retry_at=DATE_ADD(NOW(), INTERVAL POW(2, attempt) * 60 SECOND)";
    } elseif ($newStatus === 'processing') {
        $sql .= ", attempt=attempt+1";
    }
    $sql .= " WHERE id=?";
    $params[] = $jobId;
    $db->prepare($sql)->execute($params);

    uwRecordAuditEvent('payout_job_status', [
        'resource_type' => 'payout_job',
        'resource_id' => (string)$jobId,
        'reason' => "Status: {$currentStatus} → {$newStatus}" . ($errorMessage ? " ({$errorMessage})" : ''),
        'before_state' => ['status' => $currentStatus],
        'after_state' => ['status' => $newStatus, 'partner_ref' => $partnerRef, 'utr' => $utr],
    ]);
}

/**
 * Get jobs ready for processing (queued or retry with past next_retry_at).
 */
function getQueuedPayoutJobs(int $limit = 20): array
{
    ensurePayoutJobsTable();
    try {
        $st = getDB()->prepare(
            "SELECT * FROM payout_jobs
             WHERE (status = 'queued'
                    OR (status = 'retry' AND (next_retry_at IS NULL OR next_retry_at <= NOW())))
             ORDER BY created_at ASC
             LIMIT ?"
        );
        $st->bindValue(1, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get a payout job by ID.
 */
function getPayoutJob(int $jobId): ?array
{
    ensurePayoutJobsTable();
    try {
        $st = getDB()->prepare('SELECT * FROM payout_jobs WHERE id=?');
        $st->execute([$jobId]);
        $row = $st->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Get payout jobs for a merchant.
 */
function getMerchantPayoutJobs(int $merchantId, int $limit = 50): array
{
    ensurePayoutJobsTable();
    try {
        $st = getDB()->prepare('SELECT * FROM payout_jobs WHERE merchant_id=? ORDER BY created_at DESC LIMIT ?');
        $st->bindValue(1, $merchantId, PDO::PARAM_INT);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get payout job stats for admin dashboard.
 */
function getPayoutJobStats(): array
{
    ensurePayoutJobsTable();
    $stats = ['queued' => 0, 'processing' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0, 'cancelled' => 0];
    try {
        $rows = getDB()->query('SELECT status, COUNT(*) as cnt FROM payout_jobs GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $stats[$row['status']] = (int)$row['cnt'];
        }
    } catch (Throwable $e) {}
    return $stats;
}

/**
 * Cancel a payout job (only from queued/retry/failed states).
 */
function cancelPayoutJob(int $jobId, string $reason = ''): array
{
    ensurePayoutJobsTable();
    try {
        $job = getPayoutJob($jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'Job not found.'];
        }
        if (in_array($job['status'], ['success', 'cancelled', 'processing'], true)) {
            return ['ok' => false, 'error' => "Cannot cancel job in {$job['status']} state."];
        }
        updatePayoutJobStatus($jobId, 'cancelled', $reason);
        return ['ok' => true, 'message' => 'Job cancelled.'];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
