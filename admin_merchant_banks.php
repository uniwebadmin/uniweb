<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'regional_manager', 'finance', 'ops']);
$merchantId = (int)($_GET['id'] ?? $_POST['merchant_id'] ?? 0);
requireMerchantAccess($merchantId);
$db = getDB();
$merchantStmt = $db->prepare('SELECT id, merchant_code, business_name, name FROM merchants WHERE id=? AND status!=?');
$merchantStmt->execute([$merchantId, 'deleted']);
$merchant = $merchantStmt->fetch();
if (!$merchant) {
    flash('error', 'Merchant not found.');
    redirect('manage_merchant.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $accountId = (int)($_POST['account_id'] ?? 0);
    $accountStmt = $db->prepare("SELECT * FROM bank_accounts WHERE id=? AND merchant_id=? AND status!='inactive'");
    $accountStmt->execute([$accountId, $merchantId]);
    $account = $accountStmt->fetch();
    if (!$account) {
        flash('error', 'Bank account not found.');
        redirect('admin_merchant_banks.php?id=' . $merchantId);
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'set_primary') {
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE bank_accounts SET is_primary=0 WHERE merchant_id=?')->execute([$merchantId]);
            $db->prepare('UPDATE bank_accounts SET is_primary=1 WHERE id=? AND merchant_id=?')->execute([$accountId, $merchantId]);
            $db->commit();
            logStaffActivity('bank_primary_changed', 'Bank account #' . $accountId, $merchantId);
            flash('success', 'Primary settlement account changed.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Could not change primary account.');
        }
    } elseif ($action === 'delete') {
        $replacementId = 0;
        if (!empty($account['is_primary'])) {
            $replacement = $db->prepare("SELECT id FROM bank_accounts WHERE merchant_id=? AND id!=? AND status!='inactive' ORDER BY id DESC LIMIT 1");
            $replacement->execute([$merchantId, $accountId]);
            $replacementId = (int)$replacement->fetchColumn();
            if (!$replacementId) {
                flash('error', 'Cannot delete the only bank account. Ask the merchant to add another account first.');
                redirect('admin_merchant_banks.php?id=' . $merchantId);
            }
        }
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE bank_accounts SET status='inactive', is_primary=0 WHERE id=? AND merchant_id=?")->execute([$accountId, $merchantId]);
            if ($replacementId) $db->prepare('UPDATE bank_accounts SET is_primary=1 WHERE id=? AND merchant_id=?')->execute([$replacementId, $merchantId]);
            $db->commit();
            logStaffActivity('bank_account_deleted', 'Bank account #' . $accountId, $merchantId);
            flash('success', 'Bank account deleted.');
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            flash('error', 'Could not delete bank account.');
        }
    } elseif ($action === 'edit') {
        $bankName = trim($_POST['bank_name'] ?? '');
        $holder = trim($_POST['account_holder'] ?? '');
        $number = preg_replace('/\s+/', '', trim($_POST['account_number'] ?? ''));
        $ifsc = strtoupper(trim($_POST['ifsc_code'] ?? ''));
        $type = in_array($_POST['account_type'] ?? '', ['savings', 'current'], true) ? $_POST['account_type'] : 'savings';
        if (!$bankName || !$holder || !$number || !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifsc)) {
            flash('error', 'Enter valid bank details and IFSC.');
        } else {
            $db->prepare('UPDATE bank_accounts SET bank_name=?,account_holder=?,account_number=?,ifsc_code=?,account_type=? WHERE id=? AND merchant_id=?')
                ->execute([$bankName, $holder, $number, $ifsc, $type, $accountId, $merchantId]);
            logStaffActivity('bank_account_updated', 'Bank account #' . $accountId, $merchantId);
            flash('success', 'Bank account updated.');
        }
    }
    redirect('admin_merchant_banks.php?id=' . $merchantId);
}

$accountsStmt = $db->prepare("SELECT * FROM bank_accounts WHERE merchant_id=? AND status!='inactive' ORDER BY is_primary DESC,id DESC");
$accountsStmt->execute([$merchantId]);
$accounts = $accountsStmt->fetchAll();
$pageTitle = 'Merchant Bank Accounts';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl mx-auto space-y-5">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><a href="admin_view_merchant.php?id=<?= $merchantId ?>" class="text-sm text-sky-500">← Merchant View</a><h2 class="font-semibold mt-2"><?= e($merchant['business_name'] ?: $merchant['name']) ?></h2><p class="text-xs text-gray-500"><?= e($merchant['merchant_code']) ?></p></div>
        <span class="text-xs text-amber-500">All changes are recorded in Staff Activity Log</span>
    </div>
    <?php if (!$accounts): ?><div class="glass rounded-xl p-10 text-center text-gray-500">No active bank account. Merchant must add one from Bank Accounts.</div><?php endif; ?>
    <?php foreach ($accounts as $account): ?>
    <div class="glass rounded-xl p-5">
        <div class="flex flex-wrap justify-between gap-3">
            <div><p class="font-semibold"><?= e($account['bank_name']) ?></p><p class="text-sm text-gray-500"><?= e($account['account_holder']) ?> · ****<?= e(substr($account['account_number'], -4)) ?> · <?= e($account['ifsc_code']) ?></p></div>
            <?php if ($account['is_primary']): ?><span class="text-xs text-emerald-600 bg-emerald-500/10 px-3 py-1 rounded-full">Primary</span><?php endif; ?>
        </div>
        <div class="flex gap-3 mt-4">
            <?php if (!$account['is_primary']): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="action" value="set_primary"><button class="text-xs text-emerald-600">Set as Primary</button></form><?php endif; ?>
            <form method="POST" onsubmit="return confirm('Delete this bank account?')"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="action" value="delete"><button class="text-xs text-red-500">Delete</button></form>
        </div>
        <details class="mt-4 border-t border-gray-800 pt-3"><summary class="text-xs text-sky-500 cursor-pointer">Change details</summary>
            <form method="POST" class="grid sm:grid-cols-2 gap-3 mt-3"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="merchant_id" value="<?= $merchantId ?>"><input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>"><input type="hidden" name="action" value="edit">
                <input name="bank_name" value="<?= e($account['bank_name']) ?>" required class="input-field text-sm" placeholder="Bank name" aria-label="Bank name"><input name="account_holder" value="<?= e($account['account_holder']) ?>" required class="input-field text-sm" placeholder="Holder" aria-label="Account holder">
                <input name="account_number" value="<?= e($account['account_number']) ?>" required class="input-field text-sm" placeholder="Account number" aria-label="Account number"><input name="ifsc_code" value="<?= e($account['ifsc_code']) ?>" required maxlength="11" class="input-field text-sm uppercase" placeholder="IFSC" aria-label="IFSC code">
                <select name="account_type" class="input-field text-sm" aria-label="Account type"><option value="savings" <?= ($account['account_type']??'')==='savings'?'selected':'' ?>>Savings</option><option value="current" <?= ($account['account_type']??'')==='current'?'selected':'' ?>>Current</option></select><button class="btn-primary text-sm">Save Changes</button>
            </form>
        </details>
    </div>
    <?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
