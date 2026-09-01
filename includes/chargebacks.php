<?php
declare(strict_types=1);

function ensureChargebacksEngine(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        getDB()->exec("ALTER TABLE chargebacks MODIFY COLUMN status ENUM('opened','evidence_required','submitted','won','lost','withdrawn','expired') NOT NULL DEFAULT 'opened'");
    } catch (Throwable $e) { /* ok */ }
}

function chargebackStatusLabel(string $status): string
{
    return match (strtolower(trim($status))) {
        'evidence_required' => 'Evidence due',
        'submitted' => 'Evidence submitted',
        'won' => 'Won',
        'lost' => 'Lost',
        'expired' => 'Expired',
        'withdrawn' => 'Withdrawn',
        default => 'Opened',
    };
}

/** @return list<string> */
function terminalChargebackStatuses(): array
{
    return ['won', 'lost', 'withdrawn', 'expired'];
}

function chargebackStatusBadge(string $status): string
{
    $key = strtolower(trim($status));
    $map = [
        'opened' => 'bg-sky-500/10 text-sky-300',
        'evidence_required' => 'bg-amber-500/10 text-amber-300',
        'submitted' => 'bg-violet-500/10 text-violet-300',
        'won' => 'bg-emerald-500/10 text-emerald-300',
        'lost' => 'bg-red-500/10 text-red-300',
        'withdrawn' => 'bg-gray-500/10 text-gray-300',
        'expired' => 'bg-gray-500/10 text-gray-400',
    ];
    $cls = $map[$key] ?? 'bg-gray-500/10 text-gray-400';
    return '<span class="px-2 py-1 rounded-full text-xs font-medium ' . $cls . '">' . e(chargebackStatusLabel($status)) . '</span>';
}

function chargebackAllowedStatusTransition(string $from, string $to): bool
{
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if (in_array($from, terminalChargebackStatuses(), true)) {
        return false;
    }
    if ($from === $to) {
        return true;
    }
    return match ($from) {
        'opened', 'evidence_required' => in_array($to, ['evidence_required', 'submitted', 'won', 'lost', 'withdrawn', 'expired'], true),
        'submitted' => in_array($to, ['won', 'lost', 'withdrawn', 'expired'], true),
        default => false,
    };
}

function findChargebackByProviderDispute(string $provider, ?string $providerDisputeId): ?array
{
    $providerDisputeId = trim((string)$providerDisputeId);
    if ($providerDisputeId === '') {
        return null;
    }
    $st = getDB()->prepare('SELECT * FROM chargebacks WHERE provider=? AND provider_dispute_id=? LIMIT 1');
    $st->execute([strtolower(trim($provider)), $providerDisputeId]);
    $row = $st->fetch();
    return $row ?: null;
}

