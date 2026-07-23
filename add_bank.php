<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settings');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_primary' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $accId = (int)($_POST['account_id'] ?? 0);
    $own = $db->prepare("SELECT id FROM bank_accounts WHERE id = ? AND merchant_id = ? AND status != 'inactive'");
    $own->execute([$accId, $merchant['id']]);
    if ($own->fetch()) {
        $db->prepare('UPDATE bank_accounts SET is_primary = 0 WHERE merchant_id = ?')->execute([$merchant['id']]);
        $db->prepare('UPDATE bank_accounts SET is_primary = 1 WHERE id = ? AND merchant_id = ?')->execute([$accId, $merchant['id']]);
        flash('success', 'Primary settlement account updated.');
    } else {
        flash('error', 'Account not found.');
    }
    redirect('add_bank.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $accId = (int)($_POST['account_id'] ?? 0);
    $own = $db->prepare('SELECT * FROM bank_accounts WHERE id = ? AND merchant_id = ?');
    $own->execute([$accId, $merchant['id']]);
    $row = $own->fetch();
    if (!$row) {
        flash('error', 'Account not found.');
    } else {
        $replacementId = 0;
        if (!empty($row['is_primary'])) {
            $replacement = $db->prepare("SELECT id FROM bank_accounts WHERE merchant_id=? AND id!=? AND status!='inactive' ORDER BY id DESC LIMIT 1");
            $replacement->execute([$merchant['id'], $accId]);
            $replacementId = (int)$replacement->fetchColumn();
            if (!$replacementId) {
                flash('error', 'Add another bank account before deleting the only primary account.');
                redirect('add_bank.php');
            }
        }
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE bank_accounts SET status='inactive', is_primary=0 WHERE id=? AND merchant_id=?")->execute([$accId, $merchant['id']]);
            if ($replacementId) {
                $db->prepare('UPDATE bank_accounts SET is_primary=1 WHERE id=? AND merchant_id=?')->execute([$replacementId, $merchant['id']]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
        flash('success', 'Bank account removed.');
    }
    redirect('add_bank.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $accId = (int)($_POST['account_id'] ?? 0);
    $bankName = trim($_POST['bank_name'] ?? '');
    $accountNumber = preg_replace('/\s+/', '', trim($_POST['account_number'] ?? ''));
    $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $holder = trim($_POST['account_holder'] ?? '');
    $type = in_array($_POST['account_type'] ?? '', ['savings', 'current'], true) ? $_POST['account_type'] : 'savings';
    if (!$bankName || !$accountNumber || !$holder || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        flash('error', 'Enter valid bank details and IFSC.');
    } else {
        $update = $db->prepare('UPDATE bank_accounts SET bank_name=?, account_number=?, ifsc_code=?, account_holder=?, account_type=? WHERE id=? AND merchant_id=?');
        $update->execute([$bankName, $accountNumber, $ifsc, $holder, $type, $accId, $merchant['id']]);
        flash($update->rowCount() ? 'success' : 'error', $update->rowCount() ? 'Bank account updated.' : 'Account not found or unchanged.');
    }
    redirect('add_bank.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (empty($_POST['action']) || $_POST['action'] === 'add') && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $bankName = trim($_POST['bank_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
    $holder = trim($_POST['account_holder'] ?? '');
    $type = $_POST['account_type'] ?? 'savings';

    if (!$bankName || !$accountNumber || !$ifsc || !$holder) {
        flash('error', 'All fields are required.');
    } elseif (!preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
        flash('error', 'Invalid IFSC code format.');
    } else {
        $isPrimary = $db->prepare("SELECT COUNT(*) as cnt FROM bank_accounts WHERE merchant_id = ? AND status != 'inactive'");
        $isPrimary->execute([$merchant['id']]);
        $primary = $isPrimary->fetch()['cnt'] == 0 ? 1 : 0;
        $verify = verifyBankAccount($accountNumber, $ifsc, (int)$merchant['id']);
        $ok = (($verify['status'] ?? '') === 'verified') || !empty($verify['success']) || !empty($verify['ok']);
        $isLiveMerchant = merchantAccountMode($merchant) === 'live';
        $initialStatus = ($isLiveMerchant && !$ok) ? 'pending' : 'active';

        $stmt = $db->prepare('INSERT INTO bank_accounts (merchant_id, bank_name, account_number, ifsc_code, account_holder, account_type, is_primary, status) VALUES (?,?,?,?,?,?,?,?)');
        try {
            $stmt->execute([$merchant['id'], $bankName, $accountNumber, $ifsc, $holder, $type, $primary, $initialStatus]);
        } catch (Throwable $e) {
            $db->prepare('INSERT INTO bank_accounts (merchant_id, bank_name, account_number, ifsc_code, account_holder, account_type, is_primary) VALUES (?,?,?,?,?,?,?)')
                ->execute([$merchant['id'], $bankName, $accountNumber, $ifsc, $holder, $type, $primary]);
            try {
                $db->prepare("UPDATE bank_accounts SET status=? WHERE merchant_id=? AND account_number=? ORDER BY id DESC LIMIT 1")
                    ->execute([$initialStatus, $merchant['id'], $accountNumber]);
            } catch (Throwable $e2) { /* column may not exist on very old schema */ }
        }
        $msg = $ok
            ? ('Bank account added. ' . ($verify['message'] ?? 'Ready for settlements.'))
            : ($isLiveMerchant
                ? ('Bank account saved as pending verification. Settlements unlock after verification completes.')
                : ('Bank account added. ' . ($verify['message'] ?? 'Verification pending.')));
        flash('success', $msg);
        redirect('add_bank.php');
    }
}

try {
    $accounts = $db->prepare("SELECT * FROM bank_accounts WHERE merchant_id = ? AND status != 'inactive' ORDER BY is_primary DESC, id DESC");
    $accounts->execute([$merchant['id']]);
} catch (Throwable $e) {
    $accounts = $db->prepare('SELECT * FROM bank_accounts WHERE merchant_id = ? ORDER BY is_primary DESC, id DESC');
    $accounts->execute([$merchant['id']]);
}
$bankAccounts = $accounts->fetchAll();

$pageTitle = 'Bank Accounts';
require_once __DIR__ . '/header.php';
?>

<div class="grid lg:grid-cols-2 gap-4 sm:gap-6">
    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <h2 class="font-semibold mb-4">Add Bank Account</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400 block mb-1">Bank Name *</label>
                <input type="text" name="bank_name" required autocomplete="organization" class="w-full bg-dark-900 border border-gray-700 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 outline-none">
            </div>
            <div>
                <label class="text-sm text-gray-400 block mb-1">Account Holder Name *</label>
                <input type="text" name="account_holder" required value="<?= e($merchant['name']) ?>" autocomplete="name" class="w-full bg-dark-900 border border-gray-700 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 outline-none">
            </div>
            <div>
                <label class="text-sm text-gray-400 block mb-1">Account Number *</label>
                <input type="text" name="account_number" required inputmode="numeric" autocomplete="off" class="w-full bg-dark-900 border border-gray-700 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 outline-none">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="text-sm text-gray-400 block mb-1">IFSC Code *</label>
                    <input type="text" name="ifsc_code" required maxlength="11" autocomplete="off" class="w-full bg-dark-900 border border-gray-700 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 outline-none uppercase">
                </div>
                <div>
                    <label class="text-sm text-gray-400 block mb-1">Account Type</label>
                    <select name="account_type" class="w-full bg-dark-900 border border-gray-700 rounded-xl px-4 py-2.5 text-white focus:border-brand-500 outline-none">
                        <option value="savings">Savings</option>
                        <option value="current">Current</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="w-full bg-brand-600 hover:bg-brand-500 text-white py-3 rounded-xl font-semibold transition">Add Account</button>
        </form>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6 min-w-0">
        <h2 class="font-semibold mb-4">Your Bank Accounts</h2>
        <?php if (empty($bankAccounts)): ?>
        <?= renderMerchantEmptyState(
            'No bank account yet',
            'Add your settlement bank account so wallet transfers can be paid out after ops NEFT/IMPS.',
            null,
            null
        ) ?>
        <?php else: foreach ($bankAccounts as $acc): ?>
        <div class="border border-gray-800 rounded-xl p-4 mb-3 <?= $acc['is_primary'] ? 'border-brand-500/30 bg-brand-500/5' : '' ?>">
            <div class="flex items-center justify-between mb-2">
                <span class="font-medium"><?= e($acc['bank_name']) ?></span>
                <div class="flex items-center gap-2">
                    <?php if ($acc['is_primary']): ?><span class="text-xs bg-brand-600/20 text-brand-400 px-2 py-0.5 rounded-full">Primary — settlements go here</span><?php endif; ?>
                    <?php if (!empty($acc['status'])): ?><?= statusBadge($acc['status']) ?><?php endif; ?>
                </div>
            </div>
            <p class="text-sm text-gray-400"><?= e($acc['account_holder']) ?></p>
            <p class="text-sm font-mono text-gray-500 mt-1">****<?= substr($acc['account_number'], -4) ?> | <?= e($acc['ifsc_code']) ?></p>
            <div class="flex items-center gap-3 mt-3">
                <?php if (!$acc['is_primary']): ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="set_primary">
                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                    <button type="submit" class="text-xs text-brand-400 hover:underline">Set as Primary</button>
                </form>
                <?php endif; ?>
                <form method="POST" class="inline" onsubmit="return confirm('Remove this bank account? If it is primary, another active account will become primary.')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="deactivate">
                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                    <button type="submit" class="text-xs text-red-500 hover:underline">Delete</button>
                </form>
            </div>
            <details class="mt-3 border-t border-gray-800 pt-3">
                <summary class="text-xs text-sky-500 cursor-pointer">Change account details</summary>
                <form method="POST" class="grid sm:grid-cols-2 gap-3 mt-3">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                    <input name="bank_name" required value="<?= e($acc['bank_name']) ?>" class="input-field text-sm" placeholder="Bank name">
                    <input name="account_holder" required value="<?= e($acc['account_holder']) ?>" class="input-field text-sm" placeholder="Account holder">
                    <input name="account_number" required value="<?= e($acc['account_number']) ?>" class="input-field text-sm" placeholder="Account number">
                    <input name="ifsc_code" required maxlength="11" value="<?= e($acc['ifsc_code']) ?>" class="input-field text-sm uppercase" placeholder="IFSC">
                    <select name="account_type" class="input-field text-sm"><option value="savings" <?= ($acc['account_type']??'')==='savings'?'selected':'' ?>>Savings</option><option value="current" <?= ($acc['account_type']??'')==='current'?'selected':'' ?>>Current</option></select>
                    <button type="submit" class="btn-primary text-sm px-4 py-2">Save Changes</button>
                </form>
            </details>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<script>
(function () {
    function debounce(fn, ms) { let t; return function () { clearTimeout(t); t = setTimeout(() => fn.apply(this, arguments), ms); }; }
    function hintEl(input) {
        let h = input.parentElement.querySelector('.ifsc-hint');
        if (!h) {
            h = document.createElement('p');
            h.className = 'ifsc-hint text-xs mt-1';
            input.parentElement.appendChild(h);
        }
        return h;
    }
    async function lookup(input) {
        const raw = (input.value || '').trim().toUpperCase();
        const h = hintEl(input);
        if (!/^[A-Z]{4}0[A-Z0-9]{6}$/.test(raw)) { h.textContent = ''; return; }
        h.className = 'ifsc-hint text-xs mt-1 text-gray-500';
        h.textContent = 'Looking up branch…';
        try {
            const res = await fetch('ifsc_lookup.php?ifsc=' + encodeURIComponent(raw), { headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            if (data && data.ok) {
                const form = input.closest('form');
                const bank = form ? form.querySelector('input[name="bank_name"]') : null;
                if (bank && (!bank.value.trim() || bank.dataset.ifscAuto === '1')) {
                    bank.value = data.bank;
                    bank.dataset.ifscAuto = '1';
                }
                const parts = [data.branch, data.city || data.district, data.state].filter(Boolean);
                h.className = 'ifsc-hint text-xs mt-1 text-emerald-400';
                h.textContent = '✓ ' + data.bank + (parts.length ? ' — ' + parts.join(', ') : '');
            } else {
                h.className = 'ifsc-hint text-xs mt-1 text-amber-400';
                h.textContent = (data && data.error) ? data.error : 'IFSC not found — check the code.';
            }
        } catch (e) {
            h.className = 'ifsc-hint text-xs mt-1 text-gray-500';
            h.textContent = '';
        }
    }
    const run = debounce(function () { lookup(this); }, 450);
    document.querySelectorAll('input[name="ifsc_code"]').forEach(function (input) {
        input.addEventListener('input', run);
        input.addEventListener('blur', function () { lookup(this); });
        if (input.value.trim()) { /* pre-filled edit form: don't overwrite, just verify */ }
    });
})();
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
