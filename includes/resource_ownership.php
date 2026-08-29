<?php
declare(strict_types=1);

/**
 * Server-side resource ownership — customer, merchant, staff scopes.
 * Always load by id → compare owner → deny without leaking existence where practical.
 */

if (!function_exists('findCustomerOwnedTransaction') && is_file(__DIR__ . '/customer_portal.php')) {
    require_once __DIR__ . '/customer_portal.php';
}

/** Generic safe denial copy — no stack traces, no other-user hints. */
function resourceOwnershipDeniedMessage(string $scope = 'resource'): string
{
    return match ($scope) {
        'payment' => 'Payment not found or you do not have access to it.',
        'ticket' => 'Complaint not found or you do not have access to it.',
        'merchant' => 'Resource not found or access denied.',
        default => 'Not found or access denied.',
    };
}

/**
 * Customer scope — txn must match session phone (txn or payment-link phone).
 * @return array<string,mixed>|null
 */
function customerMustOwnTransaction(string $phone, string $txnId): ?array
{
    $txnId = trim($txnId);
    if ($txnId === '') {
        return null;
    }
    if (!function_exists('findCustomerOwnedTransaction')) {
        return null;
    }
    return findCustomerOwnedTransaction($phone, $txnId);
}

/**
 * Customer payment row for track/receipt — ownership enforced before fetch.
 * @return array<string,mixed>|null
 */
function fetchCustomerPaymentIfOwned(string $phone, string $txnId): ?array
{
    if (customerMustOwnTransaction($phone, $txnId) === null) {
        return null;
    }
    if (!function_exists('fetchPaymentStatusTransaction')) {
        return null;
    }
    return fetchPaymentStatusTransaction($txnId);
}

/**
 * Merchant scope — txn must belong to merchant_id.
 * @return array<string,mixed>|null
 */
function merchantMustOwnTransaction(int $merchantId, string $txnId): ?array
{
    if ($merchantId <= 0 || trim($txnId) === '') {
        return null;
    }
    if (!function_exists('fetchTransactionDetail')) {
        require_once __DIR__ . '/transaction_detail.php';
    }
    $row = fetchTransactionDetail(trim($txnId), $merchantId, false);
    return $row ?: null;
}

/** @return never */
function resourceOwnershipDenyHttp(int $httpCode = 403, string $scope = 'resource'): void
{
    $msg = resourceOwnershipDeniedMessage($scope);
    if (!function_exists('jsonResponse') || !str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        if (function_exists('flash')) {
            flash('error', $msg);
        }
        if (function_exists('isCustomerLoggedIn') && isCustomerLoggedIn()) {
            redirect('customer_portal.php');
        }
        if (function_exists('isLoggedIn') && isLoggedIn()) {
            redirect('transactions.php');
        }
        redirect('customer_login.php');
    }
    jsonResponse(['success' => false, 'error' => $msg], $httpCode);
}