function ingestChargeback(array $payload): array
{
    ensureChargebacksEngine();
    $merchantId = (int)($payload['merchant_id'] ?? 0);
    $amount = round((float)($payload['amount'] ?? 0), 2);
    if ($merchantId < 1 || $amount <= 0) {
        throw new InvalidArgumentException('Chargeback requires merchant and amount.');
    }
    $provider = strtolower(trim((string)($payload['provider'] ?? 'razorpay')));
    $providerDisputeId = trim((string)($payload['provider_dispute_id'] ?? ''));
    if ($providerDisputeId !== '') {
        $existing = findChargebackByProviderDispute($provider, $providerDisputeId);
        if ($existing) {
            return [
                'chargeback_ref' => (string)$existing['chargeback_ref'],
                'evidence_due_at' => (string)($existing['evidence_due_at'] ?? ''),
                'duplicate' => true,
            ];
        }
    }
    $transactionId = !empty($payload['transaction_id']) ? (int)$payload['transaction_id'] : null;
    if ($transactionId !== null && $transactionId > 0) {
        $txnSt = getDB()->prepare('SELECT id FROM transactions WHERE id=? AND merchant_id=? LIMIT 1');
        $txnSt->execute([$transactionId, $merchantId]);
        if (!$txnSt->fetchColumn()) {
            throw new InvalidArgumentException('Transaction does not belong to this merchant.');
        }
    }
    $ref = 'CB-' . strtoupper(bin2hex(random_bytes(6)));
    $due = !empty($payload['evidence_due_at'])
        ? (string)$payload['evidence_due_at']
        : date('Y-m-d H:i:s', strtotime('+7 days'));
    try {
        getDB()->prepare(
            'INSERT INTO chargebacks
             (chargeback_ref,merchant_id,transaction_id,provider,provider_dispute_id,amount,currency,reason_code,reason_text,status,evidence_due_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $ref,
            $merchantId,
            $transactionId,
            $provider,
            $providerDisputeId !== '' ? $providerDisputeId : null,
            $amount,
            (string)($payload['currency'] ?? 'INR'),
            $payload['reason_code'] ?? null,
            $payload['reason_text'] ?? null,
            'evidence_required',
            $due,
        ]);
    } catch (Throwable $e) {
        if ($providerDisputeId !== '' && str_contains($e->getMessage(), 'uniq_provider_dispute')) {
            $existing = findChargebackByProviderDispute($provider, $providerDisputeId);
            if ($existing) {
                return [
                    'chargeback_ref' => (string)$existing['chargeback_ref'],
                    'evidence_due_at' => (string)($existing['evidence_due_at'] ?? ''),
                    'duplicate' => true,
                ];
            }
        }
        throw $e;
    }
    createNotification($merchantId, 'Chargeback opened', "Dispute {$ref} needs evidence by " . date('d M Y', strtotime($due)) . '. This is not a refund.', 'cb_open_' . $ref);
    recordImmutableAudit('chargeback_opened', $merchantId, 'chargeback', $ref, (string)($payload['reason_text'] ?? 'opened'));
    return ['chargeback_ref' => $ref, 'evidence_due_at' => $due];
}

function submitChargebackEvidence(int $chargebackId, int $merchantId, string $notes): void
{
    ensureChargebacksEngine();
    $notes = trim($notes);
    if ($notes === '') {
        throw new InvalidArgumentException('Evidence notes are required.');
    }
    $st = getDB()->prepare(
        "UPDATE chargebacks
         SET status='submitted', evidence_notes=?, evidence_submitted_at=NOW(), updated_at=NOW()
         WHERE id=? AND merchant_id=? AND status IN ('opened','evidence_required')"
    );
    $st->execute([$notes, $chargebackId, $merchantId]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('Chargeback is not open for evidence submission.');
    }
    recordImmutableAudit('chargeback_evidence_submitted', $merchantId, 'chargeback', (string)$chargebackId, $notes);
}

/**
 * Idempotent merchant debit when chargeback is lost — once per chargeback_ref.
 *
 * @return array{ok:bool,duplicate?:bool,balance?:float}
 */
function applyChargebackLostDebit(int $chargebackId): array
{
    ensureChargebacksEngine();
    if (!function_exists('postMerchantWalletMovement') && is_file(__DIR__ . '/financial_integrity.php')) {
        require_once __DIR__ . '/financial_integrity.php';
    }
    $st = getDB()->prepare('SELECT * FROM chargebacks WHERE id=? LIMIT 1');
    $st->execute([$chargebackId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'error' => 'Chargeback not found.'];
    }
    if ((string)$row['status'] !== 'lost') {
        return ['ok' => false, 'error' => 'Chargeback is not marked lost.'];
    }
    $ref = (string)$row['chargeback_ref'];
    $amount = round((float)$row['amount'], 2);
    if ($amount <= 0) {
        return ['ok' => false, 'error' => 'Invalid chargeback amount.'];
    }
    $result = postMerchantWalletMovement(
        (int)$row['merchant_id'],
        -$amount,
        'chargeback_lost',
        $ref,
        'Chargeback lost — ' . $ref
    );
    if (function_exists('createNotification')) {
        createNotification(
            (int)$row['merchant_id'],
            'Chargeback lost',
            $ref . ' — ' . formatMoney($amount) . ' debited from settlement balance. This is not a refund credit to the customer.',
            'cb_lost_' . $ref
        );
    }
    return ['ok' => true, 'duplicate' => !empty($result['duplicate']), 'balance' => (float)($result['balance'] ?? 0)];
}

