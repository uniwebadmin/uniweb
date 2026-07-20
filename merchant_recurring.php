<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$approved = getSetting('recurring_autopay_approved', '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    if (!$approved) {
        flash('error', 'Recurring / AutoPay is disabled until partner product approval is recorded.');
        redirect('merchant_recurring.php');
    }
    if (!isMerchantLive($merchant)) {
        flash('error', 'Live Mode is required for recurring mandates.');
        redirect('merchant_recurring.php');
    }
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $freq = (string)($_POST['frequency'] ?? 'monthly');
    if ($amount < 1 || !in_array($freq, ['daily', 'weekly', 'monthly', 'yearly'], true)) {
        flash('error', 'Invalid mandate amount or frequency.');
        redirect('merchant_recurring.php');
    }
    $ref = 'MDT-' . strtoupper(bin2hex(random_bytes(6)));
    getDB()->prepare(
        'INSERT INTO recurring_mandates (mandate_ref,merchant_id,customer_name,customer_vpa,amount,frequency,status)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([
        $ref,
        (int)$merchant['id'],
        trim((string)($_POST['customer_name'] ?? '')),
        trim((string)($_POST['customer_vpa'] ?? '')),
        $amount,
        $freq,
        'pending_partner',
    ]);
    flash('success', "Mandate {$ref} saved as pending partner activation.");
    redirect('merchant_recurring.php');
}

$rows = [];
try {
    $st = getDB()->prepare('SELECT * FROM recurring_mandates WHERE merchant_id=? ORDER BY id DESC LIMIT 50');
    $st->execute([(int)$merchant['id']]);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

$pageTitle = 'Recurring / AutoPay';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="glass rounded-xl p-6 border <?= $approved ? 'border-emerald-500/20' : 'border-amber-500/30' ?>">
        <h2 class="font-semibold mb-2">Recurring mandates</h2>
        <p class="text-sm text-gray-500"><?= $approved
            ? 'Partner AutoPay product is marked approved. Mandates still require provider activation before charging.'
            : 'This product stays draft-only until admin records partner AutoPay approval (`recurring_autopay_approved=1`).' ?></p>
    </div>
    <?php if ($approved): ?>
    <form method="post" class="glass rounded-xl p-6 space-y-3">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="text-xs text-gray-500">Customer name</label><input name="customer_name" class="input-field mt-1" required></div>
            <div><label class="text-xs text-gray-500">UPI VPA</label><input name="customer_vpa" class="input-field mt-1" placeholder="name@upi" required></div>
            <div><label class="text-xs text-gray-500">Amount</label><input type="number" step="0.01" name="amount" class="input-field mt-1" required></div>
            <div><label class="text-xs text-gray-500">Frequency</label><select name="frequency" class="input-field mt-1"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="daily">Daily</option><option value="yearly">Yearly</option></select></div>
        </div>
        <button class="btn-primary px-5 py-2.5">Create mandate request</button>
    </form>
    <?php endif; ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h3 class="font-semibold">Mandate list</h3></div>
        <?php if (!$rows): ?><p class="text-sm text-gray-500 text-center py-8">No mandates yet.</p><?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <div class="px-6 py-3 border-b border-gray-800 flex justify-between gap-3 text-sm">
            <div><p class="font-mono text-xs text-sky-400"><?= e($row['mandate_ref']) ?></p><p><?= e($row['customer_name']) ?> · <?= formatMoney((float)$row['amount']) ?> / <?= e($row['frequency']) ?></p></div>
            <?= statusBadge($row['status']) ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
