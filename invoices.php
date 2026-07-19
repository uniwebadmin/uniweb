<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
$db = getDB();
fixCorruptInvoices();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    $customer = trim($_POST['customer_name'] ?? '');
    $email = trim($_POST['customer_email'] ?? '');
    $phone = trim($_POST['customer_phone'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $tax = (float)($_POST['tax_amount'] ?? 0);
    $total = $amount + $tax;
    $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
    if ($customer && $amount > 0 && $amount <= 100000) {
        $invoiceId = generateId('INV');
        $items = json_encode([['description' => trim($_POST['description'] ?? 'Service'), 'amount' => $amount]]);
        $db->prepare('INSERT INTO invoices (invoice_id, merchant_id, customer_name, customer_email, customer_phone, amount, tax_amount, total_amount, items, status, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$invoiceId, $merchant['id'], $customer, $email, $phone, $amount, $tax, $total, $items, 'sent', $dueDate]);
        flash('success', 'Invoice created: ' . $invoiceId);
        redirect('invoices.php');
    }
}
$invoices = $db->prepare('SELECT * FROM invoices WHERE merchant_id = ? ORDER BY created_at DESC LIMIT 30');
$invoices->execute([$merchant['id']]);
$invoiceList = $invoices->fetchAll();
$pageTitle = 'Invoices';
require_once __DIR__ . '/header.php';
?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Create Invoice</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400">Customer Name *</label><input type="text" name="customer_name" required class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">Customer Email</label><input type="email" name="customer_email" class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">Customer Phone</label><input type="tel" name="customer_phone" maxlength="10" class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400">Description</label><input type="text" name="description" class="input-field mt-1" placeholder="Service / Product"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Amount (₹) *</label><input type="number" name="amount" required min="1" max="100000" step="0.01" class="input-field mt-1"></div>
                <div><label class="text-sm text-gray-400">Tax (₹)</label><input type="number" name="tax_amount" min="0" step="0.01" value="0" class="input-field mt-1"></div>
            </div>
            <div><label class="text-sm text-gray-400">Due Date</label><input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" class="input-field mt-1"></div>
            <button type="submit" class="w-full btn-primary py-3">Create Invoice</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800"><h2 class="font-semibold">Invoices</h2></div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[640px]">
            <thead class="text-xs text-gray-500 uppercase bg-dark-900/50"><tr>
                <th class="px-4 py-3 text-left">Invoice ID</th><th class="px-4 py-3 text-left">Customer</th>
                <th class="px-4 py-3 text-left">Total</th><th class="px-4 py-3 text-left">Status</th><th class="px-4 py-3 text-left">Due</th><th class="px-4 py-3 text-left whitespace-nowrap">PDF</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-800">
                <?php if (empty($invoiceList)): ?><tr><td colspan="6" class="px-5 py-8"><?= renderMerchantEmptyState('No invoices yet', 'Create your first invoice for a customer, or collect via Payment Pack links.', 'merchant_payment_pack.php', 'Payment Pack →') ?></td></tr>
                <?php else: foreach ($invoiceList as $inv):
                    $invTotal = capStatAmount((float)$inv['total_amount']);
                ?>
                <tr class="hover:bg-white/5">
                    <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">
                        <a href="invoice_view.php?id=<?= e(urlencode($inv['invoice_id'])) ?>" class="text-sky-400 hover:underline"><?= e($inv['invoice_id']) ?></a>
                    </td>
                    <td class="px-4 py-3"><?= e($inv['customer_name']) ?></td>
                    <td class="px-4 py-3 font-semibold whitespace-nowrap"><?= formatMoney($invTotal) ?></td>
                    <td class="px-4 py-3"><?= statusBadge($inv['status']) ?></td>
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '—' ?></td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <a href="invoice_pdf.php?id=<?= e(urlencode($inv['invoice_id'])) ?>" class="inline-block text-xs bg-brand-600/20 text-brand-400 px-3 py-1.5 rounded-lg hover:bg-brand-600/30">↓ PDF</a>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
