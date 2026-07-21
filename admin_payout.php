<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payout.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'regional_manager']);
ensurePayoutSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['request_id'] ?? 0);
    $note = (string)($_POST['admin_note'] ?? '');
    $actor = getAdmin()['name'] ?? ($_SESSION['admin_username'] ?? 'admin');
    if ($action === 'approve' || $action === 'reject') {
        $res = decidePayoutEnableRequest($id, $action === 'approve', (string)$actor, $note);
        if ($res['ok']) {
            logStaffActivity('payout_enable_' . $action, 'Request #' . $id . ($note !== '' ? ' — ' . $note : ''), null, 'payout_enable', (string)$id);
        }
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
    }
    redirect('admin_payout.php' . (($_GET['status'] ?? '') ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all'], true)) {
    $statusFilter = 'pending';
}
$requests = getPayoutEnableRequests($statusFilter === 'all' ? 'all' : $statusFilter);
$pendingCount = getPendingPayoutEnableCount();

$pageTitle = 'Payout Enable Requests';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">Payout Enable Requests</h1>
        <p class="text-sm text-gray-500 mt-1">Approve merchant access to the payout scaffold. Live money movement stays gated until licensed partner keys are added.</p>
    </div>
    <div class="flex gap-2 text-xs">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $sk => $sl): ?>
        <a href="?status=<?= $sk ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $sk ? 'bg-brand-600 text-white' : 'glass text-gray-400 hover:text-white' ?>">
            <?= $sl ?><?= $sk === 'pending' && $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 mb-6 text-sm text-amber-200">
    <p class="font-semibold">Partner rail</p>
    <p class="text-xs mt-1"><?= e(payoutActivationMessage()) ?></p>
    <p class="text-[11px] text-gray-500 mt-2">Failed payouts must show a reason. Auto-reversal without reconciliation is not allowed.</p>
</div>

<div class="glass rounded-xl overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Merchant</th>
                <th class="px-5 py-3 text-left">Requested</th>
                <th class="px-5 py-3 text-left">Status</th>
                <th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($requests)): ?>
                <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No <?= $statusFilter === 'all' ? '' : e($statusFilter) . ' ' ?>requests.</td></tr>
                <?php else: foreach ($requests as $r): ?>
                <tr class="hover:bg-white/5 align-top">
                    <td class="px-5 py-3">
                        <p class="font-medium"><?= adminMerchantLink((int)$r['merchant_id'], $r['business_name'], 'text-white hover:text-sky-300') ?></p>
                        <p class="text-xs text-gray-500 font-mono"><?= e($r['merchant_code']) ?></p>
                        <?php if (!empty($r['merchant_note'])): ?><p class="text-xs text-gray-500 mt-1 italic">"<?= e($r['merchant_note']) ?>"</p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3 text-xs text-gray-500"><?= formatDate($r['created_at']) ?></td>
                    <td class="px-5 py-3">
                        <?php if ($r['status'] === 'pending'): ?><span class="text-amber-400 text-xs">● Pending</span>
                        <?php elseif ($r['status'] === 'approved'): ?><span class="text-emerald-400 text-xs">✓ Approved</span>
                        <?php else: ?><span class="text-red-400 text-xs">✗ Rejected</span><?php endif; ?>
                        <?php if (!empty($r['decided_by'])): ?><p class="text-[11px] text-gray-600 mt-1">by <?= e($r['decided_by']) ?></p><?php endif; ?>
                        <?php if (!empty($r['admin_note'])): ?><p class="text-[11px] text-gray-500 mt-0.5"><?= e($r['admin_note']) ?></p><?php endif; ?>
                    </td>
                    <td class="px-5 py-3">
                        <?php if ($r['status'] === 'pending'): ?>
                        <form method="POST" class="flex flex-wrap items-center gap-2" onsubmit="return confirm('Confirm this decision?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                            <input name="admin_note" maxlength="500" placeholder="Note (optional)" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5">
                            <button name="action" value="approve" class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded-lg">Approve</button>
                            <button name="action" value="reject" class="text-xs bg-red-600/20 text-red-400 px-3 py-1.5 rounded-lg">Reject</button>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-gray-600">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
