<?php
require_once __DIR__ . '/config.php';
if (!function_exists('getMerchantBeneficiaries')) {
    require_once __DIR__ . '/includes/beneficiaries.php';
}
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('settle');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_bank') {
        $holder = trim($_POST['account_holder'] ?? '');
        $acctNo = trim($_POST['account_number'] ?? '');
        $ifsc = trim($_POST['ifsc_code'] ?? '');
        $bankName = trim($_POST['bank_name'] ?? '');
        $result = addMerchantBeneficiary($merchantId, 'bank', $holder, $acctNo, $ifsc, null, $bankName);
    } elseif ($action === 'add_upi') {
        $holder = trim($_POST['account_holder'] ?? '');
        $upiId = trim($_POST['upi_id'] ?? '');
        $result = addMerchantBeneficiary($merchantId, 'upi', $holder, 'upi:' . $upiId, null, $upiId);
    } elseif ($action === 'verify') {
        $benId = (int)($_POST['beneficiary_id'] ?? 0);
        $result = verifyBeneficiary($benId, $merchantId);
    } elseif ($action === 'disable') {
        $benId = (int)($_POST['beneficiary_id'] ?? 0);
        $result = disableBeneficiary($benId, $merchantId);
    } elseif ($action === 'set_default') {
        $benId = (int)($_POST['beneficiary_id'] ?? 0);
        $ok = setDefaultBeneficiary($benId, $merchantId);
        $result = $ok ? ['ok' => true, 'message' => 'Default beneficiary updated.'] : ['ok' => false, 'error' => 'Failed to set default.'];
    }

    if ($result) {
        flash($result['ok'] ? 'success' : 'error', $result['ok'] ? $result['message'] : $result['error']);
    }
    redirect('beneficiaries.php');
}

$beneficiaries = getMerchantBeneficiaries($merchantId, true);
$verifiedCount = count(array_filter($beneficiaries, fn($b) => $b['status'] === 'verified'));

