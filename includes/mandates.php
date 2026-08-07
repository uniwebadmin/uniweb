<?php
declare(strict_types=1);

/**
 * Recurring Mandate Engine (P3.1)
 *
 * Supports UPI Autopay and eNACH mandates for subscription/recurring billing.
 *
 * Flow:
 *   1. Merchant creates mandate registration link
 *   2. Customer approves mandate via UPI app / bank
 *   3. System polls/webhooks for mandate registration status
 *   4. Cron processes due debits on next_debit_date
 *   5. Failed debits retried with backoff
 */

function ensureMandateSchema(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;
    $db = getDB();
    $sqlFile = __DIR__ . '/../migrations/051_mandates.sql';
    if (is_file($sqlFile)) {
        try {
            foreach (migrationStatements(file_get_contents($sqlFile)) as $stmt) {
                $db->exec($stmt);
            }
        } catch (Throwable $e) { /* ok */ }
    }
}

function generateMandateRef(): string
{
    return 'MND-' . strtoupper(bin2hex(random_bytes(8)));
}

/**
 * Create a new mandate registration request.
 */
function createMandate(
    int $merchantId,
    float $maxAmount,
    string $frequency,
    string $startDate,
    ?string $endDate = null,
    ?int $maxDebits = null,
    ?string $customerName = null,
    ?string $customerEmail = null,
    ?string $customerPhone = null,
    ?string $customerUpiId = null,
    string $mandateType = 'upi_autopay'
): array {
    ensureMandateSchema();
    $db = getDB();

    $ref = generateMandateRef();
    $nextDebit = $startDate;

    try {
        $db->prepare(
            'INSERT INTO mandates
             (merchant_id, mandate_ref, customer_name, customer_email, customer_phone, customer_upi_id,
              mandate_type, status, max_amount, frequency, start_date, end_date, next_debit_date, max_debits)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $merchantId, $ref, $customerName, $customerEmail, $customerPhone, $customerUpiId,
            $mandateType, 'pending', $maxAmount, $frequency, $startDate, $endDate, $nextDebit, $maxDebits
        ]);

        $mandateId = (int)$db->lastInsertId();

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('mandate_created', $merchantId, 'mandate', (string)$mandateId, "Mandate $ref created for $maxAmount/$frequency");
        }

        return ['ok' => true, 'mandate_id' => $mandateId, 'mandate_ref' => $ref];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Update mandate status (e.g., after gateway confirms registration).
 */
