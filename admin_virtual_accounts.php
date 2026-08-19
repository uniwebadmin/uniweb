<?php
require_once __DIR__ . '/config.php';
if (!function_exists('createAdditionalVirtualAccount')) {
    require_once __DIR__ . '/includes/va_manager.php';
}
if (!function_exists('vaRailReadinessReport')) {
    require_once __DIR__ . '/includes/va_workflow.php';
}
requireStaffAccess(['super', 'ceo', 'finance', 'ops']);
ensureMerchantVirtualAccountsTable();
$db = getDB();

$q = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$merchantId = (int)($_GET['merchant_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? '');
    $mid = (int)($_POST['merchant_id'] ?? 0);
    if ($action === 'create_va' && $mid > 0) {
        $label = trim((string)($_POST['label'] ?? ''));
        $gateway = strtolower(trim((string)($_POST['gateway'] ?? 'axis')));
        $gate = vaCreationGateCheck($gateway);
        if (!$gate['ok']) {
            flash('error', (string)($gate['error'] ?? vaUnsupportedCreationReason($gateway)));
        } else {
            $result = createAdditionalVirtualAccount($mid, $gateway, $label);
            flash($result['ok'] ? 'success' : 'error', $result['ok']
                ? 'Virtual account created: ' . ($result['va']['va_number'] ?? '')
                : ($result['error'] ?? 'Could not create virtual account.'));
        }
    } elseif ($action === 'toggle_va') {
        $vaId = (int)($_POST['va_id'] ?? 0);
        $newStatus = ($_POST['new_status'] ?? '') === 'active' ? 'active' : 'disabled';
        if (!setMerchantVirtualAccountStatus($vaId, $mid, $newStatus)) {
            flash('error', 'Could not update virtual account status.');
        } else {
            flash('success', 'Virtual account ' . ($newStatus === 'active' ? 'enabled' : 'disabled') . '.');
        }
    }
    redirect('admin_virtual_accounts.php' . ($mid ? '?merchant_id=' . $mid : ''));
}

$merchants = [];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $st = $db->prepare("SELECT id, merchant_code, business_name FROM merchants
        WHERE status != 'deleted' AND (LOWER(business_name) LIKE ? OR LOWER(merchant_code) LIKE ?)
        ORDER BY created_at DESC LIMIT 20");
    $st->execute([$like, $like]);
    $merchants = $st->fetchAll();
}

$selectedMerchant = null;
$vas = [];
if ($merchantId > 0) {
    $st = $db->prepare('SELECT id, merchant_code, business_name, axis_va_number FROM merchants WHERE id = ?');
    $st->execute([$merchantId]);
    $selectedMerchant = $st->fetch() ?: null;
    if ($selectedMerchant) {
        $vas = getMerchantVirtualAccounts($merchantId);
    }
}

// Platform-wide snapshot: merchants with 2+ active VAs (load already spread) vs single-VA.
$multiVaCount = 0;
$totalVaMerchants = 0;
try {
    $multiVaCount = (int)$db->query("SELECT COUNT(DISTINCT merchant_id) FROM (
        SELECT merchant_id FROM merchant_virtual_accounts WHERE status='active' GROUP BY merchant_id HAVING COUNT(*) >= 2
    ) t")->fetchColumn();
    $totalVaMerchants = (int)$db->query("SELECT COUNT(DISTINCT merchant_id) FROM merchant_virtual_accounts WHERE status='active'")->fetchColumn();
} catch (Throwable $e) {
    // table may be missing until migration 031 is applied
}

$supportedVaGateways = vaSupportedCreationGateways();
$vaRailReport = vaRailReadinessReport();

$pageTitle = 'Virtual Accounts';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl p-4 mb-6 border border-teal-500/25 text-sm">
    <p class="text-teal-300 font-semibold mb-2">VA bank rails only — Partner Registry ≠ VA adapter</p>
    <p class="text-gray-400 mb-3"><?= e($vaRailReport['message']) ?></p>
    <div class="grid sm:grid-cols-2 gap-4">
        <div class="rounded-lg border border-emerald-500/30 bg-emerald-950/20 p-3">
            <p class="text-xs uppercase tracking-wide text-emerald-400 mb-2">Supported for VA create</p>
            <ul class="space-y-1.5 text-xs">
                <?php foreach ($vaRailReport['supported'] as $row): ?>
                <li class="flex flex-wrap items-center gap-2">
                    <span class="font-semibold text-white"><?= e($row['label']) ?></span>
                    <?php if (!empty($row['ready'])): ?>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-emerald-900/60 text-emerald-300">Ready</span>
                    <?php elseif (!empty($row['listed'])): ?>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-900/40 text-amber-300">Keys pending</span>
                    <?php else: ?>
                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-800 text-slate-400">When keys ready</span>
                    <?php endif; ?>
                    <span class="text-gray-500 w-full"><?= e($row['message']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="rounded-lg border border-slate-600/40 bg-slate-900/30 p-3">
            <p class="text-xs uppercase tracking-wide text-slate-400 mb-2">Not supported for VA create</p>
            <div class="flex flex-wrap gap-1.5 mb-2">
                <?php foreach ($vaRailReport['unsupported'] as $row): ?>
                <span class="text-[10px] px-2 py-1 rounded bg-slate-800 text-slate-300 border border-slate-700" title="<?= e($row['use_instead']) ?>"><?= e($row['gateway']) ?></span>
                <?php endforeach; ?>
            </div>
            <p class="text-[11px] text-gray-500">Checkout PG / KYC partners — use payment links or the correct partner screen. Requesting VA here returns an honest fail (no fake account number).</p>
        </div>
    </div>
</div>
<div class="glass rounded-xl p-4 mb-6 border border-amber-500/25 text-sm text-gray-400">
    <p><strong class="text-amber-300">Live volume.</strong> Axis VA collections need live Axis keys in Partner Registry + commercial approval. Test/UAT rows may exist — do not treat as full live volume until keys are green on Platform Status.</p>
</div>
<div class="mb-4 flex flex-wrap gap-4 text-sm">
    <a href="admin_axis.php" class="text-gray-400 hover:text-white">← Axis partner keys</a>
    <?php if ($merchantId > 0): ?>
    <a href="admin_edit_merchant.php?id=<?= $merchantId ?>" class="text-gray-400 hover:text-white">← Back to merchant</a>
    <?php endif; ?>
</div>

<div class="grid sm:grid-cols-2 gap-4 mb-8 max-w-lg">
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Merchants with a VA</p>
        <p class="text-2xl font-bold text-brand-400 mt-1"><?= $totalVaMerchants ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-gray-800">
        <p class="text-xs text-gray-500">Multi-VA (load spread)</p>
        <p class="text-2xl font-bold text-emerald-400 mt-1"><?= $multiVaCount ?></p>
    </div>
</div>

<div class="glass rounded-xl p-5 mb-8 border border-gray-800">
    <h2 class="font-semibold mb-3">Find a merchant</h2>
    <form method="GET" class="flex flex-wrap gap-2">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Business name or merchant code" class="input-field text-sm flex-1 min-w-[220px]">
        <button class="btn-primary text-sm px-4 py-2">Search</button>
    </form>
    <?php if ($merchants): ?>
    <div class="mt-4 divide-y divide-gray-800">
        <?php foreach ($merchants as $m): ?>
        <a href="admin_virtual_accounts.php?merchant_id=<?= (int)$m['id'] ?>" class="flex justify-between items-center py-2.5 text-sm hover:text-brand-400">
            <span><?= e($m['business_name'] ?: $m['merchant_code']) ?></span>
            <span class="text-xs font-mono text-gray-500"><?= e($m['merchant_code']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php elseif ($q !== ''): ?>
    <p class="text-sm text-gray-500 mt-3">No merchants matched.</p>
    <?php endif; ?>
</div>

<?php if ($selectedMerchant): ?>
<div class="glass rounded-xl overflow-hidden border border-gray-800">
    <div class="px-5 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-semibold"><?= e($selectedMerchant['business_name'] ?: $selectedMerchant['merchant_code']) ?></h2>
            <p class="text-xs text-gray-500 font-mono mt-0.5"><?= e($selectedMerchant['merchant_code']) ?></p>
        </div>
        <form method="POST" class="flex flex-wrap items-end gap-2">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_va">
            <input type="hidden" name="merchant_id" value="<?= (int)$selectedMerchant['id'] ?>">
            <?php if (count($supportedVaGateways) > 1): ?>
            <select name="gateway" class="input-field text-sm !py-2 w-32" title="Bank partner">
                <?php foreach ($supportedVaGateways as $gw): ?>
                <option value="<?= e($gw) ?>"><?= e(vaGatewayDisplayName($gw)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" name="gateway" value="<?= e($supportedVaGateways[0] ?? 'axis') ?>">
            <?php endif; ?>
            <input type="text" name="label" placeholder="Label (optional)" class="input-field text-sm !py-2 w-40">
            <button class="btn-primary text-sm px-4 py-2">+ Create VA</button>
        </form>
    </div>
    <?php if (empty($vas)): ?>
    <div class="px-5 py-10 text-center text-sm text-gray-500">No virtual accounts yet. Create the first one above.</div>
    <?php else: ?>
    <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Label</th><th class="px-5 py-3 text-left">Bank</th><th class="px-5 py-3 text-left">VA Number</th>
            <th class="px-5 py-3 text-left">UPI</th><th class="px-5 py-3 text-left">Today</th>
            <th class="px-5 py-3 text-left">Total</th><th class="px-5 py-3 text-left">Fails today</th>
            <th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php foreach ($vas as $va): ?>
            <tr class="hover:bg-white/5">
                <td class="px-5 py-3"><?= e($va['label'] ?: '—') ?><?= $va['is_primary'] ? ' <span class="text-[10px] text-sky-400">(primary)</span>' : '' ?></td>
                <td class="px-5 py-3 text-xs text-gray-400"><?= e(vaGatewayDisplayName((string)($va['gateway'] ?? 'axis'))) ?></td>
                <td class="px-5 py-3 font-mono text-xs"><?= e($va['va_number']) ?></td>
                <td class="px-5 py-3 font-mono text-xs text-gray-400"><?= e($va['upi_id'] ?: '—') ?></td>
                <td class="px-5 py-3"><?= (int)$va['txn_count_today'] ?></td>
                <td class="px-5 py-3 text-gray-400"><?= (int)$va['txn_count_total'] ?></td>
                <td class="px-5 py-3 <?= (int)$va['fail_count_today'] > 0 ? 'text-amber-400' : 'text-gray-500' ?>"><?= (int)$va['fail_count_today'] ?></td>
                <td class="px-5 py-3"><?= statusBadge($va['status'] === 'active' ? 'active' : 'suspended') ?></td>
                <td class="px-5 py-3">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="toggle_va">
                        <input type="hidden" name="merchant_id" value="<?= (int)$selectedMerchant['id'] ?>">
                        <input type="hidden" name="va_id" value="<?= (int)$va['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $va['status'] === 'active' ? 'disabled' : 'active' ?>">
                        <button class="text-xs <?= $va['status'] === 'active' ? 'text-amber-400' : 'text-emerald-400' ?>"><?= $va['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table></div>
    <p class="px-5 py-3 text-xs text-gray-500 border-t border-gray-800">New payment requests are auto-assigned to the least-busy active VA (lowest "Today" count). A VA auto-disables after 10 failures in a day and traffic shifts to the others.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
