<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/nodal.php';
requireStaffAccess(['super', 'ceo', 'finance']);

$db = getDB();
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save' && !empty($_POST['account'])) {
        $data = $_POST['account'];
        try {
            saveNodalAccount([
                'id' => (int)($data['id'] ?? 0),
                'name' => trim($data['name'] ?? ''),
                'bank_name' => trim($data['bank_name'] ?? ''),
                'account_holder' => trim($data['account_holder'] ?? ''),
                'account_number' => trim($data['account_number'] ?? ''),
                'ifsc_code' => trim($data['ifsc_code'] ?? ''),
                'branch' => trim($data['branch'] ?? ''),
                'purpose' => trim($data['purpose'] ?? 'collections_and_settlements'),
                'is_primary' => !empty($data['is_primary']),
            ], $adminId);
            flash('success', 'Nodal/escrow account saved.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('admin_nodal_accounts.php');
    }

    if ($action === 'verify' && !empty($_POST['id'])) {
        $notes = trim($_POST['verification_notes'] ?? '');
        verifyNodalAccount((int)$_POST['id'], $adminId, $notes);
        flash('success', 'Account verified.');
        redirect('admin_nodal_accounts.php');
    }

    if ($action === 'suspend' && !empty($_POST['id'])) {
        suspendNodalAccount((int)$_POST['id']);
        flash('success', 'Account suspended.');
        redirect('admin_nodal_accounts.php');
    }
}

$accounts = getNodalAccounts();
$platformAccount = getSetting('platform_account_number', '');
$platformIfsc = getSetting('platform_ifsc', '');

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit = $editId ? getNodalAccountById($editId) : null;

