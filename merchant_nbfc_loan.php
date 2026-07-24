<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/nbfc.php';
requireLogin();

$merchant = getMerchant();
$merchantId = (int)$merchant['id'];
ensureNbfcSchema();

$loanRef = trim((string)($_GET['ref'] ?? ''));
$loan = null;
if ($loanRef !== '') {
    $loan = getNbfcLoanByRef($loanRef, $merchantId);
}
if (!$loan) {
    $loans = listNbfcLoansForMerchant($merchantId);
    $loan = $loans[0] ?? null;
}
$schedule = $loan ? getNbfcEmiSchedule((int)$loan['id']) : [];
$live = nbfcLiveDisburseAllowed();

$pageTitle = 'NBFC Loan & EMI';
require_once __DIR__ . '/header.php';
?>
<div class="max-w-3xl space-y-6">
    <div class="glass rounded-xl p-6">
        <h1 class="text-xl font-bold mb-2">NBFC Loan &amp; EMI</h1>
        <p class="text-sm text-gray-500 mb-4">After an application is approved, your loan ledger and EMI schedule appear here. Live disbursement still needs partner keys.</p>

        <?php if (!$loan): ?>
        <p class="text-sm text-amber-300">No loan yet. Submit an application on NBFC Finance — when admin/partner approves, EMI schedule is created automatically.</p>
        <a href="merchant_nbfc.php" class="inline-block mt-4 text-sm text-sky-400">← NBFC Finance</a>
        <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-3 text-sm mb-4">
            <div class="rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Loan ID</p>
                <p class="font-mono text-sky-400"><?= e($loan['loan_ref']) ?></p>
            </div>
            <div class="rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Status</p>
                <p class="font-semibold <?= ($loan['status'] ?? '') === 'active' ? 'text-emerald-400' : 'text-amber-300' ?>"><?= e(ucfirst((string)$loan['status'])) ?></p>
            </div>
            <div class="rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">Principal</p>
                <p class="font-semibold"><?= formatMoney((float)$loan['principal']) ?></p>
            </div>
            <div class="rounded-xl border border-gray-800 p-4">
                <p class="text-xs text-gray-500">EMI (est. <?= e((string)$loan['interest_rate_pa']) ?>% p.a.)</p>
                <p class="font-semibold"><?= formatMoney((float)$loan['emi_amount']) ?> × <?= (int)$loan['tenure_months'] ?> months</p>
            </div>
        </div>
        <p class="text-xs <?= $live ? 'text-emerald-400' : 'text-amber-300' ?> mb-4">
            <?= $live
                ? 'Live disbursement switch is ON — partner rail can move money when configured.'
                : 'Ledger ready. Paisa transfer waits for partner keys + nbfc_live_enabled.' ?>
        </p>
        <?php endif; ?>
    </div>

    <?php if ($loan && $schedule): ?>
    <div class="glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">EMI schedule</h2></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm min-w-[520px]">
                <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                    <th class="px-5 py-3 text-left">#</th>
                    <th class="px-5 py-3 text-left">Due date</th>
                    <th class="px-5 py-3 text-left">Amount</th>
                    <th class="px-5 py-3 text-left">Status</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-800">
                    <?php foreach ($schedule as $emi): ?>
                    <tr>
                        <td class="px-5 py-3"><?= (int)$emi['installment_no'] ?></td>
                        <td class="px-5 py-3"><?= e(formatDate($emi['due_date'])) ?></td>
                        <td class="px-5 py-3 font-semibold"><?= formatMoney((float)$emi['amount']) ?></td>
                        <td class="px-5 py-3 text-xs text-gray-400"><?= e(ucfirst((string)$emi['status'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php
    $allLoans = listNbfcLoansForMerchant($merchantId);
    if (count($allLoans) > 1):
    ?>
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-3">All loans</h2>
        <ul class="space-y-2 text-sm">
            <?php foreach ($allLoans as $l): ?>
            <li><a class="text-sky-400 font-mono" href="merchant_nbfc_loan.php?ref=<?= urlencode((string)$l['loan_ref']) ?>"><?= e($l['loan_ref']) ?></a>
                — <?= formatMoney((float)$l['principal']) ?> · <?= e((string)$l['status']) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <a href="merchant_nbfc.php" class="inline-block text-sm text-sky-400">← NBFC Finance</a>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
