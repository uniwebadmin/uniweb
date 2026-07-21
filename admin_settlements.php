<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $s = $db->prepare('SELECT merchant_id, amount, net_amount, settlement_id, status FROM settlements WHERE id=?');
    $s->execute([$id]);
    $settlement = $s->fetch();
    if (!$settlement) {
        flash('error', 'Settlement not found.');
        redirect('admin_settlements.php');
    }
    requireMerchantAccess((int)$settlement['merchant_id']);
    if ($action === 'complete') {
        requireStepUpAuth();
        $utr = strtoupper(trim((string)($_POST['utr'] ?? '')));
        if (!isValidBankTransferReference($utr)) {
            flash('error', 'Enter the bank UTR / reference from your NEFT/IMPS transfer (8–22 alphanumeric characters).');
            redirect('admin_settlements.php');
        }
        if (canonicalSettlementStatus($settlement['status'] ?? '')['key'] !== 'pending') {
            flash('error', 'Only pending settlements can be completed.');
            redirect('admin_settlements.php');
        }
        $dup = $db->prepare('SELECT id FROM settlements WHERE utr=? AND id<>? LIMIT 1');
        $dup->execute([$utr, $id]);
        if ($dup->fetch()) {
            flash('error', 'This bank reference is already recorded on another settlement.');
            redirect('admin_settlements.php');
        }
        $db->prepare("UPDATE settlements SET status='completed', utr=?, processed_at=NOW() WHERE id=?")->execute([$utr, $id]);
        createNotification((int)$settlement['merchant_id'], 'Settlement Completed', formatMoney(capStatAmount((float)$settlement['net_amount'])) . ' transferred to bank. UTR: ' . $utr);
        logStaffActivity('settlement_completed', $settlement['settlement_id'] . ' ' . formatMoney(capStatAmount((float)$settlement['net_amount'])) . ' UTR:' . $utr, (int)$settlement['merchant_id']);
        flash('success', 'Settlement marked complete.');
    } elseif ($action === 'fail') {
        requireStepUpAuth();
        if (canonicalSettlementStatus($settlement['status'] ?? '')['key'] === 'pending') {
            creditMerchantWallet((int)$settlement['merchant_id'], round((float)$settlement['amount'], 2), 'refund', null, $settlement['settlement_id'], 'Settlement failed — refunded to wallet');
            $db->prepare("UPDATE settlements SET status='failed' WHERE id=?")->execute([$id]);
            flash('success', 'Settlement failed — amount refunded to merchant wallet.');
        } else {
            flash('error', 'Only pending settlements can be failed.');
        }
    }
    redirect('admin_settlements.php');
}

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$statusFilter = trim($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'pending', 'processing', 'completed', 'failed'], true)) $statusFilter = 'all';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$where = '1=1'; $params = [];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $searchParts = ["LOWER(TRIM(COALESCE(s.settlement_id,''))) LIKE ?", "LOWER(TRIM(COALESCE(s.utr,''))) LIKE ?", "LOWER(TRIM(COALESCE(m.business_name,''))) LIKE ?", "LOWER(TRIM(COALESCE(m.merchant_code,''))) LIKE ?"];
    $params = array_fill(0, 4, $like);
    if (is_numeric($q)) { $searchParts[] = 's.net_amount = ?'; $params[] = (float)$q; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $q)) { $searchParts[] = 'DATE(s.created_at) = ?'; $params[] = $q; }
    $where .= ' AND (' . implode(' OR ', $searchParts) . ')';
}
if ($statusFilter !== 'all') { $where .= ' AND s.status = ?'; $params[] = $statusFilter; }
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where .= ' AND DATE(s.created_at) >= ?'; $params[] = $from; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where .= ' AND DATE(s.created_at) <= ?'; $params[] = $to; }
$settlementStmt = $db->prepare("SELECT s.*, m.business_name, m.merchant_code, m.id AS mid, b.bank_name, b.account_number FROM settlements s JOIN merchants m ON s.merchant_id=m.id LEFT JOIN bank_accounts b ON s.bank_account_id=b.id WHERE $where ORDER BY s.created_at DESC LIMIT 100");
$settlementStmt->execute($params);
$settlements = $settlementStmt->fetchAll();
if (!isSuperAdmin()) {
    $settlements = array_values(array_filter($settlements, static fn(array $row): bool => staffHasMerchantAccess((int)$row['merchant_id'])));
}
$pendingCount = 0;
foreach ($settlements as $row) {
    if (canonicalSettlementStatus($row['status'] ?? '')['key'] === 'pending') {
        $pendingCount++;
    }
}
$pageTitle = 'Settlements';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl p-4 mb-6 border border-sky-500/20 text-sm text-gray-400">
    <p class="text-sky-300 font-medium mb-1">Ops workflow</p>
    <p class="text-xs leading-relaxed">Merchant “Settle Now” only moves wallet → <strong class="text-gray-300">pending</strong> settlement. After you send NEFT/IMPS, enter the <strong class="text-brand-400">bank UTR</strong> to complete. Use Fail to refund the wallet if the transfer did not go through.</p>
    <?php if ($pendingCount > 0): ?>
    <p class="text-xs text-amber-400 mt-2"><?= $pendingCount ?> pending — process bank payout then enter UTR to complete.</p>
    <?php endif; ?>
    <a href="admin_settlement_settings.php" class="inline-block text-xs text-sky-400 mt-2">Settlement settings & cron →</a>
