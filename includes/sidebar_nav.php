<?php
declare(strict_types=1);

/**
 * Single source for merchant/admin sidebar pages.
 * header.php renders these groups; global_search.php jumps to the same URLs.
 */

function uniwebMerchantHiddenUrls(): array
{
    return [];
}

function uniwebAdminHiddenUrls(): array
{
    return [];
}

function uniwebMerchantNavGroups(): array
{
    $t = static function (string $key, string $fallback) {
        return function_exists('__') ? __($key) : $fallback;
    };
    return [
        ['id' => 'overview', 'title' => 'Overview', 'items' => [
            ['dashboard.php', $t('dashboard', 'Dashboard'), 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ]],
        ['id' => 'collect', 'title' => 'Collect / P2M', 'items' => [
            ['payment_links.php', $t('payment_links', 'Payment Links'), 'M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244'],
            ['qr_code.php', $t('qr_code', 'QR Code'), 'M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z'],
            ['qr_upi_print.php', 'Instant UPI QR', 'M4 4h6v6H4V4zm10 0h6v6h-6V4zM4 14h6v6H4v-6zm10 3h2v2h-2v-2zm3 0h3v2h-3v-2z'],
            ['payment_methods.php', 'Payment Methods', 'M11 3.055A5.001 5.001 0 005.055 9 5.001 5.001 0 0011 14.945 5.001 5.001 0 0016.945 9 5.001 5.001 0 0011 3.055z'],
            ['orders.php', 'Orders', 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
            ['merchant_payment_pack.php', 'Payment Pack', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
        ]],
        ['id' => 'payments', 'title' => 'Payments', 'items' => [
            ['transactions.php', $t('transactions', 'Transactions'), 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
            ['refunds.php', 'Refunds', 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6'],
            ['reports.php', $t('reports', 'Reports'), 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['disputes.php', $t('nav_disputes', 'Disputes'), 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
            ['chargebacks.php', 'Chargebacks', 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ]],
        ['id' => 'settlements', 'title' => 'Settlements', 'items' => [
            ['wallet.php', 'Settlement Balance', 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ['settlements.php', $t('settlements', 'Settlements'), 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
            ['add_bank.php', 'Settlement Bank', 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            ['merchant_instant_settlement.php', 'Instant Settlement', 'M13 10V3L4 14h7v7l9-11h-7z'],
            ['merchant_payout.php', 'Payouts', 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
            ['merchant_payout_keys.php', 'Payout API Keys', 'M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z'],
            ['merchant_settlement_settings.php', 'Settlement Settings', 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['beneficiaries.php', 'Beneficiaries', 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
            ['merchant_recurring.php', 'Recurring & Mandates', 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
        ]],
        ['id' => 'kyc', 'title' => 'KYC', 'items' => [
            ['kyc.php', $t('kyc', 'KYC'), 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            ['merchant_shop_photos.php', 'Shop Photos', 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z'],
        ]],
        ['id' => 'team', 'title' => 'Team & Customers', 'items' => [
            ['merchant_team.php', 'Team', 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['invoices.php', $t('invoices', 'Invoices'), 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['agents.php', $t('agents', 'Agents'), 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z'],
            ['merchant_customer_tickets.php', 'Customer Complaints', 'M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z'],
        ]],
        ['id' => 'tools', 'title' => 'Tools / Settings', 'items' => [
            ['checkout_customize.php', 'Checkout Customize', 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
            ['api_settings.php', $t('api_settings', 'API Settings'), 'M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ['merchant_notify_settings.php', 'Notification Settings', 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
            ['merchant_agreement.php', 'Agreement', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            ['merchant_settings.php', $t('nav_settings', 'Settings'), 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
            ['merchant_2fa.php', '2FA Security', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            ['notifications.php', $t('nav_notifications', 'Notifications'), 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9'],
            ['merchant_website.php', 'Sales Website', 'M3 5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2h-5l-3 3-3-3H5a2 2 0 01-2-2V5z'],
            ['collection_settings.php', $t('nav_collection_mode', 'Collection Mode'), 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
            ['qr_analytics.php', 'QR Analytics', 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            ['merchant_launch.php', 'Launch Center', 'M13 10V3L4 14h7v7l9-11h-7z'],
            ['support.php', $t('support', 'Support'), 'M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M5.636 5.636l3.536 3.536m0 5.656l-3.536 3.536M12 2.944l7.07 7.07a10 10 0 010 14.142L12 22.93l-7.07-7.07a10 10 0 010-14.142L12 2.944z'],
        ]],
    ];
}

function uniwebAdminNavGroups(): array
{
    return [
        ['id' => 'dashboard', 'title' => 'Dashboard', 'items' => [
            ['admin_dashboard.php', 'Overview'],
        ]],
        ['id' => 'merchants', 'title' => 'Merchants & KYC', 'items' => [
            ['manage_merchant.php', 'All Merchants'],
            ['add_merchant.php', 'Add Merchant'],
            ['admin_kyc.php', 'KYC Review'],
            ['admin_onboarding_invite.php', 'Onboarding Invites'],
            ['admin_website_reviews.php', 'Website Reviews'],
        ]],
        ['id' => 'partners', 'title' => 'Partners', 'items' => [
            ['admin_gateway_registry.php', 'Partner Registry'],
            ['gateway_settings.php', 'Platform Settings'],
            ['admin_method_requests.php', 'Method Requests'],
            ['admin_forward_queue.php', 'KYC Forward Queue'],
        ]],
        ['id' => 'payments', 'title' => 'Transactions & Refunds', 'items' => [
            ['admin_transactions.php', 'Transactions'],
            ['admin_payment_links.php', 'Payment Links'],
            ['admin_qr_codes.php', 'QR Codes'],
            ['admin_refunds.php', 'Refunds'],
            ['admin_disputes.php', 'Disputes'],
        ]],
        ['id' => 'settlements', 'title' => 'Settlements & Payouts', 'items' => [
            ['admin_settlements.php', 'Settlements'],
            ['admin_bulk_payout.php', 'Bulk Payout'],
            ['admin_payout.php', 'Payout Requests'],
        ]],
        ['id' => 'support', 'title' => 'Support', 'items' => [
            ['admin_support.php', 'Support Tickets'],
        ]],
        ['id' => 'ops', 'title' => 'Ops', 'items' => [
            ['admin_platform_status.php', 'Platform Status + Cron Jobs'],
            ['admin_watchdog.php', 'Link Watchdog'],
            ['admin_error_log.php', 'Error Log'],
        ]],
        ['id' => 'staff', 'title' => 'Staff', 'items' => [
            ['admin_manage_staff.php', 'Staff / Employees'],
        ]],
        ['id' => 'advanced_risk', 'title' => 'Advanced · Risk', 'collapsed' => true, 'items' => [
            ['admin_aml.php', 'AML Compliance'],
            ['admin_risk.php', 'Risk Rules'],
            ['admin_risk_engine.php', 'Risk Engine'],
            ['admin_chargebacks.php', 'Chargebacks'],
            ['admin_circuit_breaker.php', 'Circuit Breaker'],
            ['admin_grievance.php', 'Grievance Officer'],
            ['admin_incidents.php', 'Incidents'],
        ]],
        ['id' => 'advanced_money', 'title' => 'Advanced · Money', 'collapsed' => true, 'items' => [
            ['admin_financial_reports.php', 'Reports'],
            ['admin_reconciliation.php', 'PG Reconciliation'],
            ['admin_bank_reconciliation.php', 'Bank Reconciliation'],
            ['admin_settlement_settings.php', 'Settlement Engine'],
            ['admin_settlement_batches.php', 'Settlement Batches'],
            ['admin_rolling_reserve.php', 'Rolling Reserve'],
            ['admin_bank_holidays.php', 'Bank Holidays'],
            ['admin_wallet.php', 'Platform Bank Account'],
            ['admin_platform_wallet.php', 'Platform Fee Ledger'],
            ['admin_nodal_accounts.php', 'Nodal Accounts'],
            ['admin_ledger_state.php', 'Ledger State Machine'],
            ['admin_virtual_accounts.php', 'Virtual Accounts'],
        ]],
        ['id' => 'advanced_ops', 'title' => 'Advanced · Ops', 'collapsed' => true, 'items' => [
            ['admin_sub_merchants.php', 'Sub-Merchant Hierarchy'],
            ['admin_merchant_health.php', 'Merchant Health'],
            ['admin_customer_view.php', 'Customer Lookup'],
            ['admin_customer_tickets.php', 'Customer Complaints'],
            ['admin_reason_map.php', 'Reason Maps'],
            ['admin_auto_kyc.php', 'Auto KYC Engine'],
            ['admin_gateway_submit.php', 'KYC Submissions'],
            ['admin_integration_matrix.php', 'Integration Status Board'],
            ['admin_gateway_matrix.php', 'Gateway Routing Matrix'],
            ['admin_gateway_health.php', 'Gateway Health'],
            ['admin_partner_requests.php', 'Partner Requests'],
            ['admin_partner_commercial.php', 'Partner Commercial'],
            ['admin_pg_webhooks.php', 'PG Webhooks'],
            ['admin_webhook_reliability.php', 'Webhook Reliability'],
            ['admin_transaction_monitor.php', 'Transaction Monitor'],
            ['admin_audit_plan.php', 'Deep Audit Plan'],
            ['admin_website.php', 'Website & API Keys'],
        ]],
        ['id' => 'advanced_security', 'title' => 'Advanced · Security', 'collapsed' => true, 'items' => [
            ['admin_audit_log.php', 'Audit Log'],
            ['admin_encrypt_pii.php', 'Encrypt PII Backfill'],
            ['admin_staff_activity.php', 'Staff Activity Log'],
            ['admin_security.php', 'Security & Password'],
            ['admin_security_hardening.php', 'Security Hardening'],
        ]],
    ];
}

function uniwebFlattenNavPages(array $groups, array $hidden = []): array
{
    $out = [];
    $seen = [];
    foreach ($groups as $group) {
        foreach ($group['items'] ?? [] as $item) {
            $url = (string)($item[0] ?? '');
            $label = (string)($item[1] ?? $url);
            if ($url === '' || isset($seen[$url]) || in_array($url, $hidden, true)) {
                continue;
            }
            $seen[$url] = true;
            $out[] = [$url, $label];
        }
    }
    return $out;
}

function uniwebMerchantSearchPages(): array
{
    $pages = uniwebFlattenNavPages(uniwebMerchantNavGroups(), uniwebMerchantHiddenUrls());
    $extras = [
        ['my_account.php', 'My Account'],
        ['security.php', 'Security'],
    ];
    $have = array_column($pages, 0);
    foreach ($extras as $row) {
        if (!in_array($row[0], $have, true)) {
            $pages[] = $row;
        }
    }
    return $pages;
}

function uniwebAdminSearchPages(): array
{
    return uniwebFlattenNavPages(uniwebAdminNavGroups(), uniwebAdminHiddenUrls());
}

/** TXN / LNK / STL / GSTIN-style prefixes — short queries are allowed. */
function uniwebSearchIdPrefix(string $q): ?string
{
    $u = strtoupper(preg_replace('/\s+/', '', $q) ?? '');
    foreach (['PACK', 'PWL', 'TXN', 'LNK', 'STL', 'RFD', 'DSP', 'TKT', 'INV', 'ORD', 'BAT', 'CT', 'UW'] as $prefix) {
        if (str_starts_with($u, $prefix)) {
            return $prefix;
        }
    }
    return null;
}