$pageTitle = 'Beneficiaries';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-5xl mx-auto px-4 py-8">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Beneficiaries</h1>
            <p class="text-sm text-gray-500 mt-1">Add bank/UPI accounts. Verify via Penny Drop before auto payout.</p>
        </div>
        <div class="flex gap-2">
            <span class="text-xs px-3 py-1.5 rounded-lg border border-emerald-500/30 bg-emerald-500/5 text-emerald-400"><?= $verifiedCount ?> verified</span>
            <span class="text-xs px-3 py-1.5 rounded-lg border border-gray-700 text-gray-400"><?= count($beneficiaries) ?> total</span>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl p-4 mb-6 text-red-400 text-sm"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <!-- Add Bank -->
        <div class="glass rounded-xl p-6 border border-sky-500/20">
            <h2 class="text-lg font-semibold text-white mb-4">Add Bank Account</h2>
            <form id="add-bank" method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add_bank">
                <div>
                    <label class="text-xs text-gray-500">Account Holder Name</label>
                    <input type="text" name="account_holder" required class="input-field text-sm mt-1" placeholder="As per bank records">
                </div>
                <div>
                    <label class="text-xs text-gray-500">Account Number</label>
                    <input type="text" name="account_number" required class="input-field text-sm mt-1" placeholder="Bank account number">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs text-gray-500">IFSC Code</label>
                        <input type="text" name="ifsc_code" required class="input-field text-sm mt-1" placeholder="HDFC0001234">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">Bank Name</label>
                        <input type="text" name="bank_name" class="input-field text-sm mt-1" placeholder="HDFC Bank">
                    </div>
                </div>
                <button type="submit" class="w-full bg-sky-600 hover:bg-sky-700 text-white rounded-lg py-2.5 text-sm font-medium">Add Bank →</button>
            </form>
        </div>

        <!-- Add UPI -->
        <div class="glass rounded-xl p-6 border border-violet-500/20">
            <h2 class="text-lg font-semibold text-white mb-4">Add UPI ID</h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                <input type="hidden" name="action" value="add_upi">
                <div>
                    <label class="text-xs text-gray-500">Account Holder Name</label>
                    <input type="text" name="account_holder" required class="input-field text-sm mt-1" placeholder="As per UPI records">
                </div>
                <div>
                    <label class="text-xs text-gray-500">UPI ID</label>
                    <input type="text" name="upi_id" required class="input-field text-sm mt-1" placeholder="merchant@hdfcbank">
                </div>
                <button type="submit" class="w-full bg-violet-600 hover:bg-violet-700 text-white rounded-lg py-2.5 text-sm font-medium">Add UPI →</button>
            </form>
        </div>
    </div>

    <!-- Beneficiary List -->
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Your Beneficiaries</h2></div>
        <?php if (empty($beneficiaries)): ?>
        <?= renderMerchantEmptyState('No beneficiaries yet', 'Add a bank account or UPI ID above so settlements and payouts have a destination.', '#add-bank', 'Add a bank account →') ?>
        <?php else: ?>
        <div class="overflow-x-auto"><table class="w-full text-sm">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Type</th><th class="px-4 py-3 text-left">Holder</th><th class="px-4 py-3 text-left">Details</th>
                <th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Verified Name</th><th class="px-4 py-3 text-left">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php foreach ($beneficiaries as $b):
                    $scls = match($b['status']) {
                        'verified' => 'text-emerald-400',
                        'unverified' => 'text-gray-400',
                        'pending' => 'text-sky-400',
                        'failed' => 'text-red-400',
                        'disabled' => 'text-gray-600',
                        default => 'text-gray-400',
                    };
                ?>
                <tr>
                    <td class="px-4 py-3 text-xs">
                        <span class="px-2 py-0.5 rounded <?= $b['type'] === 'bank' ? 'bg-sky-500/10 text-sky-400' : 'bg-violet-500/10 text-violet-400' ?>"><?= e($b['type']) ?></span>
                        <?php if ($b['is_default']): ?><span class="ml-1 text-amber-400">★</span><?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-300"><?= e($b['account_holder']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        <?php if ($b['type'] === 'bank'): ?>
                            <?= e(sensitiveLast4($b['account_number'])) ?> · <?= e($b['ifsc_code'] ?? '') ?>
                        <?php else: ?>
                            <?= e($b['upi_id'] ?? '') ?>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-xs <?= $scls ?>"><?= e($b['status']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        <?php if ($b['verify_name']): ?>
                            <?= e($b['verify_name']) ?> <span class="text-gray-600">(<?= (float)$b['verify_score'] ?>%)</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="px-4 py-3 flex gap-2 flex-wrap">
                        <?php if ($b['status'] !== 'verified' && $b['status'] !== 'disabled'): ?>
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="verify"><input type="hidden" name="beneficiary_id" value="<?= (int)$b['id'] ?>"><button type="submit" class="text-xs text-emerald-400 hover:underline" onclick="return confirm('Run penny drop verification?')">Verify</button></form>
                        <?php endif; ?>
                        <?php if ($b['status'] === 'verified' && !$b['is_default']): ?>
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="set_default"><input type="hidden" name="beneficiary_id" value="<?= (int)$b['id'] ?>"><button type="submit" class="text-xs text-amber-400 hover:underline">Set Default</button></form>
                        <?php endif; ?>
                        <?php if ($b['status'] !== 'disabled'): ?>
                        <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"><input type="hidden" name="action" value="disable"><input type="hidden" name="beneficiary_id" value="<?= (int)$b['id'] ?>"><button type="submit" class="text-xs text-red-400 hover:underline" onclick="return confirm('Disable this beneficiary?')">Disable</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>

    <div class="mt-6 text-xs text-gray-600">
        <p>Only verified beneficiaries can be used for auto payout. Unverified accounts work with Manual UTR path only.</p>
        <p class="mt-1">Penny Drop verification runs in mock mode. Real verification requires partner API keys.</p>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
