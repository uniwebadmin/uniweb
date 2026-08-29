<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/customer_portal.php';
ensureCustomerPortalSchema();

$txnId = trim($_GET['txn'] ?? '');
if ($txnId === '') {
    flash('error', 'Transaction ID required.');
    redirect('login.php');
}

$adminView = isAdminLoggedIn() && !isLoggedIn();
$merchant = null;
$merchantId = null;
$isCustomer = false;
$customerPhone = '';

if (!$adminView && isLoggedIn()) {
    $merchant = getMerchant();
    $merchantId = (int)$merchant['id'];
} elseif (!$adminView && isCustomerLoggedIn()) {
    $isCustomer = true;
    $customerPhone = currentCustomerPhone();
}

if (!$adminView && !$merchant && !$isCustomer) {
    flash('error', 'Please log in to view the receipt.');
    redirect('login.php');
}

$txn = null;
if ($adminView || $merchant) {
    $txn = fetchTransactionDetail($txnId, $merchantId, $adminView);
} elseif ($isCustomer) {
    $owned = findCustomerOwnedTransaction($customerPhone, $txnId);
    if ($owned) {
        $stmt = getDB()->prepare("SELECT t.*, m.business_name, m.merchant_code, m.email AS merchant_email, m.phone AS merchant_phone,
            m.collection_mode AS merchant_collection_mode, m.account_mode,
            pl.link_id, pl.description AS link_description, pl.customer_name AS link_customer_name,
            pl.customer_phone AS link_customer_phone, pl.payment_method AS link_payment_method,
            pl.gateway_code AS link_gateway, pl.link_label
            FROM transactions t
            JOIN merchants m ON t.merchant_id = m.id
            LEFT JOIN payment_links pl ON t.payment_link_id = pl.id
            WHERE t.txn_id = ? LIMIT 1");
        $stmt->execute([$txnId]);
        $txn = $stmt->fetch() ?: null;
    }
}

if (!$txn) {
    flash('error', 'Receipt not found or access denied.');
    redirect($isCustomer ? 'customer_portal.php' : 'transactions.php');
}

$status = strtolower((string)$txn['status']);
$paid = in_array($status, ['success', 'paid', 'captured'], true);
$method = ($isCustomer && function_exists('customerPaymentMethodLabel'))
    ? customerPaymentMethodLabel($txn['payment_method'] ?? '')
    : paymentMethodLabel($txn['payment_method'] ?? '');
$backUrl = $isCustomer ? 'customer_portal.php' : ($adminView ? 'admin_transactions.php' : 'transactions.php');

$pageTitle = 'Receipt ' . $txnId;
$hideNav = true;
$hideFooter = true;
require_once __DIR__ . '/header.php';
?>
<?= renderPagePrintStyles() ?>
<div class="min-h-screen flex items-start sm:items-center justify-center p-4 sm:p-8">
    <div class="w-full max-w-lg bg-white text-gray-900 rounded-2xl shadow-2xl overflow-hidden print:shadow-none">
        <div class="bg-gradient-to-r from-brand-600 to-cyan-600 p-6 text-white print:bg-gray-100 print:text-gray-900">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="<?= e(APP_URL) ?>/assets/img/uniweb-logo.svg" alt="<?= e(APP_NAME) ?>" class="w-10 h-10 rounded-lg bg-white p-1">
                    <div>
                        <p class="font-bold text-lg leading-none"><?= e(APP_NAME) ?></p>
                        <p class="text-[11px] opacity-90">Digital Payment Receipt</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[11px] uppercase tracking-wider opacity-80">Status</p>
                    <p class="font-bold <?= $paid ? 'text-emerald-200' : 'text-amber-200' ?> print:text-gray-900"><?= $paid ? 'PAID' : e(strtoupper($status)) ?></p>
                </div>
            </div>
        </div>
        <div class="p-6 sm:p-8 space-y-6">
            <div class="text-center">
                <p class="text-sm text-gray-500">Amount paid</p>
                <p class="text-4xl sm:text-5xl font-extrabold text-brand-600 mt-1"><?= formatMoney((float)$txn['amount']) ?></p>
                <?php if (!empty($txn['utr'])): ?>
                <p class="text-xs text-gray-500 mt-2 font-mono">UTR / Ref: <?= e($txn['utr']) ?></p>
                <?php endif; ?>
            </div>

            <div class="grid sm:grid-cols-2 gap-4 text-sm">
                <div>
                    <p class="text-xs text-gray-500 uppercase">Merchant</p>
                    <p class="font-semibold mt-0.5"><?= e($txn['business_name'] ?: 'Merchant') ?></p>
                    <p class="text-xs text-gray-500 font-mono"><?= e($txn['merchant_code'] ?? '') ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Customer</p>
                    <p class="font-semibold mt-0.5"><?= e($txn['customer_name'] ?: $txn['link_customer_name'] ?: 'Customer') ?></p>
                    <p class="text-xs text-gray-500"><?= e($txn['customer_phone'] ?? '—') ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Transaction ID</p>
                    <p class="font-mono text-xs mt-0.5 break-all"><?= e($txn['txn_id']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Date &amp; Time</p>
                    <p class="mt-0.5"><?= formatDate($txn['created_at']) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Payment Method</p>
                    <p class="mt-0.5"><?= e($method) ?></p>
                </div>
                <div>
                    <p class="text-xs text-gray-500 uppercase">Payment Link</p>
                    <p class="font-mono text-xs mt-0.5 break-all"><?= e($txn['link_id'] ?? '—') ?></p>
                </div>
                <?php if (!empty($txn['description']) || !empty($txn['link_description'])): ?>
                <div class="sm:col-span-2">
                    <p class="text-xs text-gray-500 uppercase">Description</p>
                    <p class="mt-0.5"><?= e($txn['description'] ?? $txn['link_description'] ?? '') ?></p>
                </div>
                <?php endif; ?>
            </div>

            <div class="border-t border-gray-200 pt-5 text-center">
                <p class="text-xs text-gray-500">This is a computer-generated receipt. For queries, contact the merchant or <?= e(COMPANY_LEGAL_NAME) ?> support.</p>
            </div>

            <div class="flex flex-wrap justify-center gap-3 no-print">
                <button type="button" onclick="window.print()" class="bg-brand-600 hover:bg-brand-500 text-white px-5 py-2.5 rounded-xl font-semibold text-sm transition">Print / Save as PDF</button>
                <a href="<?= e($backUrl) ?>" class="inline-block border border-gray-300 hover:bg-gray-100 text-gray-700 px-5 py-2.5 rounded-xl font-semibold text-sm transition">Back</a>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/footer.php'; ?>