$pageTitle = 'Nodal / Escrow Accounts';
require_once __DIR__ . '/header.php';
?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="text-xl font-bold">Nodal / Escrow Accounts</h2>
            <p class="text-sm text-gray-400 mt-1">Customer funds must sit in a separate nodal/escrow account, never mixed with platform commission or operating account.</p>
        </div>
        <a href="?edit=0" class="btn-primary px-4 py-2 text-sm">Add Account</a>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Nodal Balance (Customer Funds)</p>
            <p class="text-2xl font-bold text-sky-400 mt-1">
                <?php
                $primary = getPrimaryNodalAccount();
                echo walletMoney($primary ? getNodalBalance((int)$primary['id']) : 0, true);
                ?>
            </p>
        </div>
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Platform Commission Wallet</p>
            <p class="text-2xl font-bold text-emerald-400 mt-1"><?= walletMoney(getPlatformWalletBalance(), true) ?></p>
        </div>
        <div class="glass rounded-xl p-5">
            <p class="text-xs text-gray-500 uppercase">Platform Payout Bank</p>
            <p class="text-sm text-gray-300 mt-1 break-all"><?= e($platformAccount ?: 'Not set') ?></p>
            <p class="text-xs text-gray-500 mt-1"><?= e($platformIfsc) ?></p>
        </div>
    </div>

    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4">Separation Checklist (RBI PA-PG)</h3>
        <ul class="text-sm text-gray-400 space-y-2">
            <li class="flex items-start gap-2"><span class="text-emerald-400">✓</span> Merchant wallet ledger tracks what platform owes each merchant (liability).</li>
            <li class="flex items-start gap-2"><span class="text-emerald-400">✓</span> Platform commission wallet is a separate operating ledger.</li>
            <li class="flex items-start gap-2"><span class="text-amber-400">↳</span> Real nodal bank account must NOT share account number / IFSC with platform payout bank.</li>
            <li class="flex items-start gap-2"><span class="text-amber-400">↳</span> Mark nodal account <strong>Verified</strong> only after bank statement / partner confirmation.</li>
        </ul>
    </div>

    <?php if (isset($_GET['edit'])): ?>
    <div class="glass rounded-xl p-4 sm:p-6">
        <h3 class="font-semibold mb-4"><?= $edit ? 'Edit Account' : 'Add Nodal Account' ?></h3>
        <form method="POST" class="grid sm:grid-cols-2 gap-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="account[id]" value="<?= (int)($edit['id'] ?? 0) ?>">
            <div><label class="text-sm text-gray-400">Name</label><input type="text" name="account[name]" value="<?= e($edit['name'] ?? '') ?>" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Bank Name</label><input type="text" name="account[bank_name]" value="<?= e($edit['bank_name'] ?? '') ?>" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Account Holder</label><input type="text" name="account[account_holder]" value="<?= e($edit['account_holder'] ?? '') ?>" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Account Number</label><input type="text" name="account[account_number]" value="<?= e($edit['account_number'] ?? '') ?>" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">IFSC</label><input type="text" name="account[ifsc_code]" value="<?= e($edit['ifsc_code'] ?? '') ?>" class="input-field mt-1 w-full" required></div>
            <div><label class="text-sm text-gray-400">Branch</label><input type="text" name="account[branch]" value="<?= e($edit['branch'] ?? '') ?>" class="input-field mt-1 w-full"></div>
            <div><label class="text-sm text-gray-400">Purpose</label><input type="text" name="account[purpose]" value="<?= e($edit['purpose'] ?? 'collections_and_settlements') ?>" class="input-field mt-1 w-full"></div>
            <div class="flex items-end"><label class="flex items-center gap-2 text-sm text-gray-400"><input type="checkbox" name="account[is_primary]" value="1" <?= !empty($edit['is_primary']) ? 'checked' : '' ?> class="rounded border-gray-600"> Primary nodal account</label></div>
            <div class="sm:col-span-2 flex gap-3">
                <button type="submit" class="btn-primary px-6 py-2.5">Save</button>
                <a href="admin_nodal_accounts.php" class="btn-secondary px-6 py-2.5">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Accounts</h3></div>
        <div class="overflow-x-auto"><table class="min-w-[640px] w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr><th class="px-4 py-3 text-left">Name</th><th class="px-4 py-3 text-left">Bank</th><th class="px-4 py-3 text-left">Account</th><th class="px-4 py-3 text-left">IFSC</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Separate</th><th class="px-4 py-3 text-left">Balance</th><th class="px-4 py-3 text-left"></th></tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($accounts)): ?><tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">No nodal/escrow accounts added.</td></tr>
                <?php else: foreach ($accounts as $a): ?>
                <tr>
                    <td class="px-4 py-3">
                        <?= e($a['name']) ?>
                        <?= $a['is_primary'] ? '<span class="ml-1 text-xs text-sky-400">(primary)</span>' : '' ?>
                    </td>
                    <td class="px-4 py-3"><?= e($a['bank_name']) ?></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= e($a['account_number']) ?></td>
                    <td class="px-4 py-3 font-mono text-xs"><?= e($a['ifsc_code']) ?></td>
                    <td class="px-4 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $a['status'] === 'verified' ? 'bg-emerald-500/15 text-emerald-400' : ($a['status'] === 'suspended' ? 'bg-red-500/15 text-red-400' : 'bg-amber-500/15 text-amber-400') ?>"><?= e($a['status']) ?></span></td>
                    <td class="px-4 py-3"><?= isNodalAccountSeparateFromPlatform($a) ? '<span class="text-emerald-400">Yes</span>' : '<span class="text-red-400">No</span>' ?></td>
                    <td class="px-4 py-3"><?= walletMoney(getNodalBalance((int)$a['id']), true) ?></td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <a href="?edit=<?= (int)$a['id'] ?>" class="text-sky-400 hover:underline text-xs">Edit</a>
                            <?php if ($a['status'] !== 'verified'): ?>
                            <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><input type="text" name="verification_notes" placeholder="Notes" class="input-field text-xs py-1 w-28"><button type="submit" class="text-xs text-emerald-400 hover:underline ml-1">Verify</button></form>
                            <?php endif; ?>
                            <?php if ($a['status'] !== 'suspended'): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Suspend this account?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="suspend"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button type="submit" class="text-xs text-red-400 hover:underline">Suspend</button></form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
