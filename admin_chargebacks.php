<?php
require_once __DIR__ . '/config.php';
if (!function_exists('wiringChargebackAdminDisputeUrl') && is_file(__DIR__ . '/includes/wiring_deep_link_workflow.php')) {
    require_once __DIR__ . '/includes/wiring_deep_link_workflow.php';
}
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
            $result = resolveChargebackStatus($id, $status);
            flash($result['ok'] ? 'success' : 'error', $result['message'] ?? 'Could not update chargeback.');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }
    redirect('admin_chargebacks.php');
}

$rows = listOpenChargebacks(100);

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="chargebacks_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ref', 'Merchant', 'Amount', 'Provider', 'Status', 'Evidence due', 'Created']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['chargeback_ref'] ?? '',
            $row['business_name'] ?? '',
            $row['amount'] ?? '',
            $row['provider'] ?? '',
            $row['status'] ?? '',
            $row['evidence_due_at'] ?? '',
            $row['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Chargebacks';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl p-4 mb-6 border border-sky-500/25 text-sm text-gray-400">
    <p><strong class="text-sky-300">Legacy ingest only.</strong> Day-to-day payment disputes → <a href="admin_disputes.php" class="text-sky-400 hover:underline">Disputes</a> (Admin first). Use this page only when importing an old bank chargeback row.</p>
</div>
<div class="grid lg:grid-cols-3 gap-4 sm:gap-6 mb-6 sm:mb-8">
    <div class="lg:col-span-1 glass rounded-xl p-4 sm:p-6 min-w-0">
        <h2 class="font-semibold mb-4">Ingest dispute</h2>
        <form method="post" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="ingest">
            <div><label class="text-xs text-gray-500">Merchant ID</label><input type="number" name="merchant_id" required inputmode="numeric" class="input-field mt-1 w-full"></div>
            <div><label class="text-xs text-gray-500">Transaction ID</label><input type="number" name="transaction_id" inputmode="numeric" class="input-field mt-1 w-full"></div>
            <div><label class="text-xs text-gray-500">Amount</label><input type="number" step="0.01" name="amount" required inputmode="decimal" class="input-field mt-1 w-full"></div>
            <div><label class="text-xs text-gray-500">Provider dispute ID</label><input name="provider_dispute_id" class="input-field mt-1 w-full"></div>
            <div><label class="text-xs text-gray-500">Reason</label><input name="reason_text" class="input-field mt-1 w-full"></div>
            <button class="btn-primary w-full py-2.5">Open chargeback</button>
        </form>
        <p class="text-[11px] text-gray-500 mt-3">Resolve actions require step-up auth. CSRF protected.</p>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden min-w-0">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-2"><h2 class="font-semibold">Open / evidence queue</h2><a href="?export=csv" class="text-xs text-sky-400 hover:text-white">Export CSV</a></div>
        <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Ref</th><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Amount</th><th class="px-4 py-3 text-left">Due</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Resolve</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
            <?php if (!$rows): ?><tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No open chargebacks.</td></tr><?php endif; ?>
            <?php foreach ($rows as $row):
                $disputeUrl = function_exists('wiringChargebackAdminDisputeUrl') ? wiringChargebackAdminDisputeUrl($row) : null;
            ?>
            <tr>
                <td class="px-4 py-3 font-mono text-xs">
                    <?= txnDetailLink($row['chargeback_ref']) ?>
                    <?php if ($disputeUrl): ?>
                    <a href="<?= e($disputeUrl) ?>" class="block text-[10px] text-sky-400 hover:underline mt-1">Open dispute →</a>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3"><?= adminMerchantLink((int)$row['merchant_id'], $row['business_name']) ?></td>
                <td class="px-4 py-3"><?= formatMoney((float)$row['amount']) ?></td>
                <td class="px-4 py-3 text-xs"><?= e($row['evidence_due_at'] ?? '-') ?></td>
                <td class="px-4 py-3"><?= statusBadge($row['status']) ?></td>
                <td class="px-4 py-3">
                    <form method="post" class="flex gap-1"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><select name="status" class="text-xs bg-gray-900 border border-gray-700 rounded px-1"><option value="won">won</option><option value="lost">lost</option><option value="withdrawn">withdrawn</option></select><button class="text-xs text-brand-400">Save</button></form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
