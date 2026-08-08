<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$approved = getSetting('recurring_autopay_approved', '0') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? 'create');
    $mandateId = (int)($_POST['mandate_id'] ?? 0);

    if ($action === 'pause' && $mandateId > 0) {
        if (pauseRecurringMandate($mandateId, (int)$merchant['id'])) {
            flash('success', 'Mandate paused.');
        } else {
            flash('error', 'Could not pause mandate.');
        }
        redirect('merchant_recurring.php');
    } elseif ($action === 'resume' && $mandateId > 0) {
        if (resumeRecurringMandate($mandateId, (int)$merchant['id'])) {
            flash('success', 'Mandate resumed.');
        } else {
            flash('error', 'Could not resume mandate.');
        }
        redirect('merchant_recurring.php');
    } elseif ($action === 'cancel' && $mandateId > 0) {
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (cancelRecurringMandate($mandateId, (int)$merchant['id'], $reason)) {
            flash('success', 'Mandate cancelled.');
        } else {
            flash('error', 'Could not cancel mandate.');
        }
        redirect('merchant_recurring.php');
    }

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
$recQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(20);
try {
    $st = getDB()->prepare('SELECT * FROM recurring_mandates WHERE merchant_id=? ORDER BY id DESC');
    $st->execute([(int)$merchant['id']]);
    $rows = $st->fetchAll();
    if ($recQ !== '') {
        $rows = array_values(array_filter($rows, static function ($row) use ($recQ) {
            $hay = strtolower(($row['mandate_ref'] ?? '') . ' ' . ($row['customer_name'] ?? '') . ' ' . ($row['customer_vpa'] ?? ''));
            return str_contains($hay, strtolower($recQ));
        }));
    }
} catch (Throwable $e) {
    $rows = [];
}
$recTotal = count($rows);
$rows = array_slice($rows, $listParams['offset'], $listParams['perPage']);

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
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold">Mandate list</h3>
            <div class="flex gap-2 items-center">
                <form method="GET" class="flex gap-2 items-center">
                    <label class="sr-only" for="rec-q">Search mandates</label>
                    <input id="rec-q" type="search" name="q" value="<?= e($recQ) ?>" placeholder="Ref / customer / VPA" class="input-field text-sm">
                    <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
                </form>
                <?= renderExportCsvLink('export_recurring.php?q=' . rawurlencode($recQ)) ?>
            </div>
        </div>
        <?php if (!$rows): ?><div class="p-0"><?= renderMerchantEmptyState('No mandates yet', 'Create a mandate request when partner AutoPay is approved.', null, null) ?></div><?php endif; ?>
        <?php foreach ($rows as $row): ?>
        <div class="px-6 py-3 border-b border-gray-800 flex justify-between gap-3 text-sm">
            <div>
                <p class="font-mono text-xs text-sky-400"><?= e($row['mandate_ref']) ?></p>
                <p><?= e($row['customer_name']) ?> · <?= formatMoney((float)$row['amount']) ?> / <?= e($row['frequency']) ?></p>
                <?php if (!empty($row['next_charge_at'])): ?><p class="text-xs text-gray-500">Next: <?= e(formatDate($row['next_charge_at'])) ?></p><?php endif; ?>
            </div>
            <div class="flex items-center gap-3">
                <?= statusBadge($row['status']) ?>
                <?php if ($row['status'] === 'active'): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="pause">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-amber-400 hover:underline">Pause</button>
                </form>
                <?php elseif ($row['status'] === 'paused'): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="resume">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-emerald-400 hover:underline">Resume</button>
                </form>
                <?php endif; ?>
                <?php if (in_array($row['status'], ['active', 'paused', 'pending_partner'], true)): ?>
                <form method="post" class="inline" onsubmit="return confirm('Cancel this mandate?')">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-red-400 hover:underline">Cancel</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?= renderListPagination($listParams['page'], $recTotal, $listParams['perPage'], ['q' => $recQ]) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
