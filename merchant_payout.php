<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/payout.php';
requireLogin();
ensurePayoutSchema();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
$db = getDB();

// Refresh payout_enabled if column was just added
try {
    $st = $db->prepare('SELECT payout_enabled FROM merchants WHERE id=?');
    $st->execute([$merchantId]);
    $merchant['payout_enabled'] = (int)$st->fetchColumn();
} catch (Throwable $e) {
    $merchant['payout_enabled'] = 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settle');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'request_enable') {
        $res = requestPayoutEnable($merchantId, (string)($_POST['note'] ?? ''));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
    } elseif ($action === 'add_beneficiary') {
        if (!merchantPayoutEnabled($merchant)) {
            flash('error', 'Request payout access first. Admin must approve before managing beneficiaries.');
        } else {
            $res = addPayoutBeneficiary($merchantId, $_POST);
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : $res['error']);
        }
    } elseif ($action === 'deactivate_beneficiary') {
        $res = deactivatePayoutBeneficiary($merchantId, (int)($_POST['beneficiary_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'update_beneficiary') {
        if (!merchantPayoutEnabled($merchant)) {
            flash('error', 'Payout access is not enabled yet.');
        } else {
            $res = updatePayoutBeneficiary($merchantId, (int)($_POST['beneficiary_id'] ?? 0), $_POST);
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
        }
    } elseif ($action === 'penny_drop') {
        $res = requestPayoutBeneficiaryPennyDrop($merchantId, (int)($_POST['beneficiary_id'] ?? 0));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'approve_checker') {
        $checker = (string)($merchant['name'] ?? $merchant['email'] ?? 'merchant');
        $res = approvePayoutChecker($merchantId, (int)($_POST['order_id'] ?? 0), $checker);
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'request_reversal') {
        $res = requestPayoutReversal($merchantId, (int)($_POST['order_id'] ?? 0), (string)($_POST['note'] ?? ''));
        flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Failed'));
    } elseif ($action === 'create_payout') {
        if (!merchantPayoutEnabled($merchant)) {
            flash('error', 'Payout access is not enabled yet.');
        } else {
            $maker = (string)($merchant['name'] ?? $merchant['email'] ?? 'merchant');
            $res = createPayoutDraft(
                $merchantId,
                (int)($_POST['beneficiary_id'] ?? 0),
                (float)($_POST['amount'] ?? 0),
                (string)($_POST['purpose'] ?? ''),
                $maker
            );
            flash($res['ok'] ? (empty($res['blocked']) ? 'success' : 'error') : 'error', $res['ok'] ? $res['message'] : $res['error']);
        }
    } elseif ($action === 'bulk_csv') {
        if (!merchantPayoutEnabled($merchant)) {
            flash('error', 'Payout access is not enabled yet.');
        } else {
            $csv = '';
            if (!empty($_FILES['bulk_csv']['tmp_name']) && is_uploaded_file($_FILES['bulk_csv']['tmp_name'])) {
                $csv = (string)file_get_contents($_FILES['bulk_csv']['tmp_name']);
            } else {
                $csv = (string)($_POST['bulk_csv_text'] ?? '');
            }
            $maker = (string)($merchant['name'] ?? $merchant['email'] ?? 'merchant');
            $res = processPayoutBulkCsv($merchantId, $csv, $maker);
            flash($res['ok'] ? 'success' : 'error', $res['ok'] ? $res['message'] : ($res['error'] ?? 'Bulk upload failed'));
            if (!empty($res['row_errors'])) {
                $_SESSION['payout_bulk_errors'] = array_slice($res['row_errors'], 0, 20);
            }
        }
    }
    redirect('merchant_payout.php');
}

if (isset($_GET['download_csv_template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="uniweb-payout-bulk-template.csv"');
    echo payoutBulkCsvHeader();
    echo "Vendor A,Acme Pvt Ltd,123456789012,HDFC0001234,1500.00,Invoice 42,HDFC Bank,current\n";
    echo "Salary,Ravi Kumar,987654321098,SBIN0000456,25000.00,March salary,SBI,savings\n";
    exit;
}

$enableReq = getMerchantPayoutEnableRequest($merchantId);
$enabled = merchantPayoutEnabled($merchant);
$beneficiaries = listPayoutBeneficiaries($merchantId, false);
$payoutQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$payoutStatus = trim($_GET['status'] ?? 'all');
$listParams = listPageParams(20);
$orderWhere = 'o.merchant_id = ?';
$orderParams = [$merchantId];
if ($payoutQ !== '') {
    $like = '%' . strtolower($payoutQ) . '%';
    $orderWhere .= ' AND (LOWER(o.payout_id) LIKE ? OR LOWER(COALESCE(b.label,\'\')) LIKE ? OR LOWER(COALESCE(o.failure_reason,\'\')) LIKE ?)';
    array_push($orderParams, $like, $like, $like);
}
if ($payoutStatus !== 'all') {
    $orderWhere .= ' AND o.status = ?';
    $orderParams[] = $payoutStatus;
}
try {
    $countSt = $db->prepare("SELECT COUNT(*) FROM payout_orders o LEFT JOIN payout_beneficiaries b ON b.id=o.beneficiary_id WHERE {$orderWhere}");
    $countSt->execute($orderParams);
    $orderTotal = (int)$countSt->fetchColumn();
    $orderSt = $db->prepare("SELECT o.*, b.label AS beneficiary_label, b.account_number FROM payout_orders o LEFT JOIN payout_beneficiaries b ON b.id=o.beneficiary_id WHERE {$orderWhere} ORDER BY o.id DESC LIMIT {$listParams['perPage']} OFFSET {$listParams['offset']}");
    $orderSt->execute($orderParams);
    $orders = $orderSt->fetchAll();
} catch (Throwable $e) {
    $orders = listPayoutOrders($merchantId, 30);
    $orderTotal = count($orders);
}
$wallet = ensureMerchantWalletReady($merchantId);
$split = getMerchantWalletSplitView($merchant, $wallet);
$isTest = (bool)($wallet['is_test'] ?? isMerchantTest($merchant));

$pageTitle = 'Payouts';
require_once __DIR__ . '/header.php';
?>

<div class="mb-6">
    <h1 class="text-xl font-bold">Payouts</h1>
    <p class="text-sm text-gray-500 mt-1">Vendor payouts via a licensed partner after collect is green. Scaffold only — no live money until partner keys + admin enable. Not Cashfree Easy Split / Razorpay Route marketplace. <a href="merchant_payout_keys.php" class="text-sky-400 hover:underline">Payout API keys →</a></p>
</div>

<div class="rounded-xl border border-amber-500/40 bg-amber-500/10 p-4 mb-6 text-sm">
    <p class="font-semibold text-amber-300">Status: <?= payoutLiveMoneyAllowed() ? 'Live rail ready' : 'Gated — keys pending' ?></p>
    <p class="text-amber-200/90 text-xs mt-1"><?= e(payoutActivationMessage()) ?></p>
    <p class="text-[11px] text-gray-500 mt-2">Failed payouts show a clear reason. Funds are never auto-credited back without a reconciliation / maker-checker gate. Route / Easy Split stays parked (Phase 11).</p>
</div>

<div class="grid sm:grid-cols-2 gap-4 mb-6">
    <div class="glass rounded-xl p-5 border border-sky-500/20">
        <p class="text-xs text-gray-500 uppercase"><?= e($split['collection']['label']) ?></p>
        <p class="text-2xl font-bold text-sky-400 mt-1"><?= walletMoney($split['collection']['available'], $isTest) ?></p>
        <p class="text-[11px] text-gray-500 mt-2"><?= e($split['collection']['note']) ?></p>
    </div>
    <div class="glass rounded-xl p-5 border border-violet-500/20">
        <p class="text-xs text-gray-500 uppercase"><?= e($split['payout']['label']) ?></p>
        <p class="text-2xl font-bold text-violet-300 mt-1"><?= walletMoney($split['payout']['available'], $isTest) ?></p>
        <p class="text-[11px] text-gray-500 mt-2"><?= e($split['payout']['note']) ?></p>
    </div>
</div>

<?php if (!$enabled): ?>
<div class="glass rounded-xl p-6 mb-6">
    <h2 class="font-semibold mb-2">Enable payouts</h2>
    <p class="text-sm text-gray-400 mb-4">Request admin approval to manage beneficiaries and submit payout drafts. Approval does not move money by itself.</p>
    <?php if ($enableReq && $enableReq['status'] === 'pending'): ?>
    <div class="bg-sky-500/10 border border-sky-500/30 text-sky-300 text-sm px-4 py-3 rounded-xl">Request pending since <?= e(formatDate($enableReq['created_at'])) ?>. Admin will review shortly.</div>
    <?php elseif ($enableReq && $enableReq['status'] === 'rejected'): ?>
    <div class="bg-red-500/10 border border-red-500/30 text-red-300 text-sm px-4 py-3 rounded-xl mb-4">
        Previous request rejected<?= !empty($enableReq['admin_note']) ? ': ' . e($enableReq['admin_note']) : '.' ?>
    </div>
    <form method="POST" class="space-y-3 max-w-lg">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="request_enable">
        <textarea name="note" rows="2" class="input-field" placeholder="Why do you need payouts? (optional)"></textarea>
        <button type="submit" class="btn-primary px-5 py-2.5">Re-submit enable request</button>
    </form>
    <?php else: ?>
    <form method="POST" class="space-y-3 max-w-lg">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="request_enable">
        <textarea name="note" rows="2" class="input-field" placeholder="Why do you need payouts? (optional)"></textarea>
        <button type="submit" class="btn-primary px-5 py-2.5">Request to enable payouts</button>
    </form>
    <?php endif; ?>
</div>
<?php else: ?>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Add beneficiary</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="add_beneficiary">
            <div><label class="text-sm text-gray-400">Label *</label><input type="text" name="label" required maxlength="120" class="input-field mt-1" placeholder="Vendor / salary / rent"></div>
            <div><label class="text-sm text-gray-400">Account holder *</label><input type="text" name="account_holder" required maxlength="190" class="input-field mt-1"></div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-sm text-gray-400">Account number *</label><input type="text" name="account_number" required class="input-field mt-1" inputmode="numeric"></div>
                <div><label class="text-sm text-gray-400">IFSC *</label><input type="text" name="ifsc_code" required maxlength="11" class="input-field mt-1 uppercase" style="text-transform:uppercase"></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div><label class="text-sm text-gray-400">Bank name</label><input type="text" name="bank_name" class="input-field mt-1"></div>
                <div><label class="text-sm text-gray-400">Type</label>
                    <select name="account_type" class="input-field mt-1"><option value="savings">Savings</option><option value="current">Current</option></select>
                </div>
            </div>
            <div><label class="text-sm text-gray-400">UPI VPA (optional)</label><input type="text" name="upi_vpa" class="input-field mt-1" placeholder="name@upi"></div>
            <p class="text-[11px] text-gray-500">IFSC auto-fills bank name via free directory. Penny-drop activates when partner keys are added.</p>
            <button type="submit" class="btn-primary px-5 py-2.5">Save beneficiary</button>
        </form>
        <script>
        (function(){
            function debounce(fn, ms){ let t; return function(){ clearTimeout(t); t=setTimeout(()=>fn.apply(this,arguments), ms); }; }
            async function lookup(input){
                const raw=(input.value||'').trim().toUpperCase();
                if(!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(raw)) return;
                try{
                    const res=await fetch('ifsc_lookup.php?ifsc='+encodeURIComponent(raw),{headers:{'Accept':'application/json'}});
                    const data=await res.json();
                    if(data&&data.ok){
                        const form=input.closest('form');
                        const bank=form&&form.querySelector('input[name="bank_name"]');
                        if(bank&&(!bank.value.trim()||bank.dataset.ifscAuto==='1')){ bank.value=data.bank; bank.dataset.ifscAuto='1'; }
                    }
                }catch(e){}
            }
            const run=debounce(function(){ lookup(this); }, 450);
            document.querySelectorAll('form input[name="ifsc_code"]').forEach(function(input){
                input.addEventListener('input', run);
                input.addEventListener('blur', function(){ lookup(this); });
            });
        })();
        </script>
    </div>

    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-2">Create payout draft</h2>
        <p class="text-xs text-gray-500 mb-4">Maker-checker placeholder: amounts ≥ ₹50,000 require checker. Live dispatch stays blocked without partner keys.</p>
        <?php
        $activeBens = array_values(array_filter($beneficiaries, static fn($b) => ($b['status'] ?? '') === 'active'));
        ?>
        <?php if (empty($activeBens)): ?>
        <p class="text-sm text-gray-500">Add an active beneficiary first.</p>
        <?php else: ?>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="create_payout">
            <div>
                <label class="text-sm text-gray-400">Beneficiary *</label>
                <select name="beneficiary_id" required class="input-field mt-1">
                    <?php foreach ($activeBens as $b): ?>
                    <option value="<?= (int)$b['id'] ?>"><?= e($b['label']) ?> · <?= e(sensitiveLast4($b['account_number'])) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label class="text-sm text-gray-400">Amount (₹) *</label><input type="number" name="amount" required min="1" max="1000000" step="0.01" class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">Purpose</label><input type="text" name="purpose" maxlength="120" class="input-field mt-1" placeholder="Vendor payment"></div>
            <button type="submit" class="btn-primary px-5 py-2.5">Submit payout draft</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="glass rounded-xl p-6 mb-6">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
        <div>
            <h2 class="font-semibold">Bulk payout (CSV)</h2>
            <p class="text-xs text-gray-500 mt-1">Upload up to 200 rows. Drafts are recorded for audit; live dispatch stays gated until partner keys are added.</p>
        </div>
        <a href="merchant_payout.php?download_csv_template=1" class="text-xs text-sky-400 hover:underline">Download CSV template →</a>
    </div>
    <form method="POST" enctype="multipart/form-data" class="space-y-3 max-w-xl">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="bulk_csv">
        <div>
            <label class="text-sm text-gray-400">CSV file</label>
            <input type="file" name="bulk_csv" accept=".csv,text/csv" class="input-field mt-1 text-sm">
        </div>
        <div>
            <label class="text-sm text-gray-400">Or paste CSV</label>
            <textarea name="bulk_csv_text" rows="4" class="input-field mt-1 font-mono text-xs" placeholder="label,account_holder,account_number,ifsc_code,amount,purpose"></textarea>
        </div>
        <button type="submit" class="btn-primary px-5 py-2.5">Upload bulk drafts</button>
    </form>
    <?php if (!empty($_SESSION['payout_bulk_errors'])): $bulkErrs = $_SESSION['payout_bulk_errors']; unset($_SESSION['payout_bulk_errors']); ?>
    <div class="mt-4 rounded-lg border border-red-500/30 bg-red-500/10 p-3 text-xs text-red-200 space-y-1">
        <?php foreach ($bulkErrs as $be): ?>
        <p>Line <?= (int)($be['line'] ?? 0) ?>: <?= e((string)($be['error'] ?? '')) ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<div class="glass rounded-xl overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Beneficiaries</h2></div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Label</th><th class="px-4 py-3 text-left">Account</th>
                <th class="px-4 py-3 text-left">IFSC</th><th class="px-4 py-3 text-left">Penny-drop</th>
                <th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left"></th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($beneficiaries)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No beneficiaries yet.</td></tr>
                <?php else: foreach ($beneficiaries as $b): ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3"><?= e($b['label']) ?><p class="text-xs text-gray-500"><?= e($b['account_holder']) ?></p></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= e(sensitiveLast4($b['account_number'])) ?></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= e($b['ifsc_code']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($b['penny_drop_status'] ?? 'pending') ?></td>
                    <td class="px-4 py-3"><?= statusBadge($b['status']) ?></td>
                    <td class="px-4 py-3">
                        <?php if (($b['status'] ?? '') === 'active'): ?>
                        <div class="flex flex-col gap-1 items-start">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="penny_drop">
                            <input type="hidden" name="beneficiary_id" value="<?= (int)$b['id'] ?>">
                            <button class="text-xs text-sky-400 hover:underline" type="submit">Verify penny-drop</button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Deactivate this beneficiary?')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="deactivate_beneficiary">
                            <input type="hidden" name="beneficiary_id" value="<?= (int)$b['id'] ?>">
                            <button class="text-xs text-red-400 hover:underline">Deactivate</button>
                        </form>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Payout history</h2>
        <p class="text-xs text-gray-500 mt-1">Failed rows always show a reason. No auto-reversal / auto-credit.</p>
    </div>
    <form method="GET" class="px-6 py-3 border-b border-gray-800 flex flex-wrap gap-3 items-end">
        <div class="flex-1 min-w-[160px]"><label class="text-[10px] text-gray-600 uppercase" for="payout-q">Search</label><input id="payout-q" type="search" name="q" value="<?= e($payoutQ) ?>" class="input-field mt-1 text-sm" placeholder="Payout ID / beneficiary"></div>
        <div><label class="text-[10px] text-gray-600 uppercase" for="payout-status">Status</label><select id="payout-status" name="status" class="input-field mt-1 text-sm"><option value="all">All</option><?php foreach (['draft','pending_checker','submitted','completed','failed'] as $pst): ?><option value="<?= $pst ?>" <?= $payoutStatus===$pst?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$pst)) ?></option><?php endforeach; ?></select></div>
        <button type="submit" class="btn-primary text-sm px-4 py-2">Filter</button>
    </form>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Payout ID</th><th class="px-4 py-3 text-left">Amount</th>
                <th class="px-4 py-3 text-left">Beneficiary</th><th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Reason / notes</th><th class="px-4 py-3 text-left">Date</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($orders)): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">No payout drafts yet.</td></tr>
                <?php else: foreach ($orders as $o): ?>
                <tr class="hover:bg-white/5 align-top">
                    <td class="px-4 py-3 font-mono text-xs text-sky-400"><?= e($o['payout_id']) ?></td>
                    <td class="px-4 py-3 font-semibold"><?= formatMoney((float)$o['amount']) ?></td>
                    <td class="px-4 py-3 text-xs"><?= e($o['beneficiary_label'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-xs"><?= e(payoutStatusLabel((string)$o['status'])) ?>
                        <?php if (($o['status'] ?? '') === 'pending_checker'): ?><p class="text-[10px] text-amber-400 mt-1">Maker-checker</p><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs <?= ($o['status'] ?? '') === 'failed' ? 'text-red-300' : 'text-gray-500' ?>">
                        <?= e(trim((string)($o['failure_reason'] ?? '')) ?: '—') ?>
                        <?php if (($o['status'] ?? '') === 'pending_checker'): ?>
                        <form method="POST" class="mt-2" onsubmit="return confirm('Approve as checker? Maker cannot approve their own draft.')">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="approve_checker">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <button class="text-xs text-emerald-400 hover:underline">Checker approve</button>
                        </form>
                        <?php endif; ?>
                        <?php if (($o['status'] ?? '') === 'failed'): ?>
                        <form method="POST" class="mt-2 space-y-1">
                            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                            <input type="hidden" name="action" value="request_reversal">
                            <input type="hidden" name="order_id" value="<?= (int)$o['id'] ?>">
                            <input type="text" name="note" maxlength="500" placeholder="Reversal note (optional)" class="input-field !py-1 !text-xs">
                            <button class="text-xs text-amber-400 hover:underline">Request reversal (no auto-credit)</button>
                        </form>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($o['created_at']) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?= renderListPagination($listParams['page'], $orderTotal, $listParams['perPage'], ['q' => $payoutQ, 'status' => $payoutStatus]) ?>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>
