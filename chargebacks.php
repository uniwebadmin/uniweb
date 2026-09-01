<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$merchantId = (int)$merchant['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    try {
        submitChargebackEvidence((int)($_POST['id'] ?? 0), $merchantId, (string)($_POST['evidence_notes'] ?? ''));
        flash('success', 'Evidence submitted for representment review.');
    } catch (Throwable $e) {
        flash('error', function_exists('userFacingError')
            ? userFacingError($e, 'Could not submit evidence', 'Add notes and try again, or open Support with the chargeback ID.')
            : 'Could not submit evidence. Try again.');
    }
    redirect('chargebacks.php');
}

$rows = listMerchantChargebacks($merchantId);
if (!$rows && strcasecmp((string)($merchant['email'] ?? ''), 'demo@uniweb.co.in') === 0) {
    ensureDemoChargebacks($merchantId);
    $rows = listMerchantChargebacks($merchantId);
}
if (!function_exists('wiringChargebackMerchantShouldRedirect') && is_file(__DIR__ . '/includes/wiring_deep_link_workflow.php')) {
    require_once __DIR__ . '/includes/wiring_deep_link_workflow.php';
}
if (function_exists('wiringChargebackMerchantShouldRedirect') && wiringChargebackMerchantShouldRedirect($rows, $_GET)) {
    redirect(wiringChargebackMerchantLaneUrl());
} elseif (!$rows && !isset($_GET['legacy'])) {
    redirect('disputes.php');
}
$siloEdu = function_exists('wiringChargebackSiloEducation') ? wiringChargebackSiloEducation(false) : null;
$pageTitle = 'Chargebacks';
require_once __DIR__ . '/header.php';
?>
<div class="glass rounded-xl p-4 mb-6 border border-sky-500/25 text-sm text-gray-400">
    <?php if (is_array($siloEdu)): ?>
    <p><?= e((string)$siloEdu['rule']) ?> <a href="<?= e((string)$siloEdu['main_lane']) ?>" class="text-sky-400 hover:underline">Disputes</a> — your main lane on UniWeb.</p>
    <p class="text-xs text-gray-600 mt-1">Legacy bank chargeback evidence only: <a href="<?= e((string)$siloEdu['legacy_page']) ?>" class="text-gray-500 hover:underline">open legacy list</a>.</p>
    <?php else: ?>
    <p>For new disputes and day-to-day chargeback workflow, use <a href="disputes.php" class="text-sky-400 hover:underline">Disputes</a> — your main lane on UniWeb (one console, like market payment companies).</p>
    <p class="text-xs text-gray-600 mt-1">This page is for legacy chargeback evidence rows only.</p>
    <?php endif; ?>
</div>
<div class="glass rounded-xl overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-800">
        <h2 class="font-semibold">Chargebacks</h2>
        <p class="text-xs text-gray-500 mt-1">Submit evidence before the deadline. Partial notes are better than a missed deadline.</p>
    </div>
    <?php if (!$rows): ?>
    <p class="text-center text-gray-500 py-10 text-sm">No chargebacks on this account.</p>
    <?php else: foreach ($rows as $row): ?>
    <div class="px-6 py-4 border-b border-gray-800">
        <div class="flex flex-wrap justify-between gap-3 mb-2">
            <div>
                <p class="font-mono text-xs text-sky-400"><?= e($row['chargeback_ref']) ?></p>
                <p class="text-sm"><?= formatMoney((float)$row['amount']) ?> · <?= e($row['reason_text'] ?: ($row['reason_code'] ?: 'Dispute')) ?></p>
                <p class="text-xs text-gray-500">Evidence due: <?= e($row['evidence_due_at'] ?? 'n/a') ?></p>
            </div>
            <?= chargebackStatusBadge((string)$row['status']) ?>
        </div>
        <?php if (in_array($row['status'], ['opened', 'evidence_required'], true)): ?>
        <form method="post" class="mt-3 space-y-2">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
            <textarea name="evidence_notes" required rows="3" class="input-field" placeholder="Order proof, delivery proof, customer communication..."></textarea>
            <button class="btn-primary px-4 py-2 text-sm">Submit evidence</button>
        </form>
        <?php elseif (!empty($row['evidence_notes'])): ?>
        <p class="text-xs text-gray-400 mt-2">Submitted: <?= e($row['evidence_notes']) ?></p>
        <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
