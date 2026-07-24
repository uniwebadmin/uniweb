<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/nbfc.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'regional_manager']);
ensureNbfcSchema();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['app_id'] ?? 0);
    $note = (string)($_POST['admin_note'] ?? '');
    $actor = getAdmin()['name'] ?? ($_SESSION['admin_username'] ?? 'admin');
    $res = decideNbfcApplication($id, $action, (string)$actor, $note);
    if ($res['ok'] && function_exists('logStaffActivity')) {
        logStaffActivity('nbfc_' . $action, 'App #' . $id, null, 'nbfc_application', (string)$id);
    }
    flash($res['ok'] ? 'success' : 'error', $res['ok'] ? ($res['message'] ?? 'Done.') : ($res['error'] ?? 'Failed.'));
    redirect('admin_nbfc.php' . (($_GET['status'] ?? '') ? '?status=' . urlencode((string)$_GET['status']) : ''));
}

$statusFilter = $_GET['status'] ?? 'actionable';
$allowed = ['actionable', 'submitted', 'sent_to_partner', 'approved', 'rejected', 'all'];
if (!in_array($statusFilter, $allowed, true)) {
    $statusFilter = 'actionable';
}
$apps = getAdminNbfcApplications($statusFilter);
$live = nbfcLiveDisburseAllowed();

$pageTitle = 'NBFC Applications';
require_once __DIR__ . '/header.php';
?>
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-xl font-bold">NBFC Applications</h1>
        <p class="text-sm text-gray-500 mt-1">Merchant finance requests. Live disbursement needs partner keys + nbfc_live_enabled.</p>
    </div>
    <p class="text-xs <?= $live ? 'text-emerald-400' : 'text-amber-300' ?>"><?= $live ? '● Live rail ready' : '○ Keys / live switch pending' ?></p>
</div>
<div class="flex flex-wrap gap-2 text-xs mb-4">
    <?php foreach (['actionable' => 'Needs action', 'submitted' => 'Submitted', 'sent_to_partner' => 'At partner', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $sk => $sl): ?>
    <a href="?status=<?= e($sk) ?>" class="px-3 py-1.5 rounded-lg <?= $statusFilter === $sk ? 'bg-brand-600 text-white' : 'glass text-gray-400' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
</div>
<div class="glass rounded-xl overflow-hidden">
    <table class="w-full text-sm min-w-[800px]">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Merchant</th>
            <th class="px-5 py-3 text-left">Application</th>
            <th class="px-5 py-3 text-left">Status</th>
            <th class="px-5 py-3 text-left">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php if (empty($apps)): ?>
            <tr><td colspan="4" class="px-5 py-10 text-center text-gray-500">No applications.</td></tr>
            <?php else: foreach ($apps as $a): ?>
            <tr class="align-top">
                <td class="px-5 py-3">
                    <p class="font-medium"><?= adminMerchantLink((int)$a['merchant_id'], $a['business_name']) ?></p>
                    <p class="text-xs text-gray-500 font-mono"><?= e($a['merchant_code']) ?></p>
                </td>
                <td class="px-5 py-3">
                    <p class="font-mono text-sky-400 text-xs"><?= e($a['app_ref']) ?></p>
                    <p><?= formatMoney((float)$a['amount']) ?> · <?= (int)$a['tenure_months'] ?> mo</p>
                    <p class="text-xs text-gray-500 mt-1"><?= e((string)$a['purpose']) ?></p>
                </td>
                <td class="px-5 py-3 text-xs text-amber-300"><?= e(ucfirst(str_replace('_', ' ', (string)$a['status']))) ?></td>
                <td class="px-5 py-3">
                    <form method="POST" class="flex flex-col gap-2 max-w-xs">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
                        <input type="text" name="admin_note" class="input-field text-xs py-1.5" placeholder="Note">
                        <?php if (($a['status'] ?? '') === 'submitted'): ?>
                        <button name="action" value="send_partner" class="text-xs px-3 py-1.5 rounded-lg bg-sky-600 text-white">Send to Partner</button>
                        <button name="action" value="approve" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white">Approve</button>
                        <button name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/80 text-white">Reject</button>
                        <?php elseif (($a['status'] ?? '') === 'sent_to_partner'): ?>
                        <button name="action" value="approve" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600 text-white">Partner OK → Approve</button>
                        <button name="action" value="reject" class="text-xs px-3 py-1.5 rounded-lg bg-red-600/80 text-white">Reject</button>
                        <?php else: ?>
                        <span class="text-xs text-gray-600">—</span>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
