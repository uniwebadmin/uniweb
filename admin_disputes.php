<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'team_leader', 'support', 'ops']);
ensureDisputesEngine();
$db = getDB();

$partnerChoices = [];
if (function_exists('getPartnerRegistry')) {
    foreach (getPartnerRegistry() as $pk => $meta) {
        $partnerChoices[(string)$pk] = (string)($meta['name'] ?? $pk);
    }
}
if ($partnerChoices === []) {
    $partnerChoices = ['payu' => 'PayU', 'razorpay' => 'Razorpay', 'cashfree' => 'Cashfree', 'axis' => 'Axis'];
}

if (!function_exists('adminDisputesReturnUrl')) {
    function adminDisputesReturnUrl(array $from = []): string
    {
        $params = [];
        $merchantId = (int)($from['_merchant_id'] ?? $from['merchant_id'] ?? 0);
        if ($merchantId > 0) {
            $params['merchant_id'] = $merchantId;
        }
        $status = preg_replace('/[^a-z_]/', '', strtolower(trim((string)($from['_status'] ?? $from['status'] ?? ''))));
        if ($status !== '' && $status !== 'all') {
            $params['status'] = $status;
        }
        $q = mb_substr(trim((string)($from['_q'] ?? $from['q'] ?? '')), 0, 100);
        if ($q !== '') {
            $params['q'] = $q;
        }
        return $params === [] ? 'admin_disputes.php' : ('admin_disputes.php?' . http_build_query($params));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $resolution = trim((string)($_POST['resolution'] ?? ''));

    if ($action === 'review' && $id > 0) {
        $db->prepare("UPDATE disputes SET status='under_review' WHERE id=? AND status IN ('open','under_review')")->execute([$id]);
        $d = $db->prepare('SELECT merchant_id, dispute_id FROM disputes WHERE id=?');
        $d->execute([$id]);
        if ($row = $d->fetch()) {
            logStaffActivity('dispute_review', 'Dispute #' . $id, (int)$row['merchant_id']);
            createNotification((int)$row['merchant_id'], 'Dispute under review', 'Admin is reviewing ' . $row['dispute_id'] . '.', 'dispute_' . $id);
        }
        flash('success', 'Dispute marked under review (Admin first).');
    } elseif ($action === 'resolve' && $id > 0) {
        $note = $resolution !== '' ? $resolution : 'Resolved by Admin';
        $db->prepare("UPDATE disputes SET status='resolved', resolution=? WHERE id=?")->execute([$note, $id]);
        $d = $db->prepare('SELECT merchant_id, dispute_id FROM disputes WHERE id=?');
        $d->execute([$id]);
        if ($row = $d->fetch()) {
            logStaffActivity('dispute_resolved', $note, (int)$row['merchant_id']);
            createNotification((int)$row['merchant_id'], 'Dispute resolved', $row['dispute_id'] . ': ' . $note, 'dispute_' . $id);
        }
        flash('success', 'Dispute resolved. Merchant sees the same note.');
    } elseif ($action === 'close' && $id > 0) {
        $note = $resolution !== '' ? $resolution : 'Closed by Admin';
        $db->prepare("UPDATE disputes SET status='closed', resolution=? WHERE id=?")->execute([$note, $id]);
        $d = $db->prepare('SELECT merchant_id, dispute_id FROM disputes WHERE id=?');
        $d->execute([$id]);
        if ($row = $d->fetch()) {
            logStaffActivity('dispute_closed', $note, (int)$row['merchant_id']);
            createNotification((int)$row['merchant_id'], 'Dispute closed', $row['dispute_id'] . ': ' . $note, 'dispute_' . $id);
        }
        flash('success', 'Dispute closed.');
    } elseif ($action === 'forward_partner' && $id > 0) {
        $result = forwardDisputeToPartner($id, (string)($_POST['partner_key'] ?? ''), $resolution, (int)($_SESSION['admin_id'] ?? 0));
        flash($result['ok'] ? 'success' : 'error', $result['message']);
    } else {
        flash('error', 'Unknown action.');
    }
    redirect(adminDisputesReturnUrl($_POST));
}

// Legacy GET actions (keep working)
if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $id = (int)$_GET['id'];
    $resolution = trim($_GET['resolution'] ?? '');
    if ($_GET['action'] === 'review') {
        $db->prepare("UPDATE disputes SET status='under_review' WHERE id=?")->execute([$id]);
        flash('success', 'Dispute marked under review.');
    } elseif ($_GET['action'] === 'resolve') {
        $db->prepare("UPDATE disputes SET status='resolved', resolution=? WHERE id=?")->execute([$resolution ?: 'Resolved by admin', $id]);
        flash('success', 'Dispute resolved.');
    } elseif ($_GET['action'] === 'close') {
        $db->prepare("UPDATE disputes SET status='closed', resolution=? WHERE id=?")->execute([$resolution ?: 'Closed', $id]);
        flash('success', 'Dispute closed.');
    }
    redirect(adminDisputesReturnUrl($_GET));
}