</div>
<form method="GET" data-live-search-form data-results-target="admin-settlement-results" class="glass rounded-xl p-4 mb-6 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[220px]"><label class="text-[10px] text-gray-600 uppercase">Search</label><input name="q" value="<?= e($q) ?>" class="input-field mt-1 text-sm" placeholder="Settlement ID / UTR / Date / Merchant / Amount" autocomplete="off"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">Status</label><select name="status" class="input-field mt-1 text-sm"><?php foreach (['all'=>'All','pending'=>'Pending','processing'=>'Processing','completed'=>'Complete','failed'=>'Failed'] as $sk=>$sl): ?><option value="<?= $sk ?>" <?= $statusFilter===$sk?'selected':'' ?>><?= $sl ?></option><?php endforeach; ?></select></div>
    <div><label class="text-[10px] text-gray-600 uppercase">From</label><input type="date" name="from" value="<?= e($from) ?>" class="input-field mt-1 text-sm"></div>
    <div><label class="text-[10px] text-gray-600 uppercase">To</label><input type="date" name="to" value="<?= e($to) ?>" class="input-field mt-1 text-sm"></div>
    <button class="btn-primary px-4 py-2.5 text-sm">Filter</button>
</form>
<div id="admin-settlement-results" class="glass rounded-xl overflow-hidden">
    <?php if (empty($settlements)): ?>
    <div class="px-6 py-14 text-center">
        <p class="font-semibold text-white mb-1">No settlements yet</p>
        <p class="text-sm text-gray-500 max-w-md mx-auto">When merchants transfer from wallet, pending rows appear here for finance to complete after bank transfer.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm min-w-[720px]">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">ID</th><th class="px-5 py-3 text-left">Merchant</th>
            <th class="px-5 py-3 text-left">Amount</th><th class="px-5 py-3 text-left">Net</th>
            <th class="px-5 py-3 text-left">Bank</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($settlements as $s): ?>
            <tr<?= uiRowClick(adminMerchantUrl((int)$s['merchant_id'])) ?>>
                <td class="px-5 py-3 font-mono text-xs">
                    <a href="settlement_detail.php?id=<?= urlencode($s['settlement_id']) ?>" class="text-sky-400 hover:text-sky-300 hover:underline"><?= e($s['settlement_id']) ?></a>
                </td>
                <td class="px-5 py-3"><?= adminMerchantLink((int)$s['merchant_id'], $s['business_name'], 'hover:text-sky-300') ?></td>
                <td class="px-5 py-3"><?= formatMoney(capStatAmount((float)$s['amount'])) ?></td>
                <td class="px-5 py-3 font-semibold text-brand-400"><?= formatMoney(capStatAmount((float)$s['net_amount'])) ?></td>
                <td class="px-5 py-3 text-xs"><?= e($s['bank_name'] ?? '—') ?> ****<?= substr($s['account_number']??'', -4) ?></td>
                <td class="px-5 py-3">
                    <div title="<?= e(settlementReasonText($s)) ?>"><?= settlementStatusBadge($s['status']) ?></div>
                    <?php if (!empty($s['utr'])): ?><p class="text-[10px] text-gray-500 font-mono mt-1"><?= e($s['utr']) ?></p><?php endif; ?>
                </td>
                <td class="px-5 py-3 whitespace-nowrap"<?= uiStopClick() ?>>
                    <a href="admin_view_merchant.php?id=<?= (int)$s['merchant_id'] ?>" class="text-xs text-emerald-400 mr-2">View</a>
                    <?php if (canonicalSettlementStatus($s['status'])['key'] === 'pending'): ?>
                    <form method="POST" class="inline-flex flex-wrap items-center gap-1 mt-1" onsubmit="return confirm('Confirm bank transfer with this UTR?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="complete">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <input type="text" name="utr" required minlength="8" maxlength="22" pattern="[A-Za-z0-9]{8,22}" class="input-field text-xs w-28 py-1 px-2" placeholder="Bank UTR" title="8–22 alphanumeric bank reference">
                        <button type="submit" class="text-xs text-brand-400">Complete</button>
                    </form>
                    <form method="POST" class="inline mt-1" onsubmit="return confirm('Fail and refund wallet?')">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="fail">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="text-xs text-red-400 ml-1">Fail</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
