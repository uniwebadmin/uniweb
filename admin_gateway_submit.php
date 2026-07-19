<?php
require_once __DIR__ . '/config.php';
requireStaffAccess(['super', 'ceo', 'ops']);
ensureGatewaySubmissionsTable();
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $merchantId = (int)($_POST['merchant_id'] ?? 0);
    $gateway = $_POST['gateway'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $allowed = ['razorpay', 'cashfree', 'payu', 'decentro', 'phonepe'];
    if ($merchantId && in_array($gateway, $allowed, true)) {
        submitMerchantToGateway($merchantId, $gateway, (int)$_SESSION['admin_id'], $notes);
        flash('success', 'Merchant KYC submitted to ' . strtoupper($gateway) . '.');
    } else {
        flash('error', 'Invalid submission.');
    }
    redirect('admin_gateway_submit.php');
}

$merchants = $db->query("SELECT id, merchant_code, business_name, name, email, phone, kyc_status, status, gstin, pan_number, cin_llpin FROM merchants WHERE status != 'deleted' ORDER BY business_name")->fetchAll();
$submissions = $db->query('SELECT gs.*, m.business_name, m.merchant_code, m.id AS merchant_row_id FROM gateway_submissions gs JOIN merchants m ON gs.merchant_id = m.id ORDER BY gs.created_at DESC LIMIT 50')->fetchAll();

$pageTitle = 'Gateway Submission';
require_once __DIR__ . '/header.php';
?>
<div class="grid lg:grid-cols-2 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Submit Merchant to Payment Gateway</h2>
        <p class="text-xs text-gray-500 mb-4">Select merchant and gateway. All uploaded KYC documents will be packaged and submitted for onboarding review.</p>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div>
                <label class="text-sm text-gray-400">Merchant *</label>
                <select name="merchant_id" required class="input-field mt-1">
                    <option value="">Select merchant</option>
                    <?php foreach ($merchants as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= e($m['business_name']) ?> (<?= e($m['merchant_code']) ?>) — KYC: <?= e($m['kyc_status']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400">Payment Gateway *</label>
                <select name="gateway" required class="input-field mt-1">
                    <option value="razorpay">Razorpay</option>
                    <option value="cashfree">Cashfree</option>
                    <option value="payu">PayU</option>
                    <option value="decentro">Decentro</option>
                    <option value="phonepe">PhonePe</option>
                </select>
            </div>
            <div>
                <label class="text-sm text-gray-400">Admin Notes</label>
                <textarea name="notes" rows="3" class="input-field mt-1" placeholder="Internal notes for this submission..."></textarea>
            </div>
            <button type="submit" class="btn-primary px-6 py-2.5">Submit to Gateway</button>
        </form>
    </div>

    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Recent Submissions</h2></div>
        <?php if (empty($submissions)): ?>
        <p class="text-gray-500 text-sm text-center py-8">No gateway submissions yet.</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50">
                    <tr><th class="px-4 py-3 text-left">Merchant</th><th class="px-4 py-3 text-left">Gateway</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Date</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($submissions as $s): ?>
                    <tr<?= uiRowClick(adminMerchantUrl((int)$s['merchant_row_id'])) ?>>
                        <td class="px-4 py-3">
                            <p class="font-medium"><?= adminMerchantLink((int)$s['merchant_row_id'], $s['business_name'], 'font-medium hover:text-sky-300') ?></p>
                            <p class="text-xs text-gray-500"><?= adminMerchantLink((int)$s['merchant_row_id'], $s['merchant_code'], 'font-mono text-sky-400') ?></p>
                        </td>
                        <td class="px-4 py-3 uppercase text-xs"><?= e($s['gateway']) ?></td>
                        <td class="px-4 py-3"><?= statusBadge($s['status']) ?></td>
                        <td class="px-4 py-3 text-xs text-gray-500"><?= formatDate($s['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