$filterMerchantId = (int)($_GET['merchant_id'] ?? 0);
$disputeQ = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$statusFilter = preg_replace('/[^a-z_]/', '', strtolower(trim((string)($_GET['status'] ?? 'all'))));
if (!in_array($statusFilter, ['all', 'open', 'under_review', 'forwarded_partner', 'resolved', 'closed'], true)) {
    $statusFilter = 'all';
}
$openCountSql = "SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review','forwarded_partner')";
$openCountParams = [];
if ($filterMerchantId > 0) {
    $openCountSql .= ' AND merchant_id = ?';
    $openCountParams[] = $filterMerchantId;
}
$openSt = $db->prepare($openCountSql);
$openSt->execute($openCountParams);
$openCount = (int)$openSt->fetchColumn();

$listSql = 'SELECT d.*, m.business_name, m.id AS merchant_row_id, t.txn_id, t.amount FROM disputes d JOIN merchants m ON d.merchant_id=m.id JOIN transactions t ON t.id=d.transaction_id';
$listWhere = [];
$listParams = [];
if ($filterMerchantId > 0) {
    $listWhere[] = 'd.merchant_id = ?';
    $listParams[] = $filterMerchantId;
}
if ($statusFilter === 'open') {
    $listWhere[] = "d.status IN ('open','under_review','forwarded_partner')";
} elseif ($statusFilter !== 'all') {
    $listWhere[] = 'd.status = ?';
    $listParams[] = $statusFilter;
}
if ($disputeQ !== '') {
    $like = '%' . strtolower($disputeQ) . '%';
    $listWhere[] = '(LOWER(d.dispute_id) LIKE ? OR LOWER(t.txn_id) LIKE ? OR LOWER(d.reason) LIKE ? OR LOWER(m.business_name) LIKE ?)';
    array_push($listParams, $like, $like, $like, $like);
}
if ($listWhere !== []) {
    $listSql .= ' WHERE ' . implode(' AND ', $listWhere);
}
try {
    $order = ' ORDER BY FIELD(d.status,"open","under_review","forwarded_partner","resolved","closed"), d.created_at DESC LIMIT 80';
    $st = $db->prepare($listSql . $order);
    $st->execute($listParams);
    $disputes = $st->fetchAll();
} catch (Throwable $e) {
    $order = ' ORDER BY d.created_at DESC LIMIT 80';
    $st = $db->prepare($listSql . $order);
    $st->execute($listParams);
    $disputes = $st->fetchAll();
}
$disputeListQuery = [];
if ($filterMerchantId > 0) {
    $disputeListQuery['merchant_id'] = $filterMerchantId;
}
if ($disputeQ !== '') {
    $disputeListQuery['q'] = $disputeQ;
}
$adminDisputesHref = static function (array $extra = []) use ($disputeListQuery): string {
    $params = array_merge($disputeListQuery, $extra);
    if (($params['status'] ?? '') === 'all') {
        unset($params['status']);
    }
    return $params === [] ? 'admin_disputes.php' : ('admin_disputes.php?' . http_build_query($params));
};
$disputeFilterHidden = static function () use ($filterMerchantId, $statusFilter, $disputeQ): string {
    $html = '';
    if ($filterMerchantId > 0) {
        $html .= '<input type="hidden" name="_merchant_id" value="' . (int)$filterMerchantId . '">';
    }
    if ($statusFilter !== 'all') {
        $html .= '<input type="hidden" name="_status" value="' . e($statusFilter) . '">';
    }
    if ($disputeQ !== '') {
        $html .= '<input type="hidden" name="_q" value="' . e($disputeQ) . '">';
    }
    return $html;
};
$pageTitle = 'Disputes';
require_once __DIR__ . '/header.php';
?>

