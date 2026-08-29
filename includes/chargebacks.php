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

function chargebackAllowedStatusTransition(string $from, string $to): bool
{
    $from = strtolower(trim($from));
    $to = strtolower(trim($to));
    if ($from === $to) {
        return true;
    }
    if (in_array($from, ['won', 'lost', 'withdrawn', 'expired'], true)) {
        return false;
    }
    return match ($from) {
        'opened', 'evidence_required' => in_array($to, ['evidence_required', 'submitted', 'won', 'lost', 'withdrawn', 'expired'], true),
        'submitted' => in_array($to, ['won', 'lost', 'withdrawn', 'expired'], true),
        default => false,
    };
}

function ingestChargeback(array $payload): array
{
    ensureChargebacksEngine();
    $merchantId = (int)($payload['merchant_id'] ?? 0);
    $amount = round((float)($payload['amount'] ?? 0), 2);
    if ($merchantId < 1 || $amount <= 0) {
        throw new InvalidArgumentException('Chargeback requires merchant and amount.');
    }
    $ref = 'CB-' . strtoupper(bin2hex(random_bytes(6)));
    $due = !empty($payload['evidence_due_at'])
        ? (string)$payload['evidence_due_at']
        : date('Y-m-d H:i:s', strtotime('+7 days'));
    getDB()->prepare(
        'INSERT INTO chargebacks
         (chargeback_ref,merchant_id,transaction_id,provider,provider_dispute_id,amount,currency,reason_code,reason_text,status,evidence_due_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $ref,
        $merchantId,
        !empty($payload['transaction_id']) ? (int)$payload['transaction_id'] : null,
        (string)($payload['provider'] ?? 'razorpay'),
        $payload['provider_dispute_id'] ?? null,
        $amount,
        (string)($payload['currency'] ?? 'INR'),
        $payload['reason_code'] ?? null,
        $payload['reason_text'] ?? null,
        'evidence_required',
        $due,
    ]);
    createNotification($merchantId, 'Chargeback opened', "Dispute {$ref} needs evidence by " . date('d M Y', strtotime($due)) . '. This is not a refund.');
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
    if (!chargebackAllowedStatusTransition((string)$row['status'], $newStatus)) {
        return ['ok' => false, 'message' => 'Status transition not allowed from ' . chargebackStatusLabel((string)$row['status']) . '.'];
    }
    getDB()->prepare('UPDATE chargebacks SET status=?, updated_at=NOW() WHERE id=?')->execute([$newStatus, $chargebackId]);
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
