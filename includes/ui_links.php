<?php
declare(strict_types=1);

/** Clickable rows & admin deep-links — Razorpay/Cashfree-style UX */

function adminMerchantUrl(int $merchantId): string
{
    return 'admin_view_merchant.php?id=' . max(0, $merchantId);
}

function adminMerchantEditUrl(int $merchantId): string
{
    return 'admin_edit_merchant.php?id=' . max(0, $merchantId);
}

function adminMerchantTransactionsUrl(int $merchantId): string
{
    return 'admin_transactions.php?merchant_id=' . max(0, $merchantId);
}

function adminMerchantRefundsUrl(int $merchantId): string
{
    return 'admin_refunds.php?merchant_id=' . max(0, $merchantId);
}

function adminMerchantApiUrl(int $merchantId): string
{
    return adminMerchantEditUrl($merchantId) . '#api-keys';
}

function adminMerchantWebsiteUrl(int $merchantId): string
{
    return adminMerchantEditUrl($merchantId) . '#website';
}

function merchantWhatsAppUrl(?string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }
    return strlen($digits) >= 10 ? 'https://wa.me/' . $digits : null;
}

function merchantMailtoLink(string $email, ?string $label = null, string $class = 'text-sky-400 hover:underline'): string
{
    $label = $label ?? $email;
    if ($email === '') {
        return e($label);
    }
    return '<a href="mailto:' . e($email) . '" class="' . e($class) . '">' . e($label) . '</a>';
}

function adminMerchantKycUrl(int $merchantId): string
{
    if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()) {
        if (function_exists('staffCanEditMerchant') && staffCanEditMerchant()) {
            return adminMerchantEditUrl($merchantId) . '#kyc';
        }
        if (function_exists('staffCanAccess') && staffCanAccess('admin_kyc.php')) {
            return 'admin_kyc.php';
        }
    }
    return adminMerchantUrl($merchantId);
}

function adminMerchantModeUrl(int $merchantId): string
{
    if (function_exists('isAdminLoggedIn') && isAdminLoggedIn()
        && function_exists('staffCanEditMerchant') && staffCanEditMerchant()) {
        return adminMerchantEditUrl($merchantId);
    }
    return adminMerchantUrl($merchantId);
}

function uiRowClick(string $href): string
{
    return ' class="hover:bg-white/5 cursor-pointer" onclick="location.href=\'' . e($href) . '\'"';
}

function uiStopClick(): string
{
    return ' onclick="event.stopPropagation()"';
}

function adminMerchantLink(int $merchantId, string $label, string $class = 'text-sky-400 hover:underline'): string
{
    if ($merchantId <= 0) {
        return e($label);
    }
    return '<a href="' . e(adminMerchantUrl($merchantId)) . '" class="' . e($class) . '">' . e($label) . '</a>';
}

function txnDetailLink(string $txnId, ?string $label = null, string $class = 'text-sky-400 hover:underline'): string
{
    if (function_exists('uwIdLink')) {
        return uwIdLink($txnId, $label, $class);
    }
    $label = $label ?? $txnId;
    return '<a href="' . e(transactionDetailUrl($txnId)) . '" class="' . e($class) . '">' . e($label) . '</a>';
}

/** Mask payer identity on merchant-facing lists (privacy). */
if (!function_exists('maskCustomerContact')) {
    function maskCustomerContact(?string $phone, ?string $name = null): string
    {
        $phone = trim((string)$phone);
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (strlen($digits) >= 4) {
            return '••••' . substr($digits, -4);
        }
        $name = trim((string)$name);
        if ($name !== '') {
            $first = function_exists('mb_substr') ? mb_substr($name, 0, 1) : substr($name, 0, 1);
            return $first . '***';
        }
        return '—';
    }
}

function getAdminLinkAuditRules(): array
{
    return [
        ['page' => 'manage_merchant.php', 'label' => 'All Merchants', 'row' => '— (per column)', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'manage_merchant.php', 'label' => 'All Merchants — email', 'row' => '—', 'code' => 'mailto:', 'name' => '—'],
        ['page' => 'manage_merchant.php', 'label' => 'All Merchants — refunds', 'row' => '—', 'code' => 'admin_refunds.php?merchant_id=', 'name' => '—'],
        ['page' => 'admin_dashboard.php', 'label' => 'Dashboard — New Merchants', 'row' => 'admin_view_merchant.php', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_dashboard.php', 'label' => 'Dashboard — Recent Txns', 'row' => 'transaction_detail.php', 'code' => 'transaction_detail.php', 'name' => 'transaction_detail.php'],
        ['page' => 'admin_transactions.php', 'label' => 'Transactions', 'row' => 'transaction_detail.php', 'code' => 'transaction_detail.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_settlements.php', 'label' => 'Settlements', 'row' => 'admin_view_merchant.php', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_kyc.php', 'label' => 'KYC Review', 'row' => 'admin_view_merchant.php', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_gateway_submit.php', 'label' => 'Gateway Submit', 'row' => 'admin_view_merchant.php', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_refunds.php', 'label' => 'Refunds', 'row' => 'transaction_detail.php', 'code' => 'transaction_detail.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_disputes.php', 'label' => 'Disputes', 'row' => 'transaction_detail.php', 'code' => 'transaction_detail.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_aml.php', 'label' => 'AML Flags', 'row' => 'admin_view_merchant.php', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_support.php', 'label' => 'Support Tickets', 'row' => 'admin_view_merchant.php', 'code' => '—', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_staff_activity.php', 'label' => 'Staff Activity', 'row' => 'admin_view_merchant.php', 'code' => '—', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_website.php', 'label' => 'Website & API Keys — merchant row', 'row' => '— (per column)', 'code' => 'admin_view_merchant.php', 'name' => 'admin_view_merchant.php'],
        ['page' => 'admin_website.php', 'label' => 'Website & API Keys — mode column', 'row' => '—', 'code' => 'admin_edit_merchant.php', 'name' => '—'],
        ['page' => 'admin_website.php', 'label' => 'Website & API Keys — API key columns', 'row' => '—', 'code' => 'admin_edit_merchant.php#api-keys', 'name' => '—'],
        ['page' => 'admin_website.php', 'label' => 'Website & API Keys — gateway row', 'row' => 'gateway_settings.php', 'code' => 'gateway_settings.php', 'name' => '—'],
        ['page' => 'transactions.php', 'label' => 'Merchant Transactions', 'row' => 'transaction_detail.php', 'code' => 'transaction_detail.php', 'name' => '—'],
        ['page' => 'payment_links.php', 'label' => 'Payment Links', 'row' => 'checkout.php', 'code' => 'checkout.php', 'name' => '—'],
    ];
}

function getRecentMerchantsForAudit(int $limit = 15): array
{
    $db = getDB();
    $stmt = $db->prepare("SELECT id, merchant_code, business_name, name, email, phone, business_entity_type, kyc_status, account_mode, status, created_at
        FROM merchants WHERE status != 'deleted' ORDER BY created_at DESC LIMIT ?");
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}