<div class="glass rounded-xl p-5 mb-6 border border-emerald-500/20 text-sm text-gray-300">
    <p class="font-semibold text-emerald-300 mb-1">Admin first — complaint → Admin → resolve / forward</p>
    <p class="text-xs text-gray-500">Version 1: every payment dispute lands here first. Resolve in-house, or <strong class="text-gray-300">single-forward</strong> one case to a partner. Bulk / smart route comes later — no new dispute app.</p>
    <div class="flex flex-wrap gap-2 mt-3 text-xs">
        <a href="admin_support.php" class="px-3 py-1.5 rounded-lg border border-gray-700 text-sky-300 hover:bg-white/5">Support tickets</a>
        <a href="admin_grievance.php" class="px-3 py-1.5 rounded-lg border border-gray-700 text-violet-300 hover:bg-white/5">Grievance</a>
        <a href="admin_customer_tickets.php" class="px-3 py-1.5 rounded-lg border border-gray-700 text-amber-300 hover:bg-white/5">Customer complaints</a>
    </div>
</div>

<?php if ($filterMerchantId > 0 || $statusFilter !== 'all' || $disputeQ !== ''): ?>
<div class="glass rounded-xl p-3 mb-4 border border-sky-500/30 text-xs text-sky-200 flex flex-wrap items-center justify-between gap-2">
    <span><?php
        $bits = [];
        if ($filterMerchantId > 0) {
            $bits[] = 'merchant #' . (int)$filterMerchantId . (!empty($disputes[0]['business_name']) ? (' — ' . (string)$disputes[0]['business_name']) : '');
        }
        if ($statusFilter !== 'all') {
            $bits[] = 'status: ' . $statusFilter;
        }
        if ($disputeQ !== '') {
            $bits[] = 'search: ' . $disputeQ;
        }
        echo 'Filtered to ' . e(implode(' · ', $bits));
    ?></span>
    <a href="admin_disputes.php" class="text-sky-400 hover:underline">Clear filter</a>
</div>
<?php endif; ?>

<form method="GET" class="glass rounded-xl p-4 mb-4 border border-gray-800 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[200px]">
        <label class="text-[10px] text-gray-600 uppercase">Search disputes</label>
        <input type="search" name="q" value="<?= e($disputeQ) ?>" placeholder="DSP… / txn / merchant / reason" class="input-field mt-1 text-sm" autocomplete="off">
    </div>
    <?php if ($filterMerchantId > 0): ?>
    <input type="hidden" name="merchant_id" value="<?= (int)$filterMerchantId ?>">
    <?php endif; ?>
    <?php if ($statusFilter !== 'all'): ?>
    <input type="hidden" name="status" value="<?= e($statusFilter) ?>">
    <?php endif; ?>
    <button type="submit" class="btn-primary px-4 py-2.5 text-sm">Search</button>
</form>

<div class="glass rounded-xl p-4 mb-6 border border-amber-500/20 text-xs text-amber-200/90">
    Bulk select + smart partner route: <strong class="text-amber-100">parked</strong>. Use one row → Forward to partner.
</div>

