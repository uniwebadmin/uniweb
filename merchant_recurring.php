<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$approved = getSetting('recurring_autopay_approved', '0') === '1';
if (!function_exists('ensureMandateSchema')) {
    require_once __DIR__ . '/includes/mandates.php';
}
if (!function_exists('ensureRecurringTables')) {
    require_once __DIR__ . '/includes/recurring.php';
}
ensureMandateSchema();
ensureRecurringTables();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $action = (string)($_POST['action'] ?? 'create');
    $mandateId = (int)($_POST['mandate_id'] ?? 0);

    // G2: Pause / Resume / Cancel actions on mandates table
    if ($action === 'pause' && $mandateId > 0) {
        if (pauseMandate($mandateId, (int)$merchant['id'])) {
            flash('success', 'Mandate paused.');
        } else {
            flash('error', 'Could not pause mandate.');
        }
        redirect('merchant_recurring.php');
    } elseif ($action === 'resume' && $mandateId > 0) {
        if (resumeMandate($mandateId, (int)$merchant['id'])) {
            flash('success', 'Mandate resumed.');
        } else {
            flash('error', 'Could not resume mandate.');
        }
        redirect('merchant_recurring.php');
    } elseif ($action === 'cancel' && $mandateId > 0) {
        $reason = trim((string)($_POST['reason'] ?? 'Merchant cancelled'));
        // G2: Cancel with partner API, not just local DB
        $result = cancelMandateWithPartner($mandateId, $reason);
        if ($result['ok']) {
            flash('success', 'Mandate cancelled. Partner has been notified.');
        } else {
            flash('error', 'Could not cancel mandate: ' . ($result['error'] ?? 'Unknown'));
        }
        redirect('merchant_recurring.php');
    } elseif ($action === 'register' && $mandateId > 0) {
        // G3: Trigger partner registration for pending mandate
        $result = registerMandateWithPartner($mandateId);
        if (!empty($result['ok']) && !empty($result['auth_url'])) {
            redirect($result['auth_url']);
        } elseif (!empty($result['ok'])) {
            flash('success', 'Mandate registered with partner. Customer approval pending.');
        } else {
            flash('error', 'Registration failed: ' . ($result['error'] ?? 'Unknown'));
        }
        redirect('merchant_recurring.php');
    }

    // G2: Create new mandate
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
    $channel = (string)($_POST['channel'] ?? 'upi');
    $mandateType = $channel === 'netbanking' ? 'enach' : 'upi_autopay';
    if ($amount < 1 || !in_array($freq, ['daily', 'weekly', 'monthly', 'quarterly', 'halfyearly', 'yearly'], true)) {
        flash('error', 'Invalid mandate amount or frequency.');
        redirect('merchant_recurring.php');
    }
    // G4: Respect partner limits — UPI Autopay max ₹2000 per txn
    if ($mandateType === 'upi_autopay' && $amount > 2000) {
        flash('error', 'UPI Autopay maximum per debit is ₹2,000. Use eNACH for higher amounts.');
        redirect('merchant_recurring.php');
    }
    $startDate = (string)($_POST['start_date'] ?? date('Y-m-d'));
    $endDate = trim((string)($_POST['end_date'] ?? '')) ?: null;
    $maxDebits = (int)($_POST['max_debits'] ?? 0) > 0 ? (int)($_POST['max_debits']) : null;
    $planName = trim((string)($_POST['plan_name'] ?? ''));
    $planId = null;

    // G2: Create subscription plan if name provided
    if ($planName !== '') {
        $planResult = createSubscriptionPlan((int)$merchant['id'], $planName, $amount, $freq, 1, $maxDebits, null);
        if (!empty($planResult['ok'])) {
            $planId = $planResult['plan_id'];
        }
    }

    // G3: Idempotency key for mandate registration
    $idemKey = 'mandate-' . (int)$merchant['id'] . '-' . $amount . '-' . $freq . '-' . $startDate . '-' . substr(md5(json_encode($_POST)), 0, 16);

    $result = createMandate(
        (int)$merchant['id'],
        $amount,
        $freq,
        $startDate,
        $endDate,
        $maxDebits,
        trim((string)($_POST['customer_name'] ?? '')),
        trim((string)($_POST['customer_email'] ?? '')) ?: null,
        trim((string)($_POST['customer_phone'] ?? '')) ?: null,
        trim((string)($_POST['customer_vpa'] ?? '')) ?: null,
        $mandateType,
        $planId,
        $channel,
        $idemKey
    );

    if (!empty($result['ok'])) {
        // G3: Auto-register with partner if keys configured
        $regResult = registerMandateWithPartner($result['mandate_id']);
        if (!empty($regResult['ok']) && !empty($regResult['auth_url'])) {
            flash('success', 'Mandate created. Customer authorisation opens next.');
            redirect($regResult['auth_url']);
        } elseif (!empty($regResult['ok'])) {
            flash('success', 'Mandate ' . $result['mandate_ref'] . ' created and registered. Customer approval pending.');
        } else {
            flash('success', 'Mandate ' . $result['mandate_ref'] . ' created. ' . ($regResult['error'] ?? 'Partner registration pending.'));
        }
    } else {
        flash('error', 'Could not create mandate: ' . ($result['error'] ?? 'Unknown'));
    }
    redirect('merchant_recurring.php');
}