function updateMandateStatus(int $mandateId, string $status, ?string $gatewayMandateId = null, ?array $gatewayResponse = null): bool
{
    ensureMandateSchema();
    $db = getDB();

    $validStatuses = ['pending', 'registered', 'active', 'paused', 'cancelled', 'failed', 'expired'];
    if (!in_array($status, $validStatuses, true)) {
        return false;
    }

    try {
        $db->prepare("UPDATE mandates SET status = ?, gateway_mandate_id = COALESCE(?, gateway_mandate_id), gateway_response = COALESCE(?, gateway_response) WHERE id = ?")
            ->execute([
                $status,
                $gatewayMandateId,
                $gatewayResponse ? json_encode($gatewayResponse) : null,
                $mandateId,
            ]);

        if ($status === 'active' || $status === 'registered') {
            $st = $db->prepare('SELECT merchant_id, mandate_ref FROM mandates WHERE id = ?');
            $st->execute([$mandateId]);
            $m = $st->fetch();
            if ($m && function_exists('createNotification')) {
                createNotification(
                    (int)$m['merchant_id'],
                    'Mandate Registered',
                    'Your mandate ' . $m['mandate_ref'] . ' has been registered. Recurring debits will start as scheduled.'
                );
            }
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Calculate next debit date based on frequency.
 */
function calculateNextDebitDate(string $currentDate, string $frequency): ?string
{
    $ts = strtotime($currentDate);
    if ($ts === false) {
        return null;
    }

    return match ($frequency) {
        'daily' => date('Y-m-d', strtotime('+1 day', $ts)),
        'weekly' => date('Y-m-d', strtotime('+1 week', $ts)),
        'monthly' => date('Y-m-d', strtotime('+1 month', $ts)),
        'quarterly' => date('Y-m-d', strtotime('+3 months', $ts)),
        'halfyearly' => date('Y-m-d', strtotime('+6 months', $ts)),
        'yearly' => date('Y-m-d', strtotime('+1 year', $ts)),
        'onetime', 'as_presented' => null,
        default => null,
    };
}

/**
 * Process due mandate debits — called by cron.
 */
function processDueMandateDebits(): array
{
    ensureMandateSchema();
    $db = getDB();

    $summary = ['checked' => 0, 'debited' => 0, 'failed' => 0, 'skipped' => 0];

    try {
        $st = $db->prepare(
            "SELECT * FROM mandates
             WHERE status = 'active'
               AND next_debit_date <= CURDATE()
               AND (end_date IS NULL OR end_date >= CURDATE())
               AND (max_debits IS NULL OR debit_count < max_debits)
             ORDER BY next_debit_date ASC
             LIMIT 100"
        );
        $st->execute();
        $mandates = $st->fetchAll();
    } catch (Throwable $e) {
        return $summary;
    }

    foreach ($mandates as $mandate) {
        $summary['checked']++;
        $mandateId = (int)$mandate['id'];
        $merchantId = (int)$mandate['merchant_id'];
        $amount = (float)$mandate['max_amount'];

        // Create debit record
        try {
            $db->prepare(
                'INSERT INTO mandate_debits (mandate_id, merchant_id, amount, status, attempted_at)
                 VALUES (?,?,?,\'pending\',NOW())'
            )->execute([$mandateId, $merchantId, $amount]);
            $debitId = (int)$db->lastInsertId();
        } catch (Throwable $e) {
            $summary['skipped']++;
            continue;
        }

        // Attempt the debit via gateway (placeholder — actual gateway integration later)
        $debitResult = attemptMandateDebit($mandate, $debitId);

        if ($debitResult['ok']) {
            $summary['debited']++;
            // Update mandate counters
            $nextDebit = calculateNextDebitDate($mandate['next_debit_date'], $mandate['frequency']);
            $db->prepare(
                "UPDATE mandates
                 SET total_debited = total_debited + ?,
                     debit_count = debit_count + 1,
                     last_debit_date = CURDATE(),
                     last_debit_amount = ?,
                     next_debit_date = ?,
                     status = CASE WHEN max_debits IS NOT NULL AND debit_count + 1 >= max_debits THEN 'expired' ELSE status END
                 WHERE id = ?"
            )->execute([$amount, $amount, $nextDebit, $mandateId]);
        } else {
            $summary['failed']++;
            $db->prepare(
                "UPDATE mandate_debits SET status = 'failed', failure_reason = ? WHERE id = ?"
            )->execute([$debitResult['error'] ?? 'Unknown', $debitId]);

            // If too many failures, pause the mandate
            $failCount = getMandateConsecutiveFailures($mandateId);
            if ($failCount >= 3) {
                updateMandateStatus($mandateId, 'paused');
                if (function_exists('createNotification')) {
                    createNotification(
                        $merchantId,
                        'Mandate Paused',
                        'Mandate ' . $mandate['mandate_ref'] . ' has been paused after 3 consecutive failed debit attempts.'
                    );
                }
            }
        }
    }

    return $summary;
}

/**
 * Attempt a single mandate debit via gateway.
 * Currently a placeholder — will be wired to actual gateway (Decentro/Razorpay) later.
 */
function attemptMandateDebit(array $mandate, int $debitId): array
{
    // TODO: Wire to actual gateway when partner keys are configured
    // For now, return a placeholder response
    return [
        'ok' => false,
        'error' => 'Mandate debit gateway not yet configured. Partner adapter required.',
        'debit_id' => $debitId,
    ];
}

/**
 * Get consecutive failure count for a mandate.
 */
function getMandateConsecutiveFailures(int $mandateId): int
{
    try {
        $st = getDB()->prepare(
            "SELECT COUNT(*) FROM mandate_debits
             WHERE mandate_id = ?
               AND status = 'failed'
               AND id > COALESCE(
                 (SELECT MAX(id) FROM mandate_debits WHERE mandate_id = ? AND status = 'success'),
                 0
               )"
        );
        $st->execute([$mandateId, $mandateId]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Get mandates for a merchant.
 */
function getMerchantMandates(int $merchantId, int $limit = 50): array
{
    ensureMandateSchema();
    try {
        $st = getDB()->prepare(
            'SELECT * FROM mandates WHERE merchant_id = ? ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$merchantId, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Get mandate debit history.
 */
function getMandateDebits(int $mandateId, int $limit = 100): array
{
    ensureMandateSchema();
    try {
        $st = getDB()->prepare(
            'SELECT * FROM mandate_debits WHERE mandate_id = ? ORDER BY id DESC LIMIT ?'
        );
        $st->execute([$mandateId, $limit]);
        return $st->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Cancel a mandate.
 */
function cancelMandate(int $mandateId, string $reason): bool
{
    ensureMandateSchema();
    $db = getDB();
    try {
        $st = $db->prepare('SELECT merchant_id, mandate_ref FROM mandates WHERE id = ?');
        $st->execute([$mandateId]);
        $m = $st->fetch();
        if (!$m) {
            return false;
        }

        $db->prepare("UPDATE mandates SET status = 'cancelled', failure_reason = ? WHERE id = ? AND status IN ('active','paused','registered','pending')")
            ->execute([$reason, $mandateId]);

        if (function_exists('recordImmutableAudit')) {
            recordImmutableAudit('mandate_cancelled', (int)$m['merchant_id'], 'mandate', (string)$mandateId, $reason);
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}
