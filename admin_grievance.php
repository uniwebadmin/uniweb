<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'finance', 'ops', 'support']);
if (!function_exists('getGrievanceStats') && is_file(__DIR__ . '/includes/grievance_engine.php')) {
    require_once __DIR__ . '/includes/grievance_engine.php';
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
$activeTab = $_GET['tab'] ?? 'list';
$selectedComplaintId = (int)($_GET['complaint_id'] ?? 0);

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_complaint') {
        $merchantId = (int)($_POST['merchant_id'] ?? 0);
        $txnRef = trim((string)($_POST['txn_ref'] ?? ''));
        $result = createGrievanceComplaint([
            'merchant_id' => $merchantId > 0 ? $merchantId : null,
            'customer_name' => trim($_POST['customer_name'] ?? ''),
            'customer_email' => trim($_POST['customer_email'] ?? ''),
            'customer_phone' => trim($_POST['customer_phone'] ?? ''),
            'transaction_id' => resolveInternalTransactionId($txnRef, $merchantId),
            'category' => trim($_POST['category'] ?? 'other'),
            'subject' => trim($_POST['subject'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'priority' => trim($_POST['priority'] ?? 'medium'),
        ]);
        if ($result) {
            flash('success', "Complaint created: {$result['complaint_id']}");
        } else {
            flash('error', 'Failed to create complaint.');
        }
        redirect('admin_grievance.php?complaint_id=' . ($result['id'] ?? 0));
    }

    if ($action === 'acknowledge' && isset($_POST['complaint_id'])) {
        acknowledgeComplaint((int)$_POST['complaint_id'], $adminId);
        flash('success', 'Complaint acknowledged.');
        redirect('admin_grievance.php?complaint_id=' . (int)$_POST['complaint_id']);
    }

    if ($action === 'escalate' && isset($_POST['complaint_id'])) {
        escalateComplaint((int)$_POST['complaint_id'], $adminId, trim($_POST['note'] ?? ''));
        flash('success', 'Complaint escalated.');
        redirect('admin_grievance.php?complaint_id=' . (int)$_POST['complaint_id']);
    }

    if ($action === 'resolve' && isset($_POST['complaint_id'])) {
        resolveComplaint((int)$_POST['complaint_id'], $adminId, trim($_POST['note'] ?? ''), trim($_POST['resolution_category'] ?? 'resolved'));
        flash('success', 'Complaint resolved.');
        redirect('admin_grievance.php?complaint_id=' . (int)$_POST['complaint_id']);
    }

    if ($action === 'reject' && isset($_POST['complaint_id'])) {
        rejectComplaint((int)$_POST['complaint_id'], $adminId, trim($_POST['note'] ?? ''));
        flash('success', 'Complaint rejected.');
        redirect('admin_grievance.php?complaint_id=' . (int)$_POST['complaint_id']);
    }

    if ($action === 'add_note' && isset($_POST['complaint_id'])) {
        addComplaintNote((int)$_POST['complaint_id'], $adminId, 'staff', trim($_POST['message'] ?? ''));
        flash('success', 'Note added.');
        redirect('admin_grievance.php?complaint_id=' . (int)$_POST['complaint_id']);
    }

    if ($action === 'auto_escalate') {
        $count = autoEscalateSlaBreached();
        flash('success', "Auto-escalated {$count} SLA-breached complaints.");
        redirect('admin_grievance.php');
    }
}

$stats = getGrievanceStats();
$statusFilter = trim($_GET['status'] ?? '');
$complaints = getGrievanceComplaints(100, $statusFilter);
$selectedComplaint = $selectedComplaintId > 0 ? getGrievanceComplaint($selectedComplaintId) : null;
$complaintActions = $selectedComplaint ? getComplaintActions($selectedComplaintId) : [];
$monthlyReport = $activeTab === 'report' ? generateGrievanceMonthlyReport(trim($_GET['month'] ?? '')) : null;

$pageTitle = 'Grievance Redressal';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="glass rounded-xl p-4 border border-emerald-500/20 text-sm text-gray-300">
        <p class="font-semibold text-emerald-300 mb-1">Admin first — grievance officer queue</p>
        <p class="text-xs text-gray-500">Complaints are acknowledged and resolved here first. Payment disputes with partner forward: <a href="admin_disputes.php" class="text-sky-400 hover:underline">Disputes queue</a> (single forward V1). Bulk / smart route parked.</p>
    </div>
    <div class="flex flex-wrap gap-3 items-center justify-between">
        <p class="text-sm text-gray-400">Complaint management, escalation, SLA tracking</p>
        <div class="flex gap-2 text-xs">
            <a href="?tab=list" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'list' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Complaints</a>
            <a href="?tab=create" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'create' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">New Complaint</a>
            <a href="?tab=report" class="px-3 py-1.5 rounded-lg <?= $activeTab === 'report' ? 'bg-brand-600/20 text-brand-400' : 'text-gray-400 hover:text-white border border-gray-800' ?>">Monthly Report</a>
        </div>
    </div>

    <div class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Total</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($stats['total']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Open</p><p class="text-2xl font-bold text-amber-400 mt-1"><?= number_format($stats['open']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Escalated</p><p class="text-2xl font-bold text-red-400 mt-1"><?= number_format($stats['escalated']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card"><p class="text-xs text-gray-500">Resolved</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($stats['resolved']) ?></p></div>
        <div class="glass rounded-xl p-5 stat-card border <?= $stats['sla_breached'] > 0 ? 'border-red-500/30' : '' ?>"><p class="text-xs text-gray-500">SLA Breached</p><p class="text-2xl font-bold <?= $stats['sla_breached'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($stats['sla_breached']) ?></p></div>
    </div>

    <?php if ($stats['sla_breached'] > 0): ?>
    <form method="POST" class="inline-block">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="auto_escalate">
        <button type="submit" class="btn-primary px-6 py-2.5">Auto-Escalate SLA-Breached (<?= $stats['sla_breached'] ?>)</button>
    </form>
    <?php endif; ?>

    <?php if ($activeTab === 'create'): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Create New Complaint</h3>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_complaint">
            <div class="grid sm:grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Customer Name</label><input type="text" name="customer_name" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Customer Email</label><input type="email" name="customer_email" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Customer Phone</label><input type="text" name="customer_phone" class="input-field mt-1 w-full"></div>
                <div><label class="text-sm text-gray-400">Merchant (optional)</label><?= renderAdminMerchantSelect('merchant_id', 0, false, true, 'Select merchant…') ?></div>
                <div><label class="text-sm text-gray-400">Transaction (optional)</label><?= renderTxnRefSearchField('txn_ref', '', 'TXN ID…', 'mt-1') ?></div>
                <div><label class="text-sm text-gray-400">Category</label>
                    <select name="category" class="input-field mt-1 w-full">
                        <option value="payment_failure">Payment Failure</option>
                        <option value="refund_delay">Refund Delay</option>
                        <option value="unauthorized_txn">Unauthorized Transaction</option>
                        <option value="settlement_delay">Settlement Delay</option>
                        <option value="kyc_issue">KYC Issue</option>
                        <option value="tech_issue">Technical Issue</option>
                        <option value="other">Other</option>
                    </select></div>
                <div><label class="text-sm text-gray-400">Priority</label>
                    <select name="priority" class="input-field mt-1 w-full">
                        <option value="low">Low</option>
                        <option value="medium" selected>Medium</option>
                        <option value="high">High</option>
                        <option value="urgent">Urgent</option>
                    </select></div>
            </div>
            <div><label class="text-sm text-gray-400">Subject</label><input type="text" name="subject" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Description</label><textarea name="description" rows="4" class="input-field mt-1 w-full" required></textarea></div>
            <button type="submit" class="btn-primary px-6 py-2.5">Create Complaint</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'list' && !$selectedComplaint): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
            <h2 class="font-semibold">Complaints</h2>
            <div class="flex gap-2 text-xs">
                <a href="?" class="px-2 py-1 <?= $statusFilter === '' ? 'text-brand-400' : 'text-gray-400' ?>">All</a>
                <a href="?status=open" class="px-2 py-1 <?= $statusFilter === 'open' ? 'text-brand-400' : 'text-gray-400' ?>">Open</a>
                <a href="?status=escalated_l1" class="px-2 py-1 <?= $statusFilter === 'escalated_l1' ? 'text-brand-400' : 'text-gray-400' ?>">Escalated L1</a>
                <a href="?status=escalated_l2" class="px-2 py-1 <?= $statusFilter === 'escalated_l2' ? 'text-brand-400' : 'text-gray-400' ?>">Escalated L2</a>
                <a href="?status=resolved" class="px-2 py-1 <?= $statusFilter === 'resolved' ? 'text-brand-400' : 'text-gray-400' ?>">Resolved</a>
            </div>
        </div>
        <div class="overflow-x-auto"><table class="min-w-[800px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Complaint ID</th><th class="px-4 py-3 text-left">Subject</th><th class="px-4 py-3 text-left">Category</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Priority</th><th class="px-4 py-3 text-left">SLA</th><th class="px-4 py-3 text-left">Created</th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($complaints)): ?><tr><td colspan="7" class="px-4 py-8 text-center text-gray-500">No complaints found.</td></tr>
                <?php else: foreach ($complaints as $c): ?>
                <tr class="hover:bg-dark-900/30 cursor-pointer" onclick="window.location='?complaint_id=<?= (int)$c['id'] ?>'">
                    <td class="px-4 py-3 text-xs font-mono text-sky-400"><?= e($c['complaint_id']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($c['subject']) ?></td>
                    <td class="px-4 py-3 text-xs capitalize"><?= e(str_replace('_', ' ', $c['category'])) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($c['status']) ?></td>
                    <td class="px-4 py-3 text-xs capitalize"><?= e($c['priority']) ?></td>
                    <td class="px-4 py-3 text-xs<?php $slaTs = strtotime((string)$c['sla_deadline']); if ($slaTs && $slaTs < time() && !in_array($c['status'], ['resolved','rejected','closed'])) echo ' text-red-400 font-bold'; ?>"><?= e($c['sla_deadline'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs"><?= formatDate($c['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php if ($selectedComplaint): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h2 class="font-semibold text-lg"><?= e($selectedComplaint['subject']) ?></h2>
                <p class="text-xs text-gray-500 mt-1">ID: <?= e($selectedComplaint['complaint_id']) ?> · Created: <?= formatDate($selectedComplaint['created_at']) ?></p>
                <p class="text-xs text-gray-500">Category: <?= e(str_replace('_', ' ', $selectedComplaint['category'])) ?> · Priority: <span class="capitalize"><?= e($selectedComplaint['priority']) ?></span> · Escalation Level: <?= (int)$selectedComplaint['escalation_level'] ?></p>
                <?php if ($selectedComplaint['customer_name']): ?><p class="text-xs text-gray-500 mt-1">Customer: <?= e($selectedComplaint['customer_name']) ?> · <?= e($selectedComplaint['customer_email'] ?? '') ?> · <?= e($selectedComplaint['customer_phone'] ?? '') ?></p><?php endif; ?>
                <?php if ($selectedComplaint['business_name']): ?><p class="text-xs text-gray-500">Merchant: <?= e($selectedComplaint['business_name']) ?> (<?= e($selectedComplaint['merchant_code']) ?>)</p><?php endif; ?>
            </div>
            <div class="text-right">
                <?= statusBadge($selectedComplaint['status']) ?>
                <p class="text-xs mt-1<?php $slaTs = strtotime((string)$selectedComplaint['sla_deadline']); if ($slaTs && $slaTs < time() && !in_array($selectedComplaint['status'], ['resolved','rejected','closed'])) echo ' text-red-400 font-bold'; else echo ' text-gray-500'; ?>">SLA: <?= e($selectedComplaint['sla_deadline'] ?? '—') ?></p>
            </div>
        </div>
        <p class="text-sm text-gray-300 bg-dark-900/40 rounded-lg p-3 mb-4"><?= e($selectedComplaint['description']) ?></p>

        <?php if ($selectedComplaint['resolution_note']): ?>
        <div class="bg-emerald-500/5 border border-emerald-500/20 rounded-lg p-3 mb-4">
            <p class="text-xs text-emerald-400 font-semibold">Resolution</p>
            <p class="text-sm text-gray-300 mt-1"><?= e($selectedComplaint['resolution_note']) ?></p>
        </div>
        <?php endif; ?>

        <?php if (!in_array($selectedComplaint['status'], ['resolved','rejected','closed'])): ?>
        <div class="flex flex-wrap gap-2 mb-6">
            <?php if ($selectedComplaint['status'] === 'open'): ?>
            <form method="POST" class="inline-block"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>"><button type="submit" name="action" value="acknowledge" class="btn-primary text-xs px-4 py-2">Acknowledge</button></form>
            <?php endif; ?>
            <form method="POST" class="inline-block"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>"><input type="text" name="note" placeholder="Escalation note" class="input-field text-xs w-40"><button type="submit" name="action" value="escalate" class="text-xs bg-amber-600/20 text-amber-300 px-4 py-2 rounded-lg">Escalate</button></form>
            <form method="POST" class="inline-block"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>"><input type="text" name="note" placeholder="Resolution note" class="input-field text-xs w-40" required><select name="resolution_category" class="input-field text-xs"><option value="resolved">Resolved</option><option value="partially_resolved">Partially</option><option value="not_resolved">Not Resolved</option></select><button type="submit" name="action" value="resolve" class="text-xs bg-emerald-600/20 text-emerald-300 px-4 py-2 rounded-lg">Resolve</button></form>
            <form method="POST" class="inline-block"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>"><input type="text" name="note" placeholder="Rejection reason" class="input-field text-xs w-40" required><button type="submit" name="action" value="reject" class="text-xs bg-red-600/20 text-red-300 px-4 py-2 rounded-lg">Reject</button></form>
        </div>
        <?php endif; ?>

        <form method="POST" class="mb-6">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="complaint_id" value="<?= (int)$selectedComplaint['id'] ?>">
            <label class="text-sm text-gray-400">Add Note / Reply</label>
            <div class="flex gap-2 mt-1">
                <input type="text" name="message" class="input-field flex-1" placeholder="Type your message...">
                <button type="submit" name="action" value="add_note" class="btn-primary px-4 py-2 text-xs">Add</button>
            </div>
        </form>

        <div>
            <h3 class="font-semibold text-sm mb-3">Action History</h3>
            <div class="space-y-2">
                <?php foreach ($complaintActions as $a): ?>
                <div class="text-xs border-l-2 border-gray-700 pl-3 py-1">
                    <span class="text-gray-500"><?= formatDate($a['created_at']) ?></span> ·
                    <span class="capitalize text-brand-400"><?= e(str_replace('_', ' ', $a['action_type'])) ?></span>
                    <?php if ($a['message']): ?><span class="text-gray-400"> — <?= e($a['message']) ?></span><?php endif; ?>
                    <?php if ($a['old_status'] && $a['new_status']): ?><span class="text-gray-600"> (<?= e($a['old_status']) ?> → <?= e($a['new_status']) ?>)</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($activeTab === 'report' && $monthlyReport): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="font-semibold">Monthly Report — <?= e($monthlyReport['month']) ?></h3>
            <form method="GET" class="flex gap-2">
                <input type="hidden" name="tab" value="report">
                <input type="month" name="month" value="<?= e($monthlyReport['month']) ?>" class="input-field text-xs">
                <button type="submit" class="btn-primary text-xs px-3 py-1.5">Load</button>
            </form>
        </div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <div class="bg-dark-900/40 rounded-lg p-4"><p class="text-xs text-gray-500">Total Complaints</p><p class="text-2xl font-bold text-brand-400 mt-1"><?= number_format($monthlyReport['total']) ?></p></div>
            <div class="bg-dark-900/40 rounded-lg p-4"><p class="text-xs text-gray-500">Resolved</p><p class="text-2xl font-bold text-emerald-400 mt-1"><?= number_format($monthlyReport['resolved']) ?></p></div>
            <div class="bg-dark-900/40 rounded-lg p-4"><p class="text-xs text-gray-500">Avg Resolution Time</p><p class="text-2xl font-bold text-sky-400 mt-1"><?= $monthlyReport['avg_resolution_hours'] ?>h</p></div>
            <div class="bg-dark-900/40 rounded-lg p-4"><p class="text-xs text-gray-500">SLA Breached</p><p class="text-2xl font-bold <?= $monthlyReport['sla_breached'] > 0 ? 'text-red-400' : 'text-emerald-400' ?> mt-1"><?= number_format($monthlyReport['sla_breached']) ?></p></div>
        </div>
        <?php if (!empty($monthlyReport['by_category'])): ?>
        <h4 class="text-sm font-semibold mb-2">By Category</h4>
        <div class="space-y-1">
            <?php foreach ($monthlyReport['by_category'] as $cat => $count): ?>
            <div class="flex justify-between text-sm"><span class="capitalize"><?= e(str_replace('_', ' ', $cat)) ?></span><span class="text-gray-400"><?= $count ?></span></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