<div class="glass rounded-xl overflow-hidden min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800 flex flex-wrap justify-between items-center gap-2">
        <h2 class="font-semibold">Admin dispute queue (<?= $openCount ?> open)</h2>
        <div class="flex flex-wrap gap-2 text-[11px]">
            <a href="<?= e($adminDisputesHref(['status' => 'open'])) ?>" class="<?= $statusFilter === 'open' ? 'text-sky-300' : 'text-gray-500 hover:text-sky-300' ?>">Open only</a>
            <a href="<?= e($adminDisputesHref(['status' => 'all'])) ?>" class="<?= $statusFilter === 'all' ? 'text-sky-300' : 'text-gray-500 hover:text-sky-300' ?>">All</a>
        </div>
    </div>
    <div class="overflow-x-auto">
    <table class="min-w-[720px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-4 sm:px-5 py-3 text-left">ID</th><th class="px-4 sm:px-5 py-3 text-left">Merchant</th><th class="px-4 sm:px-5 py-3 text-left">Txn</th>
            <th class="px-4 sm:px-5 py-3 text-left">Amount</th><th class="px-4 sm:px-5 py-3 text-left">Reason</th><th class="px-4 sm:px-5 py-3 text-left">Due</th><th class="px-4 sm:px-5 py-3 text-left">Status</th><th class="px-4 sm:px-5 py-3 text-left">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php if (empty($disputes)): ?><tr><td colspan="8" class="px-5 py-12 text-center text-gray-500">No disputes yet. When a merchant raises one, it appears here for Admin first.</td></tr>
            <?php else: foreach ($disputes as $d):
                $openD = in_array((string)$d['status'], ['open', 'under_review', 'forwarded_partner'], true);
            ?>
            <tr>
                <td class="px-4 sm:px-5 py-3 font-mono text-xs text-gray-300"><?= e($d['dispute_id']) ?></td>
                <td class="px-4 sm:px-5 py-3 text-xs"><?= adminMerchantLink((int)$d['merchant_row_id'], $d['business_name']) ?></td>
                <td class="px-4 sm:px-5 py-3 font-mono text-xs"><?= txnDetailLink($d['txn_id']) ?></td>
                <td class="px-4 sm:px-5 py-3"><?= formatMoney(capStatAmount((float)$d['amount'])) ?></td>
                <td class="px-4 sm:px-5 py-3 text-xs text-gray-400 max-w-[10rem] sm:max-w-xs truncate" title="<?= e($d['reason']) ?>"><?= e($d['reason']) ?></td>
                <?php
                $dueTs = strtotime((string)($d['sla_due_at'] ?? ''));
                $overdue = $dueTs && $openD && $dueTs < time();
                ?>
                <td class="px-4 sm:px-5 py-3 text-xs <?= $overdue ? 'text-red-400 font-semibold' : 'text-gray-500' ?>"><?= !empty($d['sla_due_at']) ? e(formatDate($d['sla_due_at'])) : '—' ?><?= $overdue ? ' · overdue' : '' ?></td>
                <td class="px-4 sm:px-5 py-3">
                    <?= statusBadge($d['status']) ?>
                    <?php if (!empty($d['forwarded_partner_key'])): ?>
                    <p class="text-[10px] text-violet-400 mt-1">→ <?= e($d['forwarded_partner_key']) ?></p>
                    <?php endif; ?>
                </td>
                <td class="px-4 sm:px-5 py-3 align-top">
                    <?php if ($openD): ?>
                    <div class="flex flex-col gap-2 min-w-[200px]">
                        <?php if ($d['status'] === 'open'): ?>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><?= $disputeFilterHidden() ?><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="text-xs text-amber-400 hover:underline">Mark under review</button></form>
                        <?php endif; ?>
                        <form method="post" class="space-y-1">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <?= $disputeFilterHidden() ?>
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <input type="text" name="resolution" maxlength="500" placeholder="Resolve note (shown to merchant)" class="input-field text-xs w-full py-1">
                            <button class="text-xs bg-emerald-600 text-white px-2 py-1 rounded">Resolve</button>
                        </form>
                        <form method="post" class="space-y-1 border-t border-gray-800 pt-2">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <?= $disputeFilterHidden() ?>
                            <input type="hidden" name="action" value="forward_partner">
                            <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                            <select name="partner_key" class="input-field text-xs w-full py-1" required>
                                <option value="">Forward to partner…</option>
                                <?php foreach ($partnerChoices as $pk => $plabel): ?>
                                <option value="<?= e($pk) ?>"><?= e($plabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="resolution" maxlength="500" placeholder="Forward note" class="input-field text-xs w-full py-1">
                            <button class="text-xs bg-violet-600/80 text-white px-2 py-1 rounded">Forward (single)</button>
                        </form>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><?= $disputeFilterHidden() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><input type="hidden" name="resolution" value="Closed by Admin"><button class="text-xs text-gray-500 hover:underline">Close</button></form>
                    </div>
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
