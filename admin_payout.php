<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payout.php';
if (is_file(__DIR__ . '/includes/payout_workflow.php')) {
    require_once __DIR__ . '/includes/payout_workflow.php';
}
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
    } elseif (in_array($action, ['reversal_approved', 'reversal_rejected', 'reversal_reconciled'], true)) {
        $decision = str_replace('reversal_', '', $action);
        $res = decidePayoutReversal((int)($_POST['reversal_id'] ?? 0), $decision, (string)$actor, $note);
        if ($res['ok']) {
            logStaffActivity('payout_reversal_' . $decision, 'Reversal #' . (int)($_POST['reversal_id'] ?? 0), null, 'payout_reversal', (string)($_POST['reversal_id'] ?? ''));
        }
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
    }
    redirect('admin_payout.php' . (($_GET['status'] ?? '') ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$statusFilter = $_GET['status'] ?? 'pending';
if (!in_array($statusFilter, ['pending', 'approved', 'rejected', 'all', 'reversals'], true)) {
    $statusFilter = 'pending';
}
$requests = $statusFilter === 'reversals' ? [] : getPayoutEnableRequests($statusFilter === 'all' ? 'all' : $statusFilter);
$pendingCount = getPendingPayoutEnableCount();
$reversals = getPayoutReversalRequests('pending', 50);

$pageTitle = 'Payout Enable Requests';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6 flex flex-wrap items-center justify-between gap-3 pb-24 lg:pb-0">
    <div>
        <h1 class="text-xl font-bold">Payout Enable Requests</h1>
        <p class="text-sm text-gray-500 mt-1">Approve merchant access to the payout scaffold. Live money stays gated until licensed partner keys. Easy Split / Route is not a live UniWeb marketplace product.</p>
    </div>
    <div class="flex gap-2 text-xs flex-wrap">
        <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All', 'reversals' => 'Reversal queue'] as $sk => $sl): ?>
        <a href="?status=<?= $sk ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $sk ? 'bg-brand-600 text-white' : 'glass text-gray-400 hover:text-white' ?>">
            <?= $sl ?><?= $sk === 'pending' && $pendingCount > 0 ? ' (' . $pendingCount . ')' : '' ?><?= $sk === 'reversals' && count($reversals) > 0 ? ' (' . count($reversals) . ')' : '' ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 mb-6 text-sm text-amber-200">
    <p class="font-semibold">Payout rail — <?= payoutLiveMoneyAllowed() ? 'Live dispatch ON' : 'Gated (stub until keys + switch)' ?></p>
    <p class="text-xs mt-1"><?= e(payoutActivationMessage()) ?></p>
    <?php if (function_exists('payoutRailReadinessReport')): $pRail = payoutRailReadinessReport(); ?>
    <p class="text-[11px] mt-2 <?= !empty($pRail['ok']) ? 'text-emerald-400' : 'text-amber-300/90' ?>">
        <?= !empty($pRail['ok']) ? 'Ready for live partner dispatch.' : ('Missing: ' . e(implode(', ', payoutRailReadinessMissingLabels($pRail)))) ?>
    </p>
    <?php endif; ?>
    <p class="text-[11px] text-gray-500 mt-2">Mock UTR uses prefix <code class="text-gray-400">UNIWEB_TEST_</code> — never real bank money. Failed payouts must show a reason. Auto-reversal without reconciliation is not allowed — reversal queue never auto-credits wallets.</p>
</div>

<?php if ($statusFilter === 'reversals'): ?>
<div class="glass rounded-xl overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Reversal / reconciliation queue</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[720px]">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-5 py-3 text-left">Merchant</th>
                <th class="px-5 py-3 text-left">Payout</th>
                <th class="px-5 py-3 text-left">Amount</th>
                <th class="px-5 py-3 text-left">Failure reason</th>
                <th class="px-5 py-3 text-left">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($reversals)): ?>
                <tr><td colspan="5" class="px-5 py-10 text-center text-gray-500">No pending reversal requests.</td></tr>
                <?php else: foreach ($reversals as $rv): ?>
                <tr class="align-top">
                    <td class="px-5 py-3"><p class="font-medium"><?= adminMerchantLink((int)$rv['merchant_id'], $rv['business_name'], 'text-white hover:text-sky-300') ?></p><p class="text-xs font-mono text-gray-500"><?= e($rv['merchant_code']) ?></p></td>
                    <td class="px-5 py-3 font-mono text-xs text-sky-400"><?= e($rv['payout_id']) ?></td>
                    <td class="px-5 py-3"><?= formatMoney((float)$rv['amount']) ?></td>
                    <td class="px-5 py-3 text-xs text-red-300 max-w-xs"><?= e($rv['failure_reason'] ?: ($rv['merchant_note'] ?: '—')) ?></td>
                    <td class="px-5 py-3">
                        <form method="POST" class="flex flex-wrap gap-2 items-center">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="reversal_id" value="<?= (int)$rv['id'] ?>">
                            <input name="admin_note" maxlength="500" placeholder="Note" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5" aria-label="Reversal note">
                            <button name="action" value="reversal_reconciled" class="text-xs bg-emerald-600 text-white px-3 py-1.5 rounded-lg">Mark reconciled (no auto-credit)</button>
                            <button name="action" value="reversal_rejected" class="text-xs bg-red-600/20 text-red-400 px-3 py-1.5 rounded-lg">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
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
                            <input name="admin_note" maxlength="500" placeholder="Note (optional)" class="text-xs bg-gray-900 border border-gray-700 rounded-lg px-2 py-1.5" aria-label="Decision note">
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
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