// G2: Fetch mandates from mandates table (not recurring_mandates)
$rows = [];
$recQ = mb_substr(trim($_GET['q'] ?? ''), 0, 100);
$listParams = listPageParams(20);
try {
    $st = getDB()->prepare('SELECT * FROM mandates WHERE merchant_id=? ORDER BY id DESC');
    $st->execute([(int)$merchant['id']]);
    $rows = $st->fetchAll();
    if ($recQ !== '') {
        $rows = array_values(array_filter($rows, static function ($row) use ($recQ) {
            $hay = strtolower(($row['mandate_ref'] ?? '') . ' ' . ($row['customer_name'] ?? '') . ' ' . ($row['customer_upi_id'] ?? ''));
            return str_contains($hay, strtolower($recQ));
        }));
    }
} catch (Throwable $e) {
    $rows = [];
}
$recTotal = count($rows);
$rows = array_slice($rows, $listParams['offset'], $listParams['perPage']);

// G2: Fetch last debit for each mandate
$lastDebits = [];
if ($rows) {
    $mandateIds = array_map(fn($r) => (int)$r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($mandateIds), '?'));
    try {
        $ds = getDB()->prepare("SELECT d.* FROM mandate_debits d JOIN (SELECT mandate_id, MAX(id) AS max_id FROM mandate_debits WHERE mandate_id IN ($placeholders) GROUP BY mandate_id) latest ON d.id = latest.max_id");
        $ds->execute($mandateIds);
        foreach ($ds->fetchAll() as $d) {
            $lastDebits[(int)$d['mandate_id']] = $d;
        }
    } catch (Throwable $e) {}
}

// G2: Fetch existing plans
$plans = getMerchantSubscriptionPlans((int)$merchant['id']);

