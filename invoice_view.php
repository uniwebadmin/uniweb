<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureInvoiceSchema();
$id = trim($_GET['id'] ?? '');
$stmt = getDB()->prepare('SELECT * FROM invoices WHERE invoice_id = ? AND merchant_id = ?');
$stmt->execute([$id, $merchant['id']]);
$inv = $stmt->fetch();
if (!$inv) {
    flash('error', 'Invoice not found.');
    redirect('invoices.php');
}
require_once __DIR__ . '/lib/SimpleInvoicePdf.php';
$total = capStatAmount((float)$inv['total_amount']);
$merchantAddr = SimpleInvoicePdf::merchantFullAddress($merchant);
$pageTitle = 'Invoice ' . $inv['invoice_id'];
require_once __DIR__ . '/header.php';
?>
<div class="max-w-2xl mx-auto space-y-6">
    <a href="invoices.php" class="text-sm text-brand-400 hover:text-brand-300">← Back to Invoices</a>
    <div class="glass rounded-xl p-6">
        <div class="flex flex-wrap justify-between gap-3 mb-6">
            <div>
                <p class="text-xs text-gray-500 uppercase tracking-wide">Invoice number</p>
                <p class="font-mono text-sky-400 text-sm mt-0.5"><?= e($inv['invoice_id']) ?></p>
                <h1 class="text-xl font-bold mt-2"><?= e($inv['customer_name']) ?></h1>
            </div>
            <?= statusBadge($inv['status']) ?>
        </div>

        <div class="grid sm:grid-cols-2 gap-6 text-sm mb-6">
            <div class="space-y-2">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Bill From</p>
                <p class="font-medium"><?= e($merchant['business_name'] ?: '—') ?></p>
                <p class="text-gray-400"><?= e($merchant['name'] ?? '') ?></p>
                <p>GSTIN: <?= e($merchant['gstin'] ?: 'Not provided') ?></p>
                <p>Email: <?= e($merchant['email'] ?: '—') ?></p>
                <p>Mobile: <?= e($merchant['phone'] ?: '—') ?></p>
                <p class="text-gray-400"><?= e($merchantAddr ?: 'Address not provided') ?></p>
            </div>
            <div class="space-y-2">
                <p class="text-xs text-gray-500 uppercase tracking-wide">Bill To</p>
                <p class="font-medium"><?= e($inv['customer_name']) ?></p>
                <p>Email: <?= e($inv['customer_email'] ?: '—') ?></p>
                <p>Mobile: <?= e($inv['customer_phone'] ?: '—') ?></p>
                <p class="text-gray-400"><?= e(($inv['customer_address'] ?? '') ?: 'Address not provided') ?></p>
            </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-4 text-sm mb-6 border-t border-gray-800 pt-4">
            <div><span class="text-gray-500">Amount</span><p><?= formatMoney(capStatAmount((float)$inv['amount'])) ?></p></div>
            <div><span class="text-gray-500">Tax</span><p><?= formatMoney(capStatAmount((float)$inv['tax_amount'])) ?></p></div>
            <div><span class="text-gray-500">Total</span><p class="text-lg font-bold text-brand-400"><?= formatMoney($total) ?></p></div>
            <div><span class="text-gray-500">Due Date</span><p><?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></p></div>
        </div>
        <div class="flex flex-wrap gap-3">
            <a href="invoice_pdf.php?id=<?= e(urlencode($inv['invoice_id'])) ?>" class="btn-primary px-5 py-2.5">↓ Download PDF</a>
            <?php if (empty($merchant['gstin']) || $merchantAddr === ''): ?>
            <a href="my_account.php" class="text-sm text-amber-400 self-center hover:underline">Complete GST / address in My Account →</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
