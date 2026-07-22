<?php
declare(strict_types=1);

function ingestChargeback(array $payload): array
{
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
    createNotification($merchantId, 'Chargeback opened', "Dispute {$ref} needs evidence by " . date('d M Y', strtotime($due)) . '.');
    recordImmutableAudit('chargeback_opened', $merchantId, 'chargeback', $ref, (string)($payload['reason_text'] ?? 'opened'));
    return ['chargeback_ref' => $ref, 'evidence_due_at' => $due];
}

function submitChargebackEvidence(int $chargebackId, int $merchantId, string $notes): void
{
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
