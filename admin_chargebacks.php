<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops', 'staff_manager']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    try {
        requireStepUpAuth();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'ingest') {
            $result = ingestChargeback([
                'merchant_id' => (int)($_POST['merchant_id'] ?? 0),
                'transaction_id' => (int)($_POST['transaction_id'] ?? 0) ?: null,
                'amount' => (float)($_POST['amount'] ?? 0),
                'provider' => trim((string)($_POST['provider'] ?? 'razorpay')),
                'provider_dispute_id' => trim((string)($_POST['provider_dispute_id'] ?? '')) ?: null,
                'reason_code' => trim((string)($_POST['reason_code'] ?? '')),
                'reason_text' => trim((string)($_POST['reason_text'] ?? '')),
            ]);
            flash('success', 'Chargeback ' . $result['chargeback_ref'] . ' opened. Evidence due ' . $result['evidence_due_at']);
        } elseif ($action === 'resolve') {
            $id = (int)($_POST['id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['won', 'lost', 'withdrawn'], true)) {
                throw new InvalidArgumentException('Invalid resolution status.');
            }
            $db->prepare("UPDATE chargebacks SET status=?, updated_at=NOW() WHERE id=?")->execute([$status, $id]);
            recordImmutableAudit('chargeback_resolved', null, 'chargeback', (string)$id, $status);
            flash('success', 'Chargeback marked ' . $status . '.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_chargebacks.php');
}

$rows = listOpenChargebacks(100);
$pageTitle = 'Chargebacks';
require_once __DIR__ . '/header.php';
?>
<div class="grid lg:grid-cols-3 gap-6 mb-8">
    <div class="lg:col-span-1 glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Ingest dispute</h2>
        <form method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="ingest">
            <div><label class="text-xs text-gray-500">Merchant ID</label><input type="number" name="merchant_id" required class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Transaction ID</label><input type="number" name="transaction_id" class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Amount</label><input type="number" step="0.01" name="amount" required class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Provider dispute ID</label><input name="provider_dispute_id" class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Reason</label><input name="reason_text" class="input-field mt-1"></div>
            <button class="btn-primary w-full py-2.5">Open chargeback</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Open / evidence queue</h2></div>
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Ref</th><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Amount</th><th class="px-4 py-3 text-left">Due</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Resolve</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
            <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No open chargebacks.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td class="px-4 py-3 font-mono text-xs"><?= e($row['chargeback_ref']) ?></td>
                <td class="px-4 py-3"><?= e($row['business_name']) ?></td>
                <td class="px-4 py-3"><?= formatMoney((float)$row['amount']) ?></td>
                <td class="px-4 py-3 text-xs"><?= e($row['evidence_due_at'] ?? '-') ?></td>
                <td class="px-4 py-3"><?= statusBadge($row['status']) ?></td>
                <td class="px-4 py-3">
                    <form method="post" class="flex gap-1"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><select name="status" class="text-xs bg-gray-900 border border-gray-700 rounded px-1"><option value="won">won</option><option value="lost">lost</option><option value="withdrawn">withdrawn</option></select><button class="text-xs text-brand-400">Save</button></form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