/** Admin resolution with allowed transitions + lost ledger hook. */
function resolveChargebackStatus(int $chargebackId, string $newStatus): array
{
    ensureChargebacksEngine();
    $newStatus = strtolower(trim($newStatus));
    if (!in_array($newStatus, ['won', 'lost', 'withdrawn', 'expired'], true)) {
        return ['ok' => false, 'message' => 'Invalid resolution status.'];
    }
    $st = getDB()->prepare('SELECT * FROM chargebacks WHERE id=? LIMIT 1');
    $st->execute([$chargebackId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'Chargeback not found.'];
    }
    $priorStatus = (string)$row['status'];
    if (!chargebackAllowedStatusTransition($priorStatus, $newStatus)) {
        return ['ok' => false, 'message' => 'Status transition not allowed from ' . chargebackStatusLabel($priorStatus) . '.'];
    }
    $upd = getDB()->prepare('UPDATE chargebacks SET status=?, updated_at=NOW() WHERE id=? AND status=?');
    $upd->execute([$newStatus, $chargebackId, $priorStatus]);
    if ($upd->rowCount() < 1) {
        return ['ok' => false, 'message' => 'Chargeback was already updated. Refresh the list.'];
    }
    recordImmutableAudit('chargeback_resolved', (int)$row['merchant_id'], 'chargeback', (string)$row['chargeback_ref'], $newStatus);
    if ($newStatus === 'lost') {
        applyChargebackLostDebit($chargebackId);
    }
    return ['ok' => true, 'message' => 'Chargeback marked ' . chargebackStatusLabel($newStatus) . '.'];
}

/** Mark overdue evidence windows expired (cron-safe). */
function expireOverdueChargebacks(): array
{
    ensureChargebacksEngine();
    $st = getDB()->prepare(
        "UPDATE chargebacks SET status='expired', updated_at=NOW()
         WHERE status IN ('opened','evidence_required') AND evidence_due_at IS NOT NULL AND evidence_due_at < NOW()"
    );
    $st->execute();
    return ['ok' => true, 'expired' => $st->rowCount()];
}

function ensureDemoChargebacks(int $merchantId): void
{
    if ($merchantId < 1) {
        return;
    }
    try {
        $st = getDB()->prepare('SELECT COUNT(*) FROM chargebacks WHERE merchant_id=?');
        $st->execute([$merchantId]);
        if ((int)$st->fetchColumn() > 0) {
            return;
        }
        ingestChargeback([
            'merchant_id' => $merchantId,
            'amount' => 1.00,
            'provider' => 'demo',
            'reason_code' => '10.4',
            'reason_text' => 'Demo chargeback — merchandise not received (sandbox sample)',
            'evidence_due_at' => date('Y-m-d H:i:s', strtotime('+5 days')),
        ]);
    } catch (Throwable $e) {
        // Table may be missing until migrations; ignore for demo seed.
    }
}

function listMerchantChargebacks(int $merchantId): array
{
    $st = getDB()->prepare('SELECT * FROM chargebacks WHERE merchant_id=? ORDER BY id DESC');
    $st->execute([$merchantId]);
    return $st->fetchAll();
}

/** @return list<array<string,mixed>> */
function listChargebacksForTransaction(int $transactionId): array
{
    if ($transactionId < 1) {
        return [];
    }
    ensureChargebacksEngine();
    $st = getDB()->prepare('SELECT * FROM chargebacks WHERE transaction_id=? ORDER BY id DESC');
    $st->execute([$transactionId]);
    return $st->fetchAll();
}

function listOpenChargebacks(int $limit = 100): array
{
    ensureChargebacksEngine();
    $st = getDB()->prepare(
        "SELECT c.*, m.business_name, m.merchant_code
         FROM chargebacks c JOIN merchants m ON m.id=c.merchant_id
         WHERE c.status IN ('opened','evidence_required','submitted')
         ORDER BY COALESCE(c.evidence_due_at, c.created_at) ASC LIMIT ?"
    );
    $st->bindValue(1, $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll();
}
