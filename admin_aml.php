<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/risk.php';
requireSuperAdmin();
ensureAmlFlagsTable();
$db = getDB();
fixCorruptGatewaySettings();
fixCorruptPaymentLinks();
$threshold = getAmlHighValueThreshold();

$db->exec("INSERT IGNORE INTO aml_flags (merchant_id, transaction_id, flag_type, severity, description)
    SELECT t.merchant_id, t.id, 'high_value', 'high', CONCAT('Transaction above ₹', " . (int)$threshold . ")
    FROM transactions t
    LEFT JOIN aml_flags af ON af.transaction_id = t.id AND af.flag_type = 'high_value'
    WHERE t.amount >= " . (int)$threshold . " AND t.amount <= " . (int)livePaymentAmountCap() . " AND t.status = 'success' AND af.id IS NULL");

if (function_exists('syncKycPendingAmlFlags')) {
    syncKycPendingAmlFlags();
}

if (isset($_GET['action'], $_GET['id']) && verifyCsrf($_GET['token'] ?? '')) {
    $db->prepare("UPDATE aml_flags SET status = ? WHERE id = ?")->execute([$_GET['action'] === 'clear' ? 'cleared' : 'reviewed', (int)$_GET['id']]);
    flash('success', 'AML flag updated.');
    redirect('admin_aml.php');
}

$flags = $db->query('SELECT af.*, m.business_name, m.merchant_code, m.id AS merchant_row_id, t.txn_id, t.amount FROM aml_flags af JOIN merchants m ON af.merchant_id=m.id LEFT JOIN transactions t ON af.transaction_id=t.id ORDER BY FIELD(af.severity,"high","medium","low"), af.created_at DESC LIMIT 100')->fetchAll();
$monthVol = (float)$db->query("SELECT COALESCE(SUM(LEAST(amount, 1000)),0) FROM transactions WHERE status='success' AND MONTH(created_at)=MONTH(CURDATE())")->fetchColumn();
$stats = [
    'open' => (int)$db->query("SELECT COUNT(*) FROM aml_flags WHERE status='open'")->fetchColumn(),
    'high' => (int)$db->query("SELECT COUNT(*) FROM aml_flags WHERE severity='high' AND status='open'")->fetchColumn(),
    'kyc_pending' => (int)$db->query("SELECT COUNT(*) FROM merchants WHERE kyc_status NOT IN ('verified') AND status='active'")->fetchColumn(),
    'volume' => capStatAmount($monthVol),
];
$pageTitle = 'AML Compliance';
require_once __DIR__ . '/header.php';
?>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-6 sm:mb-8">
    <?php foreach ([['Open Flags',$stats['open'],'text-red-400'],['High Severity',$stats['high'],'text-red-400'],['KYC Pending',$stats['kyc_pending'],'text-amber-400'],['Month Volume',formatMoney($stats['volume']),'text-brand-400']] as [$l,$v,$c]): ?>
    <div class="stat-card border border-gray-800 rounded-xl p-3 sm:p-5 min-w-0"><p class="text-xs text-gray-500"><?= $l ?></p><p class="text-xl sm:text-2xl font-bold <?= $c ?> mt-1 break-words"><?= $v ?></p></div>
    <?php endforeach; ?>
</div>

<div class="glass rounded-xl p-4 sm:p-6 mb-6">
    <h2 class="font-semibold mb-2">RBI Compliance Checklist</h2>
    <div class="grid sm:grid-cols-2 gap-2 sm:gap-3 text-sm text-gray-400">
        <div>✓ KYC verification before live payments</div>
        <div>✓ High-value transaction monitoring (≥ <?= formatMoney($threshold) ?>)</div>
        <div>✓ Merchant entity type verification</div>
        <div>✓ Suspicious activity flagging & review</div>
        <div>✓ Transaction audit trail maintained</div>
        <div>✓ Settlement only to verified bank accounts</div>
    </div>
    <p class="text-xs text-gray-500 mt-4">Flags auto-open for high-value success txns and incomplete-KYC active merchants. Review or Clear from the table below.</p>
</div>

<div class="glass rounded-xl overflow-hidden min-w-0">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">AML Flags</h2></div>
    <div class="overflow-x-auto"><table class="min-w-[560px] w-full text-sm">
        <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
            <th class="px-5 py-3 text-left">Merchant</th><th class="px-5 py-3 text-left">Type</th>
            <th class="px-5 py-3 text-left">Severity</th><th class="px-5 py-3 text-left">Description</th>
            <th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3 text-left">Action</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-800">
            <?php if (empty($flags)): ?><tr><td colspan="6" class="px-5 py-12 text-center text-gray-500">No flags. System is clean.</td></tr>
            <?php else: foreach ($flags as $f): ?>
            <tr<?= uiRowClick(adminMerchantUrl((int)$f['merchant_row_id'])) ?>>
                <td class="px-5 py-3">
                    <a href="<?= e(adminMerchantUrl((int)$f['merchant_row_id'])) ?>" class="text-sky-400 hover:underline"><?= e($f['business_name']) ?></a>
                    <p class="text-xs text-gray-500"><?= adminMerchantLink((int)$f['merchant_row_id'], $f['merchant_code'], 'font-mono text-sky-400') ?></p>
                </td>
                <td class="px-5 py-3 capitalize text-xs"><?= str_replace('_',' ',$f['flag_type']) ?></td>
                <td class="px-5 py-3"><?= statusBadge($f['severity']) ?></td>
                <td class="px-5 py-3 text-xs text-gray-400"><?= e($f['description']) ?><?php if ($f['txn_id']): ?> — <?= txnDetailLink($f['txn_id'], $f['txn_id'], 'font-mono') ?> <?= formatMoney(capStatAmount((float)$f['amount'])) ?><?php endif; ?></td>
                <td class="px-5 py-3"><?= statusBadge($f['status']) ?></td>
                <td class="px-5 py-3 whitespace-nowrap"<?= uiStopClick() ?>>
                    <a href="admin_view_merchant.php?id=<?= (int)$f['merchant_row_id'] ?>" class="text-xs text-emerald-400 mr-2">View</a>
                    <?php if ($f['status'] === 'open'): ?>
                    <a href="?action=review&id=<?= $f['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-cyan-400 mr-2">Review</a>
                    <a href="?action=clear&id=<?= $f['id'] ?>&token=<?= csrfToken() ?>" class="text-xs text-brand-400">Clear</a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table></div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
