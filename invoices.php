<?php
require_once __DIR__ . '/config.php';
requireLogin();
$merchant = getMerchant();
ensureInvoiceSchema();
$db = getDB();
fixCorruptInvoices();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf($_POST['csrf_token'] ?? '')) {
    requireMerchantTeamCapability('create_links');
    $customer = trim($_POST['customer_name'] ?? '');
    $email = trim($_POST['customer_email'] ?? '');
    $phone = trim($_POST['customer_phone'] ?? '');
    $address = trim($_POST['customer_address'] ?? '');
    $amount = (float)($_POST['amount'] ?? 0);
    $taxPct = (float)($_POST['tax_percent'] ?? 0);
    if (!in_array((int)$taxPct, [0, 12, 18, 28], true)) {
        $taxPct = 0;
    }
    $tax = round($amount * ($taxPct / 100), 2);
    $total = round($amount + $tax, 2);
    $dueDate = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
    if ($customer && $amount > 0 && $amount <= 100000) {
        $invoiceId = generateId('INV');
        $items = json_encode([['description' => trim($_POST['description'] ?? 'Service'), 'amount' => $amount]]);
        try {
            $db->prepare('INSERT INTO invoices (invoice_id, merchant_id, customer_name, customer_email, customer_phone, customer_address, amount, tax_amount, total_amount, items, status, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$invoiceId, $merchant['id'], $customer, $email, $phone, $address ?: null, $amount, $tax, $total, $items, 'sent', $dueDate]);
        } catch (Throwable $e) {
            // Graceful fallback if customer_address column is not yet applied.
            $db->prepare('INSERT INTO invoices (invoice_id, merchant_id, customer_name, customer_email, customer_phone, amount, tax_amount, total_amount, items, status, due_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$invoiceId, $merchant['id'], $customer, $email, $phone, $amount, $tax, $total, $items, 'sent', $dueDate]);
        }
        flash('success', 'Invoice created: ' . $invoiceId);
        redirect('invoices.php');
    }
}
$listParams = listPageParams(20);
$page = $listParams['page'];
$perPage = $listParams['perPage'];
$offset = $listParams['offset'];
$q = $listParams['q'];
$statusFilter = trim($_GET['status'] ?? 'all');
$invWhere = 'merchant_id = ?';
$invParams = [$merchant['id']];
if ($q !== '') {
    $like = '%' . strtolower($q) . '%';
    $invWhere .= " AND (LOWER(invoice_id) LIKE ? OR LOWER(customer_name) LIKE ? OR LOWER(COALESCE(customer_email,'')) LIKE ? OR LOWER(COALESCE(customer_phone,'')) LIKE ? OR CAST(total_amount AS CHAR) LIKE ?)";
    array_push($invParams, $like, $like, $like, $like, $like);
}
if (in_array($statusFilter, ['sent', 'paid', 'overdue', 'cancelled'], true)) {
    $invWhere .= ' AND status = ?';
    $invParams[] = $statusFilter;
}
$countStmt = $db->prepare("SELECT COUNT(*) FROM invoices WHERE {$invWhere}");
$countStmt->execute($invParams);
$invTotal = (int)$countStmt->fetchColumn();
$invoices = $db->prepare("SELECT * FROM invoices WHERE {$invWhere} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
$invoices->execute($invParams);
$invoiceList = $invoices->fetchAll();
$pageTitle = 'Invoices';
require_once __DIR__ . '/header.php';
echo renderPrintStylesheet();
?>
<div class="grid lg:grid-cols-3 gap-6">
    <div class="glass rounded-xl p-6">
        <h2 class="font-semibold mb-4">Create Invoice</h2>
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <div><label class="text-sm text-gray-400" for="inv-customer">Customer Name *</label><input id="inv-customer" type="text" name="customer_name" required class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400" for="inv-email">Customer Email</label><input id="inv-email" type="email" name="customer_email" class="input-field mt-1"></div>
            <div><label class="text-sm text-gray-400" for="inv-phone">Customer Mobile</label><input id="inv-phone" type="tel" name="customer_phone" maxlength="10" class="input-field mt-1" placeholder="10-digit mobile"></div>
            <div><label class="text-sm text-gray-400" for="inv-address">Customer Address</label><textarea id="inv-address" name="customer_address" rows="2" class="input-field mt-1" placeholder="Full billing address"></textarea></div>
            <div><label class="text-sm text-gray-400" for="inv-desc">Description</label><input id="inv-desc" type="text" name="description" class="input-field mt-1" placeholder="Service / Product"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-sm text-gray-400">Amount (₹) *</label><input type="number" name="amount" required min="1" max="100000" step="0.01" class="input-field mt-1"></div>
                <div>
                    <label class="text-sm text-gray-400">Tax %</label>
                    <select name="tax_percent" class="input-field mt-1">
                        <option value="0">0% (no tax)</option>
                        <option value="12">12% GST</option>
                        <option value="18" selected>18% GST</option>
                        <option value="28">28% GST</option>
                    </select>
                </div>
            </div>
            <p class="text-xs text-gray-500">Tax is calculated as a % of amount (saved as ₹ on the invoice PDF).</p>
            <div><label class="text-sm text-gray-400">Due Date</label><input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+7 days')) ?>" class="input-field mt-1"></div>
            <p class="text-xs text-gray-500">PDF includes your GSTIN, business name, address, mobile and email from My Account, plus this invoice number.</p>
            <button type="submit" class="w-full btn-primary py-3">Create Invoice</button>
        </form>
    </div>
    <div class="lg:col-span-2 glass rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-800 flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">Invoices</h2>
            <?= renderExportCsvLink('export_invoices.php?' . http_build_query(['q' => $q, 'status' => $statusFilter])) ?>
        </div>
        <form method="GET" class="px-6 py-3 border-b border-gray-800 flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[160px]"><label class="text-[10px] text-gray-600 uppercase" for="inv-q">Search</label><input id="inv-q" type="search" name="q" value="<?= e($q) ?>" placeholder="Invoice ID / customer / amount" class="input-field mt-1 text-sm"></div>
            <div><label class="text-[10px] text-gray-600 uppercase" for="inv-status">Status</label><select id="inv-status" name="status" class="input-field mt-1 text-sm"><option value="all">All</option><?php foreach (['sent','paid','overdue','cancelled'] as $st): ?><option value="<?= $st ?>" <?= $statusFilter===$st?'selected':'' ?>><?= ucfirst($st) ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn-primary text-sm px-4 py-2">Filter</button>
        </form>
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
        <?= renderListPagination($page, $invTotal, $perPage, ['q' => $q, 'status' => $statusFilter]) ?>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