$pageTitle = 'Recurring / AutoPay';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-4xl space-y-6">
    <!-- G7: Compliance text -->
    <div class="glass rounded-xl p-6 border <?= $approved ? 'border-emerald-500/20' : 'border-amber-500/30' ?>">
        <h2 class="font-semibold mb-2">Recurring Mandates</h2>
        <p class="text-sm text-gray-500"><?= $approved
            ? 'Partner mandate registration is wired — live money needs partner keys in Admin Registry and customer approval on the network. Customer may cancel in their UPI app; UniWeb does not guarantee debit success.'
            : 'Scaffold / gated — mandates are stored locally; partner autopay adapters and live debit need Admin approval + Registry keys.' ?></p>
        <?php if ($approved): ?>
        <p class="text-xs text-gray-600 mt-2">Customer may also cancel the mandate directly in their UPI app or bank. UniWeb does not guarantee debit success — it depends on customer balance and bank approval.</p>
        <?php endif; ?>
    </div>

    <?php if ($approved && isMerchantLive($merchant)): ?>
    <!-- G2: Create mandate form -->
    <form method="post" class="glass rounded-xl p-6 space-y-3">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <h3 class="font-semibold text-sm">Create Mandate</h3>
        <div class="grid sm:grid-cols-2 gap-3">
            <div><label class="text-xs text-gray-500">Plan name (optional)</label><input name="plan_name" class="input-field mt-1" placeholder="e.g. Monthly Subscription"></div>
            <div><label class="text-xs text-gray-500">Channel</label><select name="channel" class="input-field mt-1" id="channel-select"><option value="upi">UPI Autopay (max ₹2,000/txn)</option><option value="netbanking">eNACH (netbanking)</option></select></div>
            <div><label class="text-xs text-gray-500">Customer name</label><input name="customer_name" class="input-field mt-1" required></div>
            <div><label class="text-xs text-gray-500">Customer UPI ID</label><input name="customer_vpa" class="input-field mt-1" placeholder="name@upi" required></div>
            <div><label class="text-xs text-gray-500">Amount (₹)</label><input type="number" step="0.01" name="amount" class="input-field mt-1" id="amount-input" required></div>
            <div><label class="text-xs text-gray-500">Frequency</label><select name="frequency" class="input-field mt-1"><option value="monthly">Monthly</option><option value="weekly">Weekly</option><option value="daily">Daily</option><option value="quarterly">Quarterly</option><option value="halfyearly">Half-Yearly</option><option value="yearly">Yearly</option></select></div>
            <div><label class="text-xs text-gray-500">Start date</label><input type="date" name="start_date" class="input-field mt-1" value="<?= date('Y-m-d') ?>"></div>
            <div><label class="text-xs text-gray-500">End date (optional)</label><input type="date" name="end_date" class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Max debits (optional, blank = unlimited)</label><input type="number" name="max_debits" class="input-field mt-1" placeholder="e.g. 12"></div>
            <div><label class="text-xs text-gray-500">Customer email (optional)</label><input type="email" name="customer_email" class="input-field mt-1"></div>
            <div><label class="text-xs text-gray-500">Customer phone (optional)</label><input type="tel" name="customer_phone" class="input-field mt-1"></div>
        </div>
        <button class="btn-primary px-5 py-2.5">Create & Register Mandate</button>
    </form>
    <?php endif; ?>

    <?php if ($plans): ?>
    <!-- G2: Existing plans -->
    <div class="glass rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-2">Saved Plans</h3>
        <div class="flex flex-wrap gap-2">
        <?php foreach ($plans as $p): ?>
            <span class="text-xs bg-gray-800 rounded px-2 py-1"><?= e($p['plan_name']) ?> · <?= formatMoney((float)$p['amount']) ?>/<?= e($p['interval_unit']) ?> <?= $p['total_cycles'] ? '×' . (int)$p['total_cycles'] : '' ?></span>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- G2: Mandate list with statuses + last debit result -->
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold">Mandate List</h3>
            <div class="flex gap-2 items-center">
                <form method="GET" class="flex gap-2 items-center">
                    <label class="sr-only" for="rec-q">Search mandates</label>
                    <input id="rec-q" type="search" name="q" value="<?= e($recQ) ?>" placeholder="Ref / customer / UPI" class="input-field text-sm">
                    <button type="submit" class="btn-primary text-sm px-3 py-1.5">Search</button>
                </form>
                <?= renderExportCsvLink('export_recurring.php?q=' . rawurlencode($recQ)) ?>
            </div>
        </div>
        <?php if (!$rows): ?><div class="p-0"><?= renderMerchantEmptyState('No mandates yet', 'Create a mandate to start recurring collections.', null, null) ?></div><?php endif; ?>
        <?php foreach ($rows as $row): 
            $lastDebit = $lastDebits[(int)$row['id']] ?? null;
            $statusLower = strtolower($row['status']);
        ?>
        <div class="px-6 py-3 border-b border-gray-800 flex justify-between gap-3 text-sm">
            <div class="space-y-1">
                <p class="font-mono text-xs text-sky-400"><?= e($row['mandate_ref']) ?></p>
                <p><?= e($row['customer_name'] ?? 'Unknown') ?> · <?= formatMoney((float)$row['max_amount']) ?> / <?= e($row['frequency']) ?> · <?= e(ucfirst($row['channel'] ?? 'upi')) ?></p>
                <?php if (!empty($row['next_debit_date'])): ?><p class="text-xs text-gray-500">Next debit: <?= e(formatDate($row['next_debit_date'])) ?></p><?php endif; ?>
                <?php if (!empty($row['failure_reason'])): ?><p class="text-xs text-red-400"><?= e($row['failure_reason']) ?></p><?php endif; ?>
                <?php if ($lastDebit): ?>
                <p class="text-xs <?= $lastDebit['status'] === 'success' ? 'text-emerald-400' : 'text-red-400' ?>">Last debit: <?= e(ucfirst($lastDebit['status'])) ?> <?= $lastDebit['mapped_reason'] ? '· ' . e($lastDebit['mapped_reason']) : '' ?></p>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <?= statusBadge($statusLower) ?>
                <?php if ($statusLower === 'pending'): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="register">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-sky-400 hover:underline">Register</button>
                </form>
                <?php endif; ?>
                <?php if ($statusLower === 'active'): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="pause">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-amber-400 hover:underline">Pause</button>
                </form>
                <?php elseif ($statusLower === 'paused'): ?>
                <form method="post" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="resume">
                    <input type="hidden" name="mandate_id" value="<?= (int)$row['id'] ?>">
                    <button class="text-xs text-emerald-400 hover:underline">Resume</button>
                </form>
                <?php endif; ?>
                <?php if (in_array($statusLower, ['active', 'paused', 'pending', 'registered'], true)): ?>
                <form method="post" class="inline" onsubmit="return confirm('Cancel this mandate? Partner will be notified.')">
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
